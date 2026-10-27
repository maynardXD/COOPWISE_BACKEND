<?php require APP_DIR . 'views/partials/header.php'; ?>
<div class="login-wrap">
    <div class="login-card">
        <h2>Welcome back</h2>
        <p class="muted">Sign in to your CoopWise account.</p>

        <div id="form-message" class="<?= !empty($error) ? 'alert' : (!empty($success) ? 'success-message' : '') ?>" role="status">
            <?= htmlspecialchars($error ?: ($success ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </div>

        <form id="login-form" action="<?= BASE_URL ?>login" data-api-url="<?= BASE_URL ?>api/auth/login" method="post">
            <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
            <div style="margin-bottom: 18px;">
                <label for="login">Phone number or email</label>
                <input type="text" id="login" name="login" placeholder="Enter phone number or email" autocomplete="off" required>
            </div>

            <div style="margin-bottom: 18px;">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter password" autocomplete="current-password" required>
            </div>

            <label class="inline-check">
                <input type="checkbox" name="remember" value="1">
                Remember me on this device
            </label>
            <button class="btn btn-primary" type="submit" style="width: 100%;">Log In</button>
        </form>
        <p class="muted" style="text-align:center; margin: 18px 0 0;">
            Don't have an account? <a href="<?= BASE_URL ?>register" style="color:#0f6fc5;font-weight:700;">Register here</a>
        </p>
        <p class="muted" style="text-align:center; font-size:0.8rem;">Password recovery is not enabled in this local demo.</p>
    </div>
</div>
<script src="<?= BASE_URL ?>public/assets/js/auth.js" defer></script>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
