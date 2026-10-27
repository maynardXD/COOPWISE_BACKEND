<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<div class="panel">
    <h3 style="margin-top: 0;">Loan Amortization Schedule</h3>
    <p><strong>Member:</strong> <?= htmlspecialchars($loan['member_name'] ?? '-') ?><br>
    <strong>Loan Type:</strong> <?= htmlspecialchars($loan['loan_type_name'] ?? '-') ?><br>
    <strong>Principal:</strong> ₱<?= number_format((float)($loan['approved_amount'] ?? 0), 2) ?><br>
    <strong>Flat monthly rate:</strong> <?= number_format((float)($loan['interest_rate'] ?? 0), 2) ?>%<br>
    <strong>Term:</strong> <?= (int) ($loan['term_months'] ?? 0) ?> months<br>
    <strong>Remaining balance:</strong> ₱<?= number_format((float)($loan['current_balance'] ?? 0), 2) ?></p>
    <?php if (empty($schedule)): ?><p class="muted">Installments are generated after staff approves the application.</p><?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Due Date</th>
                <th>Principal</th>
                <th>Interest</th>
                <th>Total Due</th>
                <th>Paid</th>
                <th>Remaining</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($schedule ?? []) as $row): ?>
                <?php $schedule_remaining = max(0, (float) ($row['total_due'] ?? 0) - (float) ($row['paid_amount'] ?? 0)); ?>
                <tr>
                    <td><?= htmlspecialchars($row['due_date'] ?? '-') ?></td>
                    <td>₱<?= number_format((float)($row['principal_due'] ?? 0), 2) ?></td>
                    <td>₱<?= number_format((float)($row['interest_due'] ?? 0), 2) ?></td>
                    <td>₱<?= number_format((float)($row['total_due'] ?? 0), 2) ?></td>
                    <td>₱<?= number_format((float)($row['paid_amount'] ?? 0), 2) ?></td>
                    <td>₱<?= number_format($schedule_remaining, 2) ?></td>
                    <td><span class="badge"><?= htmlspecialchars($row['status'] ?? 'pending') ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
