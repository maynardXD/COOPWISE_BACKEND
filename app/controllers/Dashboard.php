<?php
class Dashboard extends Controller {
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
        $user_id = (int) $_SESSION['user_id'];
        $role = $user['role'];
        $stats = [];
        $recent_notifications = [];

        if ($role === 'member') {
            $member = $user['member'];
            $member_id = $member['id'] ?? 0;

            $deposits = $db->table('savings_transactions')
                ->select_sum('amount', 'total')
                ->where('member_id', $member_id)
                ->where('type', 'deposit')
                ->get();
            $withdrawals = $db->table('savings_transactions')
                ->select_sum('amount', 'total')
                ->where('member_id', $member_id)
                ->where('type', 'withdrawal')
                ->get();

            $stats = [
                'savings_balance' => (float) ($deposits['total'] ?? 0) - (float) ($withdrawals['total'] ?? 0),
            ];

            if (!$user['under_18']) {
                $loans = $db->table('loans')
                    ->select_sum('current_balance', 'loan_balance')
                    ->select_sum('total_paid', 'total_paid')
                    ->where('member_id', $member_id)
                    ->in('status', ['approved', 'active'])
                    ->get();

                $pending_due = $db->table('loan_schedules ls')
                    ->select_sum('ls.total_due', 'pending_due')
                    ->inner_join('loans l', 'l.id = ls.loan_id')
                    ->where('l.member_id', $member_id)
                    ->where('ls.status', 'pending')
                    ->get();

                $stats['loan_balance'] = (float) ($loans['loan_balance'] ?? 0);
                $stats['total_paid'] = (float) ($loans['total_paid'] ?? 0);
                $stats['pending_due'] = (float) ($pending_due['pending_due'] ?? 0);
            }

            $recent_notifications = $db->table('notifications')
                ->where('user_id', $user_id)
                ->order_by('created_at', 'DESC')
                ->limit(3)
                ->get_all();
        } else {
            $member_count = $db->table('members')->count();
            $savers = $db->table('savings_transactions')
                ->select('member_id')
                ->where('type', 'deposit')
                ->group_by('member_id')
                ->get_all();
            $deposits = $db->table('savings_transactions')->select_sum('amount', 'total')->where('type', 'deposit')->get();
            $withdrawals = $db->table('savings_transactions')->select_sum('amount', 'total')->where('type', 'withdrawal')->get();
            $stats = [
                'total_members' => (int) $member_count,
                'total_savers' => count($savers),
                'total_savings' => (float) ($deposits['total'] ?? 0) - (float) ($withdrawals['total'] ?? 0),
                'overdue_payments' => (int) $db->table('loan_schedules')->where('status', 'overdue')->count(),
            ];

            $recent_notifications = $db->table('notifications')->order_by('created_at', 'DESC')->limit(3)->get_all();
        }

        $activity_query = $db->table('activity_logs')->order_by('created_at', 'DESC');
        if ($role === 'member') {
            $activity_query->where('user_id', $user_id);
        }
        $activity = $activity_query->limit(6)->get_all();
        $flash_error = $_SESSION['access_error'] ?? '';
        unset($_SESSION['access_error']);

        $this->call->view('dashboard', [
            'user' => $user,
            'stats' => $stats,
            'activity' => $activity,
            'notifications' => $recent_notifications,
            'role' => $role,
            'age' => $user['age'],
            'under_18' => $user['under_18'],
            'flash_error' => $flash_error,
            'page_title' => ($role === 'member' ? 'Member Dashboard' : 'Admin Dashboard')
        ]);
    }
}
