<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/flexible_report.php';

// Same filters, rows and formal PDF as the barangay Flexible Reports
// (includes/flexible_report.php, barangay/generate_report.php); laid out like the User Report.
$includeAll = (string)($_GET['include_all'] ?? '0') === '1';
$params = gabaybatas_flex_params($includeAll ? [] : $_GET);
$report = gabaybatas_flex_report($conn, '', $params, 50);
$groups = gabaybatas_flex_groups();
$caseTypes = gabaybatas_flex_case_types($conn, '');
$stages = kp_stages();
$statusesByStage = [];
foreach (kp_statuses() as $key => $info) {
    $statusesByStage[$info['stage']][$key] = $info['label'];
}

// The form keeps what was typed even when "include all" overrides it.
$formParams = gabaybatas_flex_params($_GET);
$filterText = $includeAll ? 'All data mode' : ($report['applied'] ? implode(' | ', $report['applied']) : 'None (all non-archived cases)');
$downloadUrl = '../barangay/generate_report.php?' . http_build_query(array_filter($params, static fn($v) => $v !== ''));

include('../includes/header_admin.php');
include('../includes/sidebar_admin.php');
?>

<div class="main">
<div class="topbar">
    <div class="topbar-left"><div class="topbar-title">Case Reports</div></div>
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

