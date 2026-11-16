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
        $share_par_value = $this->db->raw(
            "SELECT par_value FROM share_capital_subscriptions
             WHERE status = 'active' ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $subscriptions = $this->db->raw(
            "SELECT s.id, s.member_id, s.subscription_number, s.subscribed_shares, s.par_value,
                    s.subscribed_amount, s.subscription_date, s.status, s.created_at,
                    m.member_number, m.first_name, m.last_name,
                    COALESCE(p.paid_up_amount, 0) AS paid_up_amount,
                    COALESCE((SELECT SUM(pt.amount) FROM payment_transactions pt
                              WHERE pt.subscription_id = s.id
                                AND pt.status IN ('pending', 'needs_review')), 0) AS pending_payment_amount
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

        $pagination = $this->api->pagination_params();
        $transactions = $this->db->raw(
            "SELECT t.id, t.member_id, t.subscription_id, t.transaction_type, t.amount,
                    t.balance_after, t.transaction_reference, t.payment_method,
                    t.reverses_transaction_id, t.description, t.created_at,
                   EXISTS(SELECT 1 FROM share_capital_transactions r
                          WHERE r.reverses_transaction_id = t.id) AS is_reversed,
                    s.subscription_number, m.member_number, m.first_name, m.last_name
             FROM share_capital_transactions t
             INNER JOIN share_capital_subscriptions s ON s.id = t.subscription_id
             INNER JOIN members m ON m.id = t.member_id
             " . ($member ? 'WHERE t.member_id = ?' : '') . "
             ORDER BY t.created_at DESC, t.id DESC
             LIMIT " . (int) ($pagination['per_page'] + 1) . ' OFFSET ' . (int) $pagination['offset'],
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
        $has_more = count($transactions) > $pagination['per_page'];
        if ($has_more) {
            array_pop($transactions);
        }

        $subscribed_cents = 0;
        $paid_up_cents = 0;
        foreach ($subscriptions as &$subscription) {
            $subscription['subscribed_amount'] = self::format_cents(self::money_to_cents((string) $subscription['subscribed_amount']));
            $subscription['par_value'] = self::format_cents(self::money_to_cents((string) $subscription['par_value']));
            $subscription['paid_up_amount'] = self::format_cents(self::money_to_cents((string) $subscription['paid_up_amount']));
            $subscription['pending_payment_amount'] = self::format_cents(
                self::money_to_cents((string) $subscription['pending_payment_amount'])
            );
            $unpaid_cents = $subscription['status'] === 'cancelled'
                ? 0
                : self::money_to_cents($subscription['subscribed_amount']) - self::money_to_cents($subscription['paid_up_amount']);
            $subscription['unpaid_amount'] = self::format_cents($unpaid_cents);
            if ($subscription['status'] !== 'cancelled') {
                $subscribed_cents += self::money_to_cents($subscription['subscribed_amount']);
                $paid_up_cents += self::money_to_cents($subscription['paid_up_amount']);
            }
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
            'pagination' => [
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'has_more' => $has_more
            ],
            'members' => $members,
            'share_par_value' => $share_par_value
                ? self::format_cents(self::money_to_cents((string) $share_par_value['par_value']))
                : null,
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

    public function member_contribution()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        if ($user['role'] !== 'member') {
            $this->api->respond_error('Share capital contributions from this form are available to member accounts only.', 403);
        }
        $member = $this->member_profile($user);
        $this->require_schema();

        $data = $this->api->body();
        $amount = self::money_to_cents(trim((string) ($data['amount'] ?? '')));
        if ($amount === false || $amount < 1 || $amount > 999999999999) {
            $this->api->respond_error('Enter a contribution amount from PHP 0.01 to PHP 9,999,999,999.99.', 422);
        }

        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM cooperative_policies WHERE id = 1 FOR UPDATE')->fetch();
            $par_value = $this->db->raw(
                "SELECT par_value FROM share_capital_subscriptions
                 WHERE status = 'active' ORDER BY id DESC LIMIT 1 FOR UPDATE"
            )->fetch(PDO::FETCH_ASSOC);
            if (!$par_value) {
                $this->db->roll_back();
                $this->api->respond_error(
                    'Share-capital terms are not configured. Ask cooperative staff to record an approved share subscription first.',
                    409
                );
            }
            $par_cents = self::money_to_cents((string) $par_value['par_value']);
            if ($par_cents === false || $par_cents < 1) {
                throw new RuntimeException('The cooperative share par value is invalid.');
            }
            $shares = intdiv($amount + $par_cents - 1, $par_cents);
            $subscribed_cents = $shares * $par_cents;
            if ($shares > 999999999 || $subscribed_cents > 999999999999) {
                $this->db->roll_back();
                $this->api->respond_error('The requested share-capital contribution exceeds the supported subscription limit.', 422);
            }
            $this->db->raw('SELECT id FROM members WHERE id = ? FOR UPDATE', [(int) $member['id']])->fetch();
            $subscription_id = $this->db->table('share_capital_subscriptions')->insert([
                'member_id' => (int) $member['id'],
                'subscription_number' => 'CW-SHR-MEMBER-' . bin2hex(random_bytes(10)),
                'subscribed_shares' => $shares,
                'par_value' => self::format_cents($par_cents),
                'subscribed_amount' => self::format_cents($subscribed_cents),
                'subscription_date' => date('Y-m-d'),
                'status' => 'active',
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$subscription_id) {
                throw new RuntimeException('The share-capital contribution subscription could not be created.');
            }
            $this->write_activity_log((int) $user['id'], 'share_capital', 'member_contribution_started', [
                'member_id' => (int) $member['id'],
                'subscription_id' => (int) $subscription_id,
                'amount' => self::format_cents($amount)
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise member share-capital contribution setup failed: ' . $error->getMessage());
            throw $error;
        }

        $this->api->respond([
            'message' => 'Share-capital subscription created. Continue to payment to complete the contribution.',
            'subscription_id' => (int) $subscription_id,
            'payment_amount' => self::format_cents($amount),
            'subscribed_amount' => self::format_cents($subscribed_cents),
            'unpaid_amount' => self::format_cents($subscribed_cents - $amount)
        ], 201);
    }

    public function cancel_subscription($subscription_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_schema();
        $subscription_id = filter_var($subscription_id, FILTER_VALIDATE_INT);
        if (!$subscription_id || $subscription_id < 1) {
            $this->api->respond_error('Choose a valid share subscription to cancel.', 422);
        }
        $member = $user['role'] === 'member' ? $this->member_profile($user) : null;
        if (!$member) {
            $this->require_staff($user);
        }

        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM cooperative_policies WHERE id = 1 FOR UPDATE')->fetch();
            $subscription = $this->db->raw(
                'SELECT id, member_id, subscription_number, status
                 FROM share_capital_subscriptions WHERE id = ? FOR UPDATE',
                [(int) $subscription_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$subscription || ($member && (int) $subscription['member_id'] !== (int) $member['id'])) {
                throw new DomainException('The share subscription was not found for this account.');
            }
            if ($subscription['status'] !== 'active') {
                throw new DomainException('Only an active unpaid share subscription can be cancelled.');
            }
            if ($this->net_paid_cents((int) $subscription_id) > 0) {
                throw new DomainException('A subscription with a paid contribution cannot be cancelled.');
            }
            $pending = $this->db->raw(
                "SELECT COUNT(*) AS total FROM payment_transactions
                 WHERE subscription_id = ? AND status IN ('pending', 'needs_review')",
                [(int) $subscription_id]
            )->fetch(PDO::FETCH_ASSOC);
            if ((int) ($pending['total'] ?? 0) > 0) {
                throw new DomainException('Cancel or resolve the pending payment before cancelling this subscription.');
            }
            $updated = $this->db->table('share_capital_subscriptions')
                ->where('id', (int) $subscription_id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);
            if ($updated !== 1) {
                throw new DomainException('The share subscription could not be cancelled.');
            }
            $this->write_activity_log((int) $user['id'], 'share_capital', 'unpaid_subscription_cancelled', [
                'subscription_id' => (int) $subscription_id,
                'subscription_number' => $subscription['subscription_number'],
                'member_id' => (int) $subscription['member_id']
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise share-capital subscription cancellation failed: ' . $error->getMessage());
            throw $error;
        }

        $this->api->respond(['message' => 'Unpaid share subscription cancelled.']);
    }

    public function admin_investment()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        if ($user['role'] !== 'admin') {
            $this->api->respond_error('This investment option is available to the admin account only.', 403);
        }
        $this->require_schema();

        $data = $this->api->body();
        $shares = filter_var($data['subscribed_shares'] ?? null, FILTER_VALIDATE_INT);
        $par_value = self::money_to_cents(trim((string) ($data['par_value'] ?? '')));
        $payment_method = trim((string) ($data['payment_method'] ?? 'cash'));
        if (!$shares || $shares < 1 || $shares > 999999999 || $par_value === false || $par_value < 1
            || !in_array($payment_method, ['simulation', 'cash', 'bank', 'gcash', 'check'], true)) {
            $this->api->respond_error('Enter a positive share count and par value, and choose a supported payment method.', 422);
        }
        if ($par_value > intdiv(999999999999, $shares)) {
            $this->api->respond_error('The investment amount is above the supported maximum.', 422);
        }

        $amount = $shares * $par_value;
        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM cooperative_policies WHERE id = 1 FOR UPDATE')->fetch();
            $locked_user = $this->db->raw(
                'SELECT id, email, full_name FROM users WHERE id = ? AND role = ? AND status = ? FOR UPDATE',
                [(int) $user['id'], 'admin', 'active']
            )->fetch(PDO::FETCH_ASSOC);
            if (!$locked_user) {
                throw new DomainException('The active admin account could not be verified.');
            }

            $member = $this->db->raw(
                'SELECT id, status FROM members WHERE user_id = ? FOR UPDATE',
                [(int) $locked_user['id']]
            )->fetch(PDO::FETCH_ASSOC);
            if ($member && $member['status'] !== 'active') {
                throw new DomainException('The admin account has an inactive member profile and cannot invest.');
            }
            if (!$member) {
                $name_parts = preg_split('/\s+/', trim((string) $locked_user['full_name']), -1, PREG_SPLIT_NO_EMPTY);
                $first_name = array_shift($name_parts) ?: 'Admin';
                $last_name = $name_parts ? implode(' ', $name_parts) : $first_name;
                $member_id = $this->db->table('members')->insert([
                    'user_id' => (int) $locked_user['id'],
                    'member_number' => 'STAFF-' . str_pad((string) $locked_user['id'], 10, '0', STR_PAD_LEFT),
                    'first_name' => substr($first_name, 0, 100),
                    'last_name' => substr($last_name, 0, 100),
                    'email' => $locked_user['email'],
                    'status' => 'active'
                ]);
                if (!$member_id) {
                    throw new RuntimeException('An investor profile could not be created for the admin account.');
                }
                $member = ['id' => (int) $member_id, 'status' => 'active'];
            }

            $subscription_id = $this->db->table('share_capital_subscriptions')->insert([
                'member_id' => (int) $member['id'],
                'subscription_number' => 'CW-SHR-ADMIN-' . bin2hex(random_bytes(10)),
                'subscribed_shares' => (int) $shares,
                'par_value' => self::format_cents($par_value),
                'subscribed_amount' => self::format_cents($amount),
                'subscription_date' => date('Y-m-d'),
                'status' => 'active',
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$subscription_id) {
                throw new RuntimeException('The admin share-capital subscription could not be saved.');
            }
            $reference = 'CW-ADMIN-SHR-' . strtoupper(bin2hex(random_bytes(10)));
            $transaction_id = $this->db->table('share_capital_transactions')->insert([
                'member_id' => (int) $member['id'],
                'subscription_id' => (int) $subscription_id,
                'transaction_type' => 'payment',
                'amount' => self::format_cents($amount),
                'balance_after' => self::format_cents($amount),
                'transaction_reference' => $reference,
                'payment_method' => $payment_method,
                'description' => 'Admin account share-capital investment recorded by the admin.',
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$transaction_id) {
                throw new RuntimeException('The admin share-capital payment could not be recorded.');
            }
            $this->write_activity_log((int) $user['id'], 'share_capital', 'admin_investment_recorded', [
                'member_id' => (int) $member['id'],
                'subscription_id' => (int) $subscription_id,
                'transaction_id' => (int) $transaction_id,
                'shares' => (int) $shares,
                'amount' => self::format_cents($amount),
                'payment_method' => $payment_method,
                'reference' => $reference
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise admin share-capital investment failed: ' . $error->getMessage());
            throw $error;
        }

        $this->api->respond([
            'message' => 'The admin account share-capital subscription and payment were recorded. This account remains limited to its admin role.',
            'subscription_id' => (int) $subscription_id,
            'transaction_id' => (int) $transaction_id,
            'amount' => self::format_cents($amount),
            'reference' => $reference
        ], 201);
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
            $this->db->raw('SELECT id FROM cooperative_policies WHERE id = 1 FOR UPDATE')->fetch();
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
