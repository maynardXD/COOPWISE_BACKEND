<?php require APP_DIR . 'views/partials/header.php'; ?>
<div class="login-wrap">
    <section class="login-card register-card" aria-labelledby="register-heading">
        <h2 id="register-heading">Member registration</h2>
        <p class="muted">Create your CoopWise member account. Fields marked * are required.</p>
        <div id="form-message" class="<?= !empty($errors['form']) ? 'alert' : (!empty($success) ? 'success-message' : '') ?>" role="status">
            <?= htmlspecialchars($errors['form'] ?? ($success ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </div>

        <form id="register-form" action="<?= BASE_URL ?>register" data-api-url="<?= BASE_URL ?>api/auth/register" method="post">
            <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-grid">
                <?php
                $fields = [
                    ['first_name', 'First name *', 'text', true],
                    ['middle_name', 'Middle name', 'text', false],
                    ['last_name', 'Last name *', 'text', true],
                    ['phone_number', 'Phone number *', 'tel', true],
                    ['email', 'Email address *', 'email', true],
                    ['date_of_birth', 'Date of birth *', 'date', true],
                    ['password', 'Password *', 'password', true],
                    ['password_confirmation', 'Confirm password *', 'password', true]
                ];
                foreach ($fields as $field):
                    [$name, $label, $type, $required] = $field;
                    $value = $type === 'password' ? '' : ($old[$name] ?? '');
                    $error = $errors[$name] ?? '';
                ?>
                    <div>
                        <label for="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
                        <input
                            id="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                            name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                            type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                            value="<?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $required ? 'required' : '' ?>
                            <?= in_array($name, ['first_name', 'middle_name', 'last_name'], true) ? 'maxlength="100" pattern="[\p{L} ]+"' : '' ?>
                            <?= $name === 'phone_number' ? 'minlength="11" maxlength="11" pattern="09[0-9]{9}" inputmode="numeric" autocomplete="tel" placeholder="09123456789" title="Enter 11 digits starting with 09."' : '' ?>
                            <?= $name === 'email' ? 'maxlength="254" autocomplete="email"' : '' ?>
                            <?= $name === 'password' ? 'minlength="8" maxlength="128" pattern="(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])\S{8,128}" autocomplete="new-password" title="At least 8 characters, with an uppercase letter, a number, and a special character. No spaces."' : '' ?>
                            <?= $name === 'password_confirmation' ? 'minlength="8" maxlength="128" autocomplete="new-password"' : '' ?>
                            <?= $name === 'date_of_birth' ? 'max="' . date('Y-m-d') . '"' : '' ?>
                            aria-invalid="<?= $error ? 'true' : 'false' ?>"
                        >
                        <?php if ($error): ?><small class="field-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="muted">Names may use letters and spaces only. Use an 11-digit phone number starting with 09. Passwords need at least 8 characters, one uppercase letter, one number, and one special character, with no spaces.</p>
            <p id="password-match" class="field-error" aria-live="polite"></p>
            <button class="btn btn-primary" type="submit" style="width: 100%;">Create member account</button>
        </form>
        <p class="muted" style="text-align:center; margin-bottom:0;">
            Already registered? <a href="<?= BASE_URL ?>login" style="color:#0f6fc5;font-weight:700;">Sign in</a>
        </p>
    </section>
</div>
<script src="<?= BASE_URL ?>public/assets/js/auth.js" defer></script>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
