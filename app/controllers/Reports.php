<?php
class Reports extends Controller {
    public function __construct() {
        parent::__construct();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function index() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_staff();

        $db = $this->call->database();
        $summary = [
            'members' => (int) $db->table('members')->count(),
            'savings' => (float) ($db->table('savings_transactions')->select_sum('amount', 'total')->where('type', 'deposit')->get()['total'] ?? 0)
                - (float) ($db->table('savings_transactions')->select_sum('amount', 'total')->where('type', 'withdrawal')->get()['total'] ?? 0),
            'loans' => (float) ($db->table('loans')->select_sum('current_balance', 'total')->get()['total'] ?? 0),
            'payments' => (float) ($db->table('loan_payments')->select_sum('amount_paid', 'total')->get()['total'] ?? 0),
        ];

        $this->call->view('reports', [
            'user' => $user,
            'age' => $user['age'],
            'under_18' => $user['under_18'],
            'summary' => $summary,
            'page_title' => 'Reports'
        ]);
    }
}
