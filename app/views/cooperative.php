<?php require APP_DIR . 'views/partials/header.php'; ?>
<?php require APP_DIR . 'views/partials/sidebar.php'; ?>

<div class="panel">
    <?php if (!empty($error)): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="success-message"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <p class="muted">Each enrolled member contributes the same amount once per day. After everyone contributes, the pooled amount becomes ready for the next member in payout order. Members continue contributing after their own payout until everyone has received one turn.</p>

    <?php if (!empty($can_manage)): ?>
        <details style="margin-bottom: 20px;">
            <summary><strong>Create a daily contribution cycle</strong></summary>
            <form method="post" action="<?= BASE_URL ?>cooperative/cycles/create" class="form-grid">
                <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
                <div><label for="cycle_name">Cycle name</label><input id="cycle_name" name="name" maxlength="120" required></div>
                <div><label for="daily_contribution">Daily contribution (₱ per member)</label><input id="daily_contribution" name="daily_contribution" type="number" min="0.01" step="0.01" required></div>
                <div><label for="start_date">Start date</label><input id="start_date" name="start_date" type="date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
                <div style="align-self: end;"><button class="btn btn-primary" type="submit">Create draft</button></div>
            </form>
        </details>
    <?php endif; ?>

    <?php foreach (($cycles ?? []) as $cycle): ?>
        <?php
            $own_member_id = (int) ($user['member']['id'] ?? 0);
            $own_enrollment = false;
            foreach ($cycle['members'] as $cycle_member) {
                if ((int) $cycle_member['member_id'] === $own_member_id) {
                    $own_enrollment = true;
                    break;
                }
            }
        ?>
        <section style="border-top: 1px solid #edf1f7; padding-top: 18px; margin-top: 18px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                <div>
                    <h3 style="margin:0 0 6px;"><?= htmlspecialchars($cycle['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <span class="muted">₱<?= number_format((float) $cycle['daily_contribution'], 2) ?> daily · starts <?= htmlspecialchars($cycle['start_date'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <span class="badge"><?= htmlspecialchars(ucfirst($cycle['status']), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <p class="muted">Today: <?= (int) $cycle['today_count'] ?> of <?= (int) $cycle['member_count'] ?> contributions recorded.</p>

            <table>
                <thead><tr><th>Payout order</th><th>Member</th><th>Payout</th><?php if (!empty($can_manage) && $cycle['status'] === 'draft'): ?><th>Setup</th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($cycle['members'] as $cycle_member): ?>
                        <tr>
                            <td><?= (int) $cycle_member['payout_order'] ?></td>
                            <td><?= htmlspecialchars($cycle_member['member_number'] . ' — ' . $cycle_member['first_name'] . ' ' . $cycle_member['last_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($cycle_member['payout_status'] ? ucfirst($cycle_member['payout_status']) . ' on ' . $cycle_member['payout_date'] : 'Waiting', ENT_QUOTES, 'UTF-8') ?></td>
                            <?php if (!empty($can_manage) && $cycle['status'] === 'draft'): ?>
                                <td>
                                    <form method="post" action="<?= BASE_URL ?>cooperative/cycles/member/remove">
                                        <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                                        <input type="hidden" name="member_id" value="<?= (int) $cycle_member['member_id'] ?>">
                                        <button class="btn btn-warning" type="submit">Remove</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($cycle['members'])): ?><tr><td colspan="<?= !empty($can_manage) && $cycle['status'] === 'draft' ? 4 : 3 ?>" class="muted">No members assigned yet.</td></tr><?php endif; ?>
                </tbody>
            </table>

            <?php if (!empty($can_manage) && $cycle['status'] === 'draft'): ?>
                <form method="post" action="<?= BASE_URL ?>cooperative/cycles/member" class="form-grid" style="margin-top:16px;">
                    <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                    <div>
                        <label for="member_cycle_<?= (int) $cycle['id'] ?>">Add member</label>
                        <select id="member_cycle_<?= (int) $cycle['id'] ?>" name="member_id" required>
                            <option value="">Choose a member</option>
                            <?php foreach (($members ?? []) as $member): ?>
                                <option value="<?= (int) $member['id'] ?>"><?= htmlspecialchars($member['member_number'] . ' — ' . $member['first_name'] . ' ' . $member['last_name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label for="order_cycle_<?= (int) $cycle['id'] ?>">Payout order (1 to <?= (int) $cycle['member_count'] + 1 ?>)</label><input id="order_cycle_<?= (int) $cycle['id'] ?>" name="payout_order" type="number" min="1" max="999" required></div>
                    <div style="align-self:end;"><button class="btn btn-secondary" type="submit">Add member</button></div>
                </form>
                <form method="post" action="<?= BASE_URL ?>cooperative/cycles/activate">
                    <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                    <button class="btn btn-success" type="submit">Activate cycle</button>
                </form>
            <?php endif; ?>

            <?php if ($cycle['status'] === 'active' && (empty($cycle['has_ready_payout'])) && date('Y-m-d') >= $cycle['start_date']): ?>
                <?php if (!empty($can_manage)): ?>
                    <form method="post" action="<?= BASE_URL ?>cooperative/contributions/record" class="form-grid" style="margin-top:16px;">
                        <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="cycle_id" value="<?= (int) $cycle['id'] ?>">
                        <div>
                            <label for="contributor_<?= (int) $cycle['id'] ?>">Record contribution received from</label>
                            <select id="contributor_<?= (int) $cycle['id'] ?>" name="member_id" required>
                                <option value="">Choose an enrolled member</option>
                                <?php foreach ($cycle['members'] as $cycle_member): ?>
                                    <option value="<?= (int) $cycle_member['member_id'] ?>"><?= htmlspecialchars($cycle_member['member_number'] . ' — ' . $cycle_member['first_name'] . ' ' . $cycle_member['last_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="align-self:end;"><button class="btn btn-primary" type="submit">Record today's contribution</button></div>
                    </form>
                <?php elseif ($own_enrollment): ?>
                    <p class="muted">Please give today's contribution to cooperative staff so they can record the received payment.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <?php if (empty($cycles)): ?><p class="muted">No cooperative cycles are available for your account yet.</p><?php endif; ?>
</div>

<?php if (!empty($can_manage)): ?>
    <div class="panel" style="margin-top:20px;">
        <h3 style="margin-top:0;">Ready payouts</h3>
        <p class="muted">A payout is created only after every enrolled member has contributed for that date. Confirm this action after paying the recipient in person.</p>
        <?php $ready_payouts = array_filter(($payouts ?? []), function ($payout) { return $payout['status'] === 'ready'; }); ?>
        <?php foreach ($ready_payouts as $payout): ?>
            <div class="success-message">
                <?= htmlspecialchars($payout['cycle_name'] . ' — order ' . $payout['payout_order'] . ': ' . $payout['first_name'] . ' ' . $payout['last_name'] . ' receives ₱' . number_format((float) $payout['amount'], 2) . ' on ' . $payout['payout_date'], ENT_QUOTES, 'UTF-8') ?>
                <form method="post" action="<?= BASE_URL ?>cooperative/payouts/mark-paid">
                    <input type="hidden" name="<?= htmlspecialchars(lava_instance()->security->get_csrf_token_name(), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars(lava_instance()->security->get_csrf_hash(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="payout_id" value="<?= (int) $payout['id'] ?>">
                    <button class="btn btn-success" type="submit">Confirm payout paid</button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php if (empty($ready_payouts)): ?><p class="muted">There are no payouts awaiting confirmation.</p><?php endif; ?>
    </div>
<?php endif; ?>

<div class="panel" style="margin-top:20px;">
    <h3 style="margin-top:0;">Contribution history</h3>
    <table>
        <thead><tr><th>Date</th><th>Cycle</th><?php if (!empty($can_manage)): ?><th>Member</th><?php endif; ?><th>Amount</th><th>Reference</th></tr></thead>
        <tbody>
            <?php foreach (($contributions ?? []) as $contribution): ?>
                <tr>
                    <td><?= htmlspecialchars($contribution['contribution_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($contribution['cycle_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <?php if (!empty($can_manage)): ?><td><?= htmlspecialchars($contribution['member_number'] . ' — ' . $contribution['first_name'] . ' ' . $contribution['last_name'], ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
                    <td>₱<?= number_format((float) $contribution['amount'], 2) ?></td>
                    <td><?= htmlspecialchars($contribution['reference_no'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($contributions)): ?><tr><td colspan="<?= !empty($can_manage) ? 5 : 4 ?>" class="muted">No contributions recorded.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <h3>Payout history</h3>
    <table>
        <thead><tr><th>Date</th><th>Cycle</th><th>Order</th><?php if (!empty($can_manage)): ?><th>Recipient</th><?php endif; ?><th>Amount</th><th>Status</th><th>Reference</th></tr></thead>
        <tbody>
            <?php foreach (($payouts ?? []) as $payout): ?>
                <tr>
                    <td><?= htmlspecialchars($payout['payout_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($payout['cycle_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int) $payout['payout_order'] ?></td>
                    <?php if (!empty($can_manage)): ?><td><?= htmlspecialchars($payout['member_number'] . ' — ' . $payout['first_name'] . ' ' . $payout['last_name'], ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
                    <td>₱<?= number_format((float) $payout['amount'], 2) ?></td>
                    <td><?= htmlspecialchars(ucfirst($payout['status']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($payout['reference_no'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($payouts)): ?><tr><td colspan="<?= !empty($can_manage) ? 7 : 6 ?>" class="muted">No payouts recorded.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php require APP_DIR . 'views/partials/footer.php'; ?>
