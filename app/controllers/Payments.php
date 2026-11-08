<?php
class Payments extends Controller
{
    public function __construct()
    {
        parent::__construct();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function index()
    {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_adult_services();

        $db = $this->call->database();
        $query = $db->table('loan_payments lp')
            ->select('lp.id, lp.loan_id, lp.member_id, lp.amount_paid, lp.payment_method, lp.reference_no, lp.payment_date, m.first_name, m.last_name')
            ->inner_join('members m', 'm.id = lp.member_id');
        if ($user['role'] === 'member') {
            $query->where('lp.member_id', (int) ($user['member']['id'] ?? 0));
        }
        $payments = $query->order_by('lp.payment_date', 'DESC')->get_all();
        foreach ($payments as &$payment) {
            $payment['member_name'] = trim($payment['first_name'] . ' ' . $payment['last_name']);
        }
        unset($payment);

        $this->call->view('payments', [
            'user' => $user,
            'age' => $user['age'],
            'under_18' => $user['under_18'],
            'payments' => $payments,
            'page_title' => 'Loan Payment History'
        ]);
    }
}
