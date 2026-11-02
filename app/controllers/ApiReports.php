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

        $period_start = new DateTimeImmutable('first day of this month');
        $period_end = $period_start->modify('first day of next month');
        $start = $period_start->format('Y-m-d 00:00:00');
        $end = $period_end->format('Y-m-d 00:00:00');

        $savings_deposits = $this->sum('savings_transactions', 'amount', 'type', 'deposit');
        $savings_withdrawals = $this->sum('savings_transactions', 'amount', 'type', 'withdrawal');
        $loan_balance = $this->sum('loans', 'current_balance');
        $loan_payments = $this->sum('loan_payments', 'amount_paid');
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
                'loan_balance' => number_format((float) $loan_balance, 2, '.', ''),
                'loan_payments_received' => number_format((float) $loan_payments, 2, '.', '')
            ],
            'monthly' => [
                'new_members' => $monthly_members,
                'savings_deposits' => $savings_by_type['deposit'],
                'savings_withdrawals' => $savings_by_type['withdrawal'],
                'loan_applications' => $applications_by_status,
                'loan_payments_received' => number_format((float) ($monthly_loan_payments['total'] ?? 0), 2, '.', '')
            ],
            'loan_statuses' => $loan_statuses,
            'pending_payment_submissions' => (int) $this->db->table('payment_submissions')->where('status', 'pending')->count()
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

    private function authenticated_user()
    {
        $claims = $this->api->require_jwt();
        $user = $this->User_model->find_identity((int) $claims['sub']);
        if (!$user || $user['status'] !== 'active') {
            $this->api->respond_error('Account is unavailable.', 401);
        }
        return $user;
    }
}
