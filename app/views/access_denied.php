<?php require APP_DIR . 'views/partials/header.php'; ?>
<div class="login-wrap">
    <section class="login-card" aria-labelledby="denied-heading">
        <h2 id="denied-heading">Access denied</h2>
        <p class="muted"><?= htmlspecialchars($message ?? 'You are not authorized to view this page.', ENT_QUOTES, 'UTF-8') ?></p>
        <a class="btn btn-primary" href="<?= BASE_URL ?>dashboard" style="display:block;text-align:center;">Return to dashboard</a>
    </section>
</div>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
