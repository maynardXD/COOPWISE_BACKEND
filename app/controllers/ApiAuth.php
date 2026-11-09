<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiAuth extends Controller
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

    public function request_password_reset()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('password-reset-request:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 3, 60);
        $this->require_password_reset_schema();
        $sender_email = strtolower(trim((string) getenv('BREVO_SENDER_EMAIL')));
        $sender_name = trim((string) (getenv('BREVO_SENDER_NAME') ?: 'CoopWise'));
        if (trim((string) getenv('BREVO_API_KEY')) === '' || !filter_var($sender_email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('Password-reset email is not configured. Add the Brevo API key and verified sender settings to the backend environment.', 503);
        }

        $data = $this->api->body();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('Enter the email address linked to your CoopWise account.', 422);
        }
        $user = $this->db->table('users')
            ->select('id, email, full_name')
            ->where('email', $email)
            ->where('status', 'active')
            ->get();
        if (!$user) {
            $this->api->respond(['message' => 'If an active account uses that email, a password-reset code will be sent.']);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int) $user['id']])->fetch();
            $active_requests = (int) $this->db->raw(
                'SELECT COUNT(*) AS total FROM password_reset_otps
                 WHERE user_id = ? AND consumed_at IS NULL
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
                [(int) $user['id']]
            )->fetch(PDO::FETCH_ASSOC)['total'];
            if ($active_requests > 0) {
                $this->db->commit();
                $this->api->respond(['message' => 'If an active account uses that email, a password-reset code will be sent.']);
            }
            $this->db->raw(
                'UPDATE password_reset_otps SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL',
                [date('Y-m-d H:i:s'), (int) $user['id']]
            );
            $id = $this->db->table('password_reset_otps')->insert([
                'user_id' => (int) $user['id'],
                'email' => $email,
                'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
                'expires_at' => date('Y-m-d H:i:s', time() + 600)
            ]);
            if (!$id) {
                throw new RuntimeException('The password-reset code could not be stored.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise password reset request could not be stored: ' . $error->getMessage());
            $this->api->respond_error('A password-reset email could not be prepared. Please try again later.', 503);
        }

        try {
            $this->send_reset_email($email, $user['full_name'], $otp, $sender_email, $sender_name);
        } catch (Throwable $error) {
            $this->db->raw(
                'UPDATE password_reset_otps SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL',
                [date('Y-m-d H:i:s'), (int) $id]
            );
            $support_reference = bin2hex(random_bytes(6));
            error_log(
                'CoopWise password reset email delivery failed; reference=' . $support_reference
                . '; user_id=' . (int) $user['id'] . '; provider_error=' . $error->getMessage()
            );
            $this->api->respond_error(
                'The email provider did not accept the password-reset message. Wait a minute and try again. If it keeps failing, share support reference ' . $support_reference . '.',
                502
            );
        }
        $this->api->respond(['message' => 'If an active account uses that email, a password-reset code will be sent.']);
    }

    public function confirm_password_reset()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('password-reset-confirm:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 600);
        $this->require_password_reset_schema();
        $data = $this->api->body();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $otp = trim((string) ($data['otp'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirmation'] ?? '');
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !preg_match('/^\d{6}$/D', $otp)
            || !preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9\s])[^\s]{8,128}$/D', $password)
            || $password !== $confirmation) {
            $this->api->respond_error('Enter the email, six-digit code, and matching password that meets the password rules.', 422);
        }

        $user = $this->db->table('users')
            ->select('id')
            ->where('email', $email)
            ->where('status', 'active')
            ->get();
        if (!$user) {
            $this->api->respond_error('The code is invalid or expired. Request a new code and try again.', 422);
        }

        $this->db->transaction();
        try {
            $record = $this->db->raw(
                'SELECT id, otp_hash, attempts, expires_at
                 FROM password_reset_otps
                 WHERE user_id = ? AND email = ? AND consumed_at IS NULL
                 ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [(int) $user['id'], $email]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$record || $record['expires_at'] < date('Y-m-d H:i:s') || (int) $record['attempts'] >= 5) {
                if ($record) {
                    $this->db->table('password_reset_otps')
                        ->where('id', (int) $record['id'])
                        ->update(['consumed_at' => date('Y-m-d H:i:s')]);
                }
                $this->db->commit();
                $this->api->respond_error('The code is invalid or expired. Request a new code and try again.', 422);
            }
            if (!password_verify($otp, $record['otp_hash'])) {
                $attempts = (int) $record['attempts'] + 1;
                $this->db->table('password_reset_otps')
                    ->where('id', (int) $record['id'])
                    ->update([
                        'attempts' => $attempts,
                        'consumed_at' => $attempts >= 5 ? date('Y-m-d H:i:s') : null
                    ]);
                $this->db->commit();
                $this->api->respond_error('The code is invalid or expired. Request a new code and try again.', 422);
            }
            $updated = $this->db->table('users')
                ->where('id', (int) $user['id'])
                ->update(['password' => password_hash($password, PASSWORD_DEFAULT)]);
            if ($updated !== 1) {
                throw new RuntimeException('The account password could not be updated.');
            }
            $this->db->table('password_reset_otps')
                ->where('id', (int) $record['id'])
                ->update(['consumed_at' => date('Y-m-d H:i:s')]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise password reset confirmation failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Password changed. Sign in with your new password.']);
    }

    private function send_reset_email(string $email, string $full_name, string $otp, string $sender_email, string $sender_name)
    {
        $payload = json_encode([
            'sender' => ['name' => $sender_name, 'email' => $sender_email],
            'to' => [['email' => $email, 'name' => $full_name]],
            'subject' => 'Your CoopWise password-reset code',
            'textContent' => "Your CoopWise password-reset code is {$otp}. It expires in 10 minutes. If you did not request this, ignore this email."
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('The password-reset email content could not be encoded.');
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "api-key: " . getenv('BREVO_API_KEY') . "\r\nAccept: application/json\r\nContent-Type: application/json",
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true
        ], 'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]]);
        $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
        $status_line = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $status_line, $matches);
        $status = (int) ($matches[1] ?? 0);
        if (!is_string($response) || $status < 200 || $status >= 300) {
            $details = is_string($response) ? json_decode($response, true) : null;
            $provider_code = is_array($details) ? (string) ($details['code'] ?? '') : '';
            $provider_message = is_array($details) ? (string) ($details['message'] ?? '') : '';
            $provider_message = trim(preg_replace('/\s+/', ' ', strip_tags($provider_message)));
            if ($provider_message !== '') {
                $provider_message = str_ireplace([$email, $sender_email, $otp], '[redacted]', $provider_message);
                $provider_message = substr($provider_message, 0, 300);
            }
            if ($status === 0) {
                $transport_error = error_get_last();
                $provider_message = $transport_error['message'] ?? 'No HTTP response was received from Brevo.';
                $provider_message = substr(trim(preg_replace('/\s+/', ' ', strip_tags($provider_message))), 0, 300);
            }
            throw new RuntimeException(
                'Brevo HTTP ' . ($status ?: 'unknown')
                . ($provider_code !== '' ? ' (' . substr($provider_code, 0, 80) . ')' : '')
                . ($provider_message !== '' ? ': ' . $provider_message : '')
            );
        }
    }

    private function require_password_reset_schema()
    {
        $table = $this->db->raw(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'password_reset_otps'"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$table) {
            error_log('CoopWise password reset schema is missing; apply database/coopwise_xendit_email_upgrade.sql.');
            $this->api->respond_error(
                'Password reset is not configured in the database yet. Apply database/coopwise_xendit_email_upgrade.sql, then retry.',
                503
            );
        }
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
