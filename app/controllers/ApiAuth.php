<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiAuth extends Controller
{
    private $api;

    public function __construct()
    {
        parent::__construct();
        $this->api = $this->call->library('api');
        $this->call->model('User_model');
    }

    public function login()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 300);
        $data = $this->api->body();
        $login = trim((string) ($data['login'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($login === '' || $password === '') {
            $this->api->respond([
                'message' => 'Enter your phone number or email and password.',
                'errors' => ['login' => 'Phone number or email and password are required.']
            ], 422);
        }

        $user = $this->User_model->find_for_login($login);
        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password'])) {
            $this->api->respond(['message' => 'Invalid phone number/email or password.'], 401);
        }

        $this->api->respond([
            'message' => 'Signed in successfully.',
            'access_token' => $this->api->encode_jwt([
                'sub' => (int) $user['id'],
                'role' => $user['role']
            ]),
            'token_type' => 'Bearer',
            'expires_in' => (int) config_item('payload_token_expiration'),
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'role' => $user['role']
            ]
        ]);
    }

    public function register()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('register:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 3600);
        $data = $this->api->body();
        $errors = $this->User_model->validate_registration($data);

        if ($errors) {
            $this->api->respond([
                'message' => 'Please correct the highlighted fields.',
                'errors' => $errors
            ], 422);
        }

        try {
            $this->User_model->save_registration($data);
        } catch (PDOException $error) {
            error_log('CoopWise registration database error: ' . $error->getMessage());
            $this->api->respond([
                'message' => 'Registration could not be completed. Please check whether the phone number or email is already in use.'
            ], $error->getCode() === '23000' ? 409 : 500);
        }

        $this->api->respond([
            'message' => 'Registration successful. You can now sign in.',
        ], 201);
    }

    public function me()
    {
        $this->api->require_method('GET');
        $claims = $this->api->require_jwt();
        $user = $this->User_model->find_identity((int) $claims['sub']);
        if (!$user || $user['status'] !== 'active') {
            $this->api->respond_error('Account is unavailable.', 401);
        }

        $profile = [
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'],
            'role' => $user['role']
        ];
        if ($user['role'] === 'member') {
            $this->call->model('Member_model');
            $member = $this->Member_model->find_for_user((int) $user['id']);
            if ($member) {
                $profile['member'] = [
                    'member_number' => $member['member_number'],
                    'first_name' => $member['first_name'],
                    'middle_name' => $member['middle_name'],
                    'last_name' => $member['last_name'],
                    'status' => $member['status']
                ];
            }
        }

        $this->api->respond(['user' => $profile]);
    }

}
