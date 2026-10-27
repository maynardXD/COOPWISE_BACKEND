<?php
class Payments extends Controller {
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
        $query = $db->table('loan_payments lp')
            ->select('lp.id, lp.loan_id, lp.member_id, lp.amount_paid, lp.payment_method, lp.reference_no, lp.payment_date, m.first_name, m.last_name')
            ->inner_join('members m', 'm.id = lp.member_id');
        if ($user['role'] === 'member') {
            $query->where('lp.member_id', (int) ($user['member']['id'] ?? 0));
        }
        $payments = $query->order_by('lp.payment_date', 'DESC')->get_all();
        foreach ($payments as &$payment) {
            $payment['member_name'] = trim($payment['first_name'] . ' ' . $payment['last_name']);
        }
        unset($payment);
        $active_loans = [];
        if (in_array($user['role'], ['admin', 'officer'], true)) {
            $active_loans = $db->table('loans l')
                ->select('l.id, l.loan_code, l.current_balance, m.member_number, m.first_name, m.last_name')
                ->inner_join('members m', 'm.id = l.member_id')
                ->in('l.status', ['approved', 'active'])
                ->order_by('m.last_name', 'ASC')
                ->get_all();
        }

        $this->call->view('payments', [
            'user' => $user,
            'age' => $user['age'],
            'under_18' => $user['under_18'],
            'can_record' => in_array($user['role'], ['admin', 'officer'], true),
            'active_loans' => $active_loans,
            'payments' => $payments,
            'page_title' => 'Payments'
        ]);
    }

    public function save() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_staff();

        $db = $this->call->database();
        $loan_id = filter_input(INPUT_POST, 'loan_id', FILTER_VALIDATE_INT);
        $amount_input = trim((string) ($_POST['amount'] ?? ''));
        $amount_cents = self::money_to_cents($amount_input);
        $method = trim((string) ($_POST['method'] ?? ''));

        if (!$loan_id || $amount_cents === false || $amount_cents < 1 || !in_array($method, ['cash', 'bank', 'gcash', 'check'], true)) {
            $_SESSION['payment_error'] = 'Enter a valid loan, positive amount, and payment method.';
            header('Location: ' . BASE_URL . 'payments');
            exit;
        }

        $db->transaction();
        try {
            $loan = $db->table('loans')->where('id', $loan_id)->get();
            $balance_cents = $loan ? self::money_to_cents((string) $loan['current_balance']) : false;
            if (!$loan || !in_array($loan['status'], ['approved', 'active'], true) || $balance_cents === false || $amount_cents > $balance_cents) {
                throw new DomainException('The loan was not found, is not active, or the payment exceeds its remaining balance.');
            }

            $db->table('loan_payments')->insert([
                'loan_id' => $loan_id,
                'member_id' => (int) $loan['member_id'],
                'paid_by_user_id' => (int) $user['id'],
                'amount_paid' => self::format_cents($amount_cents),
                'payment_method' => $method,
                'reference_no' => 'PM-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'payment_date' => date('Y-m-d H:i:s')
            ]);

            $remaining_cents = $amount_cents;
            $schedules = $db->table('loan_schedules')
                ->where('loan_id', $loan_id)
                ->in('status', ['pending', 'overdue'])
                ->order_by('due_date', 'ASC')
                ->get_all();
            foreach ($schedules as $schedule) {
                if ($remaining_cents <= 0) {
                    break;
                }
                $unpaid_cents = max(0, self::money_to_cents((string) $schedule['total_due']) - self::money_to_cents((string) $schedule['paid_amount']));
                $applied_cents = min($remaining_cents, $unpaid_cents);
                $paid_cents = self::money_to_cents((string) $schedule['paid_amount']) + $applied_cents;
                $db->table('loan_schedules')->where('id', (int) $schedule['id'])->update([
                    'paid_amount' => self::format_cents($paid_cents),
                    'status' => $paid_cents >= self::money_to_cents((string) $schedule['total_due']) ? 'paid' : $schedule['status']
                ]);
                $remaining_cents -= $applied_cents;
            }

            $new_balance_cents = $balance_cents - $amount_cents;
            $db->table('loans')->where('id', $loan_id)->update([
                'current_balance' => self::format_cents($new_balance_cents),
                'total_paid' => self::format_cents(self::money_to_cents((string) $loan['total_paid']) + $amount_cents),
                'status' => $new_balance_cents === 0 ? 'completed' : 'active'
            ]);
            $db->commit();
        } catch (DomainException $error) {
            $db->roll_back();
            $_SESSION['payment_error'] = $error->getMessage();
            header('Location: ' . BASE_URL . 'payments');
            exit;
        } catch (Throwable $error) {
            $db->roll_back();
            error_log('CoopWise payment transaction failed: ' . $error->getMessage());
            throw $error;
        }

        $_SESSION['payment_success'] = 'Payment recorded successfully.';

        header('Location: ' . BASE_URL . 'payments');
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
