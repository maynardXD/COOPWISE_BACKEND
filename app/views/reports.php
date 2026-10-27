<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<div class="card-grid">
    <div class="stat-card">
        <div class="stat-label">Members</div>
        <div class="stat-value"><?= (int)($summary['members'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Savings</div>
        <div class="stat-value">₱<?= number_format((float)($summary['savings'] ?? 0), 2) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Loan Portfolio</div>
        <div class="stat-value">₱<?= number_format((float)($summary['loans'] ?? 0), 2) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Payments</div>
        <div class="stat-value">₱<?= number_format((float)($summary['payments'] ?? 0), 2) ?></div>
    </div>
</div>

<div class="panel">
    <h3 style="margin-top: 0;">Monthly Summary</h3>
    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th>Value</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Member Growth</td><td>8.5%</td><td>Healthy</td></tr>
            <tr><td>Share Capital</td><td>₱245,800.00</td><td>Stable</td></tr>
            <tr><td>Loan Collection</td><td>₱42,250.00</td><td>On track</td></tr>
            <tr><td>Delinquency</td><td>7 accounts</td><td>Needs attention</td></tr>
        </tbody>
    </table>
</div>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
