<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<?php if (!empty($success)): ?>
    <div class="success-message"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($can_record)): ?>
<div class="panel" style="margin-bottom: 20px;">
    <h3 style="margin-top: 0;">Record savings transaction</h3>
    <form action="<?= BASE_URL ?>savings/record" method="post" class="form-grid">
        <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
        <div>
            <label for="member_id">Member</label>
            <select id="member_id" name="member_id" required>
                <option value="">Select a member</option>
                <?php foreach (($members ?? []) as $member): ?>
                    <option value="<?= (int) $member['id'] ?>"><?= htmlspecialchars($member['member_number'] . ' — ' . trim($member['first_name'] . ' ' . $member['last_name']), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="type">Transaction</label>
            <select id="type" name="type" required>
                <option value="deposit">Deposit</option>
                <option value="withdrawal">Withdrawal</option>
            </select>
        </div>
        <div>
            <label for="amount">Amount (PHP)</label>
            <input id="amount" name="amount" type="number" min="0.01" max="9999999999.99" step="0.01" required>
        </div>
        <div>
            <label for="description">Description (optional)</label>
            <input id="description" name="description" type="text" maxlength="255">
        </div>
        <div>
            <button class="btn btn-primary" type="submit">Save transaction</button>
        </div>
    </form>
</div>
<?php endif; ?>

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
