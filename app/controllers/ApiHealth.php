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
                'auto_pay_runs'
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
