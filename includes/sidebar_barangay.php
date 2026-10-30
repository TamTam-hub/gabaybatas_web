<?php $page = basename($_SERVER['PHP_SELF']); ?>

<div class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-row">
            <div class="brand-shield">
                <i class="uil uil-shield-check"></i>
            </div>
            <div>
                <div class="brand-name">GabayBatas</div>
                <div class="brand-sub">Barangay Portal</div>
            </div>
        </div>
    </div>

    <nav>
        <div class="sidebar-section">Main Menu</div>
        <a href="home.php" <?= ($page === 'home.php' || $page === 'dashboard.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-apps"></i> Home
        </a>
        <a href="cases.php" <?= ($page === 'cases.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-file-alt"></i> Cases
        </a>
        <a href="case_process.php" <?= ($page === 'case_process.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-balance-scale"></i> Case Process
        </a>
        <a href="kp_reports.php" <?= ($page === 'kp_reports.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-file-graph"></i> Monthly Report
        </a>
        <a href="reports.php" <?= ($page === 'reports.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-chart-bar"></i> Flexible Reports
        </a>

        <div class="sidebar-section">Account</div>
        <a href="profile.php" <?= ($page === 'profile.php') ? 'class="active"' : '' ?>>
            <i class="uil uil-user-circle"></i> My Profile
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php">
            <i class="uil uil-sign-out-alt"></i> Logout
        </a>
    </div>
</div>