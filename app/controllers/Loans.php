<?php
class Loans extends Controller {
    public function __construct() {
        parent::__construct();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function index() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_adult_services();
        $db = $this->call->database();

        $query = $db->table('loans l')
            ->select('l.id, l.member_id, l.loan_type_id, l.loan_code, l.principal_amount, l.approved_amount, l.current_balance, l.total_paid, l.term_months, l.interest_rate, l.status, l.created_at, lt.name AS loan_type_name, m.first_name, m.last_name')
            ->inner_join('loan_types lt', 'lt.id = l.loan_type_id')
            ->inner_join('members m', 'm.id = l.member_id');
        if ($user['role'] === 'member') {
            $query->where('l.member_id', (int) ($user['member']['id'] ?? 0));
        }
        $loans = $query->order_by('l.created_at', 'DESC')->get_all();
        foreach ($loans as &$loan) {
            $loan['member_name'] = trim($loan['first_name'] . ' ' . $loan['last_name']);
        }
        unset($loan);

        $staff = in_array($user['role'], ['admin', 'officer'], true);
        $product_query = $db->table('loan_types')
            ->select('id, name, description, interest_rate, max_amount, max_term_months')
            ->where('status', 'active');
        if ($user['role'] === 'member') {
            $product_query->where('max_amount', '>', 0);
        }
        $products = $product_query->order_by('name', 'ASC')->get_all();
        $members = $staff ? $db->table('members')
            ->select('id, member_number, first_name, last_name')
            ->where('status', 'active')
            ->order_by('last_name', 'ASC')
            ->get_all() : [];
        $pending = [];
        if ($staff) {
            $pending = $db->table('loans l')
                ->select('l.id, l.loan_code, l.principal_amount, l.term_months, l.interest_rate, l.purpose, l.created_at, lt.name AS loan_type_name, m.member_number, m.first_name, m.last_name')
                ->inner_join('loan_types lt', 'lt.id = l.loan_type_id')
                ->inner_join('members m', 'm.id = l.member_id')
                ->where('l.status', 'pending')
                ->order_by('l.created_at', 'ASC')
                ->get_all();
        }

        $this->call->view('loans', [
            'user' => $user,
            'age' => $user['age'],
            'under_18' => $user['under_18'],
            'loans' => $loans,
            'products' => $products,
            'members' => $members,
            'pending' => $pending,
            'can_manage' => $staff,
            'can_apply' => $user['role'] === 'member' && !empty($user['member']),
            'error' => $_SESSION['loan_error'] ?? '',
            'success' => $_SESSION['loan_success'] ?? '',
            'page_title' => 'Loans'
        ]);
        unset($_SESSION['loan_error'], $_SESSION['loan_success']);
    }

    public function apply() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_adult_services();
        if ($user['role'] !== 'member' || empty($user['member'])) {
            http_response_code(403);
            $this->call->view('access_denied', [
                'page_title' => 'Access denied',
                'message' => 'Only an adult member with a member profile can apply for a loan.'
            ]);
            return;
        }

        $db = $this->call->database();
        $type_id = filter_input(INPUT_POST, 'loan_type_id', FILTER_VALIDATE_INT);
        $principal = self::money_to_cents(trim((string) ($_POST['amount'] ?? '')));
        $term = filter_input(INPUT_POST, 'term_months', FILTER_VALIDATE_INT);
        $purpose = trim((string) ($_POST['purpose'] ?? ''));

        if (!$type_id || $principal === false || $principal < 1 || !$term || strlen($purpose) > 255) {
            $this->redirect_with_loan_error('Enter a valid product, amount, term, and purpose.');
        }

        $product = $db->table('loan_types')
            ->where('id', $type_id)
            ->where('status', 'active')
            ->get();
        if (!$product || $principal > self::money_to_cents((string) $product['max_amount']) || $term < 1 || $term > (int) $product['max_term_months']) {
            $this->redirect_with_loan_error('The requested amount or term is outside this product’s limits.');
        }

        $member_id = (int) $user['member']['id'];
        $pending_count = $db->table('loans')
            ->where('member_id', $member_id)
            ->where('status', 'pending')
            ->count();
        if ((int) $pending_count > 0) {
            $this->redirect_with_loan_error('You already have an application waiting for review.');
        }

