<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiPayments extends Controller
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

    public function submissions()
    {
        $user = $this->authenticated_user();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->submit($user);
        }
        $this->api->require_method('GET');

        $query = $this->db->table('payment_submissions ps')
            ->select('ps.id, ps.member_id, ps.loan_id, ps.cycle_id, ps.category, ps.amount, ps.reference_no, ps.storage_name, ps.status, ps.review_note, ps.created_at, ps.reviewed_at, l.loan_code, c.name AS cycle_name, m.member_number, m.first_name, m.last_name')
            ->inner_join('members m', 'm.id = ps.member_id')
            ->left_join('loans l', 'l.id = ps.loan_id')
            ->left_join('cooperative_cycles c', 'c.id = ps.cycle_id');
        if ($user['role'] === 'member') {
            $query->where('ps.member_id', (int) $this->member_profile($user)['id']);
        } else {
            $this->require_staff($user);
        }
        $submissions = $query->order_by('ps.created_at', 'DESC')->get_all();
        foreach ($submissions as &$submission) {
            $submission['has_screenshot'] = !empty($submission['storage_name']);
            unset($submission['storage_name']);
        }
        unset($submission);
        $this->api->respond(['submissions' => $submissions]);
    }

    public function screenshot($submission_id = null)
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $submission = $this->db->table('payment_submissions')
            ->where('id', (int) $submission_id)
            ->get();
        if (!$submission || !$submission['storage_name']) {
            $this->api->respond_error('Payment screenshot not found.', 404);
        }
        if ($user['role'] === 'member' && (int) $submission['member_id'] !== (int) $this->member_profile($user)['id']) {
            $this->api->respond_error('You may only view your own payment screenshot.', 403);
        }
        if (in_array($user['role'], ['admin', 'officer'], true) === false && $user['role'] !== 'member') {
            $this->api->respond_error('This account does not have a supported application role.', 403);
        }
        $this->send_private_image($submission);
    }

    public function review($submission_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $submission_id = (int) $submission_id;
        $data = $this->api->body();
        $decision = $data['decision'] ?? '';
        $note = trim((string) ($data['review_note'] ?? ''));
        if ($submission_id < 1 || !in_array($decision, ['approve', 'reject'], true) || strlen($note) > 500) {
            $this->api->respond_error('Provide a valid payment decision and a note no longer than 500 characters.', 422);
        }

        $submission = $this->db->table('payment_submissions')->where('id', $submission_id)->get();
        if (!$submission || $submission['status'] !== 'pending') {
            $this->api->respond_error('This payment submission has already been reviewed or does not exist.', 409);
        }
        $updated = $this->db->table('payment_submissions')
            ->where('id', $submission_id)
            ->where('status', 'pending')
            ->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'review_note' => $note !== '' ? $note : null,
                'reviewed_by_user_id' => (int) $user['id'],
                'reviewed_at' => date('Y-m-d H:i:s')
            ]);
        if ($updated !== 1) {
            $this->api->respond_error('This payment submission has already been reviewed.', 409);
        }
        $this->api->respond([
            'message' => $decision === 'approve'
                ? 'Payment evidence marked as verified. Record the received funds in the appropriate ledger.'
                : 'Payment evidence rejected.'
        ]);
    }

    public function admin_search()
    {
        $this->api->require_method('GET');
        $user = $this->authenticated_user();
        $this->require_staff($user);
        $term = trim((string) ($_GET['q'] ?? ''));
        if (strlen($term) < 2 || strlen($term) > 100) {
            $this->api->respond_error('Search with 2 to 100 characters.', 422);
        }
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        $members = $this->db->raw(
            "SELECT m.id, m.member_number, m.first_name, m.middle_name, m.last_name,
                    m.email, m.contact_number, m.address, m.birth_date, m.status,
                    COALESCE(u.role, 'unlinked') AS account_role
             FROM members m
             LEFT JOIN users u ON u.id = m.user_id
             WHERE m.member_number LIKE ? OR CONCAT(m.first_name, ' ', m.last_name) LIKE ?
                OR m.email LIKE ? OR m.contact_number LIKE ?
             ORDER BY m.last_name ASC, m.first_name ASC
             LIMIT 10",
            [$pattern, $pattern, $pattern, $pattern]
        )->fetchAll(PDO::FETCH_ASSOC);
        $loans = $this->db->raw(
            "SELECT l.id, l.loan_code, l.status, l.principal_amount, l.created_at,
                    m.member_number, m.first_name, m.last_name, lt.name AS loan_type_name
             FROM loans l
             INNER JOIN members m ON m.id = l.member_id
             INNER JOIN loan_types lt ON lt.id = l.loan_type_id
             WHERE l.loan_code LIKE ? OR m.member_number LIKE ?
                OR CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR lt.name LIKE ?
             ORDER BY l.created_at DESC
             LIMIT 10",
            [$pattern, $pattern, $pattern, $pattern]
        )->fetchAll(PDO::FETCH_ASSOC);
        $payments = $this->db->raw(
            "SELECT ps.id, ps.category, ps.amount, ps.reference_no, ps.status, ps.created_at,
                    m.member_number, m.first_name, m.last_name
             FROM payment_submissions ps
             INNER JOIN members m ON m.id = ps.member_id
             WHERE ps.reference_no LIKE ? OR m.member_number LIKE ?
                OR CONCAT(m.first_name, ' ', m.last_name) LIKE ?
             ORDER BY ps.created_at DESC
             LIMIT 10",
            [$pattern, $pattern, $pattern]
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->api->respond(['members' => $members, 'loans' => $loans, 'payments' => $payments]);
    }

    private function submit(array $user)
    {
        $this->api->require_method('POST');
        if ($user['role'] !== 'member') {
            $this->api->respond_error('Only members can submit payment evidence.', 403);
        }
        $member = $this->member_profile($user);
        $data = $_POST ?: $this->api->body();
        $category = trim((string) ($data['category'] ?? ''));
        $amount = self::money_to_cents(trim((string) ($data['amount'] ?? '')));
        $reference = trim((string) ($data['reference_no'] ?? ''));
        $loan_id = filter_var($data['loan_id'] ?? null, FILTER_VALIDATE_INT);
        $cycle_id = filter_var($data['cycle_id'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($category, ['cooperative', 'paluwagan', 'loan'], true)
            || $amount === false || $amount < 1 || strlen($reference) > 100) {
            $this->api->respond_error('Choose a payment type, enter a positive amount, and provide a valid reference number.', 422);
        }
        $file = $this->validated_screenshot();
        if (!$file && $reference === '') {
            $this->api->respond_error('Upload a payment screenshot or enter the transaction reference number.', 422);
        }
        if ($category === 'loan') {
            $loan = $loan_id ? $this->db->table('loans')->where('id', $loan_id)->get() : null;
            if (!$loan || (int) $loan['member_id'] !== (int) $member['id']
                || !in_array($loan['status'], ['approved', 'active'], true)) {
                $this->api->respond_error('Choose one of your approved or active loans.', 422);
            }
            $cycle_id = null;
        } elseif ($category === 'paluwagan') {
            $cycle = $cycle_id ? $this->db->table('cooperative_cycles')->where('id', $cycle_id)->get() : null;
            $enrollment = $cycle
                ? $this->db->table('cooperative_cycle_members')->where('cycle_id', $cycle_id)->where('member_id', (int) $member['id'])->get()
                : null;
            if (!$cycle || $cycle['status'] !== 'active' || !$enrollment) {
                $this->api->respond_error('Choose one of your active Paluwagan cycles.', 422);
            }
            $loan_id = null;
        } else {
            $loan_id = null;
            $cycle_id = null;
        }

        $stored = $file ? $this->store_private_image($file) : null;
        try {
            $id = $this->db->table('payment_submissions')->insert([
                'member_id' => (int) $member['id'],
                'loan_id' => $loan_id ?: null,
                'cycle_id' => $cycle_id ?: null,
                'category' => $category,
                'amount' => self::format_cents($amount),
                'reference_no' => $reference !== '' ? $reference : null,
                'storage_name' => $stored['name'] ?? null,
                'mime_type' => $stored['mime'] ?? null,
                'file_size' => $file['size'] ?? null,
                'status' => 'pending'
            ]);
            if (!$id) {
                throw new RuntimeException('Payment evidence could not be saved.');
            }
        } catch (Throwable $error) {
            if ($stored && is_file($stored['path']) && !unlink($stored['path'])) {
                error_log('CoopWise could not remove an orphaned payment screenshot: ' . $stored['path']);
            }
            throw $error;
        }
        $this->api->respond([
            'message' => 'Payment evidence submitted for staff review. It is not yet a recorded or confirmed payment.',
            'id' => (int) $id
        ], 201);
    }

    private function validated_screenshot()
    {
        if (!isset($_FILES['screenshot']) || $_FILES['screenshot']['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $file = $_FILES['screenshot'];
        $image_info = $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])
            ? @getimagesize($file['tmp_name'])
            : false;
        $mime = $image_info['mime'] ?? '';
        if (!$image_info || (int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->api->respond_error('Payment proof must be a JPEG, PNG, or WebP image no larger than 5 MB.', 422);
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
            throw new RuntimeException('Payment screenshot could not be stored.');
        }
        return ['name' => $name, 'path' => $path, 'mime' => $file['mime']];
    }

    private function send_private_image(array $file)
    {
        if (!preg_match('/^[a-f0-9]{64}\.(?:jpg|png|webp)$/D', $file['storage_name'])) {
            throw new RuntimeException('The stored screenshot filename is invalid.');
        }
        $path = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'private_uploads' . DIRECTORY_SEPARATOR . $file['storage_name'];
        if (!is_file($path) || !is_readable($path)) {
            $this->api->respond_error('The stored screenshot is unavailable.', 404);
        }
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="payment-screenshot"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
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
