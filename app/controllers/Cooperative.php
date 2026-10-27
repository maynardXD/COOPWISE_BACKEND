<?php
class Cooperative extends Controller {
    public function __construct() {
        parent::__construct();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function index() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_login();
        $db = $this->call->database();
        $staff = in_array($user['role'], ['admin', 'officer'], true);

        $cycle_query = $db->table('cooperative_cycles c')
            ->select('c.id, c.name, c.daily_contribution, c.start_date, c.status, c.created_at');
        if ($staff) {
            $cycles = $cycle_query->order_by('c.created_at', 'DESC')->get_all();
        } elseif (!empty($user['member']['id'])) {
            $cycles = $cycle_query
                ->inner_join('cooperative_cycle_members cm', 'cm.cycle_id = c.id')
                ->where('cm.member_id', (int) $user['member']['id'])
                ->order_by('c.created_at', 'DESC')
                ->get_all();
        } else {
            $cycles = [];
        }

        $today = date('Y-m-d');
        foreach ($cycles as &$cycle) {
            $cycle_id = (int) $cycle['id'];
            $cycle['members'] = $db->table('cooperative_cycle_members cm')
                ->select('cm.member_id, cm.payout_order, m.member_number, m.first_name, m.last_name, cp.status AS payout_status, cp.payout_date')
                ->inner_join('members m', 'm.id = cm.member_id')
                ->left_join('cooperative_payouts cp', 'cp.cycle_id = cm.cycle_id AND cp.member_id = cm.member_id')
                ->where('cm.cycle_id', $cycle_id)
                ->order_by('cm.payout_order', 'ASC')
                ->get_all();
            $cycle['member_count'] = count($cycle['members']);
            $cycle['today_count'] = (int) $db->table('cooperative_contributions')
                ->where('cycle_id', $cycle_id)
                ->where('contribution_date', $today)
                ->count();
            $cycle['has_ready_payout'] = (int) $db->table('cooperative_payouts')
                ->where('cycle_id', $cycle_id)
                ->where('status', 'ready')
                ->count() > 0;
        }
        unset($cycle);

        $history_query = $db->table('cooperative_contributions cc')
            ->select('cc.id, cc.cycle_id, cc.member_id, cc.contribution_date, cc.amount, cc.reference_no, c.name AS cycle_name, m.member_number, m.first_name, m.last_name')
            ->inner_join('cooperative_cycles c', 'c.id = cc.cycle_id')
            ->inner_join('members m', 'm.id = cc.member_id');
        if (!$staff) {
            $history_query->where('cc.member_id', (int) ($user['member']['id'] ?? 0));
        }
        $contributions = $history_query
            ->order_by('cc.contribution_date', 'DESC')
            ->order_by('cc.id', 'DESC')
            ->get_all();

        $payout_query = $db->table('cooperative_payouts cp')
            ->select('cp.id, cp.cycle_id, cp.member_id, cp.payout_order, cp.payout_date, cp.amount, cp.reference_no, cp.status, cp.paid_at, c.name AS cycle_name, m.member_number, m.first_name, m.last_name')
            ->inner_join('cooperative_cycles c', 'c.id = cp.cycle_id')
            ->inner_join('members m', 'm.id = cp.member_id');
        if (!$staff) {
            $payout_query->where('cp.member_id', (int) ($user['member']['id'] ?? 0));
        }
        $payouts = $payout_query
            ->order_by('cp.payout_date', 'DESC')
            ->order_by('cp.id', 'DESC')
            ->get_all();

        $members = $staff ? $db->table('members')
            ->select('id, member_number, first_name, last_name')
            ->where('status', 'active')
            ->order_by('last_name', 'ASC')
            ->get_all() : [];

        $this->call->view('cooperative', [
            'user' => $user,
            'cycles' => $cycles,
            'members' => $members,
            'contributions' => $contributions,
            'payouts' => $payouts,
            'can_manage' => $staff,
            'error' => $_SESSION['cooperative_error'] ?? '',
            'success' => $_SESSION['cooperative_success'] ?? '',
            'page_title' => 'Daily Cooperative'
        ]);
        unset($_SESSION['cooperative_error'], $_SESSION['cooperative_success']);
    }

