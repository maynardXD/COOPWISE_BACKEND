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
        $balance_breakdown = null;
        $member_balances = [];
        $summary = null;
        if ($member) {
            $balance = $this->member_balance((int) $member['id']);
            $breakdown = ['loan_credits' => 0, 'paluwagan_credits' => 0, 'other_credits' => 0, 'withdrawals' => 0];
            foreach ($transactions as $transaction) {
                $amount = self::money_to_cents((string) $transaction['amount']);
                if ($amount === false) {
                    throw new RuntimeException('A member transaction amount could not be calculated.');
                }
                $description = strtolower((string) ($transaction['description'] ?? ''));
                $reference = strtoupper((string) ($transaction['reference_no'] ?? ''));
                if ($transaction['type'] === 'withdrawal') {
                    $breakdown['withdrawals'] += $amount;
                } elseif (strpos($description, 'paluwagan payout') !== false || strpos($reference, 'CW-PALUWAGAN-') === 0) {
                    $breakdown['paluwagan_credits'] += $amount;
                } elseif (strpos($description, 'loan') !== false || strpos($reference, 'CW-DISBURSE-') === 0) {
                    $breakdown['loan_credits'] += $amount;
                } else {
                    $breakdown['other_credits'] += $amount;
                }
            }
            $balance_breakdown = array_map([self::class, 'format_cents'], $breakdown);
        } else {
            $member_balances = $this->db->raw(
                "SELECT m.id, m.member_number, m.first_name, m.last_name, m.status,
                        COALESCE(SUM(CASE WHEN s.type = 'deposit' THEN s.amount ELSE 0 END), 0) AS credits,
                        COALESCE(SUM(CASE WHEN s.type = 'withdrawal' THEN s.amount ELSE 0 END), 0) AS debits,
                        COALESCE(SUM(CASE WHEN s.type = 'deposit' THEN s.amount ELSE -s.amount END), 0) AS balance
                 FROM members m
                 LEFT JOIN savings_transactions s ON s.member_id = m.id
                 GROUP BY m.id, m.member_number, m.first_name, m.last_name, m.status
                 ORDER BY m.last_name ASC, m.first_name ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $totals = ['credits' => 0, 'debits' => 0, 'balance' => 0];
            foreach ($member_balances as &$row) {
                foreach (array_keys($totals) as $key) {
                    $cents = self::money_to_cents((string) $row[$key]);
                    if ($cents === false) {
                        throw new RuntimeException('A member savings total could not be calculated.');
                    }
                    $totals[$key] += $cents;
                    $row[$key] = self::format_cents($cents);
                }
            }
            unset($row);
            $summary = [
                'member_count' => count($member_balances),
                'credits' => self::format_cents($totals['credits']),
                'debits' => self::format_cents($totals['debits']),
                'balance' => self::format_cents($totals['balance'])
            ];
        }
        $this->api->respond([
            'transactions' => $transactions,
            'balance' => $balance,
            'balance_breakdown' => $balance_breakdown,
            'member_balances' => $member_balances,
            'summary' => $summary,
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
        $channel_value = $data['channel'] ?? null;
        if (!is_string($amount_value) || !is_string($request_id_value) || !is_string($channel_value)) {
            $this->api->respond_error('Enter a valid withdrawal amount, destination, and request identifier.', 422);
        }
        $amount = self::money_to_cents(trim($amount_value));
        $request_id = strtolower(trim($request_id_value));
        $destinations = [
            'gcash' => 'GCash',
            'maya' => 'Maya',
            'bank_bdo' => 'BDO Bank',
            'bank_bpi' => 'BPI',
            'bank_unionbank' => 'UnionBank'
        ];
        if ($amount === false || $amount < 1 || !isset($destinations[$channel_value]) || !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $request_id
        )) {
            $this->api->respond_error('Enter a valid withdrawal amount and supported demo destination.', 422);
        }

        $reference = 'CW-WD-' . strtoupper(str_replace('-', '', $request_id));
        $member_id = (int) $member['id'];
        $balance_cents = 0;
        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM members WHERE id = ? FOR UPDATE', [$member_id])->fetch();
            $existing = $this->db->raw(
                'SELECT id, amount, description FROM savings_transactions
                 WHERE member_id = ? AND type = ? AND reference_no = ? LIMIT 1',
                [$member_id, 'withdrawal', $reference]
            )->fetch(PDO::FETCH_ASSOC);
            $balance_cents = $this->member_balance($member_id);
            if ($balance_cents === false) {
                throw new RuntimeException('The member balance could not be calculated.');
            }
            if ($existing && (self::money_to_cents((string) $existing['amount']) !== $amount
                || ($existing['description'] ?? '') !== 'Simulated withdrawal successful to ' . $destinations[$channel_value] . '; no money transferred')) {
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
                    'description' => 'Simulated withdrawal successful to ' . $destinations[$channel_value] . '; no money transferred',
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
                        'reference_no' => $reference,
                        'destination' => $destinations[$channel_value]
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
                : 'Simulated withdrawal successful to ' . $destinations[$channel_value] . '. No money was transferred.',
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

    public function email_ledger_pdf()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        if ($user['role'] !== 'member') {
            $this->api->respond_error('Only members can email their own savings ledger.', 403);
        }
        $this->api->rate_limit('savings-ledger-email:' . (int) $user['id'], 3, 3600);
        $this->member_profile($user);

        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('Your account does not have a valid registered email address. Contact cooperative staff to update it.', 409);
        }
        $sender_email = strtolower(trim((string) getenv('BREVO_SENDER_EMAIL')));
        $sender_name = trim((string) (getenv('BREVO_SENDER_NAME') ?: 'CoopWise'));
        $api_key = trim((string) getenv('BREVO_API_KEY'));
        if ($api_key === '' || !filter_var($sender_email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('Ledger email is not configured. Contact cooperative staff.', 503);
        }

        $data = $this->api->body();
        $pdf_base64 = $data['pdf_base64'] ?? null;
        if (!is_string($pdf_base64) || $pdf_base64 === '' || strlen($pdf_base64) > 4 * 1024 * 1024) {
            $this->api->respond_error('The ledger PDF is invalid or too large to email. Try a shorter date range.', 422);
        }
        $pdf = base64_decode($pdf_base64, true);
        if ($pdf === false || strlen($pdf) > 3 * 1024 * 1024
            || strpos($pdf, '%PDF-') !== 0 || strpos(substr($pdf, -1024), '%%EOF') === false) {
            $this->api->respond_error('A valid PDF ledger attachment is required.', 422);
        }

        $payload = json_encode([
            'sender' => ['name' => $sender_name, 'email' => $sender_email],
            'to' => [['email' => $email, 'name' => (string) $user['full_name']]],
            'subject' => 'Your CoopWise savings ledger',
            'textContent' => 'Your requested CoopWise savings ledger is attached as a PDF. This statement reflects the records for your selected period.',
            'attachment' => [[
                'name' => 'CoopWise-Savings-Ledger.pdf',
                'content' => $pdf_base64
            ]]
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('The savings ledger email could not be encoded.');
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "api-key: " . $api_key . "\r\nAccept: application/json\r\nContent-Type: application/json",
            'content' => $payload,
            'timeout' => 20,
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
                $provider_message = str_ireplace([$email, $sender_email, $api_key], '[redacted]', $provider_message);
                $provider_message = substr($provider_message, 0, 300);
            }
            if ($status === 0) {
                $transport_error = error_get_last();
                $provider_message = $transport_error['message'] ?? 'No HTTP response was received from Brevo.';
                $provider_message = substr(trim(preg_replace('/\s+/', ' ', strip_tags($provider_message))), 0, 300);
            }
            $support_reference = bin2hex(random_bytes(6));
            error_log(
                'CoopWise savings ledger email failed; reference=' . $support_reference
                . '; user_id=' . (int) $user['id'] . '; provider_error=Brevo HTTP '
                . ($status ?: 'unknown')
                . ($provider_code !== '' ? ' (' . substr($provider_code, 0, 80) . ')' : '')
                . ($provider_message !== '' ? ': ' . $provider_message : '')
            );
            $this->api->respond_error(
                'The ledger email provider did not accept the message. Try again later. Support reference: ' . $support_reference . '.',
                502
            );
        }

        $this->api->respond(['message' => 'Brevo accepted the ledger PDF for delivery to your registered account email.']);
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