        $db->transaction();
        try {
            $inserted = $db->table('loans')->insert([
                'member_id' => $member_id,
                'loan_type_id' => $type_id,
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
            if (!$inserted) {
                throw new RuntimeException('Loan application could not be saved.');
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->roll_back();
            error_log('CoopWise loan application failed: ' . $error->getMessage());
            throw $error;
        }

        $_SESSION['loan_success'] = 'Your loan application was submitted for staff review.';
        header('Location: ' . BASE_URL . 'loans');
        exit;
    }

    public function create_product() {
        $this->call->library('coopwise_access');
        $this->coopwise_access->require_staff();
        $db = $this->call->database();

        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $rate_input = trim((string) ($_POST['interest_rate'] ?? ''));
        $max_amount = self::money_to_cents(trim((string) ($_POST['max_amount'] ?? '')));
        $term = filter_input(INPUT_POST, 'max_term_months', FILTER_VALIDATE_INT);
        if ($name === '' || strlen($name) > 100 || strlen($description) > 255
            || !preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/', $rate_input)
            || (float) $rate_input > 99.99 || $max_amount === false || $max_amount < 1
            || !$term || $term > 120) {
            $this->redirect_with_loan_error('Enter a product name, a monthly rate from 0% to 99.99%, a positive maximum amount, and a term up to 120 months.');
        }
        if ((int) $db->table('loan_types')->where('name', $name)->count() > 0) {
            $this->redirect_with_loan_error('A loan product already uses that name.');
        }

        $inserted = $db->table('loan_types')->insert([
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'interest_rate' => number_format((float) $rate_input, 2, '.', ''),
            'max_amount' => self::format_cents($max_amount),
            'max_term_months' => $term,
            'status' => 'active'
        ]);
        if (!$inserted) {
            $this->redirect_with_loan_error('The loan product could not be created. Check that its name is not already in use.');
        }

        $_SESSION['loan_success'] = 'Loan product created. Its monthly flat rate is shown to members before they apply.';
        header('Location: ' . BASE_URL . 'loans');
        exit;
    }

    public function update_product() {
        $this->call->library('coopwise_access');
        $this->coopwise_access->require_staff();
        $db = $this->call->database();
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $rate_input = trim((string) ($_POST['interest_rate'] ?? ''));
        $max_amount = self::money_to_cents(trim((string) ($_POST['max_amount'] ?? '')));
        $term = filter_input(INPUT_POST, 'max_term_months', FILTER_VALIDATE_INT);

        if (!$product_id || $name === '' || strlen($name) > 100 || strlen($description) > 255
            || !preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/', $rate_input)
            || (float) $rate_input > 99.99 || $max_amount === false || $max_amount < 1
            || !$term || $term > 120) {
            $this->redirect_with_loan_error('Enter a product name, a monthly rate from 0% to 99.99%, a positive maximum amount, and a term up to 120 months.');
        }
        if (!$db->table('loan_types')->where('id', $product_id)->get()) {
            $this->redirect_with_loan_error('The loan product no longer exists.');
        }
        if ((int) $db->table('loan_types')->where('name', $name)->where('id', '!=', $product_id)->count() > 0) {
            $this->redirect_with_loan_error('A different loan product already uses that name.');
        }

        $db->table('loan_types')->where('id', $product_id)->update([
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'interest_rate' => number_format((float) $rate_input, 2, '.', ''),
            'max_amount' => self::format_cents($max_amount),
            'max_term_months' => $term
        ]);
        $_SESSION['loan_success'] = 'Loan product settings updated.';
        header('Location: ' . BASE_URL . 'loans');
        exit;
    }

    public function review($loan_id = null) {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_staff();
        $loan_id = (int) $loan_id;
        $decision = trim((string) ($_POST['decision'] ?? ''));
        if ($loan_id < 1 || !in_array($decision, ['approve', 'reject'], true)) {
            $this->redirect_with_loan_error('Choose a valid application and review decision.');
        }

        $db = $this->call->database();
        $db->transaction();
        try {
            $loan = $db->table('loans')->where('id', $loan_id)->get();
            if (!$loan || $loan['status'] !== 'pending') {
                throw new DomainException('This application has already been reviewed or no longer exists.');
            }

            if ($decision === 'reject') {
                $updated = $db->table('loans')->where('id', $loan_id)->where('status', 'pending')->update([
                    'status' => 'rejected',
                    'approved_by_user_id' => (int) $user['id']
                ]);
                if ($updated !== 1) {
                    throw new DomainException('This application has already been reviewed.');
                }
            } else {
                $principal = self::money_to_cents((string) $loan['principal_amount']);
                $rate_basis_points = (int) round((float) $loan['interest_rate'] * 100);
                $term = (int) $loan['term_months'];
                $interest = (int) round($principal * $rate_basis_points * $term / 10000);
                $total = $principal + $interest;
                $principal_base = intdiv($principal, $term);
                $principal_remainder = $principal % $term;
                $interest_base = intdiv($interest, $term);
                $interest_remainder = $interest % $term;

                $updated = $db->table('loans')->where('id', $loan_id)->where('status', 'pending')->update([
                    'status' => 'approved',
                    'current_balance' => self::format_cents($total),
                    'approved_by_user_id' => (int) $user['id']
                ]);
                if ($updated !== 1) {
                    throw new DomainException('This application has already been reviewed.');
                }

                $approved_date = new DateTimeImmutable('today');
                $due_month = $approved_date->modify('first day of next month');
                $due_day = (int) $approved_date->format('j');
                for ($month = 0; $month < $term; $month++) {
                    $month_start = $due_month->modify('+' . $month . ' months');
                    $day = min($due_day, (int) $month_start->format('t'));
                    $principal_due = $principal_base + ($month < $principal_remainder ? 1 : 0);
                    $interest_due = $interest_base + ($month < $interest_remainder ? 1 : 0);
                    $inserted = $db->table('loan_schedules')->insert([
                        'loan_id' => $loan_id,
                        'due_date' => $month_start->setDate((int) $month_start->format('Y'), (int) $month_start->format('n'), $day)->format('Y-m-d'),
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
            }
            $db->commit();
        } catch (DomainException $error) {
            $db->roll_back();
            $this->redirect_with_loan_error($error->getMessage());
        } catch (Throwable $error) {
            $db->roll_back();
            error_log('CoopWise loan review failed: ' . $error->getMessage());
            throw $error;
        }

        $_SESSION['loan_success'] = $decision === 'approve'
            ? 'Application approved and monthly installments generated.'
            : 'Application rejected.';
        header('Location: ' . BASE_URL . 'loans');
        exit;
    }

    public function amortization($loan_id = null) {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_adult_services();

        $loan_id = (int) $loan_id;
        $db = $this->call->database();
        $loan = $db->table('loans l')
            ->select('l.id, l.member_id, l.loan_type_id, l.loan_code, l.approved_amount, l.interest_rate, l.term_months, l.current_balance, l.status, lt.name AS loan_type_name, m.first_name, m.last_name')
            ->inner_join('loan_types lt', 'lt.id = l.loan_type_id')
            ->inner_join('members m', 'm.id = l.member_id')
            ->where('l.id', $loan_id)
            ->get();

        if (!$loan) {
            http_response_code(404);
            $this->call->view('access_denied', [
                'page_title' => 'Loan not found',
                'message' => 'The requested loan schedule could not be found.'
            ]);
            return;
        }

        if ($user['role'] === 'member' && (int) $loan['member_id'] !== (int) ($user['member']['id'] ?? 0)) {
            http_response_code(403);
            $this->call->view('access_denied', [
                'page_title' => 'Access denied',
                'message' => 'You may only view your own loan schedule.'
            ]);
            return;
        }
        $loan['member_name'] = trim($loan['first_name'] . ' ' . $loan['last_name']);
        $schedule = $db->table('loan_schedules')
            ->where('loan_id', $loan_id)
            ->order_by('due_date', 'ASC')
            ->get_all();

        $this->call->view('loan_amortization', [
            'loan' => $loan,
            'schedule' => $schedule,
            'user' => $user,
            'age' => $user['age'],
            'under_18' => $user['under_18'],
            'page_title' => 'Amortization Schedule'
        ]);
    }

    private function redirect_with_loan_error($message) {
        $_SESSION['loan_error'] = $message;
        header('Location: ' . BASE_URL . 'loans');
        exit;
    }

    private static function money_to_cents(string $amount) {
        if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $amount)) {
            return false;
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private static function format_cents(int $amount) {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
