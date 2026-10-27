<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiLoans extends Controller
{
    private $api;
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->api = $this->call->library('api');
        $this->db = $this->call->database();
        $this->call->model('User_model');
        $this->call->model('Member_model');
    }

    public function products()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $query = $this->db->table('loan_types')
            ->select('id, name, description, interest_rate, max_amount, max_term_months, status');
        if ($user['role'] === 'member') {
            $query->where('status', 'active')->where('max_amount', '>', 0);
        }
        $this->api->respond(['products' => $query->order_by('name', 'ASC')->get_all()]);
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

    public function loans()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $query = $this->db->table('loans l')
            ->select('l.id, l.member_id, l.loan_type_id, l.loan_code, l.principal_amount, l.approved_amount, l.current_balance, l.total_paid, l.term_months, l.interest_rate, l.purpose, l.status, l.created_at, lt.name AS loan_type_name, m.member_number, m.first_name, m.last_name')
            ->inner_join('loan_types lt', 'lt.id = l.loan_type_id')
            ->inner_join('members m', 'm.id = l.member_id');
        if ($user['role'] === 'member') {
            $member = $this->member_profile($user);
            $query->where('l.member_id', (int) $member['id']);
        }
        $this->api->respond(['loans' => $query->order_by('l.created_at', 'DESC')->get_all()]);
    }

    public function apply()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $member = $this->member_profile($user);
        $age = Coopwise_access::age_from_birth_date($member['birth_date'] ?? null);
        if ($age === null || $age < 18) {
            $this->api->respond_error('Loan applications are available to members aged 18 and over.', 403);
        }
        $data = $this->api->body();
        $product_id = filter_var($data['loan_type_id'] ?? null, FILTER_VALIDATE_INT);
        $principal = self::money_to_cents(trim((string) ($data['amount'] ?? '')));
        $term = filter_var($data['term_months'] ?? null, FILTER_VALIDATE_INT);
        $purpose = trim((string) ($data['purpose'] ?? ''));
        if (!$product_id || $principal === false || $principal < 1 || !$term || strlen($purpose) > 255) {
            $this->api->respond_error('Enter a valid product, positive amount, term, and purpose.', 422);
        }

        $product = $this->db->table('loan_types')
            ->where('id', $product_id)->where('status', 'active')->get();
        if (!$product || self::money_to_cents((string) $product['max_amount']) < 1
            || $principal > self::money_to_cents((string) $product['max_amount'])
            || $term > (int) $product['max_term_months']) {
            $this->api->respond_error('The requested amount or term exceeds this product’s limits.', 422);
        }
        if ((int) $this->db->table('loans')->where('member_id', (int) $member['id'])->where('status', 'pending')->count() > 0) {
            $this->api->respond_error('You already have an application waiting for review.', 409);
        }

        $id = $this->db->table('loans')->insert([
            'member_id' => (int) $member['id'],
            'loan_type_id' => $product_id,
            'loan_code' => 'CW-LN-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
            'principal_amount' => self::format_cents($principal),
            'approved_amount' => self::format_cents($principal),
            'interest_rate' => number_format((float) $product['interest_rate'], 2, '.', ''),
            'term_months' => $term,
            'purpose' => $purpose !== '' ? $purpose : null,
            'status' => 'pending',
            'current_balance' => '0.00',
            'total_paid' => '0.00'
        ]);
        if (!$id) {
            throw new RuntimeException('Loan application could not be saved.');
        }
        $this->api->respond(['message' => 'Loan application submitted for review.', 'id' => (int) $id], 201);
    }

    public function review($loan_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $loan_id = (int) $loan_id;
        $data = $this->api->body();
        $decision = $data['decision'] ?? '';
        if ($loan_id < 1 || !in_array($decision, ['approve', 'reject'], true)) {
            $this->api->respond_error('Provide a valid loan and approve/reject decision.', 422);
        }

        $this->db->transaction();
        try {
            $loan = $this->db->table('loans')->where('id', $loan_id)->get();
            if (!$loan || $loan['status'] !== 'pending') {
                throw new DomainException('This application has already been reviewed or does not exist.');
            }
            if ($decision === 'reject') {
                $this->db->table('loans')->where('id', $loan_id)->where('status', 'pending')->update([
                    'status' => 'rejected',
                    'approved_by_user_id' => (int) $user['id']
                ]);
                $this->db->commit();
                $this->api->respond(['message' => 'Application rejected.']);
            }

            $principal = self::money_to_cents((string) $loan['principal_amount']);
            $term = (int) $loan['term_months'];
            $rate_basis_points = (int) round((float) $loan['interest_rate'] * 100);
            $interest = (int) round($principal * $rate_basis_points * $term / 10000);
            $total = $principal + $interest;
            $updated = $this->db->table('loans')->where('id', $loan_id)->where('status', 'pending')->update([
                'status' => 'approved',
                'current_balance' => self::format_cents($total),
                'approved_by_user_id' => (int) $user['id']
            ]);
            if ($updated !== 1) {
                throw new DomainException('This application has already been reviewed.');
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
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise API loan review failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Application approved and monthly installments generated.']);
    }

    public function schedule($loan_id = null)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $loan_id = (int) $loan_id;
        $loan = $this->db->table('loans l')
            ->select('l.id, l.member_id, l.loan_code, l.principal_amount, l.approved_amount, l.current_balance, l.interest_rate, l.term_months, l.status, lt.name AS loan_type_name')
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
        $this->api->respond(['loan' => $loan, 'schedule' => $schedule]);
    }

    public function payments()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $query = $this->db->table('loan_payments lp')
            ->select('lp.id, lp.loan_id, lp.member_id, lp.amount_paid, lp.payment_method, lp.reference_no, lp.payment_date, l.loan_code, m.first_name, m.last_name')
            ->inner_join('loans l', 'l.id = lp.loan_id')
            ->inner_join('members m', 'm.id = lp.member_id');
        if ($user['role'] === 'member') {
            $query->where('lp.member_id', (int) $this->member_profile($user)['id']);
        }
        $this->api->respond(['payments' => $query->order_by('lp.payment_date', 'DESC')->get_all()]);
    }

    public function record_payment($loan_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $loan_id = (int) $loan_id;
        $data = $this->api->body();
        $amount = self::money_to_cents(trim((string) ($data['amount'] ?? '')));
        $method = trim((string) ($data['method'] ?? ''));
        if ($loan_id < 1 || $amount === false || $amount < 1 || !in_array($method, ['cash', 'bank', 'gcash', 'check'], true)) {
            $this->api->respond_error('Enter a positive amount and valid payment method.', 422);
        }

        $this->db->transaction();
        try {
            $loan = $this->db->table('loans')->where('id', $loan_id)->get();
            $balance = $loan ? self::money_to_cents((string) $loan['current_balance']) : false;
            if (!$loan || !in_array($loan['status'], ['approved', 'active'], true) || $balance === false || $amount > $balance) {
                throw new DomainException('Loan is not active or payment exceeds the remaining balance.');
            }
            $inserted = $this->db->table('loan_payments')->insert([
                'loan_id' => $loan_id,
                'member_id' => (int) $loan['member_id'],
                'paid_by_user_id' => (int) $user['id'],
                'amount_paid' => self::format_cents($amount),
                'payment_method' => $method,
                'reference_no' => 'PM-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'payment_date' => date('Y-m-d H:i:s')
            ]);
            if (!$inserted) {
                throw new RuntimeException('Payment could not be recorded.');
            }

            $remaining = $amount;
            $schedules = $this->db->table('loan_schedules')
                ->where('loan_id', $loan_id)
                ->in('status', ['pending', 'overdue'])
                ->order_by('due_date', 'ASC')
                ->get_all();
            foreach ($schedules as $schedule) {
                if ($remaining < 1) {
                    break;
                }
                $unpaid = max(0, self::money_to_cents((string) $schedule['total_due']) - self::money_to_cents((string) $schedule['paid_amount']));
                $applied = min($remaining, $unpaid);
                $paid = self::money_to_cents((string) $schedule['paid_amount']) + $applied;
                $this->db->table('loan_schedules')->where('id', (int) $schedule['id'])->update([
                    'paid_amount' => self::format_cents($paid),
                    'status' => $paid >= self::money_to_cents((string) $schedule['total_due']) ? 'paid' : $schedule['status']
                ]);
                $remaining -= $applied;
            }
            $new_balance = $balance - $amount;
            $this->db->table('loans')->where('id', $loan_id)->update([
                'current_balance' => self::format_cents($new_balance),
                'total_paid' => self::format_cents(self::money_to_cents((string) $loan['total_paid']) + $amount),
                'status' => $new_balance === 0 ? 'completed' : 'active'
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 422);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise API payment transaction failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Payment recorded.'], 201);
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

    private function validate_product(array $data)
    {
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $rate = trim((string) ($data['interest_rate'] ?? ''));
        $max_amount = self::money_to_cents(trim((string) ($data['max_amount'] ?? '')));
        $term = filter_var($data['max_term_months'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || strlen($name) > 100 || strlen($description) > 255
            || !preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/', $rate)
            || (float) $rate > 99.99 || $max_amount === false || $max_amount < 1
            || !$term || $term > 120) {
            $this->api->respond_error('Provide a name, monthly rate 0–99.99%, positive maximum amount, and term from 1–120 months.', 422);
        }
        $status = $data['status'] ?? 'active';
        if (!in_array($status, ['active', 'inactive'], true)) {
            $this->api->respond_error('Product status must be active or inactive.', 422);
        }
        return [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'interest_rate' => number_format((float) $rate, 2, '.', ''),
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
