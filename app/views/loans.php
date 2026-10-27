<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<div class="panel">
    <?php if (!empty($error)): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="success-message"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if (!empty($can_apply)): ?>
        <h3 style="margin-top: 0;">Apply for a monthly loan</h3>
        <?php if (!empty($products)): ?>
            <p class="muted">Interest uses simple flat monthly interest on the original principal: principal × monthly rate × term. The displayed rate is saved with your application.</p>
            <form method="post" action="<?= BASE_URL ?>loans/apply" class="form-grid">
                <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label for="loan_type_id">Loan product</label>
                    <select id="loan_type_id" name="loan_type_id" required>
                        <option value="">Choose a product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= (int) $product['id'] ?>">
                                <?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?> — <?= number_format((float) $product['interest_rate'], 2) ?>% monthly, up to ₱<?= number_format((float) $product['max_amount'], 2) ?> / <?= (int) $product['max_term_months'] ?> months
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="loan_amount">Requested amount (₱)</label>
                    <input id="loan_amount" type="number" name="amount" min="1" step="0.01" required>
                </div>
                <div>
                    <label for="term_months">Term (months)</label>
                    <input id="term_months" type="number" name="term_months" min="1" max="120" required>
                </div>
                <div>
                    <label for="purpose">Purpose</label>
                    <input id="purpose" type="text" name="purpose" maxlength="255">
                </div>
                <div style="align-self: end;"><button class="btn btn-primary" type="submit">Submit application</button></div>
            </form>
        <?php else: ?>
            <p class="muted">No loan products are available yet. Ask cooperative staff to configure a product and monthly rate before applying.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($can_manage)): ?>
        <details style="margin: 18px 0;">
            <summary><strong>Configure a loan product</strong></summary>
            <p class="muted">Set the cooperative's own rate; no market rate or fee is assumed. Rate is a percentage per month, calculated flat on the original principal.</p>
            <form method="post" action="<?= BASE_URL ?>loans/products/create" class="form-grid">
                <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
                <div><label for="product_name">Product name</label><input id="product_name" name="name" maxlength="100" required></div>
                <div><label for="product_rate">Monthly flat interest (%)</label><input id="product_rate" name="interest_rate" type="number" min="0" max="99.99" step="0.01" required></div>
                <div><label for="product_max_amount">Maximum principal (₱)</label><input id="product_max_amount" name="max_amount" type="number" min="1" step="0.01" required></div>
                <div><label for="product_max_term">Maximum term (months)</label><input id="product_max_term" name="max_term_months" type="number" min="1" max="120" required></div>
                <div><label for="product_description">Description</label><input id="product_description" name="description" maxlength="255"></div>
                <div style="align-self: end;"><button class="btn btn-secondary" type="submit">Create product</button></div>
            </form>
        </details>

        <h3>Available loan products</h3>
        <table>
            <thead><tr><th>Name</th><th>Monthly flat interest</th><th>Maximum principal</th><th>Maximum term</th><th>Description</th><th>Settings</th></tr></thead>
            <tbody>
                <?php foreach (($products ?? []) as $product): ?>
                    <tr>
                        <td><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((float) $product['interest_rate'], 2) ?>%</td>
                        <td>₱<?= number_format((float) $product['max_amount'], 2) ?></td>
                        <td><?= (int) $product['max_term_months'] ?> months</td>
                        <td><?= htmlspecialchars($product['description'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <details>
                                <summary>Edit</summary>
                                <form method="post" action="<?= BASE_URL ?>loans/products/update">
                                    <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <label>Name</label><input name="name" maxlength="100" value="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    <label>Monthly flat interest (%)</label><input name="interest_rate" type="number" min="0" max="99.99" step="0.01" value="<?= htmlspecialchars($product['interest_rate'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    <label>Maximum principal (₱)</label><input name="max_amount" type="number" min="1" step="0.01" value="<?= htmlspecialchars($product['max_amount'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    <label>Maximum term (months)</label><input name="max_term_months" type="number" min="1" max="120" value="<?= (int) $product['max_term_months'] ?>" required>
                                    <label>Description</label><input name="description" maxlength="255" value="<?= htmlspecialchars($product['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="btn btn-secondary" type="submit">Save settings</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?><tr><td colspan="6" class="muted">No active loan products configured.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <h3>Applications awaiting review</h3>
        <table>
            <thead><tr><th>Member</th><th>Product</th><th>Amount</th><th>Term</th><th>Monthly rate</th><th>Purpose</th><th>Decision</th></tr></thead>
            <tbody>
                <?php foreach (($pending ?? []) as $application): ?>
                    <tr>
                        <td><?= htmlspecialchars($application['member_number'] . ' — ' . $application['first_name'] . ' ' . $application['last_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($application['loan_type_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>₱<?= number_format((float) $application['principal_amount'], 2) ?></td>
                        <td><?= (int) $application['term_months'] ?> months</td>
                        <td><?= number_format((float) $application['interest_rate'], 2) ?>%</td>
                        <td><?= htmlspecialchars($application['purpose'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $decision => $label): ?>
                                <form method="post" action="<?= BASE_URL ?>loans/review/<?= (int) $application['id'] ?>" style="display: inline;">
                                    <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="decision" value="<?= $decision ?>">
                                    <button class="btn <?= $decision === 'approve' ? 'btn-success' : 'btn-warning' ?>" type="submit"><?= $label ?></button>
                                </form>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($pending)): ?><tr><td colspan="7" class="muted">No applications are awaiting review.</td></tr><?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h3 style="margin-top: 28px;">Loan applications and accounts</h3>
    <table>
        <thead><tr><?php if (!empty($can_manage)): ?><th>Member</th><?php endif; ?><th>Loan type</th><th>Principal</th><th>Term</th><th>Monthly rate</th><th>Balance</th><th>Status</th><th>Schedule</th></tr></thead>
        <tbody>
            <?php foreach (($loans ?? []) as $loan): ?>
                <tr>
                    <?php if (!empty($can_manage)): ?><td><?= htmlspecialchars($loan['member_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
                    <td><?= htmlspecialchars($loan['loan_type_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>₱<?= number_format((float) ($loan['principal_amount'] ?? 0), 2) ?></td>
                    <td><?= (int) ($loan['term_months'] ?? 0) ?> months</td>
                    <td><?= number_format((float) ($loan['interest_rate'] ?? 0), 2) ?>%</td>
                    <td>₱<?= number_format((float) ($loan['current_balance'] ?? 0), 2) ?></td>
                    <td><span class="badge"><?= htmlspecialchars($loan['status'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?php if (in_array($loan['status'], ['approved', 'active', 'completed'], true)): ?><a href="<?= BASE_URL ?>loans/amortization/<?= (int) $loan['id'] ?>">View</a><?php else: ?>—<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($loans)): ?><tr><td colspan="<?= !empty($can_manage) ? 8 : 7 ?>" class="muted">No loan applications yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
