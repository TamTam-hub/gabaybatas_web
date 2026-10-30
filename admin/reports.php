<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/barangay_auth.php';
require_once '../includes/flexible_report.php';

// Reports Center: every download is a formal Barangay Muñoz document produced by the
// shared report code (barangay/generate_report.php, barangay/kp_monitoring_report.php,
// admin/cases_report.php, admin/users_report.php). Archived records are never included.
$quarter = (int)ceil((int)date('n') / 3);
$quick = [
    'month'   => ['This Month',   date('F Y'),                 'uil-calendar-alt',  '#2563eb', '#eff6ff'],
    'quarter' => ['This Quarter', 'Quarter ' . $quarter . ', ' . date('Y'), 'uil-chart-growth', '#7c3aed', '#f5f3ff'],
    'year'    => ['This Year',    'January – December ' . date('Y'), 'uil-calender',   '#059669', '#ecfdf5'],
    'all'     => ['All Cases',    'Every active case on record', 'uil-folder-open', '#0f172a', '#f1f5f9'],
];
foreach ($quick as $period => &$q) {
    $q[] = gabaybatas_flex_report($conn, '', gabaybatas_flex_params(['period' => $period]), 1)['total'];
}
unset($q);

$userCount = (int)$conn->query('SELECT COUNT(*) FROM users WHERE archived_at IS NULL')->fetch_row()[0];
$firstYear = (int)($conn->query('SELECT YEAR(MIN(date_submitted)) FROM cases')->fetch_row()[0] ?: date('Y'));
$firstYear = max(2020, min($firstYear, (int)date('Y')));
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
<div class="topbar">
    <div class="topbar-left"><div class="topbar-title">Reports</div></div>
    <div class="topbar-right">
        <div class="notif-btn"><i class="uil uil-bell"></i></div>
        <div class="topbar-sep"></div>
        <div class="user-pill">
            <div class="user-avatar"><i class="uil uil-user"></i></div>
            <div class="user-text">
                <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></strong>
                <span>Administrator</span>
            </div>
        </div>
    </div>
</div>

<div class="page-body rp-body">
    <p class="rp-intro">Download formal Barangay Muñoz documents (long bond PDF, with the city and barangay seals). Archived records are not included.</p>

    <!-- Quick case reports -->
    <section class="rp-section">
        <div class="rp-section-head">
            <div>
                <h2>Quick Case Reports</h2>
                <p>One click for the most common periods. Prepared by <?= htmlspecialchars(ucwords(strtolower(GABAYBATAS_ADMIN_NAME))) ?>, approved by the Punong Barangay.</p>
            </div>
        </div>
        <div class="rp-quick-grid">
            <?php foreach ($quick as $period => [$title, $sub, $icon, $color, $bg, $count]): ?>
            <div class="rp-quick" style="--c:<?= $color ?>;--cb:<?= $bg ?>">
                <div class="rp-quick-top">
                    <span class="rp-quick-icon"><i class="uil <?= $icon ?>"></i></span>
                    <span class="rp-quick-count"><?= $count ?> case<?= $count === 1 ? '' : 's' ?></span>
                </div>
                <div class="rp-quick-title"><?= $title ?></div>
                <div class="rp-quick-sub"><?= htmlspecialchars($sub) ?></div>
                <a href="../barangay/generate_report.php?period=<?= $period ?>" class="rp-quick-btn"><i class="uil uil-file-download"></i> Download PDF</a>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="rp-two">
        <!-- KP Monitoring Form No. 1 -->
        <section class="rp-section rp-card">
            <div class="rp-card-icon" style="--c:#d97706;--cb:#fffbeb"><i class="uil uil-clipboard-notes"></i></div>
            <h2>KP Monitoring Form No. 1</h2>
            <p>The DILG monthly report of Katarungang Pambarangay cases filed in a month and the action taken. Prepared by the Barangay Secretary, attested by the Punong Barangay.</p>
            <form method="GET" action="../barangay/kp_monitoring_report.php" class="rp-month-form">
                <label>Month
                    <select name="month">
                        <?php for ($mo = 1; $mo <= 12; $mo++): ?>
                        <option value="<?= $mo ?>" <?= $mo === (int)date('n') ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $mo, 1)) ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label>Year
                    <select name="year">
                        <?php for ($yr = (int)date('Y'); $yr >= $firstYear; $yr--): ?>
                        <option value="<?= $yr ?>"><?= $yr ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <button type="submit" class="rp-btn amber"><i class="uil uil-file-download"></i> Download Form</button>
            </form>
        </section>

        <!-- Custom reports -->
        <section class="rp-section rp-card">
            <div class="rp-card-icon" style="--c:#1d4ed8;--cb:#eff6ff"><i class="uil uil-sliders-v-alt"></i></div>
            <h2>Custom Reports</h2>
            <p>Choose your own filters, preview the records, then download.</p>
            <a href="cases_report.php?from=reports" class="rp-link">
                <span class="rp-link-icon"><i class="uil uil-folder-open"></i></span>
                <span><strong>Case Report</strong><small>Filter by status, case type, period or search</small></span>
                <i class="uil uil-angle-right"></i>
            </a>
            <a href="users_report.php?from=reports" class="rp-link">
                <span class="rp-link-icon"><i class="uil uil-users-alt"></i></span>
                <span><strong>User Registry Report</strong><small><?= $userCount ?> registered accounts · filter by role or status</small></span>
                <i class="uil uil-angle-right"></i>
            </a>
        </section>
    </div>
