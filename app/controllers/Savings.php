<?php
class Savings extends Controller {
    public function __construct() {
        parent::__construct();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function index() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_login();

        $db = $this->call->database();
        $query = $db->table('savings_transactions s')
            ->select('s.id, s.member_id, s.type, s.amount, s.balance_after, s.reference_no, s.description, s.created_at, m.member_number')
            ->inner_join('members m', 'm.id = s.member_id');
        if ($user['role'] === 'member') {
            $query->where('s.member_id', (int) ($user['member']['id'] ?? 0));
        }
        $transactions = $query->order_by('s.created_at', 'DESC')->get_all();
        $can_record = in_array($user['role'], ['admin', 'officer'], true);
        $members = [];
        if ($can_record) {
            $members = $db->table('members')
                ->select('id, member_number, first_name, last_name')
                ->where('status', 'active')
                ->order_by('last_name', 'ASC')
                ->get_all();
        }

        $this->call->view('savings', [
            'user' => $user,
            'age' => $user['age'],
            'under_18' => $user['under_18'],
            'transactions' => $transactions,
            'members' => $members,
            'can_record' => $can_record,
            'success' => $_SESSION['savings_success'] ?? '',
            'error' => $_SESSION['savings_error'] ?? '',
            'page_title' => 'Savings'
        ]);
        unset($_SESSION['savings_success'], $_SESSION['savings_error']);
    }

    public function save()
    {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_staff();
        $db = $this->call->database();

        $member_id = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
        $type = trim((string) ($_POST['type'] ?? ''));
        $amount_input = trim((string) ($_POST['amount'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $amount_cents = self::money_to_cents($amount_input);

        if (!$member_id || !in_array($type, ['deposit', 'withdrawal'], true) || $amount_cents === false || $amount_cents < 1 || strlen($description) > 255) {
            $_SESSION['savings_error'] = 'Choose a member, select a valid transaction type, and enter a positive amount with up to two decimal places.';
            header('Location: ' . BASE_URL . 'savings');
            exit;
        }

        $db->transaction();
        try {
            $member = $db->table('members')
                ->where('id', $member_id)
                ->where('status', 'active')
                ->get();
            if (!$member) {
                throw new DomainException('The selected member is not active.');
            }

            $deposits = $db->table('savings_transactions')
                ->select_sum('amount', 'total')
                ->where('member_id', $member_id)
                ->where('type', 'deposit')
                ->get();
            $withdrawals = $db->table('savings_transactions')
                ->select_sum('amount', 'total')
                ->where('member_id', $member_id)
                ->where('type', 'withdrawal')
                ->get();
            $balance_cents = self::money_to_cents((string) ($deposits['total'] ?? '0'))
                - self::money_to_cents((string) ($withdrawals['total'] ?? '0'));

            if ($type === 'withdrawal' && $amount_cents > $balance_cents) {
                throw new DomainException('The withdrawal amount cannot exceed the member’s available savings.');
            }

            $new_balance = $type === 'deposit'
                ? $balance_cents + $amount_cents
                : $balance_cents - $amount_cents;
            $inserted = $db->table('savings_transactions')->insert([
                'member_id' => $member_id,
                'type' => $type,
                'amount' => self::format_cents($amount_cents),
                'balance_after' => self::format_cents($new_balance),
                'reference_no' => 'CW-SAV-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'description' => $description !== '' ? $description : null,
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$inserted) {
                throw new RuntimeException('The savings transaction could not be saved.');
            }
            $db->commit();
        } catch (DomainException $error) {
            $db->roll_back();
            $_SESSION['savings_error'] = $error->getMessage();
            header('Location: ' . BASE_URL . 'savings');
            exit;
        } catch (Throwable $error) {
            $db->roll_back();
            error_log('CoopWise savings transaction failed: ' . $error->getMessage());
            throw $error;
        }

        $_SESSION['savings_success'] = ucfirst($type) . ' recorded successfully.';
        header('Location: ' . BASE_URL . 'savings');
        exit;
    }

    private static function money_to_cents(string $amount)
    {
        if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $amount)) {
            return false;
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private static function format_cents(int $amount)
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
