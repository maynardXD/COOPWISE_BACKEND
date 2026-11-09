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
        $this->verify_turnstile($data, 'login');
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

        $this->respond_with_session($user, 'Signed in successfully.');
    }

    public function config()
    {
        $this->api->require_method('GET');
        header('Cache-Control: no-store, private');
        $turnstile_site_key = trim((string) getenv('COOPWISE_TURNSTILE_SITE_KEY'));
        $turnstile_secret_key = trim((string) getenv('COOPWISE_TURNSTILE_SECRET_KEY'));
        $turnstile_configured = $turnstile_site_key !== '' && $turnstile_secret_key !== '';
        $this->api->respond([
            'turnstile_enabled' => $turnstile_configured,
            'turnstile_configuration_incomplete' => ($turnstile_site_key === '') !== ($turnstile_secret_key === ''),
            'turnstile_site_key' => $turnstile_configured ? $turnstile_site_key : null,
            'google_client_id' => trim((string) getenv('GOOGLE_OAUTH_CLIENT_ID')) ?: null
        ]);
    }

    public function google_login()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('google-login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 300);
        $client_id = trim((string) getenv('GOOGLE_OAUTH_CLIENT_ID'));
        if ($client_id === '') {
            $this->api->respond_error('Google sign-in is not configured. Contact cooperative staff.', 503);
        }

        $data = $this->api->body();
        $this->verify_turnstile($data, 'login');
        $id_token = $data['id_token'] ?? null;
        if (!is_string($id_token) || $id_token === '' || strlen($id_token) > 12000) {
            $this->api->respond_error('Google did not provide a valid sign-in credential. Try again.', 422);
        }
        try {
            $claims = $this->verify_google_id_token($id_token, $client_id);
        } catch (RuntimeException $error) {
            error_log('CoopWise Google sign-in verification service failed: ' . $error->getMessage());
            $this->api->respond_error('Google sign-in is temporarily unavailable. Try signing in with your CoopWise password.', 503);
        }
        if (!$claims) {
            $this->api->respond_error('Google could not verify this sign-in. Try again or use your CoopWise password.', 401);
        }

        $email = strtolower(trim((string) $claims['email']));
        $user = $this->db->table('users u')
            ->select('u.id, u.email, u.full_name, u.role, u.status')
            ->inner_join('members m', 'm.user_id = u.id')
            ->where('u.email', $email)
            ->where('u.role', 'member')
            ->where('u.status', 'active')
            ->where('m.status', 'active')
            ->get();
        if (!$user || strtolower(trim((string) $user['email'])) !== $email) {
            $this->api->respond_error(
                'No active CoopWise member account matches this verified Google email. Register first or use your CoopWise password.',
                403
            );
        }
        $this->respond_with_session($user, 'Signed in with Google.');
    }

    public function refresh()
    {
        $this->api->require_method('POST');
        $data = $this->api->body();
        $refresh_token = trim((string) ($data['refresh_token'] ?? ''));
        $claims = $this->api->validate_jwt($refresh_token);
        if (!$claims || ($claims['type'] ?? '') !== 'refresh' || !isset($claims['sub'])) {
            $this->api->respond_error('Your session has expired. Sign in again.', 401);
        }
        $this->api->rate_limit('token-refresh:' . (int) $claims['sub'], 30, 300);
        $this->require_refresh_token_schema();

        $user = $this->User_model->find_identity((int) $claims['sub']);
        if (!$user || $user['status'] !== 'active'
            || !in_array($user['role'], ['admin', 'officer', 'member'], true)) {
            $this->api->revoke_refresh_token($refresh_token);
            $this->api->respond_error('Your account is unavailable. Sign in again.', 401);
        }

        $this->api->refresh_access_token($refresh_token, [
            'id' => (int) $user['id'],
            'role' => $user['role']
        ]);
    }

    public function logout()
    {
        $this->api->require_method('POST');
        $data = $this->api->body();
        $refresh_token = trim((string) ($data['refresh_token'] ?? ''));
        $claims = $this->api->validate_jwt($refresh_token);
        if ($claims && ($claims['type'] ?? '') === 'refresh') {
            $this->api->revoke_refresh_token($refresh_token);
        }
        $this->api->respond(['message' => 'Signed out.']);
    }

    public function register()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('register:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 3600);
        $data = $this->api->body();
        $this->verify_turnstile($data, 'register');
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
        $data = $this->api->body();
        $this->verify_turnstile($data, 'password_reset_request');
        $this->require_password_reset_schema();
        $sender_email = strtolower(trim((string) getenv('BREVO_SENDER_EMAIL')));
        $sender_name = trim((string) (getenv('BREVO_SENDER_NAME') ?: 'CoopWise'));
        if (trim((string) getenv('BREVO_API_KEY')) === '' || !filter_var($sender_email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('Password-reset email is not configured. Add the Brevo API key and verified sender settings to the backend environment.', 503);
        }

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
        $data = $this->api->body();
        $this->verify_turnstile($data, 'password_reset_confirm');
        $this->require_password_reset_schema();
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

    private function respond_with_session(array $user, string $message)
    {
        $this->require_refresh_token_schema();
        $tokens = $this->api->issue_tokens([
            'id' => (int) $user['id'],
            'role' => $user['role']
        ]);
        $this->api->respond([
            'message' => $message,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => $tokens['token_type'],
            'expires_in' => $tokens['expires_in'],
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'role' => $user['role']
            ]
        ]);
    }

    private function verify_turnstile(array $data, string $expected_action)
    {
        $site_key = trim((string) getenv('COOPWISE_TURNSTILE_SITE_KEY'));
        $secret_key = trim((string) getenv('COOPWISE_TURNSTILE_SECRET_KEY'));
        if ($site_key === '' && $secret_key === '') {
            return;
        }
        if ($site_key === '' || $secret_key === '') {
            error_log('CoopWise Turnstile configuration is incomplete.');
            $this->api->respond_error('The sign-in security check is not configured correctly. Contact cooperative staff.', 503);
        }

        $token = $data['turnstile_token'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 2048) {
            $this->api->respond_error('Complete the security check and try again.', 422);
        }
        $payload = http_build_query([
            'secret' => $secret_key,
            'response' => $token
        ], '', '&', PHP_QUERY_RFC3986);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 8,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);
        $response_body = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        $status_line = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $status_line, $matches);
        $status = (int) ($matches[1] ?? 0);
        $response = is_string($response_body) ? json_decode($response_body, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($response)) {
            error_log('CoopWise Turnstile verification service returned HTTP ' . ($status ?: 'unknown') . '.');
            $this->api->respond_error('The security check is temporarily unavailable. Try again shortly.', 503);
        }
        if (!self::turnstile_response_is_valid($response, $expected_action, $this->allowed_turnstile_hostnames())) {
            $this->api->respond_error('The security check expired or could not be verified. Complete it again and retry.', 422);
        }
    }

    private function allowed_turnstile_hostnames(): array
    {
        $configured = trim((string) getenv('COOPWISE_TURNSTILE_HOSTNAMES'));
        $hostnames = $configured !== '' ? explode(',', $configured) : [];
        if ($configured === '') {
            $origins = trim((string) getenv('COOPWISE_ALLOWED_ORIGINS'));
            if ($origins !== '') {
                foreach (explode(',', $origins) as $origin) {
                    $host = parse_url(trim($origin), PHP_URL_HOST);
                    if (is_string($host) && $host !== '') {
                        $hostnames[] = $host;
                    }
                }
            }
            $frontend_url = trim((string) getenv('COOPWISE_FRONTEND_URL'));
            $frontend_host = $frontend_url !== '' ? parse_url($frontend_url, PHP_URL_HOST) : null;
            if (is_string($frontend_host) && $frontend_host !== '') {
                $hostnames[] = $frontend_host;
            }
            if (strtolower((string) getenv('APP_ENV')) !== 'production') {
                $hostnames = array_merge($hostnames, ['localhost', '127.0.0.1']);
            }
        }
        return array_values(array_unique(array_filter(array_map(
            static function ($hostname) {
                return strtolower(rtrim(trim((string) $hostname), '.'));
            },
            $hostnames
        ))));
    }

    public static function turnstile_response_is_valid(array $response, string $expected_action, array $allowed_hostnames): bool
    {
        if (($response['success'] ?? false) !== true
            || !isset($response['action'], $response['hostname'])
            || !is_string($response['action'])
            || !hash_equals($expected_action, $response['action'])) {
            return false;
        }
        $hostname = strtolower(rtrim(trim((string) $response['hostname']), '.'));
        $allowed = array_map(
            static function ($allowed_hostname) {
                return strtolower(rtrim(trim((string) $allowed_hostname), '.'));
            },
            $allowed_hostnames
        );
        return $hostname !== '' && in_array($hostname, $allowed, true);
    }

    private function verify_google_id_token(string $id_token, string $client_id): ?array
    {
        $parts = explode('.', $id_token);
        if (count($parts) !== 3 || strlen($parts[0]) > 2048 || strlen($parts[1]) > 8192) {
            return null;
        }
        $header_json = self::base64url_decode($parts[0]);
        $claims_json = self::base64url_decode($parts[1]);
        $signature = self::base64url_decode($parts[2]);
        if ($header_json === false || $claims_json === false || $signature === false) {
            return null;
        }
        $header = json_decode($header_json, true);
        $claims = json_decode($claims_json, true);
        if (!is_array($header) || !is_array($claims)
            || ($header['alg'] ?? null) !== 'RS256'
            || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
            return null;
        }

        $keys = $this->google_signing_keys();
        $key = self::find_google_signing_key($keys, $header['kid']);
        if (!$key) {
            $keys = $this->google_signing_keys(true);
            $key = self::find_google_signing_key($keys, $header['kid']);
        }
        if (!$key) {
            return null;
        }
        $public_key = self::google_rsa_public_key($key);
        if ($public_key === false
            || openssl_verify($parts[0] . '.' . $parts[1], $signature, $public_key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }
        return self::google_claims_are_valid($claims, $client_id) ? $claims : null;
    }

    private function google_signing_keys(bool $force_refresh = false): array
    {
        $cache_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'coopwise-google-jwks-' . hash('sha256', (string) getenv('APP_ENV')) . '.json';
        $lock = @fopen($cache_path . '.lock', 'c');
        if (is_resource($lock)) {
            flock($lock, LOCK_EX);
        }
        try {
            if (!$force_refresh) {
                $cached = @file_get_contents($cache_path);
                $cache = is_string($cached) ? json_decode($cached, true) : null;
                if (is_array($cache) && (int) ($cache['expires_at'] ?? 0) > time()
                    && is_array($cache['keys'] ?? null)) {
                    return $cache['keys'];
                }
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Accept: application/json\r\n",
                    'timeout' => 8,
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true
                ]
            ]);
            $body = @file_get_contents('https://www.googleapis.com/oauth2/v3/certs', false, $context);
            $status_line = $http_response_header[0] ?? '';
            preg_match('/\s(\d{3})\s/', $status_line, $matches);
            $status = (int) ($matches[1] ?? 0);
            $jwks = is_string($body) && strlen($body) <= 262144 ? json_decode($body, true) : null;
            if ($status < 200 || $status >= 300 || !is_array($jwks)
                || !is_array($jwks['keys'] ?? null) || !$jwks['keys']) {
                throw new RuntimeException('Google signing keys could not be retrieved.');
            }
            $cache_control = implode(' ', $http_response_header ?? []);
            $max_age = preg_match('/max-age=(\d+)/i', $cache_control, $max_age_match)
                ? (int) $max_age_match[1]
                : 300;
            $expires_at = time() + max(60, min($max_age, 3600));
            @file_put_contents($cache_path, json_encode([
                'expires_at' => $expires_at,
                'keys' => $jwks['keys']
            ], JSON_UNESCAPED_SLASHES), LOCK_EX);
            return $jwks['keys'];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function find_google_signing_key(array $keys, string $kid): ?array
    {
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid
                && ($key['kty'] ?? null) === 'RSA'
                && ($key['alg'] ?? 'RS256') === 'RS256'
                && ($key['use'] ?? 'sig') === 'sig') {
                return $key;
            }
        }
        return null;
    }

    private static function google_rsa_public_key(array $jwk)
    {
        $modulus = self::base64url_decode((string) ($jwk['n'] ?? ''));
        $exponent = self::base64url_decode((string) ($jwk['e'] ?? ''));
        if ($modulus === false || strlen($modulus) < 256 || strlen($modulus) > 1024
            || $exponent === false || $exponent === '') {
            return false;
        }
        $rsa_key = self::der_sequence(
            self::der_integer($modulus) . self::der_integer($exponent)
        );
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $subject_public_key = self::der_sequence(
            $algorithm . self::der_value(0x03, "\0" . $rsa_key)
        );
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subject_public_key), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem);
    }

    private static function google_claims_are_valid(array $claims, string $client_id): bool
    {
        $now = time();
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $email_verified = ($claims['email_verified'] ?? false) === true
            || ($claims['email_verified'] ?? null) === 'true';
        if (!in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || !in_array($client_id, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $client_id)
            || (isset($claims['azp']) && $claims['azp'] !== $client_id)
            || !isset($claims['exp'], $claims['iat'])
            || !is_numeric($claims['exp']) || !is_numeric($claims['iat'])
            || (int) $claims['exp'] <= $now
            || (int) $claims['iat'] > $now + 60
            || (int) $claims['iat'] >= (int) $claims['exp']
            || (int) $claims['exp'] - (int) $claims['iat'] > 86400
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255
            || !is_string($claims['email'] ?? null) || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
            || !$email_verified) {
            return false;
        }
        return true;
    }

    private static function base64url_decode(string $value)
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            return false;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? false : $decoded;
    }

    private static function der_sequence(string $value): string
    {
        return self::der_value(0x30, $value);
    }

    private static function der_integer(string $value): string
    {
        $value = ltrim($value, "\0");
        if ($value === '') {
            $value = "\0";
        } elseif ((ord($value[0]) & 0x80) !== 0) {
            $value = "\0" . $value;
        }
        return self::der_value(0x02, $value);
    }

    private static function der_value(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 128) {
            $encoded_length = chr($length);
        } else {
            $bytes = '';
            for ($remaining = $length; $remaining > 0; $remaining >>= 8) {
                $bytes = chr($remaining & 0xff) . $bytes;
            }
            $encoded_length = chr(0x80 | strlen($bytes)) . $bytes;
        }
        return chr($tag) . $encoded_length . $value;
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

    private function require_refresh_token_schema()
    {
        $exists = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'refresh_tokens'"
        )->fetchColumn();
        if ((int) $exists !== 1) {
            error_log('CoopWise refresh-token table is missing; apply database/coopwise_runtime_upgrade.sql.');
            $this->api->respond_error(
                'Session renewal is not configured in the database yet. Apply database/coopwise_runtime_upgrade.sql, then retry.',
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
