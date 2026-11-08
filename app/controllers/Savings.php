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
        $this->call->view('savings', [
            'user' => $user,
            'age' => $user['age'],
            'under_18' => $user['under_18'],
            'transactions' => $transactions,
            'page_title' => 'Savings'
        ]);
    }

}
