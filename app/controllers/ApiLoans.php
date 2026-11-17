<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once dirname(__DIR__, 2) . '/scripts/lib/cooperative_finance.php';
require_once dirname(__DIR__, 2) . '/scripts/lib/brevo_email.php';

class ApiLoans extends Controller
{
    private $api;
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->api = $this->call->library('api');
        $this->call->library('coopwise_access');
        $this->db = $this->call->database();
        $this->call->model('User_model');
        $this->call->model('Member_model');
    }

    public function products()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $this->require_finance_schema();
        $loanCapital = cooperativeAvailableLoanCapital($this->db);
        $availableCapital = $loanCapital['available'];
        $availableCapitalFormatted = self::format_cents($availableCapital);
        $loanCapitalBreakdown = [
            'paid_up_share_capital' => self::format_cents($loanCapital['paid_up_share_capital']),
            'collected_interest' => self::format_cents($loanCapital['collected_interest']),
            'distributed_interest' => self::format_cents($loanCapital['distributed_interest']),
            'lendable_interest' => self::format_cents($loanCapital['lendable_interest']),
            'outstanding_principal' => self::format_cents($loanCapital['outstanding_principal']),
            'pending_principal' => self::format_cents($loanCapital['pending_principal'])
        ];
        $readiness = null;
        if ($user['role'] === 'member') {
            $member = $this->member_profile($user);
            $readiness = $this->latest_readiness((int) $member['id']);
            if (!$readiness || $readiness['status'] !== 'approved') {
                $this->api->respond([
                    'products' => [],
                    'loan_readiness' => $readiness,
                    'available_loan_capital' => $availableCapitalFormatted,
                    'loan_capital_breakdown' => $loanCapitalBreakdown
                ]);
            }
        }

        $query = $this->db->table('loan_types')
            ->select('id, name, description, interest_rate, overdue_interest_rate, max_amount, max_term_months, status');
        if ($user['role'] === 'member') {
            $query->where('status', 'active')->where('max_amount', '>', 0);
        }
        $products = $query->order_by('name', 'ASC')->get_all();
        if ($user['role'] === 'member') {
            $products = array_values(array_filter($products, static function (array $product) use ($availableCapital): bool {
                return self::money_to_cents((string) $product['max_amount']) <= $availableCapital;
            }));
        }
        $this->api->respond([
            'products' => $products,
            'loan_readiness' => $readiness,
            'available_loan_capital' => $availableCapitalFormatted,
            'loan_capital_breakdown' => $loanCapitalBreakdown
        ]);
    }

    public function eligibility()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if ($user['role'] !== 'member') {
            $this->api->respond(['eligibility' => null]);
        }
        $member = $this->member_profile($user);
        $age = Coopwise_access::age_from_birth_date($member['birth_date'] ?? null);
        if ($age === null || $age < 20) {
            $this->api->respond(['eligibility' => [
                'eligible' => false,
                'loan_age_eligible' => false,
                'max_eligible_amount' => '0.00',
                'reasons' => ['Loan services are available only to members aged 20 and over.']
            ]]);
        }
        $this->require_finance_schema();
        $eligibility = $this->member_eligibility($member);
        $eligibility['active_loans'] = $this->db->raw(
            "SELECT l.id, l.loan_code, l.current_balance, lt.name AS loan_type_name,
                    (SELECT ls.due_date
                     FROM loan_schedules ls
                     WHERE ls.loan_id = l.id AND ls.status IN ('pending', 'overdue')
                     ORDER BY ls.due_date ASC, ls.id ASC LIMIT 1) AS next_due_date,
                    (SELECT GREATEST(ls.total_due - ls.paid_amount, 0)
                     FROM loan_schedules ls
                     WHERE ls.loan_id = l.id AND ls.status IN ('pending', 'overdue')
                     ORDER BY ls.due_date ASC, ls.id ASC LIMIT 1) AS next_due_amount
             FROM loans l
             INNER JOIN loan_types lt ON lt.id = l.loan_type_id
             WHERE l.member_id = ? AND l.status IN ('approved', 'active') AND l.current_balance > 0
             ORDER BY l.created_at DESC, l.id DESC",
            [(int) $member['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->api->respond(['eligibility' => $eligibility]);
    }

    public function create_product()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $data = $this->api->body();
        $product = $this->validate_product($data);
        if ((int) $this->db->table('loan_types')->where('name', $product['name'])->count() > 0) {
            $this->api->respond_error('A loan product already uses that name.', 409);
        }

        $id = $this->db->table('loan_types')->insert($product);
        if (!$id) {
            throw new RuntimeException('Loan product creation failed.');
        }
        $this->api->respond(['message' => 'Loan product created.', 'id' => (int) $id], 201);
    }

    public function update_product($product_id = null)
    {
        $this->api->require_method('PUT');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $product_id = (int) $product_id;
        if ($product_id < 1 || !$this->db->table('loan_types')->where('id', $product_id)->get()) {
            $this->api->respond_error('Loan product not found.', 404);
        }
        $product = $this->validate_product($this->api->body());
        if ((int) $this->db->table('loan_types')->where('name', $product['name'])->where('id', '!=', $product_id)->count() > 0) {
            $this->api->respond_error('Another loan product already uses that name.', 409);
        }
        $this->db->table('loan_types')->where('id', $product_id)->update($product);
        $this->api->respond(['message' => 'Loan product updated.']);
    }

    public function delete_product($product_id = null)
    {
        $this->api->require_method('DELETE');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $product_id = filter_var($product_id, FILTER_VALIDATE_INT);
        if (!$product_id || $product_id < 1) {
            $this->api->respond_error('Choose a valid loan offer to delete.', 422);
        }

        $this->db->transaction();
        try {
            $product = $this->db->raw(
                'SELECT id, name FROM loan_types WHERE id = ? FOR UPDATE',
                [(int) $product_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new DomainException('The loan offer was not found.');
            }
            $loan_count = (int) $this->db->table('loans')
                ->where('loan_type_id', (int) $product_id)
                ->count();
            if ($loan_count > 0) {
                throw new DomainException(
                    'This offer has loan history and cannot be deleted. Pause it instead to keep member records intact.'
                );
            }
            $deleted = $this->db->table('loan_types')
                ->where('id', (int) $product_id)
                ->delete();
            if ($deleted !== 1) {
                throw new DomainException('The loan offer could not be deleted.');
            }
            $this->write_activity_log((int) $user['id'], 'loans', 'offer_deleted', [
                'product_id' => (int) $product_id,
                'name' => $product['name']
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise loan offer deletion failed: ' . $error->getMessage());
            throw $error;
        }

        $this->api->respond(['message' => 'Loan offer deleted.']);
    }

    public function record_payment($loan_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_finance_schema();
        $loan_id = filter_var($loan_id, FILTER_VALIDATE_INT);
        $data = $this->api->body();
        $amount = self::money_to_cents(trim((string) ($data['amount'] ?? '')));
        $payment_method = trim((string) ($data['payment_method'] ?? 'cash'));
        if (!$loan_id || $loan_id < 1 || $amount === false || $amount < 1
            || !in_array($payment_method, ['simulation', 'cash', 'bank', 'gcash', 'check'], true)) {
            $this->api->respond_error('Choose a valid loan, positive payment amount, and supported received-payment method.', 422);
        }

        $this->db->transaction();
        try {
            $loan = $this->db->raw('SELECT * FROM loans WHERE id = ? FOR UPDATE', [(int) $loan_id])
                ->fetch(PDO::FETCH_ASSOC);
            if (!$loan || !in_array($loan['status'], ['approved', 'active'], true)) {
                throw new DomainException('Only an approved or active loan can receive a payment.');
            }
            $balance = self::money_to_cents((string) $loan['current_balance']);
            $schedules = $this->db->raw(
                "SELECT * FROM loan_schedules
                 WHERE loan_id = ? AND status IN ('pending', 'overdue')
                 ORDER BY due_date ASC, id ASC FOR UPDATE",
                [(int) $loan_id]
            )->fetchAll(PDO::FETCH_ASSOC);
            $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            $interest = $this->accrue_loan_schedules(
                $schedules,
                (string) $loan['overdue_interest_rate'],
                $today,
                true
            );
            $schedules = $interest['schedules'];
            $pending = $this->db->raw(
                "SELECT COALESCE(SUM(amount), 0) AS total FROM payment_transactions
                 WHERE loan_id = ? AND status IN ('pending', 'needs_review')",
                [(int) $loan_id]
            )->fetch(PDO::FETCH_ASSOC);
            $available = $balance + $interest['balance_cents']
                - self::money_to_cents((string) ($pending['total'] ?? '0.00'));
            if ($amount > $available) {
                throw new DomainException(
                    'The payment cannot exceed the remaining amount not already reserved by another checkout: PHP '
                    . self::format_cents(max(0, $available)) . '.'
                );
            }

            try {
                $allocation = allocateLoanRepayment($amount, $schedules);
            } catch (InvalidArgumentException $error) {
                throw new DomainException($error->getMessage());
            }
            foreach ($allocation['schedules'] as $schedule) {
                $updated = $this->db->table('loan_schedules')
                    ->where('id', (int) $schedule['id'])
                    ->update([
                        'paid_amount' => $schedule['paid_amount'],
                        'principal_paid' => $schedule['principal_paid'],
                        'interest_paid' => $schedule['interest_paid'],
                        'overdue_interest_accrued' => $schedule['overdue_interest_accrued'],
                        'overdue_interest_paid' => $schedule['overdue_interest_paid'],
                        'overdue_interest_remainder_units' => $schedule['overdue_interest_remainder_units'],
                        'status' => $schedule['status']
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('An installment could not be updated for the recorded payment.');
                }
            }

            $reference = 'CW-STAFF-PAY-' . strtoupper(bin2hex(random_bytes(10)));
            $payment_id = $this->db->table('payment_transactions')->insert([
                'member_id' => (int) $loan['member_id'],
                'purpose' => 'loan',
                'amount' => self::format_cents($amount),
                'currency' => 'PHP',
                'reference_no' => $reference,
                'loan_id' => (int) $loan_id,
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
                'applied_at' => date('Y-m-d H:i:s')
            ]);
            if (!$payment_id) {
                throw new RuntimeException('The staff-recorded payment transaction could not be saved.');
            }
            $inserted = $this->db->table('loan_payments')->insert([
                'loan_id' => (int) $loan_id,
                'member_id' => (int) $loan['member_id'],
                'paid_by_user_id' => (int) $user['id'],
                'amount_paid' => self::format_cents($amount),
                'principal_paid' => self::format_cents($allocation['principal_paid']),
                'interest_paid' => self::format_cents($allocation['interest_paid']),
                'overdue_interest_paid' => self::format_cents($allocation['overdue_interest_paid']),
                'breakdown_recorded' => 1,
                'payment_method' => $payment_method,
                'reference_no' => $reference,
                'payment_date' => date('Y-m-d H:i:s')
            ]);
            if (!$inserted) {
                throw new RuntimeException('The loan repayment ledger entry could not be saved.');
            }
            cooperativeRecordLoanInterestDistribution(
                $this->db,
                (int) $inserted,
                $allocation['interest_paid']
            );

            $new_balance = $balance - $allocation['principal_paid'] - $allocation['interest_paid'];
            $updatedSchedulesById = [];
            foreach ($allocation['schedules'] as $updatedSchedule) {
                $updatedSchedulesById[(int) $updatedSchedule['id']] = $updatedSchedule;
            }
            $remaining_overdue_interest = 0;
            foreach ($schedules as $schedule) {
                $updatedSchedule = $updatedSchedulesById[(int) $schedule['id']] ?? null;
                $paid_overdue_interest = $updatedSchedule
                    ? self::money_to_cents((string) $updatedSchedule['overdue_interest_paid'])
                    : self::money_to_cents((string) $schedule['overdue_interest_paid']);
                $accrued_overdue_interest = $updatedSchedule
                    ? self::money_to_cents((string) $updatedSchedule['overdue_interest_accrued'])
                    : self::money_to_cents((string) $schedule['overdue_interest_accrued']);
                $remaining_overdue_interest += max(
                    0,
                    $accrued_overdue_interest - $paid_overdue_interest
                );
            }
            $updated_loan = $this->db->table('loans')
                ->where('id', (int) $loan_id)
                ->update([
                    'current_balance' => self::format_cents($new_balance),
                    'total_paid' => self::format_cents(self::money_to_cents((string) $loan['total_paid']) + $amount),
                    'status' => $new_balance === 0 && $remaining_overdue_interest === 0 ? 'completed' : 'active'
                ]);
            if ($updated_loan !== 1) {
                throw new RuntimeException('The loan balance could not be updated.');
            }
            $this->write_activity_log((int) $user['id'], 'loans', 'staff_payment_recorded', [
                'loan_id' => (int) $loan_id,
                'loan_code' => $loan['loan_code'],
                'payment_id' => (int) $payment_id,
                'amount' => self::format_cents($amount),
                'payment_method' => $payment_method,
                'reference_no' => $reference
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise staff loan payment failed: ' . $error->getMessage());
            throw $error;
        }

        $this->api->respond([
            'message' => 'The received loan payment was recorded.',
            'reference_no' => $reference,
            'amount' => self::format_cents($amount),
            'remaining_balance' => self::format_cents($new_balance)
        ], 201);
    }

    public function email_payment_details($loan_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_finance_schema();
        $loan_id = filter_var($loan_id, FILTER_VALIDATE_INT);
        if (!$loan_id || $loan_id < 1) {
            $this->api->respond_error('Choose a valid loan.', 422);
        }
        $this->api->rate_limit('loan-payment-details-email:' . (int) $user['id'] . ':' . $loan_id, 5, 3600);

        $loan = $this->db->raw(
            'SELECT l.id, l.member_id, l.loan_code, l.principal_amount, l.approved_amount,
                    l.current_balance, l.total_paid, l.interest_rate, l.overdue_interest_rate,
                    l.term_months, l.purpose,
                    l.status, l.created_at, lt.name AS loan_type_name,
                    m.member_number, m.first_name, m.last_name,
                    u.email AS account_email, u.status AS account_status
             FROM loans l
             INNER JOIN loan_types lt ON lt.id = l.loan_type_id
             INNER JOIN members m ON m.id = l.member_id
             LEFT JOIN users u ON u.id = m.user_id
             WHERE l.id = ?',
            [(int) $loan_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$loan) {
            $this->api->respond_error('Loan not found.', 404);
        }
        if (!in_array($loan['status'], ['approved', 'active', 'completed'], true)) {
            $this->api->respond_error('Payment details are available after a loan is approved.', 409);
        }
        if ($loan['account_status'] !== 'active'
            || !filter_var((string) $loan['account_email'], FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('The member needs an active account with a valid email address.', 409);
        }

        $schedule = $this->db->raw(
            'SELECT id, due_date, principal_due, principal_paid, interest_due, total_due, paid_amount,
                    overdue_interest_accrued, overdue_interest_paid, overdue_interest_remainder_units,
                    overdue_interest_calculated_at, status
             FROM loan_schedules
             WHERE loan_id = ?
             ORDER BY due_date ASC, id ASC',
            [(int) $loan_id]
        )->fetchAll(PDO::FETCH_ASSOC);
        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $interest = $this->accrue_loan_schedules($schedule, (string) $loan['overdue_interest_rate'], $today, false);
        $schedule = $interest['schedules'];
        $loan['overdue_interest_balance'] = self::format_cents($interest['balance_cents']);
        $email = self::loan_payment_email_content($loan, $schedule);
        $delivery = sendBrevoTransactionalEmail(
            (string) $loan['account_email'],
            $email['subject'],
            $email['htmlContent'],
            $email['textContent']
        );
        if (!$delivery['sent']) {
            error_log(
                'CoopWise loan payment-details email failed; loan_id=' . (int) $loan_id .
                '; staff_user_id=' . (int) $user['id'] . '; error=' . $delivery['error_code']
            );
            if ($delivery['error_code'] === 'configuration_missing') {
                $this->api->respond_error('Loan email is not configured. Check the Brevo sender settings on the backend.', 503);
            }
            $this->api->respond_error('Brevo did not accept the loan details email. Please try again later.', 502);
        }

        $this->api->respond([
            'message' => 'Loan payment details email was accepted for delivery to the member’s account email.'
        ], 201);
    }

    public function loans()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $this->require_finance_schema();
        $member = $user['role'] === 'member' ? $this->member_profile($user) : null;
        $requested_status = '';
        if (!$member) {
            $requested_status = trim((string) ($_GET['status'] ?? ''));
            if ($requested_status !== ''
                && !in_array($requested_status, ['pending', 'approved', 'active', 'approved_active', 'completed', 'rejected'], true)) {
                $this->api->respond_error('Choose a valid loan status filter.', 422);
            }
        }
        $pending_query = $this->db->table('loans')->where('status', 'pending');
        if ($member) {
            $pending_query->where('member_id', (int) $member['id']);
        }
        $pending_count = $pending_query->count();
        $status_query = 'SELECT status, COUNT(*) AS total FROM loans';
        $status_params = [];
        if ($member) {
            $status_query .= ' WHERE member_id = ?';
            $status_params[] = (int) $member['id'];
        }
        $status_counts = $this->db->raw(
            $status_query . ' GROUP BY status ORDER BY status ASC',
            $status_params
        )->fetchAll(PDO::FETCH_ASSOC);

        $query = $this->db->table('loans l')
            ->select('l.id, l.member_id, l.loan_type_id, l.loan_code, l.principal_amount, l.approved_amount, l.current_balance, l.total_paid, l.term_months, l.interest_rate, l.overdue_interest_rate, l.purpose, l.status, l.review_note, l.created_at, lt.name AS loan_type_name, m.member_number, m.first_name, m.last_name')
            ->inner_join('loan_types lt', 'lt.id = l.loan_type_id')
            ->inner_join('members m', 'm.id = l.member_id');
        if ($member) {
            $query->where('l.member_id', (int) $member['id']);
        } elseif ($requested_status === 'approved_active') {
            $query->in('l.status', ['approved', 'active']);
        } elseif ($requested_status !== '') {
            $query->where('l.status', $requested_status);
        }
        $pagination = $this->api->pagination_params();
        $loans = $query->order_by('l.created_at', 'DESC')
            ->limit($pagination['offset'], $pagination['per_page'] + 1)
            ->get_all();
        if ($loans) {
            $loan_ids = array_map(static function (array $loan): int {
                return (int) $loan['id'];
            }, $loans);
            $placeholders = implode(',', array_fill(0, count($loan_ids), '?'));
            $pending_payments = $this->db->raw(
                "SELECT loan_id, COALESCE(SUM(amount), 0) AS total
                 FROM payment_transactions
                 WHERE loan_id IN ({$placeholders}) AND status IN ('pending', 'needs_review')
                 GROUP BY loan_id",
                $loan_ids
            )->fetchAll(PDO::FETCH_ASSOC);
            $reserved_by_loan = [];
            foreach ($pending_payments as $pending_payment) {
                $reserved_by_loan[(int) $pending_payment['loan_id']] =
                    self::money_to_cents((string) $pending_payment['total']);
            }
            $schedule_rows = $this->db->raw(
                "SELECT loan_id, due_date, principal_due, principal_paid, total_due, paid_amount,
                        status, overdue_interest_accrued, overdue_interest_paid,
                        overdue_interest_remainder_units, overdue_interest_calculated_at
                 FROM loan_schedules
                 WHERE loan_id IN ({$placeholders}) AND status IN ('pending', 'overdue')",
                $loan_ids
            )->fetchAll(PDO::FETCH_ASSOC);
            $annual_rates = [];
            foreach ($loans as $loan) {
                $annual_rates[(int) $loan['id']] = (string) $loan['overdue_interest_rate'];
            }
            $overdue_interest_by_loan = [];
            $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            foreach ($schedule_rows as $schedule_row) {
                $loanId = (int) $schedule_row['loan_id'];
                $accrual = cooperativeAccrueLoanScheduleOverdueInterest(
                    $schedule_row,
                    (int) round((float) ($annual_rates[$loanId] ?? 0) * 100),
                    $today
                );
                $overdue_interest_by_loan[$loanId] =
                    ($overdue_interest_by_loan[$loanId] ?? 0) + $accrual['overdue_interest_balance_cents'];
            }
            foreach ($loans as &$loan) {
                $base_balance = self::money_to_cents((string) $loan['current_balance']);
                $overdue_interest_balance = $overdue_interest_by_loan[(int) $loan['id']] ?? 0;
                $total_balance = $base_balance + $overdue_interest_balance;
                $loan['contractual_balance'] = self::format_cents($base_balance);
                $loan['overdue_interest_balance'] = self::format_cents($overdue_interest_balance);
                $loan['current_balance'] = self::format_cents($total_balance);
                $loan['available_repayment_amount'] = self::format_cents(cooperativeAvailableRepayment(
                    $total_balance,
                    $reserved_by_loan[(int) $loan['id']] ?? 0
                ));
            }
            unset($loan);
        }
        $has_more = count($loans) > $pagination['per_page'];
        if ($has_more) {
            array_pop($loans);
        }
        $this->api->respond([
            'loans' => $loans,
            'pending_count' => $pending_count,
            'status_counts' => $status_counts,
            'pagination' => [
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'has_more' => $has_more
            ]
        ]);
    }

    public function apply()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $member = $this->member_profile($user);
        $this->require_finance_schema();
        $age = Coopwise_access::age_from_birth_date($member['birth_date'] ?? null);
        if ($age === null || $age < 20) {
            $this->api->respond_error('Loan applications are available to members aged 20 and over.', 403);
        }
        $readiness = $this->latest_readiness((int) $member['id']);
        if (!$readiness || $readiness['status'] !== 'approved') {
            $this->api->respond_error('Submit your loan documents and wait for staff approval before applying for loan offers.', 403);
        }
        $data = $this->api->body();
        $product_id = filter_var($data['loan_type_id'] ?? null, FILTER_VALIDATE_INT);
        $principal = self::money_to_cents(trim((string) ($data['amount'] ?? '')));
        $term = filter_var($data['term_months'] ?? null, FILTER_VALIDATE_INT);
        $purpose = trim((string) ($data['purpose'] ?? ''));
        $accept_overdue_terms = ($data['accept_overdue_terms'] ?? '') === '1';
        $allowed_purposes = ['Medical', 'Emergency', 'Allowance', 'Education', 'Livelihood / business'];
        $is_other_purpose = strpos($purpose, 'Other: ') === 0
            && preg_match('/\A[A-Za-z ]{1,128}\z/', trim(substr($purpose, 7))) === 1;
        if (!$product_id || $principal === false || $principal < 1 || !$term
            || strlen($purpose) > 255
            || (!in_array($purpose, $allowed_purposes, true) && !$is_other_purpose)) {
            $this->api->respond_error('Choose a valid loan purpose, specify a custom purpose if needed, and enter a positive amount and term.', 422);
        }

        $product = $this->db->table('loan_types')
            ->where('id', $product_id)->where('status', 'active')->get();
        if (!$product || self::money_to_cents((string) $product['max_amount']) < 1
            || $principal > self::money_to_cents((string) $product['max_amount'])
            || $term > (int) $product['max_term_months']) {
            $this->api->respond_error('The requested amount or term exceeds this product’s limits.', 422);
        }
        if ((float) $product['overdue_interest_rate'] > 0 && !$accept_overdue_terms) {
            $this->api->respond_error('Accept the disclosed overdue-interest terms before submitting this application.', 422);
        }
        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM cooperative_policies WHERE id = 1 FOR UPDATE')->fetch();
            $eligibility = $this->member_eligibility(
                $member,
                null,
                self::money_to_cents((string) $product['max_amount'])
            );
            if (!$eligibility['eligible']) {
                throw new DomainException(implode(' ', $eligibility['reasons']));
            }
            if ($principal > self::money_to_cents($eligibility['max_eligible_amount'])) {
                throw new DomainException(
                    'The requested amount exceeds your current eligible limit of PHP ' . $eligibility['max_eligible_amount'] . '.'
                );
            }
            $id = $this->db->table('loans')->insert([
                'member_id' => (int) $member['id'],
                'loan_type_id' => $product_id,
                'loan_code' => 'CW-LN-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'principal_amount' => self::format_cents($principal),
                'approved_amount' => self::format_cents($principal),
                'interest_rate' => number_format((float) $product['interest_rate'], 2, '.', ''),
                'overdue_interest_rate' => number_format((float) $product['overdue_interest_rate'], 2, '.', ''),
                'term_months' => $term,
                'purpose' => $purpose !== '' ? $purpose : null,
                'status' => 'pending',
                'current_balance' => '0.00',
                'total_paid' => '0.00'
            ]);
            if (!$id) {
                throw new RuntimeException('Loan application could not be saved.');
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond(['message' => 'Loan application submitted for review.', 'id' => (int) $id], 201);
    }

    public function readiness()
    {
        $user = $this->authenticated_user();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->submit_readiness($user);
        }
        $this->api->require_method('GET');
        if ($user['role'] === 'member') {
            $member = $this->member_profile($user);
            $age = Coopwise_access::age_from_birth_date($member['birth_date'] ?? null);
            if ($age === null || $age < 20) {
                $this->api->respond(['available' => false, 'readiness' => null, 'documents' => []]);
            }
            $readiness = $this->latest_readiness((int) $member['id']);
            $this->api->respond([
                'available' => true,
                'readiness' => $readiness,
                'documents' => $readiness ? $this->readiness_documents((int) $readiness['id']) : []
            ]);
        }

        $this->require_staff($user);
        $submissions = $this->db->table('loan_readiness_submissions lr')
            ->select('lr.id, lr.member_id, lr.status, lr.review_note, lr.created_at, m.member_number, m.first_name, m.last_name')
            ->inner_join('members m', 'm.id = lr.member_id')
            ->where('lr.status', 'pending')
            ->order_by('lr.created_at', 'ASC')
            ->get_all();
        foreach ($submissions as &$submission) {
            $submission['documents'] = $this->readiness_documents((int) $submission['id']);
        }
        unset($submission);
        $this->api->respond(['submissions' => $submissions]);
    }

    public function review_readiness($submission_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $submission_id = (int) $submission_id;
        $data = $this->api->body();
        $decision = $data['decision'] ?? '';
        $review_note = trim((string) ($data['review_note'] ?? ''));
        if ($submission_id < 1 || !in_array($decision, ['approve', 'reject'], true) || strlen($review_note) > 500) {
            $this->api->respond_error('Provide a valid document decision and a note no longer than 500 characters.', 422);
        }
        if ((int) $this->db->table('loan_documents')->where('readiness_submission_id', $submission_id)->count() < 1) {
            $this->api->respond_error('This document submission has no files to review.', 409);
        }
        $updated = $this->db->table('loan_readiness_submissions')
            ->where('id', $submission_id)
            ->where('status', 'pending')
            ->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'review_note' => $review_note !== '' ? $review_note : null,
                'reviewed_by_user_id' => (int) $user['id'],
                'reviewed_at' => date('Y-m-d H:i:s')
            ]);
        if ($updated !== 1) {
            $this->api->respond_error('This document submission has already been reviewed.', 409);
        }
        $this->api->respond([
            'message' => $decision === 'approve'
                ? 'Loan documents approved. The member can now view available loan offers.'
                : 'Loan documents rejected. The member can submit a corrected document set.'
        ]);
    }

    public function document_file($document_id = null)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $document = $this->db->table('loan_documents')->where('id', (int) $document_id)->get();
        if (!$document) {
            $this->api->respond_error('Document not found.', 404);
        }
        if ($user['role'] === 'member') {
            $submission = $this->db->table('loan_readiness_submissions')
                ->where('id', (int) $document['readiness_submission_id'])
                ->get();
            if (!$submission || (int) $submission['member_id'] !== (int) $this->member_profile($user)['id']) {
                $this->api->respond_error('You may only view documents for your own loan review.', 403);
            }
        }
        $this->send_private_image($document);
    }

    public function review($loan_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_finance_schema();
        $loan_id = (int) $loan_id;
        $data = $this->api->body();
        $decision = $data['decision'] ?? '';
        if ($loan_id < 1 || !in_array($decision, ['approve', 'reject'], true)) {
            $this->api->respond_error('Provide a valid loan and approve/reject decision.', 422);
        }

        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM cooperative_policies WHERE id = 1 FOR UPDATE')->fetch();
            $loan = $this->db->table('loans')->where('id', $loan_id)->get();
            if (!$loan || $loan['status'] !== 'pending') {
                throw new DomainException('This application has already been reviewed or does not exist.');
            }
            $review_note = trim((string) ($data['review_note'] ?? ''));
            if (strlen($review_note) > 500) {
                $this->db->roll_back();
                $this->api->respond_error('Review notes must be 500 characters or fewer.', 422);
            }
            if ($decision === 'reject') {
                $this->db->table('loans')->where('id', $loan_id)->where('status', 'pending')->update([
                    'status' => 'rejected',
                    'approved_by_user_id' => (int) $user['id'],
                    'review_note' => $review_note !== '' ? $review_note : null
                ]);
                $this->write_activity_log((int) $user['id'], 'loans', 'application_rejected', [
                    'loan_id' => $loan_id,
                    'loan_code' => $loan['loan_code']
                ]);
                $this->db->commit();
                $this->api->respond(['message' => 'Application rejected.']);
            }
            $readiness = $this->latest_readiness((int) $loan['member_id']);
            if (!$readiness || $readiness['status'] !== 'approved') {
                throw new DomainException('Approve the member’s loan documents before approving a loan application.');
            }

            $principal = self::money_to_cents((string) $loan['principal_amount']);
            $member = $this->db->table('members')->where('id', (int) $loan['member_id'])->get();
            if (!$member || $member['status'] !== 'active') {
                throw new DomainException('Only an active member can receive a loan.');
            }
            $product = $this->db->table('loan_types')->where('id', (int) $loan['loan_type_id'])->get();
            if (!$product) {
                throw new DomainException('The loan product is no longer available.');
            }
            $eligibility = $this->member_eligibility(
                $member,
                $loan_id,
                self::money_to_cents((string) $product['max_amount'])
            );
            if (!$eligibility['eligible'] || $principal > self::money_to_cents($eligibility['max_eligible_amount'])) {
                throw new DomainException(
                    !$eligibility['eligible']
                        ? implode(' ', $eligibility['reasons'])
                        : 'The requested amount exceeds the member’s current eligibility limit of PHP ' . $eligibility['max_eligible_amount'] . '.'
                );
            }
            $term = (int) $loan['term_months'];
            $rate_basis_points = (int) round((float) $loan['interest_rate'] * 100);
            $interest = (int) round($principal * $rate_basis_points * $term / 10000);
            $total = $principal + $interest;
            $updated = $this->db->table('loans')->where('id', $loan_id)->where('status', 'pending')->update([
                'status' => 'approved',
                'current_balance' => self::format_cents($total),
                'review_note' => $review_note !== '' ? $review_note : null,
                'approved_by_user_id' => (int) $user['id']
            ]);
            if ($updated !== 1) {
                throw new DomainException('This application has already been reviewed.');
            }

            $this->db->raw('SELECT id FROM members WHERE id = ? FOR UPDATE', [(int) $loan['member_id']])->fetch();
            $savings_deposits = $this->db->table('savings_transactions')
                ->select_sum('amount', 'total')
                ->where('member_id', (int) $loan['member_id'])
                ->where('type', 'deposit')
                ->get();
            $savings_withdrawals = $this->db->table('savings_transactions')
                ->select_sum('amount', 'total')
                ->where('member_id', (int) $loan['member_id'])
                ->where('type', 'withdrawal')
                ->get();
            $savings_balance = self::money_to_cents((string) ($savings_deposits['total'] ?? '0'))
                - self::money_to_cents((string) ($savings_withdrawals['total'] ?? '0'));
            $disbursement_reference = 'CW-DISBURSE-' . $loan['loan_code'];
            $disbursement_id = $this->db->table('savings_transactions')->insert([
                'member_id' => (int) $loan['member_id'],
                'type' => 'deposit',
                'amount' => self::format_cents($principal),
                'balance_after' => self::format_cents($savings_balance + $principal),
                'reference_no' => $disbursement_reference,
                'description' => 'Approved loan disbursement credited to savings.',
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$disbursement_id) {
                throw new RuntimeException('Loan was approved but its savings disbursement could not be recorded.');
            }
            $approved_date = new DateTimeImmutable('today');
            $first_due_month = $approved_date->modify('first day of next month');
            $due_day = (int) $approved_date->format('j');
            $principal_base = intdiv($principal, $term);
            $principal_remainder = $principal % $term;
            $interest_base = intdiv($interest, $term);
            $interest_remainder = $interest % $term;
            for ($month = 0; $month < $term; $month++) {
                $month_start = $first_due_month->modify('+' . $month . ' months');
                $due_date = $month_start->setDate(
                    (int) $month_start->format('Y'),
                    (int) $month_start->format('n'),
                    min($due_day, (int) $month_start->format('t'))
                );
                $principal_due = $principal_base + ($month < $principal_remainder ? 1 : 0);
                $interest_due = $interest_base + ($month < $interest_remainder ? 1 : 0);
                $inserted = $this->db->table('loan_schedules')->insert([
                    'loan_id' => $loan_id,
                    'due_date' => $due_date->format('Y-m-d'),
                    'principal_due' => self::format_cents($principal_due),
                    'interest_due' => self::format_cents($interest_due),
                    'total_due' => self::format_cents($principal_due + $interest_due),
                    'paid_amount' => '0.00',
                    'status' => 'pending'
                ]);
                if (!$inserted) {
                    throw new RuntimeException('A monthly installment could not be generated.');
                }
            }
            $this->write_activity_log((int) $user['id'], 'loans', 'application_approved', [
                'loan_id' => $loan_id,
                'loan_code' => $loan['loan_code'],
                'approved_amount' => self::format_cents($principal),
                'term_months' => $term
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise API loan review failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Application approved, available cooperative capital was reduced by the principal, the member’s demo savings were credited, and monthly installments were generated.']);
    }

    public function schedule($loan_id = null)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $this->require_finance_schema();
        $loan_id = (int) $loan_id;
        $loan = $this->db->table('loans l')
            ->select('l.id, l.member_id, l.loan_code, l.principal_amount, l.approved_amount, l.current_balance, l.interest_rate, l.overdue_interest_rate, l.term_months, l.status, lt.name AS loan_type_name')
            ->inner_join('loan_types lt', 'lt.id = l.loan_type_id')
            ->where('l.id', $loan_id)
            ->get();
        if (!$loan) {
            $this->api->respond_error('Loan not found.', 404);
        }
        if ($user['role'] === 'member' && (int) $loan['member_id'] !== (int) $this->member_profile($user)['id']) {
            $this->api->respond_error('You may only view your own loan schedule.', 403);
        }
        $schedule = $this->db->table('loan_schedules')
            ->where('loan_id', $loan_id)
            ->order_by('due_date', 'ASC')
            ->get_all();
        $totals = ['principal' => 0, 'interest' => 0, 'due' => 0, 'paid' => 0, 'overdue_interest' => 0];
        $baseRemaining = self::money_to_cents((string) $loan['current_balance']);
        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $totalOverdueInterest = 0;
        foreach ($schedule as $installment) {
            $accrual = cooperativeAccrueLoanScheduleOverdueInterest(
                $installment,
                (int) round((float) $loan['overdue_interest_rate'] * 100),
                $today
            );
            $totalOverdueInterest += $accrual['overdue_interest_balance_cents'];
        }
        $overdueInterestRemaining = $totalOverdueInterest;
        foreach ($schedule as &$installment) {
            $accrual = cooperativeAccrueLoanScheduleOverdueInterest(
                $installment,
                (int) round((float) $loan['overdue_interest_rate'] * 100),
                $today
            );
            $principal = self::money_to_cents((string) $installment['principal_due']);
            $interest = self::money_to_cents((string) $installment['interest_due']);
            $due = self::money_to_cents((string) $installment['total_due']);
            $paid = self::money_to_cents((string) $installment['paid_amount']);
            $latePaid = self::money_to_cents((string) $installment['overdue_interest_paid']);
            $lateDue = self::money_to_cents((string) $accrual['overdue_interest_accrued']);
            $lateRemaining = max(0, $lateDue - $latePaid);
            $totals['principal'] += $principal;
            $totals['interest'] += $interest;
            $totals['due'] += $due + $lateDue;
            $totals['paid'] += $paid + $latePaid;
            $totals['overdue_interest'] += $lateDue;
            $installment = array_merge($installment, $accrual);
            $installment['overdue_interest_remaining'] = self::format_cents($lateRemaining);
            $installment['display_total_due'] = self::format_cents($due + $lateDue);
            $installment['display_paid_amount'] = self::format_cents($paid + $latePaid);
            $baseRemaining = max(0, $baseRemaining - max(0, $due - $paid));
            $overdueInterestRemaining = max(0, $overdueInterestRemaining - $lateRemaining);
            $installment['remaining_after'] = self::format_cents($baseRemaining + $overdueInterestRemaining);
        }
        unset($installment);
        $loan['contractual_balance'] = (string) $loan['current_balance'];
        $loan['overdue_interest_balance'] = self::format_cents($totalOverdueInterest);
        $loan['current_balance'] = self::format_cents(
            self::money_to_cents((string) $loan['current_balance']) + $totalOverdueInterest
        );
        $this->api->respond([
            'loan' => $loan,
            'schedule' => $schedule,
            'total_principal' => self::format_cents($totals['principal']),
            'total_interest' => self::format_cents($totals['interest']),
            'total_overdue_interest' => self::format_cents($totals['overdue_interest']),
            'total_due' => self::format_cents($totals['due']),
            'total_paid' => self::format_cents($totals['paid']),
            'generated_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function payments()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $this->require_finance_schema();
        $member = $user['role'] === 'member' ? $this->member_profile($user) : null;
        $query = $this->db->table('loan_payments lp')
            ->select('lp.id, lp.loan_id, lp.member_id, lp.amount_paid, lp.principal_paid, lp.interest_paid, lp.overdue_interest_paid, lp.breakdown_recorded, lp.payment_method, lp.reference_no, lp.payment_date, l.loan_code, m.first_name, m.last_name')
            ->inner_join('loans l', 'l.id = lp.loan_id')
            ->inner_join('members m', 'm.id = lp.member_id');
        if ($member) {
            $query->where('lp.member_id', (int) $member['id']);
        }
        $pagination = $this->api->pagination_params();
        $payments = $query->order_by('lp.payment_date', 'DESC')->order_by('lp.id', 'DESC')
            ->limit($pagination['offset'], $pagination['per_page'] + 1)
            ->get_all();
        $has_more = count($payments) > $pagination['per_page'];
        if ($has_more) {
            array_pop($payments);
        }
        $this->api->respond([
            'payments' => $payments,
            'pagination' => [
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'has_more' => $has_more
            ]
        ]);
    }

    private function authenticated_user()
    {
        $claims = $this->api->require_jwt();
        $user = $this->User_model->find_identity((int) $claims['sub']);
        if (!$user || $user['status'] !== 'active') {
            $this->api->respond_error('Account is unavailable.', 401);
        }
        if (!in_array($user['role'], ['admin', 'officer', 'member'], true)) {
            $this->api->respond_error('This account does not have a supported application role.', 403);
        }
        return $user;
    }

    private function member_profile(array $user)
    {
        if ($user['role'] !== 'member') {
            $this->api->respond_error('This action requires a member account.', 403);
        }
        $member = $this->Member_model->find_for_user((int) $user['id']);
        if (!$member || $member['status'] !== 'active') {
            $this->api->respond_error('An active member profile is required.', 403);
        }
        return $member;
    }

    private function require_staff(array $user)
    {
        if (!in_array($user['role'], ['admin', 'officer'], true)) {
            $this->api->respond_error('This action is available to cooperative staff only.', 403);
        }
    }

    private function write_activity_log(int $user_id, string $module, string $action, array $details)
    {
        $id = $this->db->table('activity_logs')->insert([
            'user_id' => $user_id,
            'module' => $module,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_SLASHES)
        ]);
        if (!$id) {
            throw new RuntimeException('The loan audit record could not be saved.');
        }
    }

    private static function loan_payment_email_content(array $loan, array $schedule): array
    {
        $escape = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $formatAmount = static function (string $amount): string {
            $cents = self::money_to_cents($amount);
            if ($cents === false) {
                throw new RuntimeException('A stored loan amount is invalid.');
            }
            return self::format_cents($cents);
        };
        $memberName = trim((string) $loan['first_name'] . ' ' . (string) $loan['last_name']);
        $loanBalanceCents = self::money_to_cents((string) $loan['current_balance']);
        $overdueInterestBalanceCents = self::money_to_cents((string) ($loan['overdue_interest_balance'] ?? '0.00'));
        $totalPaidCents = self::money_to_cents((string) $loan['total_paid']);
        if ($loanBalanceCents === false || $overdueInterestBalanceCents === false || $totalPaidCents === false) {
            throw new RuntimeException('A stored loan balance is invalid.');
        }

        $details = [
            'Loan ID' => (string) $loan['loan_code'],
            'Loan record number' => (string) $loan['id'],
            'Member' => $memberName,
            'Member ID' => (string) $loan['member_number'],
            'Loan offer' => (string) $loan['loan_type_name'],
            'Status' => ucfirst((string) $loan['status']),
            'Date applied' => (string) $loan['created_at'],
            'Requested amount' => 'PHP ' . $formatAmount((string) $loan['principal_amount']),
            'Approved amount' => 'PHP ' . $formatAmount((string) $loan['approved_amount']),
            'Contractual loan balance' => 'PHP ' . self::format_cents($loanBalanceCents),
            'Overdue interest balance' => 'PHP ' . self::format_cents($overdueInterestBalanceCents),
            'Current loan balance including overdue interest' => 'PHP ' .
                self::format_cents($loanBalanceCents + $overdueInterestBalanceCents),
            'Total paid' => 'PHP ' . self::format_cents($totalPaidCents),
            'Interest rate' => (string) $loan['interest_rate'] . '% monthly',
            'Overdue interest rate' => (string) ($loan['overdue_interest_rate'] ?? '0.00') . '% per year, simple, on unpaid installment principal',
            'Term' => (string) $loan['term_months'] . ' months',
            'Purpose' => trim((string) ($loan['purpose'] ?? '')) ?: 'Not specified'
        ];

        $scheduleRows = '';
        $scheduleText = [];
        $nextDueDate = null;
        foreach ($schedule as $installment) {
            $dueCents = self::money_to_cents((string) $installment['total_due']);
            $paidCents = self::money_to_cents((string) $installment['paid_amount']);
            $overdueInterestCents = self::money_to_cents((string) $installment['overdue_interest_accrued']);
            $overdueInterestPaidCents = self::money_to_cents((string) $installment['overdue_interest_paid']);
            if ($dueCents === false || $paidCents === false
                || $overdueInterestCents === false || $overdueInterestPaidCents === false) {
                throw new RuntimeException('A stored installment amount is invalid.');
            }
            $remainingCents = max(0, $dueCents - $paidCents)
                + max(0, $overdueInterestCents - $overdueInterestPaidCents);
            $installmentStatus = ucfirst((string) $installment['status']);
            if ($nextDueDate === null && $remainingCents > 0
                && in_array($installment['status'], ['pending', 'overdue'], true)) {
                $nextDueDate = (string) $installment['due_date'];
            }
            $scheduleRows .= '<tr><td>' . $escape($installment['due_date']) .
                '</td><td>PHP ' . $escape($formatAmount((string) $installment['principal_due'])) .
                '</td><td>PHP ' . $escape($formatAmount((string) $installment['interest_due'])) .
                '</td><td>PHP ' . $escape(self::format_cents($overdueInterestCents)) .
                '</td><td>PHP ' . $escape(self::format_cents($dueCents + $overdueInterestCents)) .
                '</td><td>PHP ' . $escape(self::format_cents($paidCents + $overdueInterestPaidCents)) .
                '</td><td>PHP ' . $escape(self::format_cents($remainingCents)) .
                '</td><td>' . $escape($installmentStatus) . '</td></tr>';
            $scheduleText[] = $installment['due_date'] . ' | Principal PHP ' .
                $formatAmount((string) $installment['principal_due']) . ' | Interest PHP ' .
                $formatAmount((string) $installment['interest_due']) . ' | Overdue interest PHP ' .
                self::format_cents($overdueInterestCents) . ' | Total due PHP ' .
                self::format_cents($dueCents + $overdueInterestCents) . ' | Paid PHP ' .
                self::format_cents($paidCents + $overdueInterestPaidCents) . ' | Remaining PHP ' .
                self::format_cents($remainingCents) . ' | ' . $installmentStatus;
        }
        $details['Next due date'] = $nextDueDate ?: 'No outstanding scheduled installment';

        $detailRows = '';
        $detailText = [];
        foreach ($details as $label => $value) {
            $detailRows .= '<tr><th align="left">' . $escape($label) . '</th><td>' .
                $escape($value) . '</td></tr>';
            $detailText[] = $label . ': ' . $value;
        }

        $scheduleHtml = $scheduleRows !== ''
            ? '<table border="1" cellpadding="6" cellspacing="0"><thead><tr>' .
                '<th>Due date</th><th>Principal</th><th>Interest</th><th>Overdue interest</th><th>Total due</th>' .
                '<th>Paid</th><th>Remaining</th><th>Status</th></tr></thead><tbody>' .
                $scheduleRows . '</tbody></table>'
            : '<p>No repayment installments are recorded for this loan.</p>';
        $schedulePlainText = $scheduleText !== []
            ? implode("\n", $scheduleText)
            : 'No repayment installments are recorded for this loan.';
        $asOf = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

        return [
            'subject' => 'CoopWise loan payment details: ' . (string) $loan['loan_code'],
            'htmlContent' => '<!doctype html><html><body><p>Hello ' . $escape($memberName) .
                ',</p><p>Here are the current details and repayment schedule for your loan, as of ' .
                $escape($asOf) . '.</p><table border="1" cellpadding="6" cellspacing="0"><tbody>' .
                $detailRows . '</tbody></table><h3>Repayment schedule</h3>' . $scheduleHtml .
                '<p>If you have already made a payment, allow time for the cooperative to update its records. ' .
                'Contact cooperative staff if anything looks incorrect.</p><p>CoopWise</p></body></html>',
            'textContent' => "Hello {$memberName},\n\nHere are the current details and repayment " .
                "schedule for your loan, as of {$asOf}.\n\n" . implode("\n", $detailText) .
                "\n\nRepayment schedule:\n" . $schedulePlainText .
                "\n\nIf you have already made a payment, allow time for the cooperative to update its records. " .
                "Contact cooperative staff if anything looks incorrect.\n\nCoopWise"
        ];
    }

    private function latest_readiness(int $member_id)
    {
        return $this->db->table('loan_readiness_submissions')
            ->where('member_id', $member_id)
            ->order_by('created_at', 'DESC')
            ->order_by('id', 'DESC')
            ->get();
    }

    private function member_eligibility(array $member, ?int $ignored_pending_loan_id = null, int $product_limit = PHP_INT_MAX)
    {
        $member_id = (int) $member['id'];
        $capital = $this->db->raw(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0) AS total
             FROM share_capital_transactions WHERE member_id = ?",
            [$member_id]
        )->fetch(PDO::FETCH_ASSOC);
        $savings = $this->db->raw(
            "SELECT COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) AS total
             FROM savings_transactions WHERE member_id = ?",
            [$member_id]
        )->fetch(PDO::FETCH_ASSOC);
        $active = $this->db->raw(
            "SELECT COALESCE(SUM(current_balance), 0) AS total FROM loans
             WHERE member_id = ? AND status IN ('approved', 'active')",
            [$member_id]
        )->fetch(PDO::FETCH_ASSOC);
        $pending_query = $this->db->table('loans')->where('member_id', $member_id)->where('status', 'pending');
        if ($ignored_pending_loan_id !== null) {
            $pending_query->where('id', '!=', $ignored_pending_loan_id);
        }
        $pending = (int) $pending_query->count() > 0;
        $policies = $this->db->raw(
            'SELECT minimum_membership_days, minimum_paid_up_share_capital,
                    minimum_savings_balance, loan_to_paid_up_share_capital_multiplier
             FROM cooperative_policies WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$policies) {
            throw new RuntimeException('Cooperative loan eligibility policies are not configured.');
        }
        $joined = new DateTimeImmutable((string) $member['created_at']);
        $membership_elapsed_seconds = max(0, time() - $joined->getTimestamp());
        $membership_days = cooperativeSimulatedMembershipDays($membership_elapsed_seconds);
        $documents = $this->latest_readiness($member_id);
        $eligibility = cooperativeLoanEligibility(
            max(0, self::money_to_cents((string) ($capital['total'] ?? '0.00'))),
            max(0, self::money_to_cents((string) ($savings['total'] ?? '0.00'))),
            max(0, self::money_to_cents((string) ($active['total'] ?? '0.00'))),
            $membership_days,
            $pending,
            $documents && $documents['status'] === 'approved',
            self::money_to_cents((string) $policies['minimum_paid_up_share_capital']),
            self::money_to_cents((string) $policies['minimum_savings_balance']),
            (int) $policies['minimum_membership_days'],
            (float) $policies['loan_to_paid_up_share_capital_multiplier'],
            $product_limit
        );
        $loan_capital = cooperativeAvailableLoanCapital($this->db, $ignored_pending_loan_id);
        $cooperative_limit = $loan_capital['available'];
        $eligibility['max_eligible_amount_cents'] = min(
            $eligibility['max_eligible_amount_cents'],
            $cooperative_limit
        );
        if ($eligibility['max_eligible_amount_cents'] < 1) {
            $eligibility['eligible'] = false;
            $eligibility['reasons'][] = 'The cooperative has no uncommitted share capital available for lending.';
        }
        return [
            'eligible' => $eligibility['eligible'],
            'max_eligible_amount' => self::format_cents($eligibility['max_eligible_amount_cents']),
            'paid_up_share_capital' => self::format_cents(max(0, self::money_to_cents((string) ($capital['total'] ?? '0.00')))),
            'savings_balance' => self::format_cents(max(0, self::money_to_cents((string) ($savings['total'] ?? '0.00')))),
            'active_loan_balance' => self::format_cents(max(0, self::money_to_cents((string) ($active['total'] ?? '0.00')))),
            'available_loan_capital' => self::format_cents($cooperative_limit),
            'membership_days' => $membership_days,
            'membership_elapsed_seconds' => $membership_elapsed_seconds,
            'membership_checked_at' => time(),
            'criteria' => [
                'minimum_paid_up_share_capital' => self::format_cents(self::money_to_cents((string) $policies['minimum_paid_up_share_capital'])),
                'minimum_savings_balance' => self::format_cents(self::money_to_cents((string) $policies['minimum_savings_balance'])),
                'minimum_membership_days' => (int) $policies['minimum_membership_days'],
                'membership_required_seconds' => cooperativeMembershipRequiredSeconds(
                    (int) $policies['minimum_membership_days']
                ),
                'loan_to_paid_up_share_capital_multiplier' => (float) $policies['loan_to_paid_up_share_capital_multiplier']
            ],
            'reasons' => $eligibility['reasons']
        ];
    }

    private function require_finance_schema()
    {
        $tables = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('cooperative_loan_interest_distributions', 'cooperative_loan_interest_returns')"
        )->fetchColumn();
        $columns = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND (
                 (table_name = 'cooperative_policies' AND column_name IN (
                     'minimum_membership_days', 'minimum_paid_up_share_capital',
                     'minimum_savings_balance', 'loan_to_paid_up_share_capital_multiplier'
                 )) OR
                 (table_name = 'loan_types' AND column_name = 'overdue_interest_rate') OR
                 (table_name = 'loans' AND column_name = 'overdue_interest_rate') OR
                 (table_name = 'loan_payments' AND column_name IN (
                     'principal_paid', 'interest_paid', 'overdue_interest_paid', 'breakdown_recorded'
                 )) OR
                 (table_name = 'loan_schedules' AND column_name IN (
                     'principal_paid', 'interest_paid', 'overdue_interest_accrued',
                     'overdue_interest_paid', 'overdue_interest_remainder_units',
                     'overdue_interest_calculated_at'
                 )) OR
                 (table_name = 'cooperative_loan_interest_distributions' AND column_name IN (
                     'loan_payment_id', 'interest_amount', 'eligible_investor_count', 'status'
                 )) OR
                 (table_name = 'cooperative_loan_interest_returns' AND column_name IN (
                     'distribution_id', 'member_id', 'amount', 'claim_status', 'claimed_at', 'claim_reference'
                 ))
             )"
        )->fetchColumn();
        if ((int) $tables !== 2 || (int) $columns !== 26) {
            error_log('CoopWise cooperative finance schema is missing; apply the cooperative-finance, loan-overdue-interest, and loan-interest returns upgrades.');
            $this->api->respond_error(
                'Loan eligibility and capital tracking are not configured. Back up the database, then run database/coopwise_cooperative_finance_upgrade.sql, database/coopwise_loan_overdue_interest_upgrade.sql, and database/coopwise_loan_interest_returns_upgrade.sql in Navicat.',
                503
            );
        }
    }

    private function accrue_loan_schedules(array $schedules, string $annual_rate, string $today, bool $persist): array
    {
        $rateBasisPoints = (int) round((float) $annual_rate * 100);
        $interestBalance = 0;
        foreach ($schedules as &$schedule) {
            $storedCalculatedAt = $schedule['overdue_interest_calculated_at'] ?? null;
            $storedRemainderUnits = (int) ($schedule['overdue_interest_remainder_units'] ?? 0);
            $storedStatus = (string) $schedule['status'];
            $accrual = cooperativeAccrueLoanScheduleOverdueInterest($schedule, $rateBasisPoints, $today);
            $schedule = array_merge($schedule, $accrual);
            $baseBalance = max(
                0,
                self::money_to_cents((string) $schedule['total_due'])
                    - self::money_to_cents((string) $schedule['paid_amount'])
            );
            $schedule['status'] = $baseBalance === 0 && $accrual['overdue_interest_balance_cents'] === 0
                ? 'paid'
                : ((string) $schedule['due_date'] < $today
                    ? 'overdue'
                    : (string) $schedule['status']);
            if ($persist && (
                $accrual['new_overdue_interest_cents'] > 0
                || $accrual['overdue_interest_remainder_units'] !== $storedRemainderUnits
                || $accrual['overdue_interest_calculated_at'] !== $storedCalculatedAt
                || $schedule['status'] !== $storedStatus
            )) {
                $updated = $this->db->table('loan_schedules')
                    ->where('id', (int) $schedule['id'])
                    ->update([
                        'overdue_interest_accrued' => $accrual['overdue_interest_accrued'],
                        'overdue_interest_remainder_units' => $accrual['overdue_interest_remainder_units'],
                        'overdue_interest_calculated_at' => $accrual['overdue_interest_calculated_at'],
                        'status' => $schedule['status']
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('An overdue installment could not be updated.');
                }
            }
            $interestBalance += $accrual['overdue_interest_balance_cents'];
        }
        unset($schedule);

        return [
            'schedules' => $schedules,
            'balance_cents' => $interestBalance
        ];
    }

    private function readiness_documents(int $submission_id)
    {
        return $this->db->table('loan_documents')
            ->select('id, original_name, mime_type, file_size, created_at')
            ->where('readiness_submission_id', $submission_id)
            ->order_by('created_at', 'ASC')
            ->get_all();
    }

    private function submit_readiness(array $user)
    {
        $this->api->require_method('POST');
        $member = $this->member_profile($user);
        $age = Coopwise_access::age_from_birth_date($member['birth_date'] ?? null);
        if ($age === null || $age < 20) {
            $this->api->respond_error('Loan document review is available to members aged 20 and over.', 403);
        }
        $latest = $this->latest_readiness((int) $member['id']);
        if ($latest && in_array($latest['status'], ['pending', 'approved'], true)) {
            $this->api->respond_error(
                $latest['status'] === 'pending'
                    ? 'Your loan documents are already waiting for staff review.'
                    : 'Your loan documents are approved; available loan offers are ready.',
                409
            );
        }
        $files = $this->validated_image_uploads('documents', 5);
        if (!$files) {
            $this->api->respond_error('Attach at least one supporting document image for review.', 422);
        }
        $submission_id = $this->db->table('loan_readiness_submissions')->insert([
            'member_id' => (int) $member['id'],
            'status' => 'pending'
        ]);
        if (!$submission_id) {
            throw new RuntimeException('Loan document submission could not be saved.');
        }

        $stored_files = [];
        try {
            foreach ($files as $file) {
                $stored = $this->store_private_image($file);
                $stored_files[] = $stored['path'];
                $inserted = $this->db->table('loan_documents')->insert([
                    'readiness_submission_id' => (int) $submission_id,
                    'uploaded_by_user_id' => (int) $user['id'],
                    'original_name' => $file['name'],
                    'storage_name' => $stored['name'],
                    'mime_type' => $stored['mime'],
                    'file_size' => $file['size']
                ]);
                if (!$inserted) {
                    throw new RuntimeException('A supporting document could not be saved.');
                }
            }
        } catch (Throwable $error) {
            $this->db->table('loan_readiness_submissions')->where('id', (int) $submission_id)->delete();
            foreach ($stored_files as $path) {
                if (is_file($path) && !unlink($path)) {
                    error_log('CoopWise could not remove an orphaned loan document: ' . $path);
                }
            }
            throw $error;
        }
        $this->api->respond(['message' => 'Loan documents submitted. Available loan offers will appear after staff approval.'], 201);
    }

    private function validated_image_uploads($field, $maximum_count)
    {
        if (!isset($_FILES[$field])) {
            return [];
        }
        $upload = $_FILES[$field];
        $files = [];
        $total_size = 0;
        $names = is_array($upload['name']) ? $upload['name'] : [$upload['name']];
        $temporary_paths = is_array($upload['tmp_name']) ? $upload['tmp_name'] : [$upload['tmp_name']];
        $errors = is_array($upload['error']) ? $upload['error'] : [$upload['error']];
        $sizes = is_array($upload['size']) ? $upload['size'] : [$upload['size']];
        if (count($names) > $maximum_count) {
            $this->api->respond_error('Attach no more than ' . $maximum_count . ' images at a time.', 422);
        }
        foreach ($names as $index => $name) {
            if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($temporary_paths[$index] ?? '')) {
                $this->api->respond_error('A selected image could not be uploaded. Please try again.', 422);
            }
            $size = (int) ($sizes[$index] ?? 0);
            $total_size += $size;
            if ($total_size > 7 * 1024 * 1024) {
                $this->api->respond_error('Supporting document images must total no more than 7 MB per application.', 422);
            }
            $image_info = @getimagesize($temporary_paths[$index]);
            $mime = $image_info['mime'] ?? '';
            if ($size < 1 || $size > 5 * 1024 * 1024
                || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $this->api->respond_error('Documents must be JPEG, PNG, or WebP images no larger than 5 MB each.', 422);
            }
            $safe_name = basename(str_replace('\\', '/', (string) $name));
            $safe_name = preg_replace('/[\x00-\x1F\x7F]/', '', $safe_name);
            $files[] = [
                'tmp_name' => $temporary_paths[$index],
                'name' => substr($safe_name ?: 'supporting-document', 0, 255),
                'size' => $size,
                'mime' => $mime
            ];
        }
        return $files;
    }

    private function store_private_image(array $file)
    {
        $directory = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'private_uploads';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private upload storage could not be created.');
        }
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$file['mime']];
        $name = bin2hex(random_bytes(32)) . '.' . $extension;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new RuntimeException('A supporting document could not be stored.');
        }
        return ['name' => $name, 'path' => $path, 'mime' => $file['mime']];
    }

    private function send_private_image(array $file)
    {
        if (!preg_match('/^[a-f0-9]{64}\.(?:jpg|png|webp)$/D', $file['storage_name'])) {
            throw new RuntimeException('The stored document filename is invalid.');
        }
        $path = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'private_uploads' . DIRECTORY_SEPARATOR . $file['storage_name'];
        if (!is_file($path) || !is_readable($path)) {
            $this->api->respond_error('The stored document is unavailable.', 404);
        }
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="document-image"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private function validate_product(array $data)
    {
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $rate = trim((string) ($data['interest_rate'] ?? ''));
        $overdue_rate = trim((string) ($data['overdue_interest_rate'] ?? '6.00'));
        $max_amount = self::money_to_cents(trim((string) ($data['max_amount'] ?? '')));
        $term = filter_var($data['max_term_months'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || strlen($name) > 100 || strlen($description) > 255
            || !preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/', $rate)
            || (float) $rate > 99.99
            || !preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/', $overdue_rate)
            || (float) $overdue_rate > 99.99 || $max_amount === false || $max_amount < 1
            || !$term || $term > 120) {
            $this->api->respond_error('Provide a name, monthly rate and overdue annual rate from 0–99.99%, positive maximum amount, and term from 1–120 months.', 422);
        }
        $status = $data['status'] ?? 'active';
        if (!in_array($status, ['active', 'inactive'], true)) {
            $this->api->respond_error('Product status must be active or inactive.', 422);
        }
        return [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'interest_rate' => number_format((float) $rate, 2, '.', ''),
            'overdue_interest_rate' => number_format((float) $overdue_rate, 2, '.', ''),
            'max_amount' => self::format_cents($max_amount),
            'max_term_months' => $term,
            'status' => $status
        ];
    }

    private static function money_to_cents(string $amount)
    {
        if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $amount)) {
            return false;
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0', STR_PAD_LEFT);
    }

    private static function format_cents(int $amount)
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
