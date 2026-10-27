<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<div class="panel">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h3 style="margin: 0;">Notifications</h3>
        <a class="btn btn-secondary" href="#">Send reminder</a>
    </div>

    <table>
        <thead>
            <tr>
                <th>Type</th>
                <th>Title</th>
                <th>Message</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($notifications ?? []) as $item): ?>
                <tr>
                    <td><span class="badge"><?= htmlspecialchars($item['type'] ?? 'system') ?></span></td>
                    <td><?= htmlspecialchars($item['title'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($item['message'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($item['created_at'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
