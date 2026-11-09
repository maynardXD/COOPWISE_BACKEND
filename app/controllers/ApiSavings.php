<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiSavings extends Controller
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

    public function index()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();

        $member = $user['role'] === 'member' ? $this->member_profile($user) : null;
        if (!$member) {
            $this->require_staff($user);
        }

        $query = $this->db->table('savings_transactions s')
            ->select('s.id, s.member_id, s.type, s.amount, s.balance_after, s.reference_no, s.description, s.created_at, m.member_number, m.first_name, m.last_name')
            ->inner_join('members m', 'm.id = s.member_id');
        if ($member) {
            $query->where('s.member_id', (int) $member['id']);
        }
        $transactions = $query->order_by('s.created_at', 'DESC')->get_all();
        $balance = null;
        if ($member) {
            $balance = $this->member_balance((int) $member['id']);
        }
        $this->api->respond([
            'transactions' => $transactions,
            'balance' => $balance,
            'auto_pay_preferences' => $member ? [
                'loan' => (bool) ($member['loan_autopay_enabled'] ?? false),
                'paluwagan' => (bool) ($member['paluwagan_autopay_enabled'] ?? false)
            ] : null
        ]);
    }

    public function withdraw()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        if ($user['role'] !== 'member') {
            $this->api->respond_error('Only members can simulate a withdrawal.', 403);
        }
        $this->api->rate_limit('savings-withdrawal:' . (int) $user['id'], 30, 3600);
        $member = $this->member_profile($user);
        $data = $this->api->body();
        $amount_value = $data['amount'] ?? null;
        $request_id_value = $data['request_id'] ?? null;
        if (!is_string($amount_value) || !is_string($request_id_value)) {
            $this->api->respond_error('Enter a valid withdrawal amount and request identifier.', 422);
        }
        $amount = self::money_to_cents(trim($amount_value));
        $request_id = strtolower(trim($request_id_value));
        if ($amount === false || $amount < 1 || !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $request_id
        )) {
            $this->api->respond_error('Enter a valid withdrawal amount and request identifier.', 422);
        }

        $reference = 'CW-WD-' . strtoupper(str_replace('-', '', $request_id));
        $member_id = (int) $member['id'];
        $balance_cents = 0;
        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM members WHERE id = ? FOR UPDATE', [$member_id])->fetch();
            $existing = $this->db->raw(
                'SELECT id, amount FROM savings_transactions
                 WHERE member_id = ? AND type = ? AND reference_no = ? LIMIT 1',
                [$member_id, 'withdrawal', $reference]
            )->fetch(PDO::FETCH_ASSOC);
            $balance_cents = $this->member_balance($member_id);
            if ($balance_cents === false) {
                throw new RuntimeException('The member balance could not be calculated.');
            }
            if ($existing && self::money_to_cents((string) $existing['amount']) !== $amount) {
                throw new DomainException('This request identifier was already used for a different withdrawal.');
            }

            if (!$existing) {
                if ($amount > $balance_cents) {
                    throw new DomainException('The withdrawal amount exceeds your available member balance.');
                }
                $transaction_id = $this->db->table('savings_transactions')->insert([
                    'member_id' => $member_id,
                    'type' => 'withdrawal',
                    'amount' => self::format_cents($amount),
                    'balance_after' => self::format_cents($balance_cents - $amount),
                    'reference_no' => $reference,
                    'description' => 'Simulated withdrawal; no money transferred',
                    'created_by_user_id' => (int) $user['id']
                ]);
                if (!$transaction_id) {
                    throw new RuntimeException('The simulated withdrawal could not be recorded.');
                }
                $log_id = $this->db->table('activity_logs')->insert([
                    'user_id' => (int) $user['id'],
                    'module' => 'savings',
                    'action' => 'simulated_withdrawal',
                    'details' => json_encode([
                        'member_id' => $member_id,
                        'amount' => self::format_cents($amount),
                        'reference_no' => $reference
                    ], JSON_UNESCAPED_SLASHES)
                ]);
                if (!$log_id) {
                    throw new RuntimeException('The simulated withdrawal audit record could not be saved.');
                }
                $balance_cents -= $amount;
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise simulated withdrawal failed: ' . $error->getMessage());
            throw $error;
        }

        $this->api->respond([
            'message' => $existing
                ? 'This simulated withdrawal was already recorded.'
                : 'Simulated withdrawal completed. No money was transferred.',
            'reference_no' => $reference,
            'balance' => self::format_cents($balance_cents)
        ]);
    }

    public function auto_pay_preferences()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $columns = $this->db->raw(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['members']
        )->fetchAll(PDO::FETCH_COLUMN);
        $required_columns = ['loan_autopay_enabled', 'paluwagan_autopay_enabled', 'autopay_consent_updated_at'];
        $missing_columns = array_values(array_diff($required_columns, $columns));
        if ($missing_columns) {
            error_log('CoopWise auto-pay schema is incomplete; missing members columns: ' . implode(', ', $missing_columns));
            $this->api->respond_error(
                'Savings auto-pay is not configured in the database yet. Apply database/coopwise_financial_workflows_upgrade.sql, then retry.',
                503
            );
        }
        $member = $this->member_profile($user);
        $data = $this->api->body();
        if (!array_key_exists('loan', $data) || !array_key_exists('paluwagan', $data)
            || !is_bool($data['loan']) || !is_bool($data['paluwagan'])) {
            $this->api->respond_error('Choose whether to enable each savings auto-payment option.', 422);
        }
        $updated = $this->db->table('members')
            ->where('id', (int) $member['id'])
            ->update([
                'loan_autopay_enabled' => $data['loan'] ? 1 : 0,
                'paluwagan_autopay_enabled' => $data['paluwagan'] ? 1 : 0,
                'autopay_consent_updated_at' => date('Y-m-d H:i:s')
            ]);
        if ($updated === false) {
            throw new RuntimeException('Savings auto-payment preferences could not be saved.');
        }
        $this->api->respond([
            'message' => 'Savings auto-payment preferences saved.',
            'auto_pay_preferences' => ['loan' => $data['loan'], 'paluwagan' => $data['paluwagan']]
        ]);
    }

    public function ledger($member_id = null)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        if ($user['role'] === 'member') {
            if ($member_id !== null) {
                $this->api->respond_error('Members may only view their own savings ledger.', 403);
            }
            $member = $this->member_profile($user);
        } else {
            $this->require_staff($user);
            $id = filter_var($member_id, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                $this->api->respond_error('Choose a valid member to view the savings ledger.', 422);
            }
            $member = $this->db->table('members')
                ->where('id', (int) $id)
                ->get();
            if (!$member) {
                $this->api->respond_error('Member not found.', 404);
            }
        }

        $today = date('Y-m-d');
        $to = trim((string) ($_GET['to'] ?? $today));
        $from = trim((string) ($_GET['from'] ?? ''));
        if (!self::valid_date($to) || $to > $today || ($from !== '' && !self::valid_date($from))) {
            $this->api->respond_error('Choose valid ledger dates.', 422);
        }
        if ($from === '') {
            $first = $this->db->raw(
                'SELECT DATE(MIN(created_at)) AS first_date FROM savings_transactions WHERE member_id = ?',
                [(int) $member['id']]
            )->fetch(PDO::FETCH_ASSOC);
            $from = $first['first_date'] ?: $to;
        }
        if ($from > $to) {
            $this->api->respond_error('The ledger start date must be on or before its end date.', 422);
        }

        $opening = $this->db->raw(
            'SELECT balance_after FROM savings_transactions
             WHERE member_id = ? AND created_at < ?
             ORDER BY created_at DESC, id DESC LIMIT 1',
            [(int) $member['id'], $from . ' 00:00:00']
        )->fetch(PDO::FETCH_ASSOC);
        $opening_cents = self::money_to_cents((string) ($opening['balance_after'] ?? '0.00'));
        $transactions = $this->db->raw(
            'SELECT id, type, amount, balance_after, reference_no, description, created_at
             FROM savings_transactions
             WHERE member_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
             ORDER BY created_at ASC, id ASC',
            [(int) $member['id'], $from . ' 00:00:00', $to . ' 00:00:00']
        )->fetchAll(PDO::FETCH_ASSOC);

        $deposits_cents = 0;
        $withdrawals_cents = 0;
        foreach ($transactions as &$transaction) {
            $amount_cents = self::money_to_cents((string) $transaction['amount']);
            if ($transaction['type'] === 'deposit') {
                $deposits_cents += $amount_cents;
            } else {
                $withdrawals_cents += $amount_cents;
            }
            $transaction['amount'] = self::format_cents($amount_cents);
            $transaction['balance_after'] = self::format_cents(self::money_to_cents((string) $transaction['balance_after']));
        }
        unset($transaction);

        $closing_cents = $transactions
            ? self::money_to_cents((string) $transactions[count($transactions) - 1]['balance_after'])
            : $opening_cents;
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
            'period' => ['from' => $from, 'to' => $to],
            'opening_balance' => self::format_cents($opening_cents),
            'deposits_total' => self::format_cents($deposits_cents),
            'withdrawals_total' => self::format_cents($withdrawals_cents),
            'closing_balance' => self::format_cents($closing_cents),
            'generated_at' => date('Y-m-d H:i:s'),
            'transactions' => $transactions
        ]);
    }

    private function member_balance(int $member_id)
    {
        $deposits = $this->db->table('savings_transactions')
            ->select_sum('amount', 'total')
            ->where('member_id', $member_id)
            ->where('type', 'deposit')
            ->get();
        $withdrawals = $this->db->table('savings_transactions')
            ->select_sum('amount', 'total')
            ->where('member_id', $member_id)
            ->where('type', 'withdrawal')
            ->get();
        return self::money_to_cents((string) ($deposits['total'] ?? '0'))
            - self::money_to_cents((string) ($withdrawals['total'] ?? '0'));
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

    private static function money_to_cents(string $amount)
    {
        if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $amount)) {
            return false;
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0', STR_PAD_LEFT);
    }

    private static function format_cents(int $amount)
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function valid_date(string $date)
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }
}
