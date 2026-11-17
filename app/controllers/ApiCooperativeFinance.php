<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/*
 * This file sends cooperative capital and member-return information to the website.
 * It also handles a member moving an available return to savings or share capital.
 */
require_once dirname(__DIR__, 2) . '/scripts/lib/cooperative_finance.php';

class ApiCooperativeFinance extends Controller
{
    private $api;
    private $db;

    // This method sets up this controller and its database tools.
    public function __construct()
    {
        parent::__construct();
        $this->api = $this->call->library('api');
        $this->db = $this->call->database();
        $this->call->model('User_model');
        $this->call->model('Member_model');
    }

    // This prepares the capital breakdown and return history for the signed-in user.
    public function index()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $this->require_finance_schema();
        $policy = $this->db->raw(
            'SELECT cooperative_registration_date FROM cooperative_policies WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        $loan_capital = cooperativeAvailableLoanCapital($this->db);
        $loan_interest_summary = $this->db->raw(
            "SELECT
                COALESCE(SUM(CASE WHEN d.status = 'allocated' THEN d.interest_amount ELSE 0 END), 0) AS allocated_interest,
                COALESCE(SUM(CASE WHEN d.status = 'retained' THEN d.interest_amount ELSE 0 END), 0) AS retained_interest,
                COUNT(CASE WHEN d.status = 'allocated' THEN 1 END) AS allocated_payment_count,
                COALESCE((
                    SELECT SUM(r.amount) FROM cooperative_loan_interest_returns r
                    WHERE r.claim_status = 'available'
                ), 0) AS available_returns
             FROM cooperative_loan_interest_distributions d"
        )->fetch(PDO::FETCH_ASSOC);

        $result = [
            'available_loan_capital' => self::format_cents($loan_capital['available']),
            'loan_capital_breakdown' => [
                'paid_up_share_capital' => self::format_cents($loan_capital['paid_up_share_capital']),
                'collected_interest' => self::format_cents($loan_capital['collected_interest']),
                'distributed_interest' => self::format_cents($loan_capital['distributed_interest']),
                'lendable_interest' => self::format_cents($loan_capital['lendable_interest']),
                'outstanding_principal' => self::format_cents($loan_capital['outstanding_principal']),
                'pending_principal' => self::format_cents($loan_capital['pending_principal'])
            ],
            'loan_interest_summary' => [
                'allocated_interest' => self::format_cents(self::money_to_cents((string) ($loan_interest_summary['allocated_interest'] ?? '0.00'))),
                'retained_interest' => self::format_cents(self::money_to_cents((string) ($loan_interest_summary['retained_interest'] ?? '0.00'))),
                'available_returns' => self::format_cents(self::money_to_cents((string) ($loan_interest_summary['available_returns'] ?? '0.00'))),
                'allocated_payment_count' => (int) ($loan_interest_summary['allocated_payment_count'] ?? 0)
            ],
            'cooperative_registration_date' => $policy['cooperative_registration_date'] ?? null
        ];
        $result['approved_member_returns'] = self::format_cents(self::money_to_cents((string) ($this->db->raw(
            "SELECT COALESCE(SUM(r.total_return), 0) AS total
             FROM cooperative_member_returns r
             INNER JOIN cooperative_surplus_distributions d ON d.id = r.distribution_id
             WHERE d.status = 'approved'"
        )->fetch(PDO::FETCH_ASSOC)['total'] ?? '0.00')));
        if ($user['role'] === 'admin' || $user['role'] === 'officer') {
            $result['available_member_returns'] = self::format_cents(self::money_to_cents((string) ($this->db->raw(
                "SELECT COALESCE(SUM(r.total_return), 0) AS total
                 FROM cooperative_member_returns r
                 INNER JOIN cooperative_surplus_distributions d ON d.id = r.distribution_id
                 WHERE d.status = 'approved' AND r.claim_status = 'available'"
            )->fetch(PDO::FETCH_ASSOC)['total'] ?? '0.00')));
            $result['loan_interest_allocations'] = $this->db->raw(
                 "SELECT r.id, r.amount, r.claim_status, r.claimed_at, r.created_at,
                         d.interest_amount, d.eligible_investor_count, lp.payment_date,
                         l.loan_code, m.member_number, m.first_name, m.last_name
                  FROM cooperative_loan_interest_returns r
                  INNER JOIN cooperative_loan_interest_distributions d ON d.id = r.distribution_id
                  INNER JOIN loan_payments lp ON lp.id = d.loan_payment_id
                  INNER JOIN loans l ON l.id = lp.loan_id
                  INNER JOIN members m ON m.id = r.member_id
                  WHERE d.status = 'allocated'
                  ORDER BY r.created_at DESC, r.id DESC
                  LIMIT 100"
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $member = null;
        if ($user['role'] === 'member') {
            $member = $this->member_profile($user);
        } elseif ($user['role'] === 'admin') {
            $linked_member = $this->Member_model->find_for_user((int) $user['id']);
            if ($linked_member && $linked_member['status'] === 'active') {
                $member = $linked_member;
            }
        }
        if ($member) {
            if ($user['role'] === 'admin') {
                $result['member_profile'] = [
                    'member_id' => (int) $member['id'],
                    'member_number' => $member['member_number']
                ];
                $savings = $this->db->raw(
                    "SELECT COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) AS balance
                     FROM savings_transactions WHERE member_id = ?",
                    [(int) $member['id']]
                )->fetch(PDO::FETCH_ASSOC);
                $result['member_savings_balance'] = self::format_cents(
                    self::money_to_cents((string) ($savings['balance'] ?? '0.00'))
                );
            }
            $result['member_returns'] = $this->db->raw(
                "SELECT r.id, r.distribution_id, d.fiscal_year, r.paid_up_share_capital,
                        r.patronage_basis, r.interest_on_share_capital, r.patronage_refund,
                        r.total_return, r.claim_status, r.claimed_at, d.approved_at
                 FROM cooperative_member_returns r
                 INNER JOIN cooperative_surplus_distributions d ON d.id = r.distribution_id
                 WHERE r.member_id = ? AND d.status = 'approved'
                 ORDER BY d.fiscal_year DESC, r.id DESC",
                [(int) $member['id']]
            )->fetchAll(PDO::FETCH_ASSOC);
            $result['loan_interest_returns'] = $this->db->raw(
                "SELECT r.id, r.distribution_id, r.amount, r.claim_status, r.claimed_at, r.created_at,
                        d.interest_amount, d.eligible_investor_count, lp.payment_date,
                        l.loan_code, lp.reference_no
                 FROM cooperative_loan_interest_returns r
                 INNER JOIN cooperative_loan_interest_distributions d ON d.id = r.distribution_id
                 INNER JOIN loan_payments lp ON lp.id = d.loan_payment_id
                 INNER JOIN loans l ON l.id = lp.loan_id
                 WHERE r.member_id = ? AND d.status = 'allocated'
                 ORDER BY r.created_at DESC, r.id DESC",
                [(int) $member['id']]
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $result['member_returns'] = [];
            $result['loan_interest_returns'] = [];
        }
        $this->api->respond($result);
    }

    // This moves available returns to the member's chosen account and records the claim.
    public function claim_returns()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        if (!in_array($user['role'], ['member', 'admin'], true)) {
            $this->api->respond_error('Only a member or the admin’s own investor account can claim member returns.', 403);
        }
        $this->require_finance_schema();
        $member = $this->member_profile($user);
        $body = $this->api->body();
        $destination = $body['destination'] ?? '';
        $source = $body['source'] ?? 'surplus';
        if (!in_array($destination, ['savings', 'share_capital'], true)) {
            $this->api->respond_error('Choose savings or share capital for your approved member returns.', 422);
        }
        if (!in_array($source, ['surplus', 'loan_interest'], true)) {
            $this->api->respond_error('Choose a supported member-return source.', 422);
        }

        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM cooperative_policies WHERE id = 1 FOR UPDATE')->fetch();
            $this->db->raw('SELECT id FROM members WHERE id = ? FOR UPDATE', [(int) $member['id']])->fetch();
            $returns = $source === 'loan_interest'
                ? $this->db->raw(
                    "SELECT r.id, r.amount AS total_return
                     FROM cooperative_loan_interest_returns r
                     INNER JOIN cooperative_loan_interest_distributions d ON d.id = r.distribution_id
                     WHERE r.member_id = ? AND d.status = 'allocated'
                       AND r.claim_status = 'available' AND r.amount > 0
                     ORDER BY r.created_at ASC, r.id ASC
                     FOR UPDATE",
                    [(int) $member['id']]
                )->fetchAll(PDO::FETCH_ASSOC)
                : $this->db->raw(
                    "SELECT r.id, r.total_return
                     FROM cooperative_member_returns r
                     INNER JOIN cooperative_surplus_distributions d ON d.id = r.distribution_id
                     WHERE r.member_id = ? AND d.status = 'approved' AND r.claim_status = 'available'
                     ORDER BY d.fiscal_year ASC, r.id ASC
                     FOR UPDATE",
                    [(int) $member['id']]
                )->fetchAll(PDO::FETCH_ASSOC);
            if (!$returns) {
                throw new DomainException($source === 'loan_interest'
                    ? 'There are no available loan-interest returns to transfer.'
                    : 'There are no approved member returns available to transfer.');
            }
            $amount = 0;
            $return_ids = [];
            foreach ($returns as $return) {
                $amount += self::money_to_cents((string) $return['total_return']);
                $return_ids[] = (int) $return['id'];
            }
            $reference = 'CW-RETURN-' . strtoupper($destination) . '-' . bin2hex(random_bytes(12));
            if ($destination === 'savings') {
                $balance = $this->db->raw(
                    "SELECT COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) AS total
                     FROM savings_transactions WHERE member_id = ?",
                    [(int) $member['id']]
                )->fetch(PDO::FETCH_ASSOC);
                $balance_after = self::money_to_cents((string) ($balance['total'] ?? '0.00')) + $amount;
                $inserted = $this->db->table('savings_transactions')->insert([
                    'member_id' => (int) $member['id'],
                    'type' => 'deposit',
                    'amount' => self::format_cents($amount),
                    'balance_after' => self::format_cents($balance_after),
                    'reference_no' => $reference,
                    'description' => $source === 'loan_interest'
                        ? 'Equal loan-interest member return credited to savings.'
                        : 'Approved cooperative dividend/member return credited to savings.',
                    'created_by_user_id' => (int) $user['id']
                ]);
                if (!$inserted) {
                    throw new RuntimeException('The approved member returns could not be credited to savings.');
                }
            } else {
                $subscription = $this->db->raw(
                    "SELECT id, par_value FROM share_capital_subscriptions
                     WHERE member_id = ? AND status = 'active'
                     ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE",
                    [(int) $member['id']]
                )->fetch(PDO::FETCH_ASSOC);
                if (!$subscription) {
                    throw new DomainException('An active share subscription is required before returns can be reinvested.');
                }
                $par_value = self::money_to_cents((string) $subscription['par_value']);
                $share_count = (int) ceil($amount / $par_value);
                if ($share_count < 1 || $share_count > 999999999
                    || $par_value > intdiv(999999999999, $share_count)) {
                    throw new DomainException('The return amount exceeds the supported share subscription limit.');
                }
                $subscribed_amount = $par_value * $share_count;
                $subscription_id = $this->db->table('share_capital_subscriptions')->insert([
                    'member_id' => (int) $member['id'],
                    'subscription_number' => 'CW-SHR-RETURN-' . bin2hex(random_bytes(10)),
                    'subscribed_shares' => $share_count,
                    'par_value' => self::format_cents($par_value),
                    'subscribed_amount' => self::format_cents($subscribed_amount),
                    'subscription_date' => date('Y-m-d'),
                    'status' => 'active',
                    'created_by_user_id' => (int) $user['id']
                ]);
                if (!$subscription_id) {
                    throw new RuntimeException('The reinvestment share subscription could not be created.');
                }
                $inserted = $this->db->table('share_capital_transactions')->insert([
                    'member_id' => (int) $member['id'],
                    'subscription_id' => (int) $subscription_id,
                    'transaction_type' => 'payment',
                    'amount' => self::format_cents($amount),
                    'balance_after' => self::format_cents($amount),
                    'transaction_reference' => $reference,
                    'payment_method' => 'simulation',
                    'description' => $source === 'loan_interest'
                        ? 'Equal loan-interest member return reinvested as share capital.'
                        : 'Approved cooperative dividend/member return reinvested as share capital.',
                    'created_by_user_id' => (int) $user['id']
                ]);
                if (!$inserted) {
                    throw new RuntimeException('The approved member returns could not be reinvested as share capital.');
                }
            }
            foreach ($return_ids as $return_id) {
                $return_table = $source === 'loan_interest'
                    ? 'cooperative_loan_interest_returns'
                    : 'cooperative_member_returns';
                $updated = $this->db->table($return_table)
                    ->where('id', $return_id)
                    ->where('claim_status', 'available')
                    ->update([
                        'claim_status' => $destination === 'savings' ? 'paid_to_savings' : 'reinvested',
                        'claimed_at' => date('Y-m-d H:i:s'),
                        'claim_reference' => $reference
                    ]);
                if ($updated !== 1) {
                    throw new DomainException('A member return was already transferred. Refresh the page and check your balance.');
                }
            }
            $this->write_activity_log((int) $user['id'], 'cooperative_finance', $source === 'loan_interest'
                ? 'loan_interest_returns_claimed'
                : 'member_returns_claimed', [
                'destination' => $destination,
                'amount' => self::format_cents($amount),
                'return_ids' => $return_ids,
                'source' => $source,
                'reference_no' => $reference
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        $this->api->respond([
            'message' => $destination === 'savings'
                ? ($source === 'loan_interest'
                    ? 'Loan-interest returns were added to your savings.'
                    : 'Approved member returns were added to your savings.')
                : ($source === 'loan_interest'
                    ? 'Loan-interest returns were reinvested as share capital.'
                    : 'Approved member returns were reinvested as share capital.'),
            'amount' => self::format_cents($amount)
        ]);
    }

    // This method returns or creates cooperative surplus distribution information.
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

    // This method approves a draft cooperative surplus distribution.
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

    // This method cancels a draft surplus distribution.
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

    // This method prepares a draft surplus distribution for staff review.
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

    // This method gets the account that is currently signed in.
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

    // This method loads the member profile for the signed-in account.
    private function member_profile(array $user)
    {
        $member = $this->Member_model->find_for_user((int) $user['id']);
        if (!$member || $member['status'] !== 'active') {
            $this->api->respond_error('An active member profile is required.', 403);
        }
        return $member;
    }

    // This method checks that the signed-in account has staff access.
    private function require_staff(array $user)
    {
        if (!in_array($user['role'], ['admin', 'officer'], true)) {
            $this->api->respond_error('This action is available to cooperative staff only.', 403);
        }
    }

    // This method checks that the required finance tables are installed.
    private function require_finance_schema()
    {
        $required_tables = [
            'cooperative_surplus_distributions',
            'cooperative_member_returns',
            'cooperative_loan_interest_distributions',
            'cooperative_loan_interest_returns'
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
                 (table_name = 'loan_schedules' AND column_name IN ('principal_paid', 'interest_paid')) OR
                 (table_name = 'cooperative_member_returns' AND column_name IN (
                     'claim_status', 'claimed_at', 'claim_reference'
                 )) OR
                 (table_name = 'cooperative_loan_interest_distributions' AND column_name IN (
                     'loan_payment_id', 'interest_amount', 'eligible_investor_count', 'status'
                 )) OR
                 (table_name = 'cooperative_loan_interest_returns' AND column_name IN (
                     'distribution_id', 'member_id', 'amount', 'claim_status', 'claimed_at', 'claim_reference'
                 ))
             )"
        )->fetchColumn();
        if ((int) $found !== count($required_tables) || (int) $columns !== 23) {
            error_log('CoopWise cooperative finance schema is missing; apply the finance, member-return claims, and loan-interest returns upgrades.');
            $this->api->respond_error(
                'Cooperative finance features are not configured. Back up the database, then run database/coopwise_cooperative_finance_upgrade.sql, database/coopwise_member_return_claims_upgrade.sql, and database/coopwise_loan_interest_returns_upgrade.sql in Navicat.',
                503
            );
        }
    }

    // This method saves a record of the user action.
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

    // This method converts a money amount to cents for accurate calculations.
    private static function money_to_cents(string $amount)
    {
        if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $amount)) {
            return false;
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);
    }

    // This method formats cents.
    private static function format_cents(int $amount)
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
