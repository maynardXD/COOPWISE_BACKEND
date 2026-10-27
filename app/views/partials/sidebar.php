<div class="app-shell">
    <?php
    $sidebar_user = $user ?? [];
    $sidebar_role = $sidebar_user['role'] ?? ($_SESSION['role'] ?? 'member');
    $sidebar_under_18 = $sidebar_role === 'member' && !empty($sidebar_user['under_18']);
    $sidebar_staff = in_array($sidebar_role, ['admin', 'officer'], true);
    ?>
    <aside class="sidebar">
        <div class="brand">Coop<span>Wise</span></div>
        <div class="nav-group">
            <a class="nav-link <?= (($page_title ?? '') === 'Dashboard' || ($page_title ?? '') === 'Member Dashboard' || ($page_title ?? '') === 'Admin Dashboard') ? 'active' : '' ?>" href="<?= BASE_URL ?>dashboard">Dashboard</a>
            <?php if ($sidebar_staff): ?>
                <a class="nav-link <?= (($page_title ?? '') === 'Members') ? 'active' : '' ?>" href="<?= BASE_URL ?>members">Members</a>
            <?php else: ?>
                <a class="nav-link <?= (($page_title ?? '') === 'Member Profile') ? 'active' : '' ?>" href="<?= BASE_URL ?>profile">My Profile</a>
            <?php endif; ?>
            <a class="nav-link <?= (($page_title ?? '') === 'Savings') ? 'active' : '' ?>" href="<?= BASE_URL ?>savings">Savings</a>
            <a class="nav-link <?= (($page_title ?? '') === 'Daily Cooperative') ? 'active' : '' ?>" href="<?= BASE_URL ?>cooperative">Daily Cooperative</a>
            <?php if ($sidebar_staff || !$sidebar_under_18): ?>
                <a class="nav-link <?= (($page_title ?? '') === 'Loans') ? 'active' : '' ?>" href="<?= BASE_URL ?>loans">Loans</a>
                <a class="nav-link <?= (($page_title ?? '') === 'Payments') ? 'active' : '' ?>" href="<?= BASE_URL ?>payments">Payments</a>
            <?php endif; ?>
            <?php if ($sidebar_staff): ?>
                <a class="nav-link <?= (($page_title ?? '') === 'Reports') ? 'active' : '' ?>" href="<?= BASE_URL ?>reports">Reports</a>
            <?php elseif (!$sidebar_under_18): ?>
                <a class="nav-link <?= (($page_title ?? '') === 'Notifications') ? 'active' : '' ?>" href="<?= BASE_URL ?>notifications">Notifications</a>
            <?php endif; ?>
            <a class="nav-link" href="<?= BASE_URL ?>logout">Logout</a>
        </div>
    </aside>
    <main class="content">
        <div class="topbar">
            <h1><?= htmlspecialchars($page_title ?? 'Dashboard') ?></h1>
            <div class="user-pill"><?= htmlspecialchars($sidebar_user['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst($sidebar_role), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
