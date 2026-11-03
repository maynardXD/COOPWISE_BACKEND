<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiSavingsWithdrawals extends Controller
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
        $this->require_workflow_schema();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->request($user);
        }
        $this->api->require_method('GET');

        $member = $user['role'] === 'member' ? $this->member_profile($user) : null;
        if (!$member) {
            $this->require_staff($user);
        }

        $term = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? 'all'));
        $sort = trim((string) ($_GET['sort'] ?? 'newest'));
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
        if (strlen($term) > 100 || !in_array($status, ['all', 'pending', 'completed', 'rejected'], true)
            || !in_array($sort, ['newest', 'oldest', 'amount_desc', 'amount_asc', 'member'], true)
            || !$page || $page < 1) {
            $this->api->respond_error('Choose a valid withdrawal search, status, and sort option.', 422);
        }

        $from_sql = " FROM savings_withdrawal_requests w
                INNER JOIN members m ON m.id = w.member_id";
        $sql = "SELECT w.id, w.member_id, w.request_reference, w.amount, w.gcash_account_name,
                       w.gcash_number, w.status, w.receipt_storage_name, w.receipt_mime_type,
                       w.receipt_file_size, w.admin_note, w.requested_at, w.completed_at,
                       m.member_number, m.first_name, m.last_name
                {$from_sql}";
        $conditions = [];
        $params = [];
        if ($member) {
            $conditions[] = 'w.member_id = ?';
            $params[] = (int) $member['id'];
        } elseif ($status !== 'all') {
            $conditions[] = 'w.status = ?';
            $params[] = $status;
        }
        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $pattern = '%' . $escaped . '%';
            $conditions[] = '(w.request_reference LIKE ? OR m.member_number LIKE ? OR CONCAT(m.first_name, " ", m.last_name) LIKE ? OR w.gcash_account_name LIKE ?)';
            array_push($params, $pattern, $pattern, $pattern, $pattern);
        }
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $count_sql = 'SELECT COUNT(*)' . $from_sql;
        if ($conditions) {
            $count_sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $total = (int) $this->db->raw($count_sql, $params)->fetchColumn();
        $order_by = [
            'newest' => 'w.requested_at DESC, w.id DESC',
            'oldest' => 'w.requested_at ASC, w.id ASC',
            'amount_desc' => 'w.amount DESC, w.requested_at DESC',
            'amount_asc' => 'w.amount ASC, w.requested_at DESC',
            'member' => 'm.last_name ASC, m.first_name ASC, w.requested_at DESC'
        ][$sort];
        $per_page = 50;
        $pages = max(1, (int) ceil($total / $per_page));
        $page = min($page, $pages);
        $sql .= ' ORDER BY ' . $order_by . ' LIMIT ' . $per_page . ' OFFSET ' . (($page - 1) * $per_page);
        $requests = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($requests as &$request) {
            $request['has_receipt'] = $request['receipt_storage_name'] !== null;
            unset($request['receipt_storage_name']);
            if ($member) {
                unset($request['gcash_account_name'], $request['gcash_number']);
            }
        }
        unset($request);
        $this->api->respond([
            'withdrawals' => $requests,
            'page' => $page,
            'pages' => $pages,
            'total' => $total
        ]);
    }

    public function receipt($request_id = null)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $this->require_workflow_schema();
        $request = $this->db->table('savings_withdrawal_requests')
            ->where('id', (int) $request_id)
            ->get();
        if (!$request || !$request['receipt_storage_name']) {
            $this->api->respond_error('Withdrawal receipt not found.', 404);
        }
        if ($user['role'] === 'member' && (int) $request['member_id'] !== (int) $this->member_profile($user)['id']) {
            $this->api->respond_error('You may only view your own withdrawal receipts.', 403);
        }
        if ($user['role'] !== 'member') {
            $this->require_staff($user);
        }
        $this->send_private_image($request);
    }

    public function upload_receipt($request_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_workflow_schema();
        $this->require_staff($user);
        $id = filter_var($request_id, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            $this->api->respond_error('Choose a valid withdrawal request.', 422);
        }
        $file = $this->validated_receipt();
        $stored = $this->store_private_image($file);
        try {
            $request = $this->db->table('savings_withdrawal_requests')->where('id', $id)->get();
            if (!$request || $request['status'] !== 'pending') {
                throw new DomainException('A receipt can only be attached to a pending withdrawal request.');
            }
            $old_receipt = $request['receipt_storage_name'];
            $updated = $this->db->table('savings_withdrawal_requests')
                ->where('id', $id)
                ->where('status', 'pending')
                ->update([
                    'receipt_storage_name' => $stored['name'],
                    'receipt_mime_type' => $stored['mime'],
                    'receipt_file_size' => $file['size']
                ]);
            if ($updated !== 1) {
                throw new DomainException('This withdrawal request is no longer pending; the receipt was not attached.');
            }
            if ($old_receipt && preg_match('/^[a-f0-9]{64}\.(?:jpg|png|webp)$/D', $old_receipt)) {
                $old_path = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'private_uploads' . DIRECTORY_SEPARATOR . $old_receipt;
                if (is_file($old_path) && !unlink($old_path)) {
                    error_log('CoopWise could not remove a replaced withdrawal receipt: ' . $old_path);
                }
            }
        } catch (DomainException $error) {
            if (is_file($stored['path']) && !unlink($stored['path'])) {
                error_log('CoopWise could not remove an orphaned withdrawal receipt: ' . $stored['path']);
            }
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            if (is_file($stored['path']) && !unlink($stored['path'])) {
                error_log('CoopWise could not remove an orphaned withdrawal receipt: ' . $stored['path']);
            }
            error_log('CoopWise withdrawal receipt upload failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'GCash transfer receipt saved.']);
    }

    public function complete($request_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_workflow_schema();
        $this->require_staff($user);
        $id = filter_var($request_id, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            $this->api->respond_error('Choose a valid withdrawal request.', 422);
        }
        $note = trim((string) (($this->api->body())['admin_note'] ?? ''));
        if (strlen($note) > 500) {
            $this->api->respond_error('Notes must be 500 characters or fewer.', 422);
        }
        $this->db->transaction();
        try {
            $request = $this->db->raw(
                'SELECT status, receipt_storage_name FROM savings_withdrawal_requests WHERE id = ? FOR UPDATE',
                [$id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$request || $request['status'] !== 'pending' || !$request['receipt_storage_name']) {
                throw new DomainException('Attach a transfer receipt first; only pending requests can be completed.');
            }
            $updated = $this->db->table('savings_withdrawal_requests')
                ->where('id', $id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'completed',
                    'completed_by_user_id' => (int) $user['id'],
                    'completed_at' => date('Y-m-d H:i:s'),
                    'admin_note' => $note !== '' ? $note : null
                ]);
            if ($updated !== 1) {
                throw new DomainException('This withdrawal request has already been reviewed.');
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise withdrawal completion failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Withdrawal marked completed. The saved receipt is available in its history.']);
    }

    public function reject($request_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_workflow_schema();
        $this->require_staff($user);
        $id = filter_var($request_id, FILTER_VALIDATE_INT);
        $note = trim((string) (($this->api->body())['admin_note'] ?? ''));
        if (!$id || $id < 1 || $note === '' || strlen($note) > 500) {
            $this->api->respond_error('Choose a request and provide a rejection reason of no more than 500 characters.', 422);
        }

        $this->db->transaction();
        try {
            $request = $this->db->raw(
                'SELECT * FROM savings_withdrawal_requests WHERE id = ? FOR UPDATE',
                [$id]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$request || $request['status'] !== 'pending') {
                throw new DomainException('This withdrawal request is not pending.');
            }
            $this->db->raw('SELECT id FROM members WHERE id = ? FOR UPDATE', [(int) $request['member_id']])->fetch();
            $balance = $this->member_balance((int) $request['member_id']);
            $refund = self::money_to_cents((string) $request['amount']);
            $reference = 'CW-WD-REFUND-' . (int) $request['id'] . '-' . bin2hex(random_bytes(3));
            $inserted = $this->db->table('savings_transactions')->insert([
                'member_id' => (int) $request['member_id'],
                'type' => 'deposit',
                'amount' => self::format_cents($refund),
                'balance_after' => self::format_cents($balance + $refund),
                'reference_no' => $reference,
                'description' => 'Refund for rejected GCash withdrawal ' . $request['request_reference'],
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$inserted) {
                throw new RuntimeException('Withdrawal refund could not be recorded.');
            }
            $updated = $this->db->table('savings_withdrawal_requests')
                ->where('id', $id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'completed_by_user_id' => (int) $user['id'],
                    'completed_at' => date('Y-m-d H:i:s'),
                    'admin_note' => $note
                ]);
            if ($updated !== 1) {
                throw new DomainException('This withdrawal request has already been reviewed.');
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise withdrawal rejection failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond(['message' => 'Withdrawal request rejected and its reserved savings were returned to the member.']);
    }

    private function request(array $user)
    {
        $this->api->require_method('POST');
        $this->require_workflow_schema();
        $member = $this->member_profile($user);
        $data = $this->api->body();
        $amount = self::money_to_cents(trim((string) ($data['amount'] ?? '')));
        $gcash_name = trim((string) ($data['gcash_account_name'] ?? ''));
        $gcash_number = trim((string) ($data['gcash_number'] ?? ''));
        $confirm_number = trim((string) ($data['confirm_gcash_number'] ?? ''));
        if ($amount === false || $amount < 1 || $gcash_name === '' || strlen($gcash_name) > 120
            || !preg_match('/^09\d{9}$/D', $gcash_number) || $gcash_number !== $confirm_number) {
            $this->api->respond_error('Enter a valid amount, GCash account name, matching 11-digit GCash numbers, and confirm the details.', 422);
        }

        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM members WHERE id = ? FOR UPDATE', [(int) $member['id']])->fetch();
            $balance = $this->member_balance((int) $member['id']);
            if ($amount > $balance) {
                throw new DomainException('The withdrawal amount cannot exceed your available savings.');
            }
            $reference = 'CW-WD-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
            $request_id = $this->db->table('savings_withdrawal_requests')->insert([
                'member_id' => (int) $member['id'],
                'request_reference' => $reference,
                'savings_reference_no' => $reference,
                'amount' => self::format_cents($amount),
                'gcash_account_name' => $gcash_name,
                'gcash_number' => $gcash_number,
                'status' => 'pending'
            ]);
            if (!$request_id) {
                throw new RuntimeException('Withdrawal request could not be saved.');
            }
            $inserted = $this->db->table('savings_transactions')->insert([
                'member_id' => (int) $member['id'],
                'type' => 'withdrawal',
                'amount' => self::format_cents($amount),
                'balance_after' => self::format_cents($balance - $amount),
                'reference_no' => $reference,
                'description' => 'GCash withdrawal requested; awaiting staff transfer.',
                'created_by_user_id' => (int) $user['id']
            ]);
            if (!$inserted) {
                throw new RuntimeException('Withdrawal reservation could not be recorded in savings history.');
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 422);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise savings withdrawal request failed: ' . $error->getMessage());
            throw $error;
        }
        $this->api->respond([
            'message' => 'Withdrawal request submitted. The amount is reserved from your savings while staff reviews and sends it.',
            'id' => (int) $request_id,
            'request_reference' => $reference
        ], 201);
    }

    private function validated_receipt()
    {
        if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['receipt']['tmp_name'])) {
            $this->api->respond_error('Attach a GCash transfer receipt image before continuing.', 422);
        }
        $file = $_FILES['receipt'];
        $image_info = @getimagesize($file['tmp_name']);
        $mime = $image_info['mime'] ?? '';
        if (!$image_info || (int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->api->respond_error('Transfer receipt must be a JPEG, PNG, or WebP image no larger than 5 MB.', 422);
        }
        return ['tmp_name' => $file['tmp_name'], 'size' => (int) $file['size'], 'mime' => $mime];
    }

    private function store_private_image(array $file)
    {
        $directory = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'private_uploads';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private upload storage could not be created.');
        }
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$file['mime']];
        $name = bin2hex(random_bytes(32)) . '.' . $extension;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new RuntimeException('GCash transfer receipt could not be stored.');
        }
        return ['name' => $name, 'path' => $path, 'mime' => $file['mime']];
    }

    private function send_private_image(array $request)
    {
        if (!preg_match('/^[a-f0-9]{64}\.(?:jpg|png|webp)$/D', $request['receipt_storage_name'])) {
            throw new RuntimeException('The stored transfer receipt filename is invalid.');
        }
        $path = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'private_uploads' . DIRECTORY_SEPARATOR . $request['receipt_storage_name'];
        if (!is_file($path) || !is_readable($path)) {
            $this->api->respond_error('The stored transfer receipt is unavailable.', 404);
        }
        header('Content-Type: ' . $request['receipt_mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="gcash-transfer-receipt"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
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

    private function require_workflow_schema()
    {
        $table = $this->db->raw(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['savings_withdrawal_requests']
        )->fetchColumn();
        if ((int) $table !== 1) {
            error_log('CoopWise withdrawal schema is incomplete; savings_withdrawal_requests table is missing.');
            $this->api->respond_error(
                'GCash savings withdrawals are not configured in the database yet. Apply database/coopwise_financial_workflows_upgrade.sql, then retry.',
                503
            );
        }
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
