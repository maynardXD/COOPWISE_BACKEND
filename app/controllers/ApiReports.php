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
        $this->require_table('loan_readiness_submissions', 'database/coopwise_documents_payments_upgrade.sql');
        $this->require_finance_schema();

        $period_start = new DateTimeImmutable('first day of this month');
        $period_end = $period_start->modify('first day of next month');
        $start = $period_start->format('Y-m-d 00:00:00');
        $end = $period_end->format('Y-m-d 00:00:00');

        $savings_deposits = $this->sum('savings_transactions', 'amount', 'type', 'deposit');
        $savings_withdrawals = $this->sum('savings_transactions', 'amount', 'type', 'withdrawal');
        $loan_balance = $this->sum('loans', 'current_balance');
        $loan_payments = $this->sum('loan_payments', 'amount_paid');
        $loan_interest_received = $this->sum('loan_payments', 'interest_paid');
        $loan_principal_receivable = $this->db->raw(
            'SELECT COALESCE(SUM(GREATEST(principal_due - principal_paid, 0)), 0) AS total FROM loan_schedules'
        )->fetch(PDO::FETCH_ASSOC);
        $loan_fund = $this->db->raw(
            'SELECT available_balance FROM cooperative_loan_fund WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        $approved_member_returns = $this->db->raw(
            "SELECT COALESCE(SUM(r.total_return), 0) AS total
             FROM cooperative_member_returns r
             INNER JOIN cooperative_surplus_distributions d ON d.id = r.distribution_id
             WHERE d.status = 'approved'"
        )->fetch(PDO::FETCH_ASSOC);
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
        $monthly_loan_payment_split = $this->db->raw(
            'SELECT COALESCE(SUM(principal_paid), 0) AS principal,
                    COALESCE(SUM(interest_paid), 0) AS interest
             FROM loan_payments WHERE payment_date >= ? AND payment_date < ?',
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
        $activity_logs = $this->db->raw(
            'SELECT al.id, al.module, al.action, al.details, al.created_at,
                    u.full_name AS actor_name, u.role AS actor_role
             FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT 50'
        )->fetchAll(PDO::FETCH_ASSOC);
        $overdue_installments = $this->db->raw(
            "SELECT COUNT(*) AS count,
                    COALESCE(SUM(GREATEST(total_due - paid_amount, 0)), 0) AS outstanding
             FROM loan_schedules
             WHERE due_date < CURRENT_DATE AND status IN ('pending', 'overdue')"
        )->fetch(PDO::FETCH_ASSOC);
        $operations = [
            'loans_awaiting_review' => (int) $this->db->table('loans')->where('status', 'pending')->count(),
            'documents_awaiting_review' => (int) $this->db->table('loan_readiness_submissions')->where('status', 'pending')->count(),
            'active_paluwagan_cycles' => (int) $this->db->table('cooperative_cycles')->where('status', 'active')->count(),
            'payouts_awaiting_confirmation' => (int) $this->db->table('cooperative_payouts')->where('status', 'ready')->count(),
            'overdue_installment_count' => (int) ($overdue_installments['count'] ?? 0),
            'overdue_installment_balance' => self::format_cents(self::money_to_cents((string) ($overdue_installments['outstanding'] ?? '0.00')))
        ];

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
                'loan_principal_receivable' => number_format((float) ($loan_principal_receivable['total'] ?? 0), 2, '.', ''),
                'loan_fund_available' => number_format((float) ($loan_fund['available_balance'] ?? 0), 2, '.', ''),
                'loan_payments_received' => number_format((float) $loan_payments, 2, '.', ''),
                'loan_interest_received' => number_format((float) $loan_interest_received, 2, '.', ''),
                'approved_member_returns' => number_format((float) ($approved_member_returns['total'] ?? 0), 2, '.', '')
            ],
            'monthly' => [
                'new_members' => $monthly_members,
                'savings_deposits' => $savings_by_type['deposit'],
                'savings_withdrawals' => $savings_by_type['withdrawal'],
                'share_capital_payments' => self::format_cents(self::money_to_cents((string) ($monthly_share_capital_payments['total'] ?? '0.00'))),
                'loan_applications' => $applications_by_status,
                'loan_payments_received' => number_format((float) ($monthly_loan_payments['total'] ?? 0), 2, '.', ''),
                'loan_principal_received' => number_format((float) ($monthly_loan_payment_split['principal'] ?? 0), 2, '.', ''),
                'loan_interest_received' => number_format((float) ($monthly_loan_payment_split['interest'] ?? 0), 2, '.', '')
            ],
            'loan_statuses' => $loan_statuses,
            'activity_logs' => $activity_logs,
            'operations' => $operations
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

    private function require_table($table, $migration)
    {
        $exists = $this->db->raw(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->fetchColumn();
        if ((int) $exists !== 1) {
            error_log('CoopWise report schema is missing table ' . $table . '; apply ' . $migration . '.');
            $this->api->respond_error(
                'Operational reports are not configured in the database yet. Apply ' . $migration . ' in Navicat, then retry.',
                503
            );
        }
    }

    private function require_finance_schema()
    {
        $tables = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN (
                 'cooperative_loan_fund', 'cooperative_loan_fund_transactions',
                 'cooperative_surplus_distributions', 'cooperative_member_returns'
             )"
        )->fetchColumn();
        $columns = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND (
                 (table_name = 'cooperative_policies' AND column_name IN (
                     'minimum_membership_days', 'minimum_paid_up_share_capital',
                     'minimum_savings_balance', 'loan_to_paid_up_share_capital_multiplier',
                     'cooperative_registration_date'
                 )) OR
                 (table_name = 'loan_payments' AND column_name IN ('principal_paid', 'interest_paid', 'breakdown_recorded')) OR
                 (table_name = 'loan_schedules' AND column_name IN ('principal_paid', 'interest_paid'))
             )"
        )->fetchColumn();
        if ((int) $tables !== 4 || (int) $columns !== 10) {
            error_log('CoopWise cooperative finance schema is missing; apply database/coopwise_cooperative_finance_upgrade.sql.');
            $this->api->respond_error(
                'Cooperative finance reports are not configured. Back up the database, then run database/coopwise_cooperative_finance_upgrade.sql in Navicat.',
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
