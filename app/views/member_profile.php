<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<div class="card-grid">
    <div class="panel">
        <h3 style="margin-top: 0;">Profile</h3>
        <p><strong>Name:</strong> <?= htmlspecialchars(trim(($member['first_name'] ?? '') . ' ' . ($member['middle_name'] ?? '') . ' ' . ($member['last_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Member No:</strong> <?= htmlspecialchars($member['member_number'] ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Contact:</strong> <?= htmlspecialchars($member['contact_number'] ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($member['email'] ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Date of Birth:</strong> <?= htmlspecialchars($member['birth_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Age:</strong> <?= $age === null ? 'Not available' : (int) $age ?></p>
        <p><strong>Address:</strong> <?= htmlspecialchars($member['address'] ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="panel">
        <h3 style="margin-top: 0;">QR Code</h3>
        <div style="width: 180px; height: 180px; background: linear-gradient(135deg, #0b3655, #1f7ac7); border-radius: 18px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.5rem; margin-top: 16px;">
            QR
        </div>
    </div>
</div>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
