<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Coopwise_access
{
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->database();
        $this->lava->call->model('User_model');
        $this->lava->call->model('Member_model');
    }

    public static function age_from_birth_date($birth_date)
    {
        if (!is_string($birth_date) || $birth_date === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birth_date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        $today = new DateTimeImmutable('today');
        if ($date > $today) {
            return null;
        }

        return $date->diff($today)->y;
    }

    public function current_user()
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $user = $this->lava->User_model->find_identity((int) $_SESSION['user_id']);
        if (!$user || $user['status'] !== 'active') {
            return null;
        }

        $member = null;
        $age = null;
        if ($user['role'] === 'member') {
            $member = $this->lava->Member_model->find_for_user((int) $user['id']);
            $age = $member ? self::age_from_birth_date($member['birth_date'] ?? null) : null;
        }

        $user['member'] = $member;
        $user['age'] = $age;
        $user['under_18'] = $user['role'] === 'member' && ($age === null || $age < 18);
        return $user;
    }

    public function require_login()
    {
        $user = $this->current_user();
        if (!$user) {
            unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['full_name']);
            $_SESSION['login_error'] = 'Please sign in to continue.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }
        return $user;
    }

    public function require_staff()
    {
        $user = $this->require_login();
        if (!in_array($user['role'], ['admin', 'officer'], true)) {
            http_response_code(403);
            $this->lava->call->view('access_denied', [
                'page_title' => 'Access denied',
                'message' => 'This page is available to cooperative staff only.'
            ]);
            exit;
        }
        return $user;
    }

    public function require_adult_services()
    {
        $user = $this->require_login();
        if ($user['role'] === 'member' && $user['under_18']) {
            $_SESSION['access_error'] = 'Loan services are available only to members aged 18 and above.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
        return $user;
    }
}