<div class="page-body ur-body">
    <div class="ur-head">
        <p class="ur-sub">Build a custom dataset and download it as a formal Barangay Muñoz report (long bond). Archived cases are not included.</p>
        <?php // Opened from the Reports page: Back returns there (kept through Apply and Reset).
        $fromReports = ($_GET['from'] ?? '') === 'reports'; ?>
        <a href="<?= $fromReports ? 'reports.php' : 'all_cases.php' ?>" class="ur-back"><i class="uil uil-arrow-left"></i> Back to <?= $fromReports ? 'Reports' : 'Manage Cases' ?></a>
    </div>

    <div class="ur-card">
        <form method="GET" class="ur-form">
            <?php if ($fromReports): ?><input type="hidden" name="from" value="reports"><?php endif; ?>
            <div class="ur-grid">
                <div class="ur-field">
                    <label for="searchInput">Search</label>
                    <input type="text" name="search" id="searchInput" value="<?= htmlspecialchars($formParams['search']) ?>" placeholder="Case no., complainant, respondent">
                </div>
                <div class="ur-field">
                    <label for="statusInput">Status</label>
                    <select name="status" id="statusInput">
                        <option value="">All Statuses</option>
                        <optgroup label="Status groups">
                            <?php foreach ($groups as $key => $group): ?>
                            <option value="group:<?= $key ?>" <?= $formParams['status'] === 'group:' . $key ? 'selected' : '' ?>><?= htmlspecialchars($group['label']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php foreach ($statusesByStage as $stage => $statusList): ?>
                        <optgroup label="<?= htmlspecialchars($stages[$stage]['label'] ?? ucfirst($stage)) ?>">
                            <?php foreach ($statusList as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $formParams['status'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ur-field">
                    <label for="caseTypeInput">Case Type</label>
                    <select name="case_type" id="caseTypeInput">
                        <option value="">All Types</option>
                        <?php foreach ($caseTypes as $typeOpt): ?>
                        <option value="<?= htmlspecialchars($typeOpt) ?>" <?= $formParams['case_type'] === $typeOpt ? 'selected' : '' ?>><?= htmlspecialchars($typeOpt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ur-field">
                    <label for="periodInput">Period</label>
                    <select name="period" id="periodInput">
                        <option value="all" <?= $formParams['period'] === 'all' ? 'selected' : '' ?>>All Periods</option>
                        <option value="month" <?= $formParams['period'] === 'month' ? 'selected' : '' ?>>This Month</option>
                        <option value="quarter" <?= $formParams['period'] === 'quarter' ? 'selected' : '' ?>>This Quarter</option>
                        <option value="year" <?= $formParams['period'] === 'year' ? 'selected' : '' ?>>This Year</option>
                        <option value="custom" <?= $formParams['period'] === 'custom' ? 'selected' : '' ?>>Custom Dates</option>
                    </select>
                </div>
                <div class="ur-field ur-date">
                    <label for="dateFromInput">From</label>
                    <input type="date" name="date_from" id="dateFromInput" value="<?= htmlspecialchars($formParams['date_from']) ?>">
                </div>
                <div class="ur-field ur-date">
                    <label for="dateToInput">To</label>
                    <input type="date" name="date_to" id="dateToInput" value="<?= htmlspecialchars($formParams['date_to']) ?>">
                </div>
                <div class="ur-field ur-check">
                    <label class="ur-check-label"><input type="checkbox" name="include_all" value="1" <?= $includeAll ? 'checked' : '' ?>> Ignore filters and include all cases</label>
                </div>
            </div>
            <div class="ur-actions">
                <button type="submit" class="ur-btn apply"><i class="uil uil-filter"></i> Apply</button>
                <a href="cases_report.php<?= $fromReports ? '?from=reports' : '' ?>" class="ur-btn reset"><i class="uil uil-redo"></i> Reset</a>
                <a href="<?= htmlspecialchars($downloadUrl) ?>" class="ur-btn download"><i class="uil uil-file-download"></i> Download PDF</a>
            </div>
        </form>
    </div>

    <div class="ur-card">
        <div class="ur-meta"><strong>Applied Filters:</strong> <?= htmlspecialchars($filterText) ?></div>
        <div class="ur-meta"><strong>Total Records in Download:</strong> <?= (int)$report['total'] ?></div>

        <div class="ur-table-wrap">
            <table class="ur-table">
                <thead>
                    <tr><th>Case No.</th><th>Complainant</th><th>Respondent</th><th>Case Type</th><th>Status</th><th>Date Filed</th></tr>
                </thead>
                <tbody>
                    <?php if ($report['rows']): ?>
                        <?php foreach ($report['rows'] as $row): $dateTs = strtotime((string)$row['date_submitted']); ?>
                        <tr>
                            <td><?= htmlspecialchars(gabaybatas_format_case_id($row['case_id'] ?? '', $row['id'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['name'] ?: '—')) ?></td>
                            <td><?= htmlspecialchars((string)($row['complained_person'] ?: '—')) ?></td>
                            <td><?= htmlspecialchars((string)($row['case_type'] ?: '—')) ?></td>
                            <td><?= htmlspecialchars(kp_status_label((string)$row['kp_status'])) ?></td>
                            <td><?= $dateTs ? date('M d, Y', $dateTs) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="6" class="ur-empty">No records matched the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($report['total'] > 50): ?>
        <div class="ur-note">Preview shows first 50 rows only. Download includes all <?= (int)$report['total'] ?> rows.</div>
        <?php endif; ?>
    </div>
</div>
</div>

<script>
(function () {
    // The date range applies only to "Custom Dates".
    const period = document.getElementById('periodInput');
    const dates = [document.getElementById('dateFromInput'), document.getElementById('dateToInput')];
    function syncDates() {
        const custom = period.value === 'custom';
        dates.forEach(function (input) {
            input.disabled = !custom;
            input.closest('.ur-field').classList.toggle('is-disabled', !custom);
        });
    }
    period.addEventListener('change', syncDates);
    syncDates();
})();
</script>

<style>
/* Same layout as the User Report (admin/users_report.php) */
.ur-body{padding:24px 28px 40px;}
.ur-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;}
.ur-sub{font-size:12.5px;color:var(--text-sub);margin:0;}
.ur-back{display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:12.5px;font-weight:600;color:#1d4ed8;background:#eff6ff;border:1px solid #dbeafe;border-radius:9px;padding:8px 13px;white-space:nowrap;}
.ur-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:12px;}
.ur-form{display:flex;flex-direction:column;gap:12px;}
.ur-grid{display:grid;grid-template-columns:1.3fr 1.3fr 1fr 1fr .9fr .9fr;gap:10px;}
.ur-field{display:flex;flex-direction:column;gap:6px;}
.ur-field.is-disabled{opacity:.45;}
.ur-field label{font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#6b7280;}
.ur-field input,.ur-field select{width:100%;height:36px;border:1px solid #d1d5db;border-radius:8px;padding:0 10px;font-size:12.5px;color:#111827;background:#fff;box-sizing:border-box;font-family:inherit;}
.ur-check{grid-column:1/-1;}
.ur-check-label{display:flex;align-items:center;gap:7px;font-size:12.5px;color:#111827;text-transform:none!important;letter-spacing:0!important;font-weight:600!important;}
.ur-check-label input[type="checkbox"]{width:13px;height:13px;accent-color:#1d4ed8;}
.ur-actions{display:flex;flex-wrap:wrap;gap:8px;}
.ur-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 12px;border-radius:8px;text-decoration:none;font-size:12.5px;font-weight:600;border:1px solid #d1d5db;background:#fff;color:#111827;font-family:inherit;}
.ur-btn.apply{background:#1d4ed8;border-color:#1d4ed8;color:#fff;cursor:pointer;}
.ur-btn.download{background:#111827;border-color:#111827;color:#fff;}
.ur-meta{font-size:12.5px;color:#334155;margin-bottom:6px;}
.ur-table-wrap{overflow-x:auto;margin-top:10px;}
.ur-table{width:100%;border-collapse:collapse;}
.ur-table th,.ur-table td{border:1px solid #d1d5db;padding:8px 10px;font-size:12.5px;text-align:left;vertical-align:top;}
.ur-table th{background:#f3f4f6;font-weight:700;color:#111827;}
.ur-empty{text-align:center;color:#6b7280;padding:16px;}
.ur-note{margin-top:8px;font-size:12px;color:#6b7280;}
@media(max-width:1200px){.ur-grid{grid-template-columns:repeat(3,minmax(140px,1fr));}}
@media(max-width:700px){.ur-body{padding:16px;}.ur-head{flex-direction:column;align-items:flex-start;}.ur-grid{grid-template-columns:1fr;}}
</style>

</body>
</html>
