<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<div class="panel">
    <h3 style="margin-top: 0;">Transaction History</h3>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Member</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Balance After</th>
                <th>Reference</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($transactions ?? []) as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars($entry['created_at'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($entry['member_number'] ?? ($entry['member_id'] ?? '-')) ?></td>
                    <td><?= htmlspecialchars($entry['type'] ?? '-') ?></td>
                    <td>₱<?= number_format((float)($entry['amount'] ?? 0), 2) ?></td>
                    <td>₱<?= number_format((float)($entry['balance_after'] ?? 0), 2) ?></td>
                    <td><?= htmlspecialchars($entry['reference_no'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
