<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiCheckout extends Controller
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

    public function transactions()
    {
        $user = $this->authenticated_user();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->create_checkout();
        }
        $this->api->require_method('GET');
        $this->require_payment_schema();

        $member = $user['role'] === 'member' ? $this->member_profile($user) : null;
        if (!$member) {
            $this->require_staff($user);
        }
        $query = $this->db->table('payment_transactions p')
            ->select('p.id, p.member_id, p.purpose, p.amount, p.currency, p.provider_fee, p.net_amount, p.reference_no,
                      p.loan_id, p.cycle_id, p.subscription_id, p.status, p.checkout_url,
                      p.review_note, p.created_at, p.paid_at, p.applied_at,
                      m.member_number, m.first_name, m.last_name')
            ->inner_join('members m', 'm.id = p.member_id');
        if ($member) {
            $query->where('p.member_id', (int) $member['id']);
        }
        $this->api->respond(['transactions' => $query->order_by('p.created_at', 'DESC')->get_all()]);
    }

    public function create_checkout()
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->api->rate_limit('checkout:' . (int) $user['id'], 10, 900);
        if ($user['role'] !== 'member') {
            $this->api->respond_error('Only members can start a payment checkout.', 403);
        }
        $member = $this->member_profile($user);
        $this->require_payment_schema();
        $this->require_xendit_config();
        $frontend_url = rtrim((string) getenv('COOPWISE_FRONTEND_URL'), '/');
        $frontend_scheme = strtolower((string) parse_url($frontend_url, PHP_URL_SCHEME));
        $allowed_schemes = strtolower((string) getenv('APP_ENV')) === 'production' ? ['https'] : ['https', 'http'];
        if (!filter_var($frontend_url, FILTER_VALIDATE_URL) || !in_array($frontend_scheme, $allowed_schemes, true)) {
            $this->api->respond_error(
                'Online payments are not configured correctly. Please contact cooperative staff; no payment was started.',
                503
            );
        }
        $data = $this->api->body();
        $purpose = trim((string) ($data['purpose'] ?? ''));
        $amount = self::money_to_cents(trim((string) ($data['amount'] ?? '')));
        $loan_id = filter_var($data['loan_id'] ?? null, FILTER_VALIDATE_INT);
        $cycle_id = filter_var($data['cycle_id'] ?? null, FILTER_VALIDATE_INT);
        $subscription_id = filter_var($data['subscription_id'] ?? null, FILTER_VALIDATE_INT);

        if (!in_array($purpose, ['savings', 'loan', 'paluwagan', 'share_capital'], true)
            || $amount === false || $amount < 100 || $amount > 100000000) {
            $this->api->respond_error('Choose a payment purpose and an amount from PHP 1.00 to PHP 1,000,000.00.', 422);
        }
        if (($purpose === 'loan' && (!$loan_id || $cycle_id || $subscription_id))
            || ($purpose === 'paluwagan' && (!$cycle_id || $loan_id || $subscription_id))
            || ($purpose === 'share_capital' && (!$subscription_id || $loan_id || $cycle_id))
            || ($purpose === 'savings' && ($loan_id || $cycle_id || $subscription_id))) {
            $this->api->respond_error('Choose only the account or cycle related to this payment.', 422);
        }

        $reference = 'CW-PAY-' . strtoupper(bin2hex(random_bytes(6)));
        $transaction_id = null;
        $business_date = $purpose === 'paluwagan' ? date('Y-m-d') : null;
        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM members WHERE id = ? FOR UPDATE', [(int) $member['id']])->fetch();
            if ($purpose === 'loan') {
                $this->reserve_loan_amount((int) $loan_id, (int) $member['id'], $amount);
            } elseif ($purpose === 'paluwagan') {
                $this->reserve_paluwagan_contribution((int) $cycle_id, (int) $member['id'], $amount, $business_date);
            } elseif ($purpose === 'share_capital') {
                $this->reserve_share_capital_amount((int) $subscription_id, (int) $member['id'], $amount);
            }
            $transaction_id = $this->db->table('payment_transactions')->insert([
                'member_id' => (int) $member['id'],
                'purpose' => $purpose,
                'amount' => self::format_cents($amount),
                'currency' => 'PHP',
                'reference_no' => $reference,
                'loan_id' => $loan_id ?: null,
                'cycle_id' => $cycle_id ?: null,
                'subscription_id' => $subscription_id ?: null,
                'business_date' => $business_date,
                'status' => 'pending'
            ]);
            if (!$transaction_id) {
                throw new RuntimeException('The payment transaction could not be created.');
            }
            $this->db->commit();
        } catch (DomainException $error) {
            $this->db->roll_back();
            $this->api->respond_error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->db->roll_back();
            error_log('CoopWise payment reservation failed: ' . $error->getMessage());
            throw $error;
        }

        try {
            $xendit_started_at = microtime(true);
            $payload = [
                'reference_id' => $reference,
                'session_type' => 'PAY',
                'mode' => 'PAYMENT_LINK',
                'amount' => $amount / 100,
                'currency' => 'PHP',
                'country' => 'PH',
                'success_return_url' => $frontend_url . '/?payment=success&transaction=' . (int) $transaction_id,
                'cancel_return_url' => $frontend_url . '/?payment=cancelled&transaction=' . (int) $transaction_id
            ];
            $xendit_request_started_at = microtime(true);
            $response = $this->xendit_request('POST', '/sessions', $payload);
            $xendit_request_elapsed = microtime(true) - $xendit_request_started_at;
            if ($xendit_request_elapsed >= 2) {
                error_log(sprintf('CoopWise Xendit session request took %.2f seconds.', $xendit_request_elapsed));
            }
            $session_id = (string) ($response['payment_session_id'] ?? '');
            $checkout_url = (string) ($response['payment_link_url'] ?? '');
            if ($session_id === '' || !filter_var($checkout_url, FILTER_VALIDATE_URL)
                || !in_array(parse_url($checkout_url, PHP_URL_SCHEME), ['https'], true)) {
                throw new RuntimeException('Xendit did not return a valid hosted checkout session.');
            }
            $updated = $this->db->table('payment_transactions')
                ->where('id', (int) $transaction_id)
                ->where('status', 'pending')
                ->update(['provider_checkout_id' => $session_id, 'checkout_url' => $checkout_url]);
            if ($updated !== 1) {
                throw new RuntimeException('The checkout URL could not be attached to its payment record.');
            }
        } catch (Throwable $error) {
            $this->db->table('payment_transactions')
                ->where('id', (int) $transaction_id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'needs_review',
                    'review_note' => 'Checkout setup did not return a confirmed result. Contact staff before retrying.'
                ]);
            $elapsed_seconds = isset($xendit_started_at) ? microtime(true) - $xendit_started_at : 0;
            error_log(sprintf(
                'CoopWise Xendit checkout creation failed after %.2f seconds: %s',
                $elapsed_seconds,
                $error->getMessage()
            ));
            $this->api->respond_error('Xendit checkout could not be started. Please try again later.', 502);
        }

        $this->api->respond([
            'message' => 'Checkout is ready. Your account will update after Xendit confirms the payment.',
            'transaction_id' => (int) $transaction_id,
            'checkout_url' => $checkout_url
        ], 201);
    }

    public function sync($transaction_id = null)
    {
        $this->api->require_method('POST');
        $user = $this->authenticated_user();
        $this->require_payment_schema();
        $transaction_id = filter_var($transaction_id, FILTER_VALIDATE_INT);
        if (!$transaction_id || $transaction_id < 1) {
            $this->api->respond_error('Choose a valid payment transaction.', 422);
        }
        $query = $this->db->table('payment_transactions')
            ->where('id', (int) $transaction_id);
        if ($user['role'] === 'member') {
            $member = $this->member_profile($user);
            $query->where('member_id', (int) $member['id']);
        } else {
            $this->require_staff($user);
        }
        $transaction = $query->get();
        if (!$transaction) {
            $this->api->respond_error('Payment transaction not found.', 404);
        }
        if (!in_array($transaction['status'], ['pending', 'needs_review'], true) || !$transaction['provider_checkout_id']) {
            $this->api->respond(['message' => 'Payment status is ' . $transaction['status'] . '.', 'status' => $transaction['status']]);
        }
        $this->require_xendit_config();
        try {
            $session = $this->xendit_request(
                'GET',
                '/sessions/' . rawurlencode($transaction['provider_checkout_id'])
            );
            $status = strtoupper((string) ($session['status'] ?? ''));
            if ($status === 'COMPLETED') {
                [$paid_amount, $paid_currency] = self::checkout_amount_and_currency($session);
                $this->apply_paid_checkout(
                    (int) $transaction['id'],
                    (string) $transaction['provider_checkout_id'],
                    $paid_amount,
                    $paid_currency,
                    !empty($session['payment_request_id']) ? (string) $session['payment_request_id'] : null,
                    (string) ($session['reference_id'] ?? '')
                );
            } elseif (in_array($status, ['EXPIRED', 'CANCELED'], true)) {
                $this->db->table('payment_transactions')
                    ->where('id', (int) $transaction['id'])
                    ->where('status', 'pending')
                    ->update(['status' => 'failed']);
            }
        } catch (Throwable $error) {
            error_log('CoopWise Xendit status check failed for transaction ' . (int) $transaction_id . ': ' . $error->getMessage());
            $this->api->respond_error('Xendit could not confirm the status right now. Please try again shortly.', 502);
        }
        $updated = $this->db->table('payment_transactions')
            ->where('id', (int) $transaction_id)
            ->get();
        $this->api->respond([
            'message' => 'Payment status checked.',
            'status' => $updated['status'] ?? 'pending'
        ]);
    }

    public function webhook()
    {
        $this->api->require_method('POST');
        $raw_body = file_get_contents('php://input');
        $callback_token = trim((string) ($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? ''));
        if (!is_string($raw_body) || $raw_body === '' || strlen($raw_body) > 1048576
            || !$this->verify_webhook_token($callback_token)) {
            $this->api->respond_error('Invalid Xendit webhook token.', 401);
        }
        $this->require_payment_schema();
        $this->require_xendit_config();
        $event = json_decode($raw_body, true);
        if (!is_array($event)) {
            $this->api->respond_error('Invalid Xendit webhook event.', 400);
        }
        $event_type = (string) ($event['event'] ?? '');
        $event_data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $event_id = (string) ($event['id'] ?? $event_data['event_id'] ?? '');
        if ($event_id === '') {
            $event_id = 'sha256-' . hash('sha256', $raw_body);
        }
        if (strlen($event_id) > 120 || $event_type === '' || strlen($event_type) > 100) {
            $this->api->respond_error('Invalid Xendit webhook event.', 400);
        }
        if ($this->db->table('payment_webhook_events')->where('provider_event_id', $event_id)->count() > 0) {
            $this->api->respond(['message' => 'Webhook event was already processed.']);
        }

        try {
            if ($event_type === 'payment_session.completed') {
                $provider_reference = (string) ($event_data['reference_id'] ?? '');
                $session_id = (string) (
                    $event_data['payment_session_id']
                    ?? $event_data['id']
                    ?? ''
                );
                $transaction_query = $this->db->table('payment_transactions');
                if ($provider_reference !== '') {
                    $transaction_query->where('reference_no', $provider_reference);
                } elseif ($session_id !== '') {
                    $transaction_query->where('provider_checkout_id', $session_id);
                } else {
                    throw new RuntimeException('Completed webhook did not include a payment reference or session identifier.');
                }
                $transaction = $transaction_query->get();
                if (!$transaction) {
                    error_log('CoopWise acknowledged Xendit completed event without a matching payment transaction: ' . $event_id);
                } elseif (empty($transaction['provider_checkout_id'])
                    || ($session_id !== '' && !hash_equals((string) $transaction['provider_checkout_id'], $session_id))) {
                    throw new RuntimeException('Completed Xendit session does not match a CoopWise transaction.');
                } else {
                    $session = $this->xendit_request(
                        'GET',
                        '/sessions/' . rawurlencode($transaction['provider_checkout_id'])
                    );
                    if (strtoupper((string) ($session['status'] ?? '')) !== 'COMPLETED') {
                        throw new RuntimeException('Xendit has not confirmed the payment session as completed.');
                    }
                    [$paid_amount, $paid_currency] = self::checkout_amount_and_currency($session);
                    $this->apply_paid_checkout(
                        (int) $transaction['id'],
                        (string) $transaction['provider_checkout_id'],
                        $paid_amount,
                        $paid_currency,
                        !empty($session['payment_request_id']) ? (string) $session['payment_request_id'] : null,
                        (string) ($session['reference_id'] ?? '')
                    );
                }
            } elseif ($event_type === 'payment_session.expired') {
                $session_id = (string) ($event_data['payment_session_id'] ?? $event_data['id'] ?? '');
                $expired_query = $this->db->table('payment_transactions');
                if ($session_id !== '') {
                    $expired_query->where('provider_checkout_id', $session_id);
                } elseif (!empty($event_data['reference_id'])) {
                    $expired_query->where('reference_no', (string) $event_data['reference_id']);
                }
                if ($session_id !== '' || !empty($event_data['reference_id'])) {
                    $expired_query->where('status', 'pending')->update(['status' => 'failed']);
                }
            }
            $this->db->table('payment_webhook_events')->insert([
                'provider_event_id' => $event_id,
                'event_type' => $event_type,
                'payload_sha256' => hash('sha256', $raw_body)
            ]);
        } catch (Throwable $error) {
            error_log('CoopWise Xendit webhook processing failed for event ' . $event_id . ': ' . $error->getMessage());
            $this->api->respond_error('Webhook processing failed; Xendit should retry this event.', 503);
        }
        $this->api->respond(['message' => 'Webhook processed.']);
    }

    private function reserve_loan_amount(int $loan_id, int $member_id, int $amount)
    {
        $loan = $this->db->raw('SELECT id, member_id, status, current_balance FROM loans WHERE id = ? FOR UPDATE', [$loan_id])
            ->fetch(PDO::FETCH_ASSOC);
        if (!$loan || (int) $loan['member_id'] !== $member_id || !in_array($loan['status'], ['approved', 'active'], true)) {
            throw new DomainException('Choose one of your approved or active loans.');
        }
        $balance = self::money_to_cents((string) $loan['current_balance']);
        $pending = $this->db->raw(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM payment_transactions
             WHERE loan_id = ? AND status IN ('pending', 'needs_review')",
            [$loan_id]
        )->fetch(PDO::FETCH_ASSOC);
        if ($balance === false || $amount > $balance - self::money_to_cents((string) ($pending['total'] ?? '0.00'))) {
            throw new DomainException('This payment exceeds the loan balance that is still available for checkout.');
        }
    }

    private function reserve_paluwagan_contribution(int $cycle_id, int $member_id, int $amount, string $business_date)
    {
        $cycle = $this->db->raw('SELECT id, status, start_date, daily_contribution FROM cooperative_cycles WHERE id = ? FOR UPDATE', [$cycle_id])
            ->fetch(PDO::FETCH_ASSOC);
        $enrollment = $this->db->table('cooperative_cycle_members')
            ->where('cycle_id', $cycle_id)
            ->where('member_id', $member_id)
            ->get();
        if (!$cycle || $cycle['status'] !== 'active' || $cycle['start_date'] > $business_date || !$enrollment) {
            throw new DomainException('Choose one of your active Paluwagan cycles.');
        }
        if ($amount !== self::money_to_cents((string) $cycle['daily_contribution'])) {
            throw new DomainException('Paluwagan payments must match the cycle’s daily contribution.');
        }
        if ($this->db->table('cooperative_payouts')
            ->where('cycle_id', $cycle_id)
            ->where('status', 'ready')
            ->count() > 0) {
            throw new DomainException('Staff must confirm the pending Paluwagan payout before the next contribution round.');
        }
        $exists = $this->db->table('cooperative_contributions')
            ->where('cycle_id', $cycle_id)
            ->where('member_id', $member_id)
            ->where('contribution_date', $business_date)
            ->get();
        $pending = $this->db->table('payment_transactions')
            ->where('cycle_id', $cycle_id)
            ->where('member_id', $member_id)
            ->where('business_date', $business_date)
            ->in('status', ['pending', 'needs_review'])
            ->count();
        if ($exists || $pending > 0) {
            throw new DomainException('A contribution for this member and day is already recorded or awaiting payment.');
        }
    }

    private function reserve_share_capital_amount(int $subscription_id, int $member_id, int $amount)
    {
        $subscription = $this->db->raw(
            'SELECT id, member_id, status, subscribed_amount FROM share_capital_subscriptions WHERE id = ? FOR UPDATE',
            [$subscription_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$subscription || (int) $subscription['member_id'] !== $member_id || $subscription['status'] !== 'active') {
            throw new DomainException('Choose one of your active share-capital subscriptions.');
        }
        $paid = $this->db->raw(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0) AS total
             FROM share_capital_transactions WHERE subscription_id = ?",
            [$subscription_id]
        )->fetch(PDO::FETCH_ASSOC);
        $pending = $this->db->raw(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM payment_transactions
             WHERE subscription_id = ? AND status IN ('pending', 'needs_review')",
            [$subscription_id]
        )->fetch(PDO::FETCH_ASSOC);
        $remaining = self::money_to_cents((string) $subscription['subscribed_amount'])
            - self::money_to_cents((string) ($paid['total'] ?? '0.00'))
            - self::money_to_cents((string) ($pending['total'] ?? '0.00'));
        if ($amount > $remaining) {
            throw new DomainException('This payment exceeds the unpaid amount of the subscription.');
        }
    }

    private function apply_paid_checkout(
        int $transaction_id,
        string $session_id,
        int $provider_amount,
        string $currency,
        ?string $provider_payment_id,
        string $provider_reference = '',
        ?int $provider_fee = null,
        ?int $net_amount = null
    )
    {
        $this->db->transaction();
        try {
            $payment = $this->db->raw('SELECT * FROM payment_transactions WHERE id = ? FOR UPDATE', [$transaction_id])
                ->fetch(PDO::FETCH_ASSOC);
            if (!$payment || ($payment['provider_checkout_id'] !== null && $payment['provider_checkout_id'] !== $session_id)
                || $provider_amount !== self::money_to_cents((string) $payment['amount'])
                || $currency !== 'PHP'
                || $provider_reference === ''
                || !hash_equals((string) $payment['reference_no'], $provider_reference)) {
                throw new DomainException('The provider payment does not match the stored CoopWise transaction.');
            }
            if ($payment['applied_at'] !== null && $payment['status'] === 'paid') {
                $this->db->commit();
                return;
            }
            if (!in_array($payment['status'], ['pending', 'needs_review'], true)) {
                throw new DomainException('The CoopWise payment transaction is not pending.');
            }
            if ($payment['provider_checkout_id'] === null) {
                $this->db->table('payment_transactions')
                    ->where('id', $transaction_id)
                    ->update(['provider_checkout_id' => $session_id]);
            }

            try {
                switch ($payment['purpose']) {
                    case 'savings':
                        $this->apply_savings_deposit($payment);
                        break;
                    case 'loan':
                        $this->apply_loan_payment($payment);
                        break;
                    case 'paluwagan':
                        $this->apply_paluwagan_payment($payment);
                        break;
                    case 'share_capital':
                        $this->apply_share_capital_payment($payment);
                        break;
                    default:
                        throw new DomainException('Unsupported CoopWise payment purpose.');
                }
            } catch (DomainException $error) {
                $this->db->table('payment_transactions')
                    ->where('id', $transaction_id)
                    ->update([
                        'status' => 'needs_review',
                        'provider_payment_id' => $provider_payment_id,
                        'provider_fee' => $provider_fee === null ? null : self::format_cents($provider_fee),
                        'net_amount' => $net_amount === null ? null : self::format_cents($net_amount),
                        'paid_at' => date('Y-m-d H:i:s'),
                        'review_note' => $error->getMessage()
                    ]);
                $this->write_activity_log(null, 'payments', 'payment_needs_review', [
                    'transaction_id' => $transaction_id,
                    'purpose' => $payment['purpose']
                ]);
                $this->db->commit();
                return;
            }
            $updated = $this->db->table('payment_transactions')
                ->where('id', $transaction_id)
                ->in('status', ['pending', 'needs_review'])
                ->update([
                    'status' => 'paid',
                    'provider_payment_id' => $provider_payment_id,
                    'provider_fee' => $provider_fee === null ? null : self::format_cents($provider_fee),
                    'net_amount' => $net_amount === null ? null : self::format_cents($net_amount),
                    'paid_at' => date('Y-m-d H:i:s'),
                    'applied_at' => date('Y-m-d H:i:s'),
                    'review_note' => null
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('The confirmed payment status could not be saved.');
            }
            $this->write_activity_log(null, 'payments', 'payment_confirmed', [
                'transaction_id' => $transaction_id,
                'purpose' => $payment['purpose'],
                'amount' => (string) $payment['amount']
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    private function apply_savings_deposit(array $payment)
    {
        $this->db->raw('SELECT id FROM members WHERE id = ? FOR UPDATE', [(int) $payment['member_id']])->fetch();
        $sums = $this->db->raw(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) AS deposits,
                COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END), 0) AS withdrawals
             FROM savings_transactions WHERE member_id = ?",
            [(int) $payment['member_id']]
        )->fetch(PDO::FETCH_ASSOC);
        $balance = self::money_to_cents((string) $sums['deposits']) - self::money_to_cents((string) $sums['withdrawals']);
        $inserted = $this->db->table('savings_transactions')->insert([
            'member_id' => (int) $payment['member_id'],
            'type' => 'deposit',
            'amount' => $payment['amount'],
            'balance_after' => self::format_cents($balance + self::money_to_cents((string) $payment['amount'])),
            'reference_no' => $payment['reference_no'],
            'description' => 'Xendit-confirmed savings deposit.',
            'created_by_user_id' => null
        ]);
        if (!$inserted) {
            throw new RuntimeException('The confirmed savings deposit could not be recorded.');
        }

    }

    private function apply_loan_payment(array $payment)
    {
        $loan = $this->db->raw('SELECT * FROM loans WHERE id = ? FOR UPDATE', [(int) $payment['loan_id']])
            ->fetch(PDO::FETCH_ASSOC);
        $amount = self::money_to_cents((string) $payment['amount']);
        $balance = $loan ? self::money_to_cents((string) $loan['current_balance']) : false;
        if (!$loan || (int) $loan['member_id'] !== (int) $payment['member_id']
            || !in_array($loan['status'], ['approved', 'active'], true)
            || $balance === false || $amount > $balance) {
            throw new DomainException('The paid loan repayment no longer matches an eligible loan balance.');
        }
        $inserted = $this->db->table('loan_payments')->insert([
            'loan_id' => (int) $loan['id'],
            'member_id' => (int) $payment['member_id'],
            'paid_by_user_id' => null,
            'amount_paid' => $payment['amount'],
            'payment_method' => 'xendit',
            'reference_no' => $payment['reference_no'],
            'payment_date' => date('Y-m-d H:i:s')
        ]);
        if (!$inserted) {
            throw new RuntimeException('The confirmed loan payment could not be recorded.');
        }
        $remaining = $amount;
        $schedules = $this->db->table('loan_schedules')
            ->where('loan_id', (int) $loan['id'])
            ->in('status', ['pending', 'overdue'])
            ->order_by('due_date', 'ASC')
            ->get_all();
        foreach ($schedules as $schedule) {
            if ($remaining < 1) {
                break;
            }
            $unpaid = max(0, self::money_to_cents((string) $schedule['total_due'])
                - self::money_to_cents((string) $schedule['paid_amount']));
            $applied = min($remaining, $unpaid);
            $paid = self::money_to_cents((string) $schedule['paid_amount']) + $applied;
            $this->db->table('loan_schedules')->where('id', (int) $schedule['id'])->update([
                'paid_amount' => self::format_cents($paid),
                'status' => $paid >= self::money_to_cents((string) $schedule['total_due']) ? 'paid' : $schedule['status']
            ]);
            $remaining -= $applied;
        }
        $new_balance = $balance - $amount;
        $this->db->table('loans')->where('id', (int) $loan['id'])->update([
            'current_balance' => self::format_cents($new_balance),
            'total_paid' => self::format_cents(self::money_to_cents((string) $loan['total_paid']) + $amount),
            'status' => $new_balance === 0 ? 'completed' : 'active'
        ]);
    }

    private function apply_paluwagan_payment(array $payment)
    {
        $cycle = $this->db->raw('SELECT * FROM cooperative_cycles WHERE id = ? FOR UPDATE', [(int) $payment['cycle_id']])
            ->fetch(PDO::FETCH_ASSOC);
        $membership = $this->db->table('cooperative_cycle_members')
            ->where('cycle_id', (int) $payment['cycle_id'])
            ->where('member_id', (int) $payment['member_id'])
            ->get();
        $date = (string) $payment['business_date'];
        if (!$cycle || $cycle['status'] !== 'active' || !$membership || $cycle['start_date'] > $date) {
            throw new DomainException('The Paluwagan cycle is no longer eligible for this paid contribution.');
        }
        if ($this->db->table('cooperative_payouts')
            ->where('cycle_id', (int) $payment['cycle_id'])
            ->where('status', 'ready')
            ->count() > 0) {
            throw new DomainException('The previous Paluwagan payout is still awaiting staff confirmation.');
        }
        if ($this->db->table('cooperative_contributions')
            ->where('cycle_id', (int) $payment['cycle_id'])
            ->where('member_id', (int) $payment['member_id'])
            ->where('contribution_date', $date)
            ->count() > 0) {
            throw new DomainException('A contribution for this member and date is already recorded.');
        }
        $inserted = $this->db->table('cooperative_contributions')->insert([
            'cycle_id' => (int) $payment['cycle_id'],
            'member_id' => (int) $payment['member_id'],
            'contribution_date' => $date,
            'amount' => $payment['amount'],
            'reference_no' => $payment['reference_no'],
            'recorded_by_user_id' => null
        ]);
        if (!$inserted) {
            throw new RuntimeException('The confirmed Paluwagan contribution could not be recorded.');
        }

        $member_count = (int) $this->db->table('cooperative_cycle_members')
            ->where('cycle_id', (int) $payment['cycle_id'])->count();
        $today_count = (int) $this->db->table('cooperative_contributions')
            ->where('cycle_id', (int) $payment['cycle_id'])
            ->where('contribution_date', $date)
            ->count();
        if ($member_count < 1 || $today_count !== $member_count
            || $this->db->table('cooperative_payouts')
                ->where('cycle_id', (int) $payment['cycle_id'])->where('status', 'ready')->count() > 0) {
            return;
        }
        $assigned = $this->db->table('cooperative_cycle_members cm')
            ->select('cm.member_id, cm.payout_order, cp.id AS payout_id')
            ->left_join('cooperative_payouts cp', 'cp.cycle_id = cm.cycle_id AND cp.member_id = cm.member_id')
            ->where('cm.cycle_id', (int) $payment['cycle_id'])
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
            throw new DomainException('The Paluwagan cycle has no unassigned payout recipient.');
        }
        $amount = self::money_to_cents((string) $cycle['daily_contribution']) * $member_count;
        $payout_id = $this->db->table('cooperative_payouts')->insert([
            'cycle_id' => (int) $payment['cycle_id'],
            'member_id' => (int) $recipient['member_id'],
            'payout_order' => (int) $recipient['payout_order'],
            'payout_date' => $date,
            'amount' => self::format_cents($amount),
            'reference_no' => 'CW-PAYOUT-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
            'status' => 'ready'
        ]);
        if (!$payout_id) {
            throw new RuntimeException('The pooled Paluwagan payout could not be created.');
        }
    }

    private function apply_share_capital_payment(array $payment)
    {
        $subscription = $this->db->raw(
            'SELECT id, member_id, subscribed_amount, status FROM share_capital_subscriptions WHERE id = ? FOR UPDATE',
            [(int) $payment['subscription_id']]
        )->fetch(PDO::FETCH_ASSOC);
        $amount = self::money_to_cents((string) $payment['amount']);
        $paid = $this->db->raw(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0) AS total
             FROM share_capital_transactions WHERE subscription_id = ?",
            [(int) $payment['subscription_id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$subscription || (int) $subscription['member_id'] !== (int) $payment['member_id']
            || $subscription['status'] !== 'active'
            || self::money_to_cents((string) $subscription['subscribed_amount']) - self::money_to_cents((string) ($paid['total'] ?? '0.00')) < $amount) {
            throw new DomainException('The paid amount no longer fits the share-capital subscription.');
        }
        $balance_after = self::money_to_cents((string) ($paid['total'] ?? '0.00')) + $amount;
        $inserted = $this->db->table('share_capital_transactions')->insert([
            'member_id' => (int) $payment['member_id'],
            'subscription_id' => (int) $payment['subscription_id'],
            'transaction_type' => 'payment',
            'amount' => $payment['amount'],
            'balance_after' => self::format_cents($balance_after),
            'transaction_reference' => $payment['reference_no'],
            'payment_method' => 'xendit',
            'created_by_user_id' => null
        ]);
        if (!$inserted) {
            throw new RuntimeException('The confirmed share-capital contribution could not be recorded.');
        }
        if ($balance_after >= self::money_to_cents((string) $subscription['subscribed_amount'])) {
            $this->db->table('share_capital_subscriptions')
                ->where('id', (int) $subscription['id'])
                ->update(['status' => 'completed']);
        }
    }

    private static function checkout_amount_and_currency(array $session)
    {
        $amount = $session['amount'] ?? null;
        if (!is_numeric($amount)) {
            throw new RuntimeException('Xendit payment session did not include a valid amount.');
        }
        $amount_string = number_format((float) $amount, 2, '.', '');
        $amount_cents = self::money_to_cents($amount_string);
        $currency = strtoupper((string) ($session['currency'] ?? ''));
        if ($amount_cents === false || $currency === '') {
            throw new RuntimeException('Xendit payment session did not include valid payment details.');
        }
        return [$amount_cents, $currency];
    }

    private function verify_webhook_token(string $provided_token)
    {
        $expected_token = (string) getenv('XENDIT_WEBHOOK_TOKEN');
        return $expected_token !== '' && $provided_token !== '' && hash_equals($expected_token, $provided_token);
    }

    private function require_xendit_config()
    {
        $key = (string) getenv('XENDIT_SECRET_KEY');
        $mode = self::xendit_mode();
        if (!in_array($mode, ['test', 'live'], true)) {
            $this->api->respond_error('XENDIT_MODE must be either test or live.', 503);
        }
        $expected_prefix = $mode === 'live' ? 'xnd_production_' : 'xnd_development_';
        if (!str_starts_with($key, $expected_prefix) || getenv('XENDIT_WEBHOOK_TOKEN') === '') {
            $this->api->respond_error('Xendit is not configured for ' . $mode . ' mode. Add the matching secret key and webhook token to the backend environment.', 503);
        }
    }

    private function require_payment_schema()
    {
        $tables = $this->db->raw(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('payment_transactions', 'payment_webhook_events')"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) !== 2) {
            error_log('CoopWise payment schema is missing; apply database/coopwise_xendit_email_upgrade.sql.');
            $this->api->respond_error(
                'Online payments are not configured in the database yet. Apply database/coopwise_xendit_email_upgrade.sql, then retry.',
                503
            );
        }
    }

    private static function xendit_mode()
    {
        return strtolower(trim((string) (getenv('XENDIT_MODE') ?: 'test')));
    }

    private function xendit_request(string $method, string $path, ?array $payload = null)
    {
        $key = (string) getenv('XENDIT_SECRET_KEY');
        $body = $payload !== null
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            : null;
        if ($payload !== null && $body === false) {
            throw new RuntimeException('Xendit request could not be encoded.');
        }
        $headers = [
            'Authorization: Basic ' . base64_encode($key . ':'),
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        if ($method === 'POST') {
            $headers[] = 'Idempotency-Key: ' . hash('sha256', (string) ($payload['reference_id'] ?? $path));
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 20,
            'ignore_errors' => true
        ], 'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]]);
        $response_body = file_get_contents('https://api.xendit.co' . $path, false, $context);
        $status_line = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $status_line, $matches);
        $status = (int) ($matches[1] ?? 0);
        $decoded = is_string($response_body) ? json_decode($response_body, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new RuntimeException('Xendit API request failed with HTTP ' . ($status ?: 'unknown') . '.');
        }
        return $decoded;
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

    private function write_activity_log(?int $user_id, string $module, string $action, array $details)
    {
        $id = $this->db->table('activity_logs')->insert([
            'user_id' => $user_id,
            'module' => $module,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_SLASHES)
        ]);
        if (!$id) {
            throw new RuntimeException('The payment audit record could not be saved.');
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
