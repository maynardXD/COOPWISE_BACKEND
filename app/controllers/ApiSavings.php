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
        $user = $this->authenticated_user();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->record($user);
        }
        $this->api->require_method('GET');

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
        $members = $user['role'] === 'member' ? [] : $this->db->table('members')
            ->select('id, member_number, first_name, last_name')
            ->where('status', 'active')
            ->order_by('last_name', 'ASC')
            ->get_all();
        $this->api->respond(['transactions' => $transactions, 'members' => $members, 'balance' => $balance]);
    }

    private function record(array $user)
    {
        $this->api->require_method('POST');
        $this->require_staff($user);
        $data = $this->api->body();
        $member_id = filter_var($data['member_id'] ?? null, FILTER_VALIDATE_INT);
        $type = trim((string) ($data['type'] ?? ''));
        $amount = self::money_to_cents(trim((string) ($data['amount'] ?? '')));
        $description = trim((string) ($data['description'] ?? ''));
        if (!$member_id || !in_array($type, ['deposit', 'withdrawal'], true)
            || $amount === false || $amount < 1 || strlen($description) > 255) {
            $this->api->respond_error('Choose a member and transaction type, and enter a positive amount.', 422);
        }

        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM members WHERE id = ? FOR UPDATE', [$member_id])->fetch();
            $member = $this->db->table('members')->where('id', $member_id)->where('status', 'active')->get();
            if (!$member) {
                throw new DomainException('The selected member is not active.');
            }
            $balance = $this->member_balance((int) $member_id);
            if ($type === 'withdrawal' && $amount > $balance) {
                throw new DomainException('The withdrawal amount cannot exceed the member’s available savings.');
            }
            $new_balance = $type === 'deposit' ? $balance + $amount : $balance - $amount;
            $inserted = $this->db->table('savings_transactions')->insert([
                'member_id' => (int) $member_id,
                'type' => $type,
                'amount' => self::format_cents($amount),
                'balance_after' => self::format_cents($new_balance),
                'reference_no' => 'CW-SAV-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'description' => $description !== '' ? $description : null,
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$inserted) {
                throw new RuntimeException('The savings transaction could not be saved.');
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 422);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise API savings transaction failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => ucfirst($type) . ' recorded successfully.'], 201);
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
}
