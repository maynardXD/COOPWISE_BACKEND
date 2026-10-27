<?php
class Members extends Controller {
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
        $members = $db->table('members m')
            ->select('m.id, m.member_number, m.first_name, m.middle_name, m.last_name, m.email, m.contact_number, m.birth_date, m.status, u.role')
            ->left_join('users u', 'u.id = m.user_id')
            ->order_by('m.id', 'ASC')
            ->get_all();

        $this->call->view('members', [
            'members' => $members,
            'user' => $user,
            'page_title' => 'Members'
        ]);
    }

    public function profile() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_login();

        if ($user['role'] !== 'member') {
            $this->coopwise_access->require_staff();
            $member_id = filter_input(INPUT_GET, 'member_id', FILTER_VALIDATE_INT);
            $member = $member_id
                ? $this->Member_model->find($member_id)
                : null;
        } else {
            $member = $user['member'];
        }

        $this->call->view('member_profile', [
            'user' => $user,
            'member' => $member,
            'age' => $user['age'],
            'page_title' => 'Member Profile'
        ]);
    }
}
