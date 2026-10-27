<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<?php if (!empty($flash_error)): ?>
    <div class="alert"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($under_18)): ?>
    <div class="success-message">Savings and profile features are available for your account. Loan services are available only to members aged 18 and above.</div>
<?php endif; ?>

<?php if (($role ?? '') === 'member'): ?>
    <div class="card-grid">
        <div class="stat-card">
            <div class="stat-label">Savings Balance</div>
            <div class="stat-value">₱<?= number_format((float)($stats['savings_balance'] ?? 0), 2) ?></div>
        </div>
        <?php if (empty($under_18)): ?>
            <div class="stat-card">
                <div class="stat-label">Loan Balance</div>
                <div class="stat-value">₱<?= number_format((float)($stats['loan_balance'] ?? 0), 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Paid</div>
                <div class="stat-value">₱<?= number_format((float)($stats['total_paid'] ?? 0), 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Due</div>
                <div class="stat-value">₱<?= number_format((float)($stats['pending_due'] ?? 0), 2) ?></div>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card-grid">
        <div class="stat-card">
            <div class="stat-label">Total Members</div>
            <div class="stat-value"><?= (int)($stats['total_members'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Savers</div>
            <div class="stat-value"><?= (int)($stats['total_savers'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Savings</div>
            <div class="stat-value">₱<?= number_format((float)($stats['total_savings'] ?? 0), 2) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Overdue Payments</div>
            <div class="stat-value"><?= (int)($stats['overdue_payments'] ?? 0) ?></div>
        </div>
    </div>
<?php endif; ?>

<div class="panel">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h3 style="margin: 0;">Recent activity</h3>
        <?php if (in_array(($role ?? ''), ['admin', 'officer'], true)): ?>
            <a class="btn btn-secondary" href="<?= BASE_URL ?>reports">View reports</a>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Module</th>
                <th>Action</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($activity ?? []) as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['created_at'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($log['module'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($log['action'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require APP_DIR . 'views/partials/footer.php'; ?>
