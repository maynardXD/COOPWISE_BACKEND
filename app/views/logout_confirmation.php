<?php require APP_DIR . 'views/partials/header.php'; ?>
<div class="login-wrap">
    <section class="login-card" aria-labelledby="logout-title">
        <h2 id="logout-title">Log out?</h2>
        <p class="muted">Are you sure you want to log out of CoopWise, <?= htmlspecialchars($user_name) ?>?</p>

        <form action="<?= BASE_URL ?>logout" method="post">
            <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
            <button class="btn btn-primary" type="submit" style="width: 100%;">Yes, log out</button>
        </form>

        <a class="btn btn-secondary" href="<?= BASE_URL ?>dashboard" style="display: block; text-align: center; margin: 14px 0 0;">Stay logged in</a>
    </section>
</div>
</body>
</html>
