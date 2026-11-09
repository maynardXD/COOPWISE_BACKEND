<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiHealth extends Controller
{
    private $api;

    public function __construct()
    {
        parent::__construct();
        $this->api = $this->call->library('api');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $this->api->respond([
            'status' => 'ok',
            'service' => 'CoopWise API',
            'time' => gmdate('c')
        ]);
    }

    public function ready()
    {
        $this->api->require_method('GET');
        try {
            $db = $this->call->database();
            $db->raw('SELECT 1')->fetchColumn();
            $required_tables = [
                'users',
                'members',
                'savings_transactions',
                'payment_transactions',
                'payment_webhook_events',
                'refresh_tokens',
                'auto_pay_runs',
                'cooperative_policies',
                'cooperative_loan_fund',
                'cooperative_loan_fund_transactions',
                'cooperative_surplus_distributions',
                'cooperative_member_returns'
            ];
            $placeholders = implode(',', array_fill(0, count($required_tables), '?'));
            $tables = $db->raw(
                'SELECT table_name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name IN (' . $placeholders . ')',
                $required_tables
            )->fetchAll(PDO::FETCH_COLUMN);
            if (count($tables) !== count($required_tables)) {
                error_log('CoopWise readiness check failed: required database schema is incomplete.');
                $this->api->respond_error('The service is not ready because its database schema is incomplete.', 503);
            }
            $required_columns = [
                ['cooperative_policies', 'minimum_membership_days'],
                ['cooperative_policies', 'minimum_paid_up_share_capital'],
                ['cooperative_policies', 'minimum_savings_balance'],
                ['cooperative_policies', 'loan_to_paid_up_share_capital_multiplier'],
                ['cooperative_policies', 'cooperative_registration_date'],
                ['loan_payments', 'principal_paid'],
                ['loan_payments', 'interest_paid'],
                ['loan_payments', 'breakdown_recorded'],
                ['loan_schedules', 'principal_paid'],
                ['loan_schedules', 'interest_paid']
            ];
            $column_conditions = [];
            $column_parameters = [];
            foreach ($required_columns as [$table, $column]) {
                $column_conditions[] = '(table_name = ? AND column_name = ?)';
                $column_parameters[] = $table;
                $column_parameters[] = $column;
            }
            $columns = $db->raw(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND (' . implode(' OR ', $column_conditions) . ')',
                $column_parameters
            )->fetchColumn();
            if ((int) $columns !== count($required_columns)) {
                error_log('CoopWise readiness check failed: cooperative finance columns are incomplete.');
                $this->api->respond_error('The service is not ready because its cooperative finance schema is incomplete.', 503);
            }
        } catch (Throwable $error) {
            error_log('CoopWise readiness check failed: ' . $error->getMessage());
            $this->api->respond_error('The service is not ready because its database is unavailable.', 503);
        }

        $this->api->respond([
            'status' => 'ready',
            'service' => 'CoopWise API',
            'time' => gmdate('c')
        ]);
    }
}
