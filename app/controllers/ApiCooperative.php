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
        $required_tables = [
            'cooperative_cycles',
            'cooperative_cycle_members',
            'cooperative_contributions',
            'cooperative_payouts'
        ];
        $required_columns = [
            'cooperative_cycles' => ['id', 'name', 'daily_contribution', 'start_date', 'status', 'created_at'],
            'cooperative_cycle_members' => ['cycle_id', 'member_id', 'payout_order'],
            'cooperative_contributions' => ['id', 'cycle_id', 'member_id', 'contribution_date', 'amount', 'reference_no', 'recorded_by_user_id'],
            'cooperative_payouts' => ['id', 'cycle_id', 'member_id', 'payout_order', 'payout_date', 'amount', 'reference_no', 'status', 'paid_at']
        ];
        $schema = $this->db->raw(
            'SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?, ?)',
            $required_tables
        )->fetchAll(PDO::FETCH_ASSOC);
        $found_tables = array_unique(array_column($schema, 'table_name'));
        $missing_tables = array_values(array_diff($required_tables, $found_tables));
        $connection_info = $this->db->raw(
            'SELECT DATABASE() AS active_database, CURRENT_USER() AS authenticated_account,
                    USER() AS connection_account, @@hostname AS server_hostname, @@port AS server_port'
        )->fetch(PDO::FETCH_ASSOC);
        $missing_columns = [];
        foreach ($required_columns as $table => $columns) {
            $found_columns = array_column(
                array_filter($schema, static function ($row) use ($table) {
                    return $row['table_name'] === $table;
                }),
                'column_name'
            );
            foreach (array_diff($columns, $found_columns) as $column) {
                $missing_columns[] = $table . '.' . $column;
            }
        }

        if ($missing_tables || $missing_columns) {
            $missing_tables = [];
            $missing_columns = [];
            $probe_errors = [];
            foreach ($required_columns as $table => $columns) {
                $quoted_columns = array_map(static function ($column) {
                    return '`' . $column . '`';
                }, $columns);
                try {
                    $this->db->raw(
                        'SELECT ' . implode(', ', $quoted_columns) . ' FROM `' . $table . '` LIMIT 0'
                    );
                } catch (PDOException $exception) {
                    $error_info = $exception->errorInfo;
                    $sql_state = (string) ($error_info[0] ?? $exception->getCode());
                    $driver_code = (int) ($error_info[1] ?? 0);
                    if ($sql_state === '42S02' || $driver_code === 1146) {
                        $missing_tables[] = $table;
                    } elseif ($sql_state === '42S22' || $driver_code === 1054) {
                        preg_match("/Unknown column '([^']+)'/i", $exception->getMessage(), $matches);
                        $missing_columns[] = $table . '.' . ($matches[1] ?? 'unknown');
                    } else {
                        throw $exception;
                    }
                    $probe_errors[] = $exception->getMessage();
                }
            }
            $missing_tables = array_values(array_unique($missing_tables));
            $missing_columns = array_values(array_unique($missing_columns));
        }

        if ($missing_tables) {
            error_log(
                'CoopWise cooperative API schema is incomplete; MySQL connection: '
                . json_encode($connection_info)
                . '; tables visible in information_schema: '
                . implode(', ', $found_tables)
                . '; missing tables: '
                . implode(', ', $missing_tables)
                . (empty($probe_errors) ? '' : '; direct checks: ' . implode(' | ', $probe_errors))
            );
            $this->api->respond_error(
                'The API database is missing the Daily Paluwagan tables. Check Render DB_HOST, DB_DATABASE, and DB_USERNAME against the database where the migration was applied.',
                503
            );
        }
        if ($missing_columns) {
            error_log(
                'CoopWise cooperative API schema is incomplete; MySQL connection: '
                . json_encode($connection_info)
                . '; missing columns: '
                . implode(', ', $missing_columns)
                . (empty($probe_errors) ? '' : '; direct checks: ' . implode(' | ', $probe_errors))
            );
            $this->api->respond_error(
                'The API database has incomplete Daily Paluwagan tables. Check Render DB_HOST, DB_DATABASE, and DB_USERNAME against the database where the migration was applied.',
                503
            );
        }
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
        if ($member) {
            $contribution_query->where('cc.member_id', (int) $member['id']);
        }
        $contribution_pagination = $this->api->pagination_params(50, 100, 'contribution_page');
        $contributions = $contribution_query
            ->order_by('cc.contribution_date', 'DESC')
            ->order_by('cc.id', 'DESC')
            ->limit($contribution_pagination['offset'], $contribution_pagination['per_page'] + 1)
            ->get_all();
        $contributions_has_more = count($contributions) > $contribution_pagination['per_page'];
        if ($contributions_has_more) {
            array_pop($contributions);
        }

        $payout_query = $this->db->table('cooperative_payouts cp')
            ->select('cp.id, cp.cycle_id, cp.member_id, cp.payout_order, cp.payout_date, cp.amount, cp.reference_no, cp.status, cp.paid_at, c.name AS cycle_name, m.member_number, m.first_name, m.last_name')
            ->inner_join('cooperative_cycles c', 'c.id = cp.cycle_id')
            ->inner_join('members m', 'm.id = cp.member_id');
        if ($member) {
            $payout_query->where('cp.member_id', (int) $member['id']);
        }
        $payout_pagination = $this->api->pagination_params(50, 100, 'payout_page');
        $payouts = $payout_query
            ->order_by('cp.payout_date', 'DESC')
            ->order_by('cp.id', 'DESC')
            ->limit($payout_pagination['offset'], $payout_pagination['per_page'] + 1)
            ->get_all();
        $payouts_has_more = count($payouts) > $payout_pagination['per_page'];
        if ($payouts_has_more) {
            array_pop($payouts);
        }

        $this->api->respond([
            'cycles' => $cycles,
            'contributions' => $contributions,
            'contributions_pagination' => [
                'page' => $contribution_pagination['page'],
                'per_page' => $contribution_pagination['per_page'],
                'has_more' => $contributions_has_more
            ],
            'payouts' => $payouts,
            'payouts_pagination' => [
                'page' => $payout_pagination['page'],
                'per_page' => $payout_pagination['per_page'],
                'has_more' => $payouts_has_more
            ],
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
        $raw_member_ids = $data['member_ids'] ?? (isset($data['member_id']) ? [$data['member_id']] : []);
        if (!$cycle_id || !is_array($raw_member_ids) || count($raw_member_ids) < 1 || count($raw_member_ids) > 999) {
            $this->api->respond_error('Choose a draft cycle and between one and 999 members.', 422);
        }
        $member_ids = [];
        foreach ($raw_member_ids as $raw_member_id) {
            $member_id = filter_var($raw_member_id, FILTER_VALIDATE_INT);
            if (!$member_id || $member_id < 1) {
                $this->api->respond_error('Every selected member must be valid.', 422);
            }
            $member_ids[] = (int) $member_id;
        }
        if (count(array_unique($member_ids)) !== count($member_ids)) {
            $this->api->respond_error('A member may be selected only once.', 422);
        }

        $this->db->transaction();
        try {
            $cycle = $this->db->raw('SELECT id, status FROM cooperative_cycles WHERE id = ? FOR UPDATE', [(int) $cycle_id])
                ->fetch(PDO::FETCH_ASSOC);
            if (!$cycle || $cycle['status'] !== 'draft') {
                throw new DomainException('Members can only be enrolled in a draft cycle.');
            }
            $existing_members = $this->db->table('cooperative_cycle_members')
                ->where('cycle_id', (int) $cycle_id)
                ->order_by('payout_order', 'ASC')
                ->get_all();
            $current_count = count($existing_members);
            if ($current_count + count($member_ids) > 999) {
                throw new DomainException('A cycle cannot have more than 999 members.');
            }
            foreach ($existing_members as $index => $existing_member) {
                $expected_order = $index + 1;
                if ((int) $existing_member['payout_order'] !== $expected_order) {
                    $this->db->table('cooperative_cycle_members')
                        ->where('cycle_id', (int) $cycle_id)
                        ->where('member_id', (int) $existing_member['member_id'])
                        ->update(['payout_order' => $expected_order]);
                }
            }
            foreach ($member_ids as $member_id) {
                $member = $this->db->table('members')
                    ->where('id', $member_id)
                    ->where('status', 'active')
                    ->get();
                if (!$member) {
                    throw new DomainException('Only active members can join a draft cycle.');
                }
                if ((int) $this->db->table('cooperative_cycle_members')
                    ->where('cycle_id', (int) $cycle_id)
                    ->where('member_id', $member_id)
                    ->count() > 0) {
                    throw new DomainException('One or more selected members are already enrolled.');
                }
            }

            for ($index = count($member_ids) - 1; $index > 0; $index--) {
                $random_index = random_int(0, $index);
                [$member_ids[$index], $member_ids[$random_index]] = [$member_ids[$random_index], $member_ids[$index]];
            }
            foreach ($member_ids as $index => $member_id) {
                $inserted = $this->db->table('cooperative_cycle_members')->insert([
                    'cycle_id' => (int) $cycle_id,
                    'member_id' => $member_id,
                    'payout_order' => $current_count + $index + 1
                ]);
                if (!$inserted) {
                    throw new RuntimeException('A selected member could not be enrolled in the cycle.');
                }
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise batch cycle enrollment failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond([
            'message' => count($member_ids) . ' member(s) added with a randomized payout order.',
            'member_ids_in_payout_order' => $member_ids
        ], 201);
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
        $remaining_members = $this->db->table('cooperative_cycle_members')
            ->where('cycle_id', (int) $cycle_id)
            ->order_by('payout_order', 'ASC')
            ->get_all();
        foreach ($remaining_members as $index => $remaining_member) {
            $expected_order = $index + 1;
            if ((int) $remaining_member['payout_order'] !== $expected_order) {
                $this->db->table('cooperative_cycle_members')
                    ->where('cycle_id', (int) $cycle_id)
                    ->where('member_id', (int) $remaining_member['member_id'])
                    ->update(['payout_order' => $expected_order]);
            }
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
        $this->db->transaction();
        try {
            $updated = $this->db->table('cooperative_cycles')
                ->where('id', $cycle_id)
                ->where('status', 'draft')
                ->update(['status' => 'active']);
            if ($updated !== 1) {
                throw new DomainException('This cycle is no longer a draft and could not be activated.');
            }
            $this->write_activity_log((int) $user['id'], 'cooperative', 'cycle_activated', [
                'cycle_id' => (int) $cycle_id
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise cycle activation failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Cycle activated.']);
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
            $payout = $this->db->raw(
                'SELECT * FROM cooperative_payouts WHERE id = ? FOR UPDATE',
                [(int) $payout_id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$payout || $payout['status'] !== 'ready') {
                throw new DomainException('This payout is not awaiting confirmation.');
            }
            $this->db->raw(
                'SELECT id FROM members WHERE id = ? FOR UPDATE',
                [(int) $payout['member_id']]
            )->fetch();
            $payout_amount = self::money_to_cents((string) $payout['amount']);
            $balance = $this->db->raw(
                "SELECT COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) AS balance
                 FROM savings_transactions WHERE member_id = ?",
                [(int) $payout['member_id']]
            )->fetch(PDO::FETCH_ASSOC);
            $balance_cents = self::money_to_cents((string) ($balance['balance'] ?? '0.00'));
            if ($payout_amount === false || $balance_cents === false) {
                throw new RuntimeException('The Paluwagan payout balance could not be calculated.');
            }
            $ledger_reference = 'CW-PALUWAGAN-' . strtoupper((string) $payout['reference_no']);
            $existing_credit = $this->db->raw(
                'SELECT amount FROM savings_transactions
                 WHERE member_id = ? AND type = ? AND reference_no = ? LIMIT 1',
                [(int) $payout['member_id'], 'deposit', $ledger_reference]
            )->fetch(PDO::FETCH_ASSOC);
            if ($existing_credit) {
                if (self::money_to_cents((string) $existing_credit['amount']) !== $payout_amount) {
                    throw new RuntimeException('A conflicting Paluwagan payout credit already exists.');
                }
            } else {
                $credit_id = $this->db->table('savings_transactions')->insert([
                    'member_id' => (int) $payout['member_id'],
                    'type' => 'deposit',
                    'amount' => self::format_cents($payout_amount),
                    'balance_after' => self::format_cents($balance_cents + $payout_amount),
                    'reference_no' => $ledger_reference,
                    'description' => 'Paluwagan payout credited to available member balance',
                    'created_by_user_id' => (int) $user['id']
                ]);
                if (!$credit_id) {
                    throw new RuntimeException('The Paluwagan payout could not be credited to the member balance.');
                }
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
            $this->write_activity_log((int) $user['id'], 'cooperative', 'payout_confirmed', [
                'payout_id' => (int) $payout_id,
                'cycle_id' => (int) $payout['cycle_id'],
                'member_id' => (int) $payout['member_id'],
                'amount_credited' => self::format_cents($payout_amount),
                'ledger_reference' => $ledger_reference
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise API payout confirmation failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Paluwagan payout marked paid and added to the member’s available balance.']);
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

    private function write_activity_log(int $user_id, string $module, string $action, array $details)
    {
        $id = $this->db->table('activity_logs')->insert([
            'user_id' => $user_id,
            'module' => $module,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_SLASHES)
        ]);
        if (!$id) {
            throw new RuntimeException('The cooperative audit record could not be saved.');
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
