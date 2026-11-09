<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once dirname(__DIR__, 2) . '/scripts/lib/cooperative_finance.php';

class ApiCooperativeFinance extends Controller
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
        $this->require_finance_schema();
        $fund = $this->db->raw(
            'SELECT available_balance, updated_at FROM cooperative_loan_fund WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$fund) {
            throw new RuntimeException('The cooperative loan fund account is missing.');
        }
        $policy = $this->db->raw(
            'SELECT cooperative_registration_date FROM cooperative_policies WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);

        $result = [
            'loan_fund' => [
                'available_balance' => self::format_cents(self::money_to_cents((string) $fund['available_balance'])),
                'updated_at' => $fund['updated_at']
            ],
            'cooperative_registration_date' => $policy['cooperative_registration_date'] ?? null,
            'fund_transactions' => []
        ];
        if (in_array($user['role'], ['admin', 'officer'], true)) {
            $result['fund_transactions'] = $this->db->raw(
                'SELECT t.id, t.transaction_type, t.amount, t.balance_after, t.reference_no,
                        t.description, t.created_at, u.full_name AS actor_name, l.loan_code
                 FROM cooperative_loan_fund_transactions t
                 LEFT JOIN users u ON u.id = t.created_by_user_id
                 LEFT JOIN loans l ON l.id = t.loan_id
                 ORDER BY t.created_at DESC, t.id DESC LIMIT 50'
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($user['role'] === 'member') {
            $member = $this->member_profile($user);
            $result['member_returns'] = $this->db->raw(
                "SELECT r.id, r.distribution_id, d.fiscal_year, r.paid_up_share_capital,
                        r.patronage_basis, r.interest_on_share_capital, r.patronage_refund,
                        r.total_return, d.approved_at
                 FROM cooperative_member_returns r
                 INNER JOIN cooperative_surplus_distributions d ON d.id = r.distribution_id
                 WHERE r.member_id = ? AND d.status = 'approved'
                 ORDER BY d.fiscal_year DESC, r.id DESC",
                [(int) $member['id']]
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        $this->api->respond($result);
    }

    public function fund()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_finance_schema();
        $data = $this->api->body();
        $amount = self::money_to_cents(trim((string) ($data['amount'] ?? '')));
        $description = trim((string) ($data['description'] ?? ''));
        if ($amount === false || $amount < 1 || strlen($description) > 255) {
            $this->api->respond_error('Enter a positive funding amount and a description of at most 255 characters.', 422);
        }

        $this->db->transaction();
        try {
            $fund = $this->db->raw(
                'SELECT available_balance FROM cooperative_loan_fund WHERE id = 1 FOR UPDATE'
            )->fetch(PDO::FETCH_ASSOC);
            if (!$fund) {
                throw new RuntimeException('The cooperative loan fund account is missing.');
            }
            $balance = self::money_to_cents((string) $fund['available_balance']) + $amount;
            $updated = $this->db->table('cooperative_loan_fund')->where('id', 1)->update([
                'available_balance' => self::format_cents($balance),
                'updated_by_user_id' => (int) $user['id']
            ]);
            if ($updated === false) {
                throw new RuntimeException('The cooperative lending pool could not be funded.');
            }
            $reference = 'CW-FUND-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            $transaction_id = $this->db->table('cooperative_loan_fund_transactions')->insert([
                'transaction_type' => 'funding',
                'amount' => self::format_cents($amount),
                'balance_after' => self::format_cents($balance),
                'reference_no' => $reference,
                'description' => $description !== '' ? $description : 'Staff-recorded simulated lending-pool funding.',
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$transaction_id) {
                throw new RuntimeException('The lending-pool funding history could not be saved.');
            }
            $this->write_activity_log((int) $user['id'], 'cooperative_finance', 'loan_fund_added', [
                'amount' => self::format_cents($amount),
                'balance_after' => self::format_cents($balance),
                'reference_no' => $reference
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond([
            'message' => 'Simulated lending-pool funds recorded.',
            'available_balance' => self::format_cents($balance)
        ], 201);
    }

    public function surplus()
    {
        $user = $this->authenticated_user();
        $this->require_finance_schema();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->require_staff($user);
            $this->create_surplus_draft($user);
        }
        $this->api->require_method('GET');
        if ($user['role'] === 'member') {
            $member = $this->member_profile($user);
            $distributions = $this->db->raw(
                "SELECT d.id, d.fiscal_year, d.net_surplus, d.reserve_allocation, d.education_allocation,
                        d.community_allocation, d.optional_allocation, d.share_capital_pool, d.patronage_pool,
                        d.status, d.approved_at, r.paid_up_share_capital, r.patronage_basis,
                        r.interest_on_share_capital, r.patronage_refund, r.total_return
                 FROM cooperative_surplus_distributions d
                 INNER JOIN cooperative_member_returns r ON r.distribution_id = d.id
                 WHERE r.member_id = ? AND d.status = 'approved'
                 ORDER BY d.fiscal_year DESC, d.id DESC",
                [(int) $member['id']]
            )->fetchAll(PDO::FETCH_ASSOC);
            $this->api->respond(['distributions' => $distributions]);
        }

        $this->require_staff($user);
        $distributions = $this->db->raw(
            "SELECT d.id, d.fiscal_year, d.net_surplus, d.reserve_allocation, d.education_allocation,
                    d.community_allocation, d.optional_allocation, d.share_capital_pool, d.patronage_pool,
                    d.status, d.approved_at, d.created_at, creator.full_name AS created_by_name,
                    approver.full_name AS approved_by_name, COALESCE(summary.member_count, 0) AS member_count,
                    COALESCE(summary.total_member_returns, 0) AS total_member_returns
             FROM cooperative_surplus_distributions d
             LEFT JOIN users creator ON creator.id = d.created_by_user_id
             LEFT JOIN users approver ON approver.id = d.approved_by_user_id
             LEFT JOIN (
                 SELECT distribution_id, COUNT(*) AS member_count, SUM(total_return) AS total_member_returns
                 FROM cooperative_member_returns GROUP BY distribution_id
             ) summary ON summary.distribution_id = d.id
             ORDER BY d.fiscal_year DESC, d.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->api->respond(['distributions' => $distributions]);
    }

    public function approve_surplus($distribution_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_finance_schema();
        $distribution_id = (int) $distribution_id;
        if ($distribution_id < 1) {
            $this->api->respond_error('Choose a valid surplus distribution.', 422);
        }

        $this->db->transaction();
        try {
            $distribution = $this->db->raw(
                'SELECT id, fiscal_year, status, share_capital_pool, patronage_pool
                 FROM cooperative_surplus_distributions WHERE id = ? FOR UPDATE',
                [$distribution_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$distribution || $distribution['status'] !== 'draft') {
                throw new DomainException('This surplus distribution is already reviewed or does not exist.');
            }
            $member_count = (int) $this->db->table('cooperative_member_returns')
                ->where('distribution_id', $distribution_id)->count();
            $has_member_pool = self::money_to_cents((string) $distribution['share_capital_pool'])
                + self::money_to_cents((string) $distribution['patronage_pool']) > 0;
            if ($member_count < 1 && $has_member_pool) {
                throw new DomainException('This draft has no eligible member returns and cannot be approved.');
            }
            $updated = $this->db->table('cooperative_surplus_distributions')
                ->where('id', $distribution_id)
                ->where('status', 'draft')
                ->update([
                    'status' => 'approved',
                    'approved_by_user_id' => (int) $user['id'],
                    'approved_at' => date('Y-m-d H:i:s')
                ]);
            if ($updated !== 1) {
                throw new DomainException('This surplus distribution has already been reviewed.');
            }
            $total_returns = self::money_to_cents((string) $distribution['share_capital_pool'])
                + self::money_to_cents((string) $distribution['patronage_pool']);
            $this->write_activity_log((int) $user['id'], 'cooperative_finance', 'surplus_distribution_approved', [
                'distribution_id' => $distribution_id,
                'fiscal_year' => (int) $distribution['fiscal_year'],
                'member_count' => $member_count,
                'total_member_returns' => self::format_cents($total_returns)
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond(['message' => 'Surplus distribution approved and member returns published.']);
    }

    public function cancel_surplus($distribution_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_finance_schema();
        $distribution_id = (int) $distribution_id;
        if ($distribution_id < 1) {
            $this->api->respond_error('Choose a valid surplus distribution.', 422);
        }

        $this->db->transaction();
        try {
            $distribution = $this->db->raw(
                'SELECT id, fiscal_year, status FROM cooperative_surplus_distributions WHERE id = ? FOR UPDATE',
                [$distribution_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$distribution || $distribution['status'] !== 'draft') {
                throw new DomainException('Only an unpublished draft can be cancelled.');
            }
            $deleted = $this->db->table('cooperative_member_returns')
                ->where('distribution_id', $distribution_id)
                ->delete();
            if ($deleted === false) {
                throw new RuntimeException('The draft member calculations could not be removed.');
            }
            $updated = $this->db->table('cooperative_surplus_distributions')
                ->where('id', $distribution_id)
                ->where('status', 'draft')
                ->update(['status' => 'cancelled']);
            if ($updated !== 1) {
                throw new DomainException('This surplus distribution has already been reviewed.');
            }
            $this->write_activity_log((int) $user['id'], 'cooperative_finance', 'surplus_distribution_cancelled', [
                'distribution_id' => $distribution_id,
                'fiscal_year' => (int) $distribution['fiscal_year']
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond(['message' => 'Unpublished surplus draft cancelled. You can prepare a corrected draft.']);
    }

    private function create_surplus_draft(array $user)
    {
        $data = $this->api->body();
        $fiscal_year = filter_var($data['fiscal_year'] ?? null, FILTER_VALIDATE_INT);
        $net_surplus = self::money_to_cents(trim((string) ($data['net_surplus'] ?? '')));
        $percentages = [];
        foreach ([
            'reserve_percent',
            'education_percent',
            'community_percent',
            'optional_percent',
            'share_capital_pool_percent'
        ] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if (!preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/', $value)) {
                $this->api->respond_error('Enter valid allocation percentages using up to two decimal places.', 422);
            }
            $percentages[$field] = (float) $value;
        }
        if (!$fiscal_year || $net_surplus === false) {
            $this->api->respond_error('Enter a valid fiscal year and net surplus amount.', 422);
        }
        $existing = (int) $this->db->table('cooperative_surplus_distributions')
            ->where('fiscal_year', $fiscal_year)
            ->where('status', '!=', 'cancelled')
            ->count();
        if ($existing > 0) {
            $this->api->respond_error('A draft or approved distribution already exists for this fiscal year.', 409);
        }

        $policy = $this->db->raw(
            'SELECT cooperative_registration_date FROM cooperative_policies WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$policy || !$policy['cooperative_registration_date']) {
            $this->api->respond_error('Set the cooperative registration date in the Learning Center before preparing distributions.', 422);
        }
        try {
            $allocations = cooperativeSurplusAllocations(
                $net_surplus,
                (int) $fiscal_year,
                (string) $policy['cooperative_registration_date'],
                $percentages['reserve_percent'],
                $percentages['education_percent'],
                $percentages['community_percent'],
                $percentages['optional_percent'],
                $percentages['share_capital_pool_percent']
            );
        } catch (InvalidArgumentException $error) {
            $this->api->respond_error($error->getMessage(), 422);
        }

        $end_exclusive = ((int) $fiscal_year + 1) . '-01-01 00:00:00';
        $start = (int) $fiscal_year . '-01-01 00:00:00';
        $capital_rows = $this->db->raw(
            "SELECT member_id, SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END) AS balance
             FROM share_capital_transactions WHERE created_at < ? GROUP BY member_id",
            [$end_exclusive]
        )->fetchAll(PDO::FETCH_ASSOC);
        $capital_by_member = [];
        foreach ($capital_rows as $row) {
            $capital = self::money_to_cents((string) $row['balance']);
            if ($capital > 0) {
                $capital_by_member[(int) $row['member_id']] = $capital;
            }
        }
        $patronage_rows = $this->db->raw(
            'SELECT member_id, SUM(interest_paid) AS basis
             FROM loan_payments WHERE payment_date >= ? AND payment_date < ? AND breakdown_recorded = 1
             GROUP BY member_id',
            [$start, $end_exclusive]
        )->fetchAll(PDO::FETCH_ASSOC);
        $patronage_by_member = [];
        foreach ($patronage_rows as $row) {
            $basis = self::money_to_cents((string) $row['basis']);
            if ($basis > 0) {
                $patronage_by_member[(int) $row['member_id']] = $basis;
            }
        }
        try {
            $share_allocations = cooperativeProportionalAllocation($allocations['share_capital_pool'], $capital_by_member);
            $patronage_allocations = cooperativeProportionalAllocation($allocations['patronage_pool'], $patronage_by_member);
        } catch (InvalidArgumentException $error) {
            $this->api->respond_error($error->getMessage(), 422);
        }

        $member_ids = array_unique(array_merge(array_keys($capital_by_member), array_keys($patronage_by_member)));
        $this->db->transaction();
        try {
            $existing = $this->db->raw(
                "SELECT id FROM cooperative_surplus_distributions
                 WHERE fiscal_year = ? AND status IN ('draft', 'approved') FOR UPDATE",
                [(int) $fiscal_year]
            )->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                throw new DomainException('A draft or approved distribution already exists for this fiscal year.');
            }
            $id = $this->db->table('cooperative_surplus_distributions')->insert([
                'fiscal_year' => (int) $fiscal_year,
                'net_surplus' => self::format_cents($net_surplus),
                'reserve_allocation' => self::format_cents($allocations['reserve_allocation']),
                'education_allocation' => self::format_cents($allocations['education_allocation']),
                'community_allocation' => self::format_cents($allocations['community_allocation']),
                'optional_allocation' => self::format_cents($allocations['optional_allocation']),
                'share_capital_pool' => self::format_cents($allocations['share_capital_pool']),
                'patronage_pool' => self::format_cents($allocations['patronage_pool']),
                'status' => 'draft',
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$id) {
                throw new RuntimeException('The surplus distribution draft could not be saved.');
            }
            foreach ($member_ids as $member_id) {
                $share_return = (int) ($share_allocations[$member_id] ?? 0);
                $patronage_return = (int) ($patronage_allocations[$member_id] ?? 0);
                $total_return = $share_return + $patronage_return;
                if ($total_return < 1) {
                    continue;
                }
                $return_id = $this->db->table('cooperative_member_returns')->insert([
                    'distribution_id' => (int) $id,
                    'member_id' => (int) $member_id,
                    'paid_up_share_capital' => self::format_cents((int) ($capital_by_member[$member_id] ?? 0)),
                    'patronage_basis' => self::format_cents((int) ($patronage_by_member[$member_id] ?? 0)),
                    'interest_on_share_capital' => self::format_cents($share_return),
                    'patronage_refund' => self::format_cents($patronage_return),
                    'total_return' => self::format_cents($total_return)
                ]);
                if (!$return_id) {
                    throw new RuntimeException('A member return calculation could not be saved.');
                }
            }
            $this->write_activity_log((int) $user['id'], 'cooperative_finance', 'surplus_distribution_drafted', [
                'distribution_id' => (int) $id,
                'fiscal_year' => (int) $fiscal_year,
                'net_surplus' => self::format_cents($net_surplus)
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond(['message' => 'Surplus distribution draft calculated. Review it before publishing.', 'id' => (int) $id], 201);
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

    private function require_finance_schema()
    {
        $required_tables = [
            'cooperative_loan_fund',
            'cooperative_loan_fund_transactions',
            'cooperative_surplus_distributions',
            'cooperative_member_returns'
        ];
        $placeholders = implode(',', array_fill(0, count($required_tables), '?'));
        $found = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN ($placeholders)",
            $required_tables
        )->fetchColumn();
        $columns = $this->db->raw(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND (
                 (table_name = 'cooperative_policies' AND column_name IN (
                     'minimum_membership_days', 'minimum_paid_up_share_capital',
                     'minimum_savings_balance', 'loan_to_paid_up_share_capital_multiplier',
                     'cooperative_registration_date'
                 )) OR
                 (table_name = 'loan_payments' AND column_name IN ('principal_paid', 'interest_paid', 'breakdown_recorded')) OR
                 (table_name = 'loan_schedules' AND column_name IN ('principal_paid', 'interest_paid'))
             )"
        )->fetchColumn();
        if ((int) $found !== count($required_tables) || (int) $columns !== 10) {
            error_log('CoopWise cooperative finance schema is missing; apply database/coopwise_cooperative_finance_upgrade.sql.');
            $this->api->respond_error(
                'Cooperative finance features are not configured. Back up the database, then run database/coopwise_cooperative_finance_upgrade.sql in Navicat.',
                503
            );
        }
    }

    private function write_activity_log(int $user_id, string $module, string $action, array $details)
    {
        $id = $this->db->table('activity_logs')->insert([
            'user_id' => $user_id,
            'module' => $module,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_SLASHES)
        ]);
        if (!$id) {
            throw new RuntimeException('The cooperative finance audit record could not be saved.');
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
}
