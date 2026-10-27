<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class User_model extends Model
{
    protected $table = 'users';
    protected $fillable = [
        'email',
        'password',
        'full_name',
        'role',
        'status'
    ];

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->model('Member_model');
    }

    public function find_for_login($login)
    {
        $user = $this->db->table('users u')
            ->select('u.id, u.email, u.password, u.full_name, u.role, u.status')
            ->where('u.email', strtolower(trim((string) $login)))
            ->get();

        if (!$user && preg_match('/^09\d{9}$/', (string) $login)) {
            $user = $this->db->table('users u')
                ->select('u.id, u.email, u.password, u.full_name, u.role, u.status')
                ->inner_join('members m', 'm.user_id = u.id')
                ->where('m.contact_number', $login)
                ->get();
        }

        return $user;
    }

    public function find_identity($id)
    {
        return $this->db->table($this->table)
            ->select('id, email, full_name, role, status')
            ->where('id', (int) $id)
            ->get();
    }

    public function email_exists($email)
    {
        return $this->db->table($this->table)
            ->where('email', $email)
            ->count() > 0;
    }

    public function phone_exists($phone)
    {
        return $this->db->table('members')
            ->where('contact_number', $phone)
            ->count() > 0;
    }

    private function email_domain_has_mail_records($email)
    {
        $domain = substr(strrchr($email, '@'), 1);

        return checkdnsrr($domain, 'MX')
            || checkdnsrr($domain, 'A')
            || checkdnsrr($domain, 'AAAA');
    }

    public function register_member(array $user_data, array $member_data)
    {
        $this->db->transaction();

        try {
            $user_id = $this->insert($user_data);
            if (!$user_id) {
                throw new RuntimeException('Could not create member account.');
            }

            $member_data['user_id'] = (int) $user_id;
            $member_id = $this->Member_model->insert($member_data);

            if (!$member_id) {
                throw new RuntimeException('Could not create member profile.');
            }

            $this->db->commit();
            return (int) $user_id;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function validate_registration(array $data)
    {
        $errors = [];
        $first = trim((string) ($data['first_name'] ?? ''));
        $middle = trim((string) ($data['middle_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $phone = trim((string) ($data['phone_number'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $dob = trim((string) ($data['date_of_birth'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirmation'] ?? '');

        foreach (['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last] as $field => $name) {
            if ($field === 'middle_name' && $name === '') {
                continue;
            }
            if ($name === '' || strlen($name) > 100 || !preg_match('/^\p{L}+(?: \p{L}+)*$/u', $name)) {
                $label = str_replace('_', ' ', $field);
                $errors[$field] = ucfirst($label) . ' may contain letters and spaces only.';
            }
        }

        if (!preg_match('/^09\d{9}$/', $phone)) {
            $errors['phone_number'] = 'Enter an 11-digit Philippine mobile number starting with 09.';
        } elseif ($this->phone_exists($phone)) {
            $errors['phone_number'] = 'That phone number is already registered.';
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (!$this->email_domain_has_mail_records($email)) {
            $errors['email'] = 'Enter an email address with a real, reachable mail domain.';
        } elseif ($this->email_exists($email)) {
            $errors['email'] = 'That email address is already registered.';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        $date_errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($date_errors && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0)) || $date->format('Y-m-d') !== $dob) {
            $errors['date_of_birth'] = 'Enter a valid date of birth.';
        } elseif ($date > new DateTimeImmutable('today')) {
            $errors['date_of_birth'] = 'Date of birth cannot be in the future.';
        }
        if (!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9\s])[^\s]{8,128}$/D', $password)) {
            $errors['password'] = 'Use 8-128 characters with an uppercase letter, a number, and a special character. Spaces are not allowed.';
        }
        if ($password !== $confirmation) {
            $errors['password_confirmation'] = 'The passwords do not match.';
        }

        return $errors;
    }

    public function save_registration(array $data)
    {
        $first = trim((string) $data['first_name']);
        $middle = trim((string) ($data['middle_name'] ?? ''));
        $last = trim((string) $data['last_name']);
        $email = strtolower(trim((string) $data['email']));
        $full_name = trim($first . ' ' . ($middle !== '' ? $middle . ' ' : '') . $last);

        return $this->register_member([
            'email' => $email,
            'password' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'full_name' => $full_name,
            'role' => 'member',
            'status' => 'active'
        ], [
            'member_number' => $this->Member_model->next_member_number(),
            'first_name' => $first,
            'middle_name' => $middle !== '' ? $middle : null,
            'last_name' => $last,
            'email' => $email,
            'contact_number' => trim((string) $data['phone_number']),
            'birth_date' => trim((string) $data['date_of_birth']),
            'status' => 'active'
        ]);
    }
}
