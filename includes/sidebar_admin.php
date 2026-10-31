<?php $page = basename($_SERVER['PHP_SELF']); require_once __DIR__ . '/brand_mark.php'; ?>

<div class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo-row">
            <div class="brand-icon"><i class="gb-mark"><?= gabaybatas_brand_mark() ?></i></div>
            <div>
                <div class="brand-title">GabayBatas</div>
                <div class="brand-sub">Admin Control Panel</div>
            </div>
        </div>
    </div>

    <nav>
        <div class="nav-label">Main</div>
        <a href="dashboard.php" <?= ($page === 'dashboard.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-apps"></i> Home Dashboard
        </a>
        <a href="manage_users.php" <?= ($page === 'manage_users.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-users-alt"></i> Manage Users
        </a>
        <a href="all_cases.php" <?= ($page === 'all_cases.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-folder-open"></i> Manage Cases
        </a>
        <a href="residency_verifications.php" <?= ($page === 'residency_verifications.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-check-circle"></i> Residency Verification
        </a>
        <a href="archive.php" <?= ($page === 'archive.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-archive"></i> Records Archive
        </a>

        <div class="nav-label">Insights</div>
        <a href="analytics.php" <?= ($page === 'analytics.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-chart-bar"></i> Analytics
        </a>
        <a href="reports.php" <?= ($page === 'reports.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-file-alt"></i> Reports
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="gb-logout"><i class="uil uil-signout"></i> Logout</a>
    </div>
</div>