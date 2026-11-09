<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiPolicies extends Controller
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

    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticated_user();
        $this->require_policy_schema();
        $policy = $this->db->raw(
            'SELECT membership_policy, share_capital_policy, loan_policy,
                    paluwagan_policy, demo_balance_policy, minimum_membership_days,
                    minimum_paid_up_share_capital, minimum_savings_balance,
                    loan_to_paid_up_share_capital_multiplier, cooperative_registration_date,
                    updated_at
             FROM cooperative_policies WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$policy) {
            $this->api->respond_error('Cooperative policy information is not configured. Apply database/coopwise_policies_upgrade.sql.', 503);
        }
        $this->api->respond(['policies' => $policy]);
    }

    public function update()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_policy_schema();

        $fields = [
            'membership_policy',
            'share_capital_policy',
            'loan_policy',
            'paluwagan_policy',
            'demo_balance_policy'
        ];
        $data = $this->api->body();
        $policy = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                $this->api->respond_error('Complete all cooperative policy fields.', 422);
            }
            $value = trim($data[$field]);
            if ($value === '' || mb_strlen($value) > 5000) {
                $this->api->respond_error('Each policy must contain 1 to 5,000 characters.', 422);
            }
            $policy[$field] = $value;
        }
        $minimum_membership_days = filter_var(
            $data['minimum_membership_days'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 3650]]
        );
        $minimum_share_capital = self::money_to_cents(trim((string) ($data['minimum_paid_up_share_capital'] ?? '')));
        $minimum_savings = self::money_to_cents(trim((string) ($data['minimum_savings_balance'] ?? '')));
        $multiplier = trim((string) ($data['loan_to_paid_up_share_capital_multiplier'] ?? ''));
        $registration_date = trim((string) ($data['cooperative_registration_date'] ?? ''));
        if ($minimum_membership_days === false || $minimum_share_capital === false || $minimum_savings === false
            || !preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/', $multiplier)
            || (float) $multiplier > 100
            || ($registration_date !== '' && !self::valid_date($registration_date))) {
            $this->api->respond_error('Enter valid loan eligibility values and a valid cooperative registration date.', 422);
        }
        $policy['minimum_membership_days'] = $minimum_membership_days;
        $policy['minimum_paid_up_share_capital'] = self::format_cents($minimum_share_capital);
        $policy['minimum_savings_balance'] = self::format_cents($minimum_savings);
        $policy['loan_to_paid_up_share_capital_multiplier'] = number_format((float) $multiplier, 2, '.', '');
        $policy['cooperative_registration_date'] = $registration_date !== '' ? $registration_date : null;
        $policy['updated_by_user_id'] = (int) $user['id'];
        $this->db->transaction();
        try {
            $updated = $this->db->table('cooperative_policies')->where('id', 1)->update($policy);
            if ($updated === false) {
                throw new RuntimeException('Cooperative policies could not be saved.');
            }
            $log_id = $this->db->table('activity_logs')->insert([
                'user_id' => (int) $user['id'],
                'module' => 'cooperative_policies',
                'action' => 'updated',
                'details' => json_encode(['fields' => $fields], JSON_UNESCAPED_SLASHES)
            ]);
            if (!$log_id) {
                throw new RuntimeException('The cooperative policy audit record could not be saved.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond(['message' => 'Cooperative policies saved.']);
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
            $this->api->respond_error('Only cooperative staff can update policies.', 403);
        }
    }

    private function require_policy_schema()
    {
        $columns = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'cooperative_policies'
               AND column_name IN (
                   'membership_policy', 'share_capital_policy', 'loan_policy',
                   'paluwagan_policy', 'demo_balance_policy', 'minimum_membership_days',
                   'minimum_paid_up_share_capital', 'minimum_savings_balance',
                   'loan_to_paid_up_share_capital_multiplier', 'cooperative_registration_date'
               )"
        )->fetchColumn();
        if ((int) $columns !== 10) {
            error_log('CoopWise policy schema is missing; apply database/coopwise_policies_upgrade.sql.');
            $this->api->respond_error(
                'Cooperative policies are not configured in the database yet. Run database/coopwise_policies_upgrade.sql and database/coopwise_cooperative_finance_upgrade.sql in Navicat, then retry.',
                503
            );
        }
    }

    private static function money_to_cents(string $amount)
    {
        if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $amount)) {
            return false;
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);
    }

    private static function format_cents(int $amount)
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function valid_date(string $date)
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date && $date <= date('Y-m-d');
    }
}
