<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiReports extends Controller
{
    private $api;
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->api = $this->call->library('api');
        $this->db = $this->call->database();
        $this->call->model('User_model');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if (!in_array($user['role'], ['admin', 'officer'], true)) {
            $this->api->respond_error('Reports are available to cooperative staff only.', 403);
        }
        $this->require_share_capital_schema();

        $period_start = new DateTimeImmutable('first day of this month');
        $period_end = $period_start->modify('first day of next month');
        $start = $period_start->format('Y-m-d 00:00:00');
        $end = $period_end->format('Y-m-d 00:00:00');

        $savings_deposits = $this->sum('savings_transactions', 'amount', 'type', 'deposit');
        $savings_withdrawals = $this->sum('savings_transactions', 'amount', 'type', 'withdrawal');
        $loan_balance = $this->sum('loans', 'current_balance');
        $loan_payments = $this->sum('loan_payments', 'amount_paid');
        $share_capital_subscribed = $this->sum('share_capital_subscriptions', 'subscribed_amount');
        $share_capital_paid = $this->db->raw(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0) AS total
             FROM share_capital_transactions"
        )->fetch(PDO::FETCH_ASSOC);
        $monthly_savings = $this->db->raw(
            'SELECT type, SUM(amount) AS total FROM savings_transactions WHERE created_at >= ? AND created_at < ? GROUP BY type',
            [$start, $end]
        )->fetchAll(PDO::FETCH_ASSOC);
        $monthly_loans = $this->db->raw(
            'SELECT status, COUNT(*) AS total FROM loans WHERE created_at >= ? AND created_at < ? GROUP BY status',
            [$start, $end]
        )->fetchAll(PDO::FETCH_ASSOC);
        $monthly_loan_payments = $this->db->raw(
            'SELECT COALESCE(SUM(amount_paid), 0) AS total FROM loan_payments WHERE payment_date >= ? AND payment_date < ?',
            [$start, $end]
        )->fetch(PDO::FETCH_ASSOC);
        $monthly_share_capital_payments = $this->db->raw(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0) AS total
             FROM share_capital_transactions WHERE created_at >= ? AND created_at < ?",
            [$start, $end]
        )->fetch(PDO::FETCH_ASSOC);
        $monthly_members = (int) $this->db->table('members')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
        $loan_statuses = $this->db->raw(
            'SELECT status, COUNT(*) AS total FROM loans GROUP BY status ORDER BY status ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $savings_by_type = ['deposit' => '0.00', 'withdrawal' => '0.00'];
        foreach ($monthly_savings as $row) {
            $savings_by_type[$row['type']] = number_format((float) $row['total'], 2, '.', '');
        }
        $applications_by_status = [];
        foreach ($monthly_loans as $row) {
            $applications_by_status[$row['status']] = (int) $row['total'];
        }
        $this->api->respond([
            'period' => $period_start->format('F Y'),
            'summary' => [
                'members' => (int) $this->db->table('members')->count(),
                'active_members' => (int) $this->db->table('members')->where('status', 'active')->count(),
                'savings_balance' => number_format((float) $savings_deposits - (float) $savings_withdrawals, 2, '.', ''),
                'share_capital_subscribed' => self::format_cents(self::money_to_cents((string) $share_capital_subscribed)),
                'share_capital_paid_up' => self::format_cents(self::money_to_cents((string) ($share_capital_paid['total'] ?? '0.00'))),
                'loan_balance' => number_format((float) $loan_balance, 2, '.', ''),
                'loan_payments_received' => number_format((float) $loan_payments, 2, '.', '')
            ],
            'monthly' => [
                'new_members' => $monthly_members,
                'savings_deposits' => $savings_by_type['deposit'],
                'savings_withdrawals' => $savings_by_type['withdrawal'],
                'share_capital_payments' => self::format_cents(self::money_to_cents((string) ($monthly_share_capital_payments['total'] ?? '0.00'))),
                'loan_applications' => $applications_by_status,
                'loan_payments_received' => number_format((float) ($monthly_loan_payments['total'] ?? 0), 2, '.', '')
            ],
            'loan_statuses' => $loan_statuses
        ]);
    }

    private function sum($table, $column, $filter_column = null, $filter_value = null)
    {
        $query = $this->db->table($table)->select_sum($column, 'total');
        if ($filter_column !== null) {
            $query->where($filter_column, $filter_value);
        }
        return $query->get()['total'] ?? '0';
    }

    private function require_share_capital_schema()
    {
        $tables = $this->db->raw(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('share_capital_subscriptions', 'share_capital_transactions')"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) !== 2) {
            error_log('CoopWise share capital schema is missing; apply database/coopwise_share_capital_upgrade.sql.');
            $this->api->respond_error(
                'Share capital reports are not configured in the database yet. Apply database/coopwise_share_capital_upgrade.sql in Navicat, then retry.',
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
        return $user;
    }

    private static function money_to_cents(string $amount)
    {
        if (!preg_match('/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/', $amount)) {
            throw new RuntimeException('A share capital report contains an invalid monetary value.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private static function format_cents(int $amount)
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
