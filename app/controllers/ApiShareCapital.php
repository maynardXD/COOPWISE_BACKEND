<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiShareCapital extends Controller
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

    public function index()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $this->require_schema();

        $member = $user['role'] === 'member' ? $this->member_profile($user) : null;
        if (!$member) {
            $this->require_staff($user);
        }

        $where = $member ? 'WHERE s.member_id = ?' : '';
        $params = $member ? [(int) $member['id']] : [];
        $subscriptions = $this->db->raw(
            "SELECT s.id, s.member_id, s.subscription_number, s.subscribed_shares, s.par_value,
                    s.subscribed_amount, s.subscription_date, s.status, s.created_at,
                    m.member_number, m.first_name, m.last_name,
                    COALESCE(p.paid_up_amount, 0) AS paid_up_amount
             FROM share_capital_subscriptions s
             INNER JOIN members m ON m.id = s.member_id
             LEFT JOIN (
                 SELECT subscription_id,
                        SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END) AS paid_up_amount
                 FROM share_capital_transactions
                 GROUP BY subscription_id
             ) p ON p.subscription_id = s.id
             " . $where . "
             ORDER BY s.created_at DESC, s.id DESC",
            $params
        )->fetchAll(PDO::FETCH_ASSOC);

        $transactions = $this->db->raw(
            "SELECT t.id, t.member_id, t.subscription_id, t.transaction_type, t.amount,
                    t.balance_after, t.transaction_reference, t.payment_method,
                    t.reverses_transaction_id, t.description, t.created_at,
                    s.subscription_number, m.member_number, m.first_name, m.last_name
             FROM share_capital_transactions t
             INNER JOIN share_capital_subscriptions s ON s.id = t.subscription_id
             INNER JOIN members m ON m.id = t.member_id
             " . ($member ? 'WHERE t.member_id = ?' : '') . "
             ORDER BY t.created_at DESC, t.id DESC",
            $params
        )->fetchAll(PDO::FETCH_ASSOC);

        $subscribed_cents = 0;
        $paid_up_cents = 0;
        foreach ($subscriptions as &$subscription) {
            $subscription['subscribed_amount'] = self::format_cents(self::money_to_cents((string) $subscription['subscribed_amount']));
            $subscription['par_value'] = self::format_cents(self::money_to_cents((string) $subscription['par_value']));
            $subscription['paid_up_amount'] = self::format_cents(self::money_to_cents((string) $subscription['paid_up_amount']));
            $subscription['unpaid_amount'] = self::format_cents(
                self::money_to_cents($subscription['subscribed_amount']) - self::money_to_cents($subscription['paid_up_amount'])
            );
            $subscribed_cents += self::money_to_cents($subscription['subscribed_amount']);
            $paid_up_cents += self::money_to_cents($subscription['paid_up_amount']);
        }
        unset($subscription);

        foreach ($transactions as &$transaction) {
            $transaction['amount'] = self::format_cents(self::money_to_cents((string) $transaction['amount']));
            $transaction['balance_after'] = self::format_cents(self::money_to_cents((string) $transaction['balance_after']));
        }
        unset($transaction);

        $members = $user['role'] === 'member' ? [] : $this->db->table('members')
            ->select('id, member_number, first_name, last_name')
            ->where('status', 'active')
            ->order_by('last_name', 'ASC')
            ->get_all();

        $this->api->respond([
            'subscriptions' => $subscriptions,
            'transactions' => $transactions,
            'members' => $members,
            'summary' => [
                'subscribed_amount' => self::format_cents($subscribed_cents),
                'paid_up_amount' => self::format_cents($paid_up_cents),
                'unpaid_amount' => self::format_cents($subscribed_cents - $paid_up_cents)
            ]
        ]);
    }

    public function create_subscription()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_schema();

        $data = $this->api->body();
        $member_id = filter_var($data['member_id'] ?? null, FILTER_VALIDATE_INT);
        $shares = filter_var($data['subscribed_shares'] ?? null, FILTER_VALIDATE_INT);
        $par_value = self::money_to_cents(trim((string) ($data['par_value'] ?? '')));
        $date = trim((string) ($data['subscription_date'] ?? date('Y-m-d')));
        if (!$member_id || !$shares || $shares < 1 || $shares > 999999999
            || $par_value === false || $par_value < 1 || !self::valid_date($date) || $date > date('Y-m-d')) {
            $this->api->respond_error('Choose a member, a valid share count, a positive par value, and a valid subscription date.', 422);
        }
        if ($par_value > intdiv(999999999999, $shares)) {
            $this->api->respond_error('The subscription amount is above the supported maximum.', 422);
        }
        $total_cents = $shares * $par_value;

        $member = $this->db->table('members')
            ->where('id', (int) $member_id)
            ->where('status', 'active')
            ->get();
        if (!$member) {
            $this->api->respond_error('The selected member is not active.', 422);
        }

        $this->db->transaction();
        try {
            $id = $this->db->table('share_capital_subscriptions')->insert([
                'member_id' => (int) $member_id,
                'subscription_number' => 'CW-SHR-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'subscribed_shares' => (int) $shares,
                'par_value' => self::format_cents($par_value),
                'subscribed_amount' => self::format_cents($total_cents),
                'subscription_date' => $date,
                'status' => 'active',
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$id) {
                throw new RuntimeException('Share capital subscription could not be saved.');
            }
            $this->write_activity_log((int) $user['id'], 'share_capital', 'subscription_created', [
                'subscription_id' => (int) $id,
                'member_id' => (int) $member_id,
                'subscribed_shares' => (int) $shares
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $this->api->respond(['message' => 'Share capital subscription created.', 'id' => (int) $id], 201);
    }

    public function reverse_payment($transaction_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_schema();

        $id = filter_var($transaction_id, FILTER_VALIDATE_INT);
        $reason = trim((string) ($this->api->body()['reason'] ?? ''));
        if (!$id || $id < 1 || $reason === '' || strlen($reason) > 255) {
            $this->api->respond_error('Choose a payment and provide a correction reason of up to 255 characters.', 422);
        }

        $this->db->transaction();
        try {
            $payment = $this->db->raw(
                "SELECT id, member_id, subscription_id, amount
                 FROM share_capital_transactions
                 WHERE id = ? AND transaction_type = 'payment'
                 FOR UPDATE",
                [(int) $id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new DomainException('The share capital payment was not found.');
            }
            $subscription = $this->db->raw(
                'SELECT id, subscribed_amount
                 FROM share_capital_subscriptions
                 WHERE id = ?
                 FOR UPDATE',
                [(int) $payment['subscription_id']]
            )->fetch(PDO::FETCH_ASSOC);
            $already_reversed = (int) $this->db->table('share_capital_transactions')
                ->where('reverses_transaction_id', (int) $id)
                ->count();
            if (!$subscription || $already_reversed > 0) {
                throw new DomainException('This payment has already been reversed or its subscription is unavailable.');
            }

            $paid = $this->net_paid_cents((int) $subscription['id']);
            $amount = self::money_to_cents((string) $payment['amount']);
            if ($paid < $amount) {
                throw new DomainException('The payment cannot be reversed because its amount is no longer in the paid-up balance.');
            }
            $new_paid = $paid - $amount;
            $inserted = $this->db->table('share_capital_transactions')->insert([
                'member_id' => (int) $payment['member_id'],
                'subscription_id' => (int) $payment['subscription_id'],
                'transaction_type' => 'reversal',
                'amount' => self::format_cents($amount),
                'balance_after' => self::format_cents($new_paid),
                'transaction_reference' => 'CW-SHR-REV-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'payment_method' => null,
                'reverses_transaction_id' => (int) $id,
                'description' => $reason,
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$inserted) {
                throw new RuntimeException('The share capital reversal could not be saved.');
            }
            $this->db->table('share_capital_subscriptions')
                ->where('id', (int) $subscription['id'])
                ->update(['status' => 'active']);
            $this->write_activity_log((int) $user['id'], 'share_capital', 'payment_reversed', [
                'transaction_id' => (int) $id,
                'reversal_id' => (int) $inserted,
                'amount' => self::format_cents($amount)
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 422);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise share capital reversal failed: ' . $error->getMessage());
            throw $error;
        }

        $this->api->respond(['message' => 'Share capital payment reversed with an audit record.', 'id' => (int) $inserted], 201);
    }

    private function net_paid_cents(int $subscription_id)
    {
        $row = $this->db->raw(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0) AS paid
             FROM share_capital_transactions
             WHERE subscription_id = ?",
            [$subscription_id]
        )->fetch(PDO::FETCH_ASSOC);
        return self::money_to_cents((string) ($row['paid'] ?? '0.00'));
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
            throw new RuntimeException('The share capital audit record could not be saved.');
        }
    }

    private function require_schema()
    {
        $tables = $this->db->raw(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('share_capital_subscriptions', 'share_capital_transactions')"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) !== 2) {
            error_log('CoopWise share capital schema is missing; apply database/coopwise_share_capital_upgrade.sql.');
            $this->api->respond_error(
                'Share capital is not configured in the database yet. Apply database/coopwise_share_capital_upgrade.sql in Navicat, then retry.',
                503
            );
        }
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

    private static function valid_date(string $date)
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }
}
