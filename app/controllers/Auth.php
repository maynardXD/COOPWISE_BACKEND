<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth extends Controller
{
    public function __construct()
    {
        parent::__construct();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $this->call->model('User_model');
    }

    public function index()
    {
        if (!empty($_SESSION['user_id'])) {
            $user = $this->User_model->find_identity((int) $_SESSION['user_id']);
            if ($user && $user['status'] === 'active') {
                header('Location: ' . BASE_URL . 'dashboard');
                exit;
            }
            $_SESSION = [];
        }

        $this->call->view('login', [
            'error' => $_SESSION['login_error'] ?? '',
            'success' => $_SESSION['registration_success'] ?? '',
            'page_title' => 'CoopWise Login'
        ]);
        unset($_SESSION['login_error'], $_SESSION['registration_success']);
    }

    public function login()
    {
        $this->index();
    }

    public function authenticate()
    {
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $user = $login !== '' ? $this->User_model->find_for_login($login) : null;
        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password'])) {
            $_SESSION['login_error'] = 'Invalid phone number/email or password.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $this->start_authenticated_session($user);
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }

    public function register()
    {
        if (!empty($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        $this->call->view('register', [
            'errors' => $_SESSION['registration_errors'] ?? [],
            'old' => $_SESSION['registration_old'] ?? [],
            'success' => $_SESSION['registration_success'] ?? '',
            'page_title' => 'Member Registration'
        ]);
        unset($_SESSION['registration_errors'], $_SESSION['registration_old'], $_SESSION['registration_success']);
    }

    public function save_registration()
    {
        $data = $_POST;
        $errors = $this->User_model->validate_registration($data);
        if ($errors) {
            $_SESSION['registration_errors'] = $errors;
            $_SESSION['registration_old'] = array_intersect_key($data, array_flip([
                'first_name', 'middle_name', 'last_name', 'phone_number',
                'email', 'date_of_birth'
            ]));
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        try {
            $this->User_model->save_registration($data);
        } catch (PDOException $error) {
            error_log('CoopWise registration database error: ' . $error->getMessage());
            $_SESSION['registration_errors'] = [
                'form' => 'Registration could not be completed. Check whether the phone number or email is already in use.'
            ];
            $_SESSION['registration_old'] = array_intersect_key($data, array_flip([
                'first_name', 'middle_name', 'last_name', 'phone_number',
                'email', 'date_of_birth'
            ]));
            header('Location: ' . BASE_URL . 'register');
            exit;
        }

        $_SESSION['registration_success'] = 'Registration successful. You can now sign in.';
        header('Location: ' . BASE_URL . 'login');
        exit;
    }

    public function logout()
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $user = $this->User_model->find_identity((int) $_SESSION['user_id']);
        $this->call->view('logout_confirmation', [
            'page_title' => 'Confirm Logout',
            'user_name' => $user['full_name'] ?? 'your account'
        ]);
    }

    public function confirm_logout()
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: ' . BASE_URL . 'login');
        exit;
    }

    private function start_authenticated_session(array $user)
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];
    }
}
