<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiMemberCards extends Controller
{
    private $api;
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->api = $this->call->library('api');
        $this->db = $this->call->database();
        $this->call->model('User_model');
        $this->call->model('Member_model');
    }

    public function issue($member_id = null)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if ($member_id === null) {
            if ($user['role'] !== 'member') {
                $this->api->respond_error('Choose a member to create an ID card.', 422);
            }
            $member = $this->member_profile($user);
        } else {
            $this->require_staff($user);
            $id = filter_var($member_id, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                $this->api->respond_error('Choose a valid member.', 422);
            }
            $member = $this->db->table('members')
                ->where('id', (int) $id)
                ->get();
            if (!$member) {
                $this->api->respond_error('Member not found.', 404);
            }
        }

        $secret = (string) config_item('jwt_secret');
        $id = (int) $member['id'];
        $encoded_id = rtrim(strtr(base64_encode((string) $id), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', 'coopwise-member-card:v1:' . $id, $secret);
        $this->api->respond([
            'member' => [
                'member_number' => $member['member_number'],
                'full_name' => trim(implode(' ', array_filter([
                    $member['first_name'],
                    $member['middle_name'] ?? null,
                    $member['last_name']
                ]))),
                'status' => $member['status']
            ],
            'verification_token' => 'v1.' . $encoded_id . '.' . $signature
        ]);
    }

    public function verify()
    {
        $this->api->require_method('GET');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $this->api->rate_limit('member-card-verify:' . $ip, 30, 60);
        $token = trim((string) ($_GET['token'] ?? ''));
        if (!preg_match('/^v1\.([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $token, $parts)) {
            $this->api->respond_error('This CoopWise member card could not be verified.', 404);
        }

        $decoded_id = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $member_id = $decoded_id !== false ? filter_var($decoded_id, FILTER_VALIDATE_INT) : false;
        if (!$member_id || $member_id < 1) {
            $this->api->respond_error('This CoopWise member card could not be verified.', 404);
        }

        $expected = hash_hmac('sha256', 'coopwise-member-card:v1:' . $member_id, (string) config_item('jwt_secret'));
        if (!hash_equals($expected, $parts[2])) {
            $this->api->respond_error('This CoopWise member card could not be verified.', 404);
        }

        $member = $this->db->table('members')
            ->where('id', (int) $member_id)
            ->get();
        if (!$member) {
            $this->api->respond_error('This CoopWise member card could not be verified.', 404);
        }

        $this->api->respond([
            'verified' => true,
            'member' => [
                'member_number' => $member['member_number'],
                'full_name' => trim(implode(' ', array_filter([
                    $member['first_name'],
                    $member['middle_name'] ?? null,
                    $member['last_name']
                ]))),
                'status' => $member['status']
            ],
            'verified_at' => date(DATE_ATOM)
        ]);
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

    private function member_profile(array $user)
    {
        $member = $this->Member_model->find_for_user((int) $user['id']);
        if (!$member || $member['status'] !== 'active') {
            $this->api->respond_error('An active member profile is required.', 403);
        }
        return $member;
    }

    private function require_staff(array $user)
    {
        if (!in_array($user['role'], ['admin', 'officer'], true)) {
            $this->api->respond_error('This action is available to cooperative staff only.', 403);
        }
    }
}