</div>
</div>

<style>
.rp-body{padding:24px 28px 44px;}
.rp-intro{margin:0 0 18px;font-size:13.5px;color:#475569;}
.rp-section{margin-bottom:18px;}
.rp-section-head{margin-bottom:12px;}
.rp-section h2{margin:0;font-size:16px;font-weight:700;color:#0f172a;}
.rp-section p{margin:3px 0 0;font-size:12.5px;color:#64748b;line-height:1.5;}
.rp-quick-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.rp-quick{display:flex;flex-direction:column;background:#fff;border:1px solid #e2e8f0;border-top:3px solid var(--c);border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(15,23,42,.04);}
.rp-quick-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.rp-quick-icon{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;background:var(--cb);color:var(--c);font-size:20px;}
.rp-quick-count{font-size:11.5px;font-weight:700;color:var(--c);background:var(--cb);border-radius:999px;padding:3px 10px;}
.rp-quick-title{font-size:15px;font-weight:700;color:#0f172a;}
.rp-quick-sub{font-size:12px;color:#64748b;margin:2px 0 14px;flex:1;}
.rp-quick-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 12px;border-radius:9px;background:var(--c);color:#fff;font-size:12.5px;font-weight:600;text-decoration:none;}
.rp-quick-btn:hover{filter:brightness(1.1);}
.rp-two{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.rp-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;box-shadow:0 1px 4px rgba(15,23,42,.04);margin-bottom:0;}
.rp-card-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:var(--cb);color:var(--c);font-size:21px;margin-bottom:12px;}
.rp-month-form{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-top:16px;}
.rp-month-form label{display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#6b7280;}
.rp-month-form select{height:38px;min-width:140px;border:1px solid #d1d5db;border-radius:8px;padding:0 10px;font-size:13px;color:#111827;background:#fff;font-family:inherit;}
.rp-btn{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 14px;border-radius:9px;border:0;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit;color:#fff;}
.rp-btn.amber{background:#d97706;}
.rp-btn.amber:hover{background:#b45309;}
.rp-link{display:flex;align-items:center;gap:12px;margin-top:12px;padding:12px 14px;border:1px solid #e2e8f0;border-radius:11px;text-decoration:none;color:#334155;transition:border-color .15s,background .15s;}
.rp-link:hover{border-color:#93c5fd;background:#f8fbff;}
.rp-link-icon{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#eff6ff;color:#1d4ed8;font-size:18px;flex-shrink:0;}
.rp-link span:nth-child(2){flex:1;}
.rp-link strong{display:block;font-size:13.5px;color:#0f172a;}
.rp-link small{display:block;font-size:12px;color:#64748b;margin-top:1px;}
.rp-link > i{font-size:20px;color:#94a3b8;}
@media(max-width:1200px){.rp-quick-grid{grid-template-columns:repeat(2,1fr);}.rp-two{grid-template-columns:1fr;}}
@media(max-width:640px){.rp-body{padding:16px;}.rp-quick-grid{grid-template-columns:1fr;}}
</style>
</body>
</html>