    public function create_cycle() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_staff();
        $name = trim((string) ($_POST['name'] ?? ''));
        $amount = self::money_to_cents(trim((string) ($_POST['daily_contribution'] ?? '')));
        $start_date = trim((string) ($_POST['start_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $start_date);
        $date_errors = DateTimeImmutable::getLastErrors();

        if ($name === '' || strlen($name) > 120 || $amount === false || $amount < 1
            || !$date || ($date_errors && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $start_date || $start_date < date('Y-m-d')) {
            $this->redirect_with_error('Enter a cycle name, positive daily amount, and a valid start date today or later.');
        }

        $inserted = $this->call->database()->table('cooperative_cycles')->insert([
            'name' => $name,
            'daily_contribution' => self::format_cents($amount),
            'start_date' => $start_date,
            'status' => 'draft',
            'created_by_user_id' => (int) $user['id']
        ]);
        if (!$inserted) {
            throw new RuntimeException('The cooperative cycle could not be created.');
        }
        $_SESSION['cooperative_success'] = 'Cycle created as a draft. Add members and payout order, then activate it.';
        header('Location: ' . BASE_URL . 'cooperative');
        exit;
    }

    public function add_member() {
        $this->call->library('coopwise_access');
        $this->coopwise_access->require_staff();
        $db = $this->call->database();
        $cycle_id = filter_input(INPUT_POST, 'cycle_id', FILTER_VALIDATE_INT);
        $member_id = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
        $order = filter_input(INPUT_POST, 'payout_order', FILTER_VALIDATE_INT);
        if (!$cycle_id || !$member_id || !$order || $order > 999) {
            $this->redirect_with_error('Choose an eligible cycle and member, and enter a positive payout order.');
        }

        $cycle = $db->table('cooperative_cycles')->where('id', $cycle_id)->get();
        $member = $db->table('members')->where('id', $member_id)->where('status', 'active')->get();
        $duplicate_member = $db->table('cooperative_cycle_members')
            ->where('cycle_id', $cycle_id)->where('member_id', $member_id)->count();
        $duplicate_order = $db->table('cooperative_cycle_members')
            ->where('cycle_id', $cycle_id)->where('payout_order', $order)->count();
        if (!$cycle || $cycle['status'] !== 'draft' || !$member || (int) $duplicate_member > 0 || (int) $duplicate_order > 0) {
            $this->redirect_with_error('Only active members can be added to a draft cycle; member and order must be unique.');
        }

        $inserted = $db->table('cooperative_cycle_members')->insert([
            'cycle_id' => $cycle_id,
            'member_id' => $member_id,
            'payout_order' => $order
        ]);
        if (!$inserted) {
            throw new RuntimeException('The cycle member could not be added.');
        }
        $_SESSION['cooperative_success'] = 'Member added to the draft cycle in the selected payout order.';
        header('Location: ' . BASE_URL . 'cooperative');
        exit;
    }

    public function activate_cycle() {
        $this->call->library('coopwise_access');
        $this->coopwise_access->require_staff();
        $db = $this->call->database();
        $cycle_id = filter_input(INPUT_POST, 'cycle_id', FILTER_VALIDATE_INT);
        $cycle = $cycle_id ? $db->table('cooperative_cycles')->where('id', $cycle_id)->get() : null;
        $members = $cycle ? $db->table('cooperative_cycle_members')
            ->where('cycle_id', $cycle_id)
            ->order_by('payout_order', 'ASC')
            ->get_all() : [];

        if (!$cycle || $cycle['status'] !== 'draft' || count($members) < 2) {
            $this->redirect_with_error('A draft cycle needs at least two members before it can be activated.');
        }
        foreach ($members as $index => $member) {
            if ((int) $member['payout_order'] !== $index + 1) {
                $this->redirect_with_error('Payout order must be consecutive, beginning at 1, before activation.');
            }
        }

        $db->table('cooperative_cycles')->where('id', $cycle_id)->update(['status' => 'active']);
        $_SESSION['cooperative_success'] = 'Cycle activated. Daily contributions can now be recorded.';
        header('Location: ' . BASE_URL . 'cooperative');
        exit;
    }

    public function remove_member() {
        $this->call->library('coopwise_access');
        $this->coopwise_access->require_staff();
        $db = $this->call->database();
        $cycle_id = filter_input(INPUT_POST, 'cycle_id', FILTER_VALIDATE_INT);
        $member_id = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
        $cycle = $cycle_id ? $db->table('cooperative_cycles')->where('id', $cycle_id)->get() : null;
        if (!$cycle || $cycle['status'] !== 'draft' || !$member_id) {
            $this->redirect_with_error('Members can only be removed from a draft cycle.');
        }

        $deleted = $db->table('cooperative_cycle_members')
            ->where('cycle_id', $cycle_id)
            ->where('member_id', $member_id)
            ->delete();
        if ($deleted !== 1) {
            $this->redirect_with_error('That member is not assigned to this draft cycle.');
        }
        $_SESSION['cooperative_success'] = 'Member removed. You can now reassign the payout order.';
        header('Location: ' . BASE_URL . 'cooperative');
        exit;
    }

    public function record_contribution() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_staff();
        $db = $this->call->database();
        $cycle_id = filter_input(INPUT_POST, 'cycle_id', FILTER_VALIDATE_INT);
        $member_id = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
        if (!$cycle_id || !$member_id) {
            $this->redirect_with_error('Choose an active cycle and an enrolled member.');
        }

        $db->transaction();
        try {
            $cycle = $db->table('cooperative_cycles')->where('id', $cycle_id)->get();
            $membership = $db->table('cooperative_cycle_members')
                ->where('cycle_id', $cycle_id)
                ->where('member_id', $member_id)
                ->get();
            if (!$cycle || $cycle['status'] !== 'active' || !$membership || $cycle['start_date'] > date('Y-m-d')) {
                throw new DomainException('Contributions are only accepted for enrolled members in an active cycle on or after its start date.');
            }
            $ready_payout = $db->table('cooperative_payouts')
                ->where('cycle_id', $cycle_id)
                ->where('status', 'ready')
                ->count();
            if ((int) $ready_payout > 0) {
                throw new DomainException('Staff must confirm the previous day’s payout before recording contributions for another day.');
            }
            $already_recorded = $db->table('cooperative_contributions')
                ->where('cycle_id', $cycle_id)
                ->where('member_id', $member_id)
                ->where('contribution_date', date('Y-m-d'))
                ->count();
            if ((int) $already_recorded > 0) {
                throw new DomainException('This member already has a contribution recorded for today.');
            }

            $inserted = $db->table('cooperative_contributions')->insert([
                'cycle_id' => $cycle_id,
                'member_id' => $member_id,
                'contribution_date' => date('Y-m-d'),
                'amount' => $cycle['daily_contribution'],
                'reference_no' => 'CW-COOP-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'recorded_by_user_id' => (int) $user['id']
            ]);
            if (!$inserted) {
                throw new RuntimeException('The daily contribution could not be recorded.');
            }

            $member_count = (int) $db->table('cooperative_cycle_members')
                ->where('cycle_id', $cycle_id)
                ->count();
            $today_count = (int) $db->table('cooperative_contributions')
                ->where('cycle_id', $cycle_id)
                ->where('contribution_date', date('Y-m-d'))
                ->count();
            if ($member_count > 0 && $today_count === $member_count) {
                $recipient = $db->table('cooperative_cycle_members cm')
                    ->select('cm.member_id, cm.payout_order, cp.id AS payout_id')
                    ->left_join('cooperative_payouts cp', 'cp.cycle_id = cm.cycle_id AND cp.member_id = cm.member_id')
                    ->where('cm.cycle_id', $cycle_id)
                    ->order_by('cm.payout_order', 'ASC')
                    ->get_all();
                $next_recipient = null;
                foreach ($recipient as $candidate) {
                    if ($candidate['payout_id'] === null) {
                        $next_recipient = $candidate;
                        break;
                    }
                }
                if (!$next_recipient) {
                    throw new RuntimeException('All payout turns have already been assigned before this cycle completed.');
                }
                $payout_amount = self::money_to_cents((string) $cycle['daily_contribution']) * $member_count;
                $payout_inserted = $db->table('cooperative_payouts')->insert([
                    'cycle_id' => $cycle_id,
                    'member_id' => (int) $next_recipient['member_id'],
                    'payout_order' => (int) $next_recipient['payout_order'],
                    'payout_date' => date('Y-m-d'),
                    'amount' => self::format_cents($payout_amount),
                    'reference_no' => 'CW-PAYOUT-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                    'status' => 'ready'
                ]);
                if (!$payout_inserted) {
                    throw new RuntimeException('The pooled payout could not be created.');
                }
            }
            $db->commit();
        } catch (DomainException $error) {
            $db->roll_back();
            $this->redirect_with_error($error->getMessage());
        } catch (Throwable $error) {
            $db->roll_back();
            error_log('CoopWise daily contribution failed: ' . $error->getMessage());
            throw $error;
        }

        $_SESSION['cooperative_success'] = $today_count === $member_count
            ? 'Contribution recorded. Today’s pooled payout is ready for staff confirmation.'
            : 'Daily contribution recorded.';
        header('Location: ' . BASE_URL . 'cooperative');
        exit;
    }

    public function mark_payout_paid() {
        $this->call->library('coopwise_access');
        $user = $this->coopwise_access->require_staff();
        $db = $this->call->database();
        $payout_id = filter_input(INPUT_POST, 'payout_id', FILTER_VALIDATE_INT);
        if (!$payout_id) {
            $this->redirect_with_error('Choose a valid payout.');
        }

        $db->transaction();
        try {
            $payout = $db->table('cooperative_payouts')->where('id', $payout_id)->get();
            if (!$payout || $payout['status'] !== 'ready') {
                throw new DomainException('This payout is not awaiting confirmation.');
            }
            $updated = $db->table('cooperative_payouts')
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
            $member_count = (int) $db->table('cooperative_cycle_members')
                ->where('cycle_id', (int) $payout['cycle_id'])
                ->count();
            $paid_count = (int) $db->table('cooperative_payouts')
                ->where('cycle_id', (int) $payout['cycle_id'])
                ->where('status', 'paid')
                ->count();
            if ($member_count > 0 && $paid_count === $member_count) {
                $db->table('cooperative_cycles')
                    ->where('id', (int) $payout['cycle_id'])
                    ->update(['status' => 'completed']);
            }
            $db->commit();
        } catch (DomainException $error) {
            $db->roll_back();
            $this->redirect_with_error($error->getMessage());
        } catch (Throwable $error) {
            $db->roll_back();
            error_log('CoopWise payout confirmation failed: ' . $error->getMessage());
            throw $error;
        }

        $_SESSION['cooperative_success'] = 'Payout marked paid. The next daily round can begin.';
        header('Location: ' . BASE_URL . 'cooperative');
        exit;
    }

    private function redirect_with_error($message) {
        $_SESSION['cooperative_error'] = $message;
        header('Location: ' . BASE_URL . 'cooperative');
        exit;
    }

    private static function money_to_cents(string $amount) {
        if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $amount)) {
            return false;
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private static function format_cents(int $amount) {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
