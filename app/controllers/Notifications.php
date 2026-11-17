<?php
// This file loads the notifications page.

class Notifications extends Controller {
    // This method sets up this controller and its database tools.
    public function __construct() {
        parent::__construct();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    // This method loads the main information for this page or API.
    public function index() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_login();

        $db = $this->call->database();
        $query = $db->table('notifications');
        if ($user['role'] === 'member') {
            $query->where('user_id', (int) $user['id']);
        }
        $notifications = $query->order_by('created_at', 'DESC')->get_all();

        $this->call->view('notifications', [
            'user' => $user,
            'age' => $user['age'],
            'under_18' => $user['under_18'],
            'notifications' => $notifications,
            'page_title' => 'Notifications'
        ]);
    }
}
