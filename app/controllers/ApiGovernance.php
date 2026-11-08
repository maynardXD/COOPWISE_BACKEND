<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiGovernance extends Controller
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
        $this->require_schema();

        $meetings = $this->db->table('cooperative_meetings')
            ->select('id, title, description, meeting_date, status, created_at, closed_at')
            ->order_by('created_at', 'DESC')
            ->get_all();
        foreach ($meetings as &$meeting) {
            $meeting_id = (int) $meeting['id'];
            $results_visible = !$member || $meeting['status'] === 'closed';
            $meeting['options'] = $this->db->raw(
                'SELECT o.id, o.option_text, COUNT(v.id) AS votes
                 FROM cooperative_meeting_options o
                 LEFT JOIN cooperative_meeting_votes v ON v.option_id = o.id AND v.meeting_id = o.meeting_id
                 WHERE o.meeting_id = ?
                 GROUP BY o.id, o.option_text
                 ORDER BY o.id ASC',
                [$meeting_id]
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($meeting['options'] as &$option) {
                $option['votes'] = $results_visible ? (int) $option['votes'] : null;
            }
            unset($option);
            $meeting['results_visible'] = $results_visible;
            $meeting['vote_count'] = $results_visible
                ? array_sum(array_column($meeting['options'], 'votes'))
                : null;
            $meeting['my_vote_option_id'] = $member
                ? (int) ($this->db->table('cooperative_meeting_votes')
                    ->select('option_id')
                    ->where('meeting_id', $meeting_id)
                    ->where('member_id', (int) $member['id'])
                    ->get()['option_id'] ?? 0)
                : null;
        }
        unset($meeting);

        $audit_logs = [];
        if (!$member) {
            $audit_logs = $this->db->table('activity_logs a')
                ->select('a.id, a.module, a.action, a.details, a.created_at, u.full_name AS actor_name, u.role AS actor_role')
                ->left_join('users u', 'u.id = a.user_id')
                ->order_by('a.created_at', 'DESC')
                ->limit(100)
                ->get_all();
        }

        $this->api->respond(['meetings' => $meetings, 'audit_logs' => $audit_logs]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_schema();
        $data = $this->api->body();
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $date = trim((string) ($data['meeting_date'] ?? ''));
        $raw_options = $data['options'] ?? null;
        if ($title === '' || strlen($title) > 160 || strlen($description) > 1000
            || !is_array($raw_options) || count($raw_options) < 2 || count($raw_options) > 6) {
            $this->api->respond_error('Enter a title, an optional description of up to 1000 characters, and 2 to 6 voting choices.', 422);
        }
        if ($date !== '' && !self::valid_date($date)) {
            $this->api->respond_error('Enter a valid meeting date.', 422);
        }
        $options = [];
        foreach ($raw_options as $raw_option) {
            if (!is_string($raw_option)) {
                $this->api->respond_error('Every voting choice must be text.', 422);
            }
            $option = trim($raw_option);
            if ($option === '' || strlen($option) > 200) {
                $this->api->respond_error('Each voting choice must contain 1 to 200 characters.', 422);
            }
            $options[] = $option;
        }
        if (count(array_unique(array_map('strtolower', $options))) !== count($options)) {
            $this->api->respond_error('Voting choices must be unique.', 422);
        }

        $this->db->transaction();
        try {
            $meeting_id = $this->db->table('cooperative_meetings')->insert([
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'meeting_date' => $date !== '' ? $date : null,
                'status' => 'open',
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$meeting_id) {
                throw new RuntimeException('The cooperative meeting could not be created.');
            }
            foreach ($options as $option) {
                if (!$this->db->table('cooperative_meeting_options')->insert([
                    'meeting_id' => (int) $meeting_id,
                    'option_text' => $option
                ])) {
                    throw new RuntimeException('A voting choice could not be saved.');
                }
            }
            $this->log_activity((int) $user['id'], 'governance', 'meeting_created', [
                'meeting_id' => (int) $meeting_id,
                'title' => $title
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise governance meeting creation failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Meeting and member poll created.', 'id' => (int) $meeting_id], 201);
    }

    public function vote($meeting_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $member = $this->member_profile($user);
        $this->require_schema();
        $meeting_id = filter_var($meeting_id, FILTER_VALIDATE_INT);
        $option_id = filter_var(($this->api->body())['option_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$meeting_id || !$option_id) {
            $this->api->respond_error('Choose a meeting and one voting choice.', 422);
        }

        $this->db->transaction();
        try {
            $meeting = $this->db->raw(
                'SELECT id, status FROM cooperative_meetings WHERE id = ? FOR UPDATE',
                [(int) $meeting_id]
            )->fetch(PDO::FETCH_ASSOC);
            $option = $this->db->table('cooperative_meeting_options')
                ->where('id', (int) $option_id)
                ->where('meeting_id', (int) $meeting_id)
                ->get();
            if (!$meeting || $meeting['status'] !== 'open' || !$option) {
                throw new DomainException('This poll is closed or the selected choice is unavailable.');
            }
            if ((int) $this->db->table('cooperative_meeting_votes')
                ->where('meeting_id', (int) $meeting_id)
                ->where('member_id', (int) $member['id'])
                ->count() > 0) {
                throw new DomainException('You have already voted in this poll.');
            }
            $inserted = $this->db->table('cooperative_meeting_votes')->insert([
                'meeting_id' => (int) $meeting_id,
                'option_id' => (int) $option_id,
                'member_id' => (int) $member['id']
            ]);
            if (!$inserted) {
                throw new RuntimeException('Your vote could not be recorded.');
            }
            $this->log_activity((int) $user['id'], 'governance', 'member_voted', [
                'meeting_id' => (int) $meeting_id
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise member vote failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Your vote was recorded. Votes are private; only totals are shown.']);
    }

    public function close($meeting_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $this->require_schema();
        $meeting_id = filter_var($meeting_id, FILTER_VALIDATE_INT);
        if (!$meeting_id || $meeting_id < 1) {
            $this->api->respond_error('Choose a valid meeting.', 422);
        }
        $this->db->transaction();
        try {
            $updated = $this->db->table('cooperative_meetings')
                ->where('id', (int) $meeting_id)
                ->where('status', 'open')
                ->update(['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s')]);
            if ($updated !== 1) {
                throw new DomainException('This poll is already closed or does not exist.');
            }
            $this->log_activity((int) $user['id'], 'governance', 'meeting_closed', [
                'meeting_id' => (int) $meeting_id
            ]);
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise poll closure failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Poll closed. Final results are shown to members.']);
    }

    private function require_schema()
    {
        $tables = $this->db->raw(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('cooperative_meetings', 'cooperative_meeting_options', 'cooperative_meeting_votes', 'activity_logs')"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) !== 4) {
            error_log('CoopWise governance schema is missing; apply database/coopwise_governance_upgrade.sql.');
            $this->api->respond_error(
                'Member meetings and voting are not set up yet. Apply database/coopwise_governance_upgrade.sql, then retry.',
                503
            );
        }
    }

    private function log_activity(int $user_id, string $module, string $action, array $details)
    {
        $id = $this->db->table('activity_logs')->insert([
            'user_id' => $user_id,
            'module' => $module,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_SLASHES)
        ]);
        if (!$id) {
            throw new RuntimeException('The governance audit record could not be saved.');
        }
    }

    private function authenticated_user()
    {
        $claims = $this->api->require_jwt();
        $user = $this->User_model->find_identity((int) $claims['sub']);
        if (!$user || $user['status'] !== 'active') {
            $this->api->respond_error('Account is unavailable.', 401);
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

    private static function valid_date(string $date)
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))
            && $parsed->format('Y-m-d') === $date;
    }
}
