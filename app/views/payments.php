<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<div class="panel">
    <?php if (!empty($_SESSION['payment_error'])): ?>
        <div class="alert"><?= htmlspecialchars($_SESSION['payment_error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php unset($_SESSION['payment_error']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['payment_success'])): ?>
        <div class="success-message"><?= htmlspecialchars($_SESSION['payment_success'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php unset($_SESSION['payment_success']); ?>
    <?php endif; ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
        <h3 style="margin: 0;">Payment Recording</h3>
        <?php if (!empty($can_record)): ?><span class="badge">Staff payment entry</span><?php endif; ?>
    </div>

    <?php if (!empty($can_record)): ?>
        <form method="post" action="<?= BASE_URL ?>payments/record" class="form-grid" style="margin-bottom: 18px;">
            <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label for="loan_id">Member loan</label>
                <select id="loan_id" name="loan_id" required>
                    <option value="">Choose an active loan</option>
                    <?php foreach (($active_loans ?? []) as $loan): ?>
                        <option value="<?= (int) $loan['id'] ?>">
                            <?= htmlspecialchars($loan['member_number'] . ' — ' . $loan['first_name'] . ' ' . $loan['last_name'] . ' — ' . $loan['loan_code'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="amount">Amount</label>
                <input id="amount" type="number" step="0.01" name="amount" min="0.01" required>
            </div>
            <div>
                <label for="method">Payment Method</label>
                <select id="method" name="method" required>
                    <option value="cash">Cash</option>
                    <option value="bank">Bank</option>
                    <option value="gcash">GCash</option>
                    <option value="check">Check</option>
                </select>
            </div>
            <div style="align-self: end;">
                <button class="btn btn-success" type="submit">Save Payment</button>
            </div>
        </form>
    <?php else: ?>
        <p class="muted">You can review your payment history here. Contact an officer to record a payment.</p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Payment Date</th>
                <th>Member</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Reference</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($payments ?? []) as $payment): ?>
                <tr>
                    <td><?= htmlspecialchars($payment['payment_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($payment['member_name'] ?? ($payment['member_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>₱<?= number_format((float)($payment['amount_paid'] ?? 0), 2) ?></td>
                    <td><?= htmlspecialchars($payment['payment_method'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($payment['reference_no'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
