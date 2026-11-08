<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<div class="panel">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
        <h3 style="margin: 0;">Loan Payment History</h3>
    </div>
    <p class="muted">Payments are recorded after PayMongo confirms checkout. Use the Payments page in the CoopWise frontend to start a payment.</p>

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
