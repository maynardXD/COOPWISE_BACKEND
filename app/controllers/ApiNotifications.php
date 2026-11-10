<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once dirname(__DIR__, 2) . '/scripts/lib/brevo_sms_reminders.php';

class ApiNotifications extends Controller
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

    public function sms_preferences()
    {
        $this->api->require_method('GET');
        $member = $this->member_profile($this->authenticated_user());
        $this->require_sms_schema();

        $phone = coopwiseNormalizePhilippineSmsPhone((string) ($member['contact_number'] ?? ''));
        $this->api->respond([
            'preferences' => [
                'payment_reminders_enabled' => (bool) $member['sms_payment_reminders_enabled'],
                'phone_available' => $phone !== false,
                'phone_masked' => $phone === false ? null : '******' . substr($phone, -4)
            ],
            'sms_configured' => coopwiseBrevoSmsConfigured()
        ]);
    }

    public function update_sms_preferences()
    {
        $this->api->require_method('POST');
        $member = $this->member_profile($this->authenticated_user());
        $this->require_sms_schema();
        $data = $this->api->body();
        $enabled = $data['payment_reminders_enabled'] ?? null;
        if (!is_bool($enabled)) {
            $this->api->respond_error('Choose whether to enable SMS payment reminders.', 422);
        }
        $phone = coopwiseNormalizePhilippineSmsPhone((string) ($member['contact_number'] ?? ''));
        if ($enabled && $phone === false) {
            $this->api->respond_error('Add a valid Philippine mobile number to your member profile before opting in.', 422);
        }

        $this->db->raw(
            'UPDATE members
             SET sms_payment_reminders_consented_at = IF(? = 1, NOW(), sms_payment_reminders_consented_at),
                 sms_payment_reminders_revoked_at = IF(? = 0 AND sms_payment_reminders_enabled = 1, NOW(), sms_payment_reminders_revoked_at),
                 sms_payment_reminders_enabled = ?
             WHERE id = ?',
            [(int) $enabled, (int) $enabled, (int) $enabled, (int) $member['id']]
        );

        $this->api->respond([
            'message' => $enabled
                ? 'SMS payment reminders enabled. You can turn them off at any time.'
                : 'SMS payment reminders disabled.',
            'preferences' => [
                'payment_reminders_enabled' => $enabled,
                'phone_available' => $phone !== false,
                'phone_masked' => $phone === false ? null : '******' . substr($phone, -4)
            ],
            'sms_configured' => coopwiseBrevoSmsConfigured()
        ]);
    }

    private function authenticated_user()
    {
        $claims = $this->api->require_jwt();
        $user = $this->User_model->find_identity((int) $claims['sub']);
        if (!$user || $user['status'] !== 'active') {
            $this->api->respond_error('Account is unavailable.', 401);
        }
        if ($user['role'] !== 'member') {
            $this->api->respond_error('SMS reminder preferences are available to members only.', 403);
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

    private function require_sms_schema()
    {
        $columns = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'members'
               AND column_name IN (
                   'sms_payment_reminders_enabled',
                   'sms_payment_reminders_consented_at',
                   'sms_payment_reminders_revoked_at'
               )"
        )->fetchColumn();
        if ((int) $columns !== 3) {
            error_log('CoopWise SMS reminder preference schema is missing; apply database/coopwise_brevo_sms_reminders_upgrade.sql.');
            $this->api->respond_error(
                'SMS reminders are not configured in the database yet. Back up the database, then apply database/coopwise_brevo_sms_reminders_upgrade.sql.',
                503
            );
        }
    }
}
