<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiCooperative extends Controller
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

    public function cycles()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $member = $user['role'] === 'member' ? $this->member_profile($user) : null;
        $query = $this->db->table('cooperative_cycles c')
            ->select('c.id, c.name, c.daily_contribution, c.start_date, c.status, c.created_at');
        if ($member) {
            $query->inner_join('cooperative_cycle_members cm', 'cm.cycle_id = c.id')
                ->where('cm.member_id', (int) $member['id']);
        }
        $cycles = $query->order_by('c.created_at', 'DESC')->get_all();
        foreach ($cycles as &$cycle) {
            $cycle_id = (int) $cycle['id'];
            $cycle['members'] = $this->db->table('cooperative_cycle_members cm')
                ->select('cm.member_id, cm.payout_order, m.member_number, m.first_name, m.last_name, cp.status AS payout_status, cp.payout_date')
                ->inner_join('members m', 'm.id = cm.member_id')
                ->left_join('cooperative_payouts cp', 'cp.cycle_id = cm.cycle_id AND cp.member_id = cm.member_id')
                ->where('cm.cycle_id', $cycle_id)
                ->order_by('cm.payout_order', 'ASC')
                ->get_all();
            $cycle['today_contributions'] = (int) $this->db->table('cooperative_contributions')
                ->where('cycle_id', $cycle_id)
                ->where('contribution_date', date('Y-m-d'))
                ->count();
            $cycle['member_count'] = count($cycle['members']);
            $cycle['ready_payout'] = $this->db->table('cooperative_payouts cp')
                ->select('cp.id, cp.member_id, cp.payout_order, cp.payout_date, cp.amount, cp.reference_no, cp.status, m.member_number, m.first_name, m.last_name')
                ->inner_join('members m', 'm.id = cp.member_id')
                ->where('cp.cycle_id', $cycle_id)
                ->where('cp.status', 'ready')
                ->get();
            if ($member) {
                $cycle['my_contributed_today'] = (int) $this->db->table('cooperative_contributions')
                    ->where('cycle_id', $cycle_id)
                    ->where('member_id', (int) $member['id'])
                    ->where('contribution_date', date('Y-m-d'))
                    ->count() > 0;
            }
        }
        unset($cycle);

        $contribution_query = $this->db->table('cooperative_contributions cc')
            ->select('cc.id, cc.cycle_id, cc.member_id, cc.contribution_date, cc.amount, cc.reference_no, c.name AS cycle_name, m.member_number, m.first_name, m.last_name')
            ->inner_join('cooperative_cycles c', 'c.id = cc.cycle_id')
            ->inner_join('members m', 'm.id = cc.member_id');
        $payout_query = $this->db->table('cooperative_payouts cp')
            ->select('cp.id, cp.cycle_id, cp.member_id, cp.payout_order, cp.payout_date, cp.amount, cp.reference_no, cp.status, cp.paid_at, c.name AS cycle_name, m.member_number, m.first_name, m.last_name')
            ->inner_join('cooperative_cycles c', 'c.id = cp.cycle_id')
            ->inner_join('members m', 'm.id = cp.member_id');
        if ($member) {
            $contribution_query->where('cc.member_id', (int) $member['id']);
            $payout_query->where('cp.member_id', (int) $member['id']);
        }

        $this->api->respond([
            'cycles' => $cycles,
            'contributions' => $contribution_query->order_by('cc.contribution_date', 'DESC')->get_all(),
            'payouts' => $payout_query->order_by('cp.payout_date', 'DESC')->get_all(),
            'members' => $user['role'] === 'member' ? [] : $this->db->table('members')
                ->select('id, member_number, first_name, last_name')
                ->where('status', 'active')
                ->order_by('last_name', 'ASC')
                ->get_all()
        ]);
    }

    public function create_cycle()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $data = $this->api->body();
        $name = trim((string) ($data['name'] ?? ''));
        $amount = self::money_to_cents(trim((string) ($data['daily_contribution'] ?? '')));
        $start_date = trim((string) ($data['start_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $start_date);
        $date_errors = DateTimeImmutable::getLastErrors();
        if ($name === '' || strlen($name) > 120 || $amount === false || $amount < 1
            || !$date || ($date_errors && ($date_errors['warning_count'] || $date_errors['error_count']))
            || $date->format('Y-m-d') !== $start_date || $start_date < date('Y-m-d')) {
            $this->api->respond_error('Enter a cycle name, positive daily amount, and valid start date today or later.', 422);
        }
        $id = $this->db->table('cooperative_cycles')->insert([
            'name' => $name,
            'daily_contribution' => self::format_cents($amount),
            'start_date' => $start_date,
            'status' => 'draft',
            'created_by_user_id' => (int) $user['id']
        ]);
        if (!$id) {
            throw new RuntimeException('Cycle creation failed.');
        }
        $this->api->respond(['message' => 'Draft cycle created.', 'id' => (int) $id], 201);
    }

    public function add_member()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $data = $this->api->body();
        $cycle_id = filter_var($data['cycle_id'] ?? null, FILTER_VALIDATE_INT);
        $member_id = filter_var($data['member_id'] ?? null, FILTER_VALIDATE_INT);
        $order = filter_var($data['payout_order'] ?? null, FILTER_VALIDATE_INT);
        if (!$cycle_id || !$member_id || !$order || $order > 999) {
            $this->api->respond_error('Choose a cycle and active member, and provide a positive payout order.', 422);
        }
        $cycle = $this->db->table('cooperative_cycles')->where('id', $cycle_id)->get();
        $member = $this->db->table('members')->where('id', $member_id)->where('status', 'active')->get();
        if (!$cycle || $cycle['status'] !== 'draft' || !$member) {
            $this->api->respond_error('Only active members can join a draft cycle.', 409);
        }
        if ((int) $this->db->table('cooperative_cycle_members')->where('cycle_id', $cycle_id)->where('member_id', $member_id)->count() > 0) {
            $this->api->respond_error('This member is already enrolled.', 409);
        }
        if ((int) $this->db->table('cooperative_cycle_members')->where('cycle_id', $cycle_id)->where('payout_order', $order)->count() > 0) {
            $this->api->respond_error('That payout order is already assigned.', 409);
        }
        $this->db->table('cooperative_cycle_members')->insert([
            'cycle_id' => $cycle_id,
            'member_id' => $member_id,
            'payout_order' => $order
        ]);
        $this->api->respond(['message' => 'Member added to the draft cycle.'], 201);
    }

    public function remove_member()
    {
        $this->api->require_method('DELETE');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $data = $this->api->body();
        $cycle_id = filter_var($data['cycle_id'] ?? null, FILTER_VALIDATE_INT);
        $member_id = filter_var($data['member_id'] ?? null, FILTER_VALIDATE_INT);
        $cycle = $cycle_id ? $this->db->table('cooperative_cycles')->where('id', $cycle_id)->get() : null;
        if (!$cycle || $cycle['status'] !== 'draft' || !$member_id) {
            $this->api->respond_error('Members can only be removed from a draft cycle.', 409);
        }
        $deleted = $this->db->table('cooperative_cycle_members')
            ->where('cycle_id', $cycle_id)
            ->where('member_id', $member_id)
            ->delete();
        if ($deleted !== 1) {
            $this->api->respond_error('Member is not assigned to this draft cycle.', 404);
        }
        $this->api->respond(['message' => 'Member removed from the draft cycle.']);
    }

    public function activate_cycle()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $cycle_id = filter_var(($this->api->body())['cycle_id'] ?? null, FILTER_VALIDATE_INT);
        $cycle = $cycle_id ? $this->db->table('cooperative_cycles')->where('id', $cycle_id)->get() : null;
        $members = $cycle ? $this->db->table('cooperative_cycle_members')
            ->where('cycle_id', $cycle_id)
            ->order_by('payout_order', 'ASC')
            ->get_all() : [];
        if (!$cycle || $cycle['status'] !== 'draft' || count($members) < 2) {
            $this->api->respond_error('A draft cycle needs at least two members before activation.', 409);
        }
        if (count($members) > 999) {
            $this->api->respond_error('A cycle cannot have more than 999 members.', 422);
        }
        $contribution_cents = self::money_to_cents((string) $cycle['daily_contribution']);
        if ($contribution_cents === false || $contribution_cents > intdiv(999999999999, count($members))) {
            $this->api->respond_error('The pooled payout exceeds the maximum supported amount.', 422);
        }
        foreach ($members as $index => $member) {
            if ((int) $member['payout_order'] !== $index + 1) {
                $this->api->respond_error('Payout order must be consecutive, starting from 1.', 422);
            }
        }
        $this->db->table('cooperative_cycles')->where('id', $cycle_id)->update(['status' => 'active']);
        $this->api->respond(['message' => 'Cycle activated.']);
    }

    public function record_contribution()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $data = $this->api->body();
        $cycle_id = filter_var($data['cycle_id'] ?? null, FILTER_VALIDATE_INT);
        $member_id = filter_var($data['member_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$cycle_id || !$member_id) {
            $this->api->respond_error('Choose a cycle and enrolled member.', 422);
        }

        $this->db->transaction();
        try {
            $cycle = $this->db->table('cooperative_cycles')->where('id', $cycle_id)->get();
            $membership = $this->db->table('cooperative_cycle_members')
                ->where('cycle_id', $cycle_id)->where('member_id', $member_id)->get();
            if (!$cycle || $cycle['status'] !== 'active' || !$membership || $cycle['start_date'] > date('Y-m-d')) {
                throw new DomainException('Contributions require an enrolled member, active cycle, and valid start date.');
            }
            if ((int) $this->db->table('cooperative_payouts')->where('cycle_id', $cycle_id)->where('status', 'ready')->count() > 0) {
                throw new DomainException('Staff must confirm the pending payout before recording another round.');
            }
            if ((int) $this->db->table('cooperative_contributions')
                ->where('cycle_id', $cycle_id)->where('member_id', $member_id)->where('contribution_date', date('Y-m-d'))->count() > 0) {
                throw new DomainException('This member already has a contribution for today.');
            }

            $inserted = $this->db->table('cooperative_contributions')->insert([
                'cycle_id' => $cycle_id,
                'member_id' => $member_id,
                'contribution_date' => date('Y-m-d'),
                'amount' => $cycle['daily_contribution'],
                'reference_no' => 'CW-COOP-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'recorded_by_user_id' => (int) $user['id']
            ]);
            if (!$inserted) {
                throw new RuntimeException('Daily contribution could not be recorded.');
            }

            $member_count = (int) $this->db->table('cooperative_cycle_members')->where('cycle_id', $cycle_id)->count();
            $today_count = (int) $this->db->table('cooperative_contributions')
                ->where('cycle_id', $cycle_id)->where('contribution_date', date('Y-m-d'))->count();
            $payout_created = false;
            if ($member_count > 0 && $today_count === $member_count) {
                $assigned = $this->db->table('cooperative_cycle_members cm')
                    ->select('cm.member_id, cm.payout_order, cp.id AS payout_id')
                    ->left_join('cooperative_payouts cp', 'cp.cycle_id = cm.cycle_id AND cp.member_id = cm.member_id')
                    ->where('cm.cycle_id', $cycle_id)
                    ->order_by('cm.payout_order', 'ASC')
                    ->get_all();
                $recipient = null;
                foreach ($assigned as $candidate) {
                    if ($candidate['payout_id'] === null) {
                        $recipient = $candidate;
                        break;
                    }
                }
                if (!$recipient) {
                    throw new RuntimeException('All payout turns were assigned before the cycle completed.');
                }
                $amount = self::money_to_cents((string) $cycle['daily_contribution']) * $member_count;
                $payout = $this->db->table('cooperative_payouts')->insert([
                    'cycle_id' => $cycle_id,
                    'member_id' => (int) $recipient['member_id'],
                    'payout_order' => (int) $recipient['payout_order'],
                    'payout_date' => date('Y-m-d'),
                    'amount' => self::format_cents($amount),
                    'reference_no' => 'CW-PAYOUT-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                    'status' => 'ready'
                ]);
                if (!$payout) {
                    throw new RuntimeException('Pooled payout could not be created.');
                }
                $payout_created = true;
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise API contribution failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond([
            'message' => $payout_created ? 'Contribution recorded; pooled payout is ready for staff confirmation.' : 'Contribution recorded.',
            'payout_created' => $payout_created
        ], 201);
    }

    public function mark_payout_paid()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $payout_id = filter_var(($this->api->body())['payout_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$payout_id) {
            $this->api->respond_error('Choose a valid payout.', 422);
        }
        $this->db->transaction();
        try {
            $payout = $this->db->table('cooperative_payouts')->where('id', $payout_id)->get();
            if (!$payout || $payout['status'] !== 'ready') {
                throw new DomainException('This payout is not awaiting confirmation.');
            }
            $updated = $this->db->table('cooperative_payouts')
                ->where('id', $payout_id)
                ->where('status', 'ready')
                ->update([
                    'status' => 'paid',
                    'paid_by_user_id' => (int) $user['id'],
                    'paid_at' => date('Y-m-d H:i:s')
                ]);
            if ($updated !== 1) {
                throw new DomainException('This payout has already been confirmed.');
            }
            $member_count = (int) $this->db->table('cooperative_cycle_members')
                ->where('cycle_id', (int) $payout['cycle_id'])->count();
            $paid_count = (int) $this->db->table('cooperative_payouts')
                ->where('cycle_id', (int) $payout['cycle_id'])->where('status', 'paid')->count();
            if ($member_count > 0 && $paid_count === $member_count) {
                $this->db->table('cooperative_cycles')->where('id', (int) $payout['cycle_id'])->update(['status' => 'completed']);
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise API payout confirmation failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Payout marked paid.']);
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
        if ($user['role'] !== 'member') {
            $this->api->respond_error('This action requires a member account.', 403);
        }
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
