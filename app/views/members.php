<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<div class="panel">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h3 style="margin: 0;">Member Directory</h3>
        <a class="btn btn-primary" href="<?= BASE_URL ?>members/profile">View profile</a>
    </div>

    <table>
        <thead>
            <tr>
                <th>Member #</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Role</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($members ?? []) as $member): ?>
                <tr>
                    <td><?= htmlspecialchars($member['member_number'] ?? '-') ?></td>
                    <td><?= htmlspecialchars(trim(($member['first_name'] ?? '') . ' ' . ($member['middle_name'] ?? '') . ' ' . ($member['last_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($member['contact_number'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($member['email'] ?? '-') ?></td>
                    <td><span class="badge"><?= htmlspecialchars($member['role'] ?? 'member') ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
