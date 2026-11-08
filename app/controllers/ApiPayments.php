<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiPayments extends Controller
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

    public function members()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $term = trim((string) ($_GET['q'] ?? ''));
        if (strlen($term) > 100) {
            $this->api->respond_error('Search with no more than 100 characters.', 422);
        }

        $sql = "SELECT m.id, m.member_number, m.first_name, m.middle_name, m.last_name,
                       m.email, m.contact_number, m.address, m.birth_date, m.status,
                       COALESCE(u.role, 'unlinked') AS account_role
                FROM members m
                LEFT JOIN users u ON u.id = m.user_id";
        $params = [];
        if ($term !== '') {
            $escaped_term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $name_pattern = '%' . $escaped_term . '%';
            $prefix_pattern = $escaped_term . '%';
            $sql .= " WHERE CONCAT_WS(' ', m.first_name, m.middle_name, m.last_name) LIKE ?
                      OR m.member_number LIKE ? OR m.email LIKE ? OR m.contact_number LIKE ?";
            $params = [$name_pattern, $prefix_pattern, $prefix_pattern, $prefix_pattern];
        }
        $sql .= ' ORDER BY m.last_name ASC, m.first_name ASC LIMIT 100';
        $members = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $this->api->respond(['members' => $members]);
    }

    private function authenticated_user()
    {
        $claims = $this->api->require_jwt();
        $user = $this->User_model->find_identity((int) $claims['sub']);
        if (!$user || $user['status'] !== 'active') {
            $this->api->respond_error('Account is unavailable.', 401);
        }
        if (!in_array($user['role'], ['admin', 'officer', 'member'], true)) {
            $this->api->respond_error('This account does not have a supported application role.', 403);
        }
        return $user;
    }

    private function require_staff(array $user)
    {
        if (!in_array($user['role'], ['admin', 'officer'], true)) {
            $this->api->respond_error('This action is available to cooperative staff only.', 403);
        }
    }
}
