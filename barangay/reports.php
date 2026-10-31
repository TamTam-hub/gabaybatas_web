<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/flexible_report.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/case_selection.php';
gabaybatas_block_barangay_delete_requests();

$currentBarangay = gabaybatas_get_user_barangay($conn);

// Same filters and rows as the PDF (includes/flexible_report.php); the preview shows one page at a time.
$params = gabaybatas_flex_params($_GET);
$report = gabaybatas_flex_report($conn, $currentBarangay, $params);
$perPage = gabaybatas_per_page(10);
$pageNo = min(max(1, (int)($_GET['page'] ?? 1)), max(1, (int)ceil($report['total'] / $perPage)));
$previewRows = array_slice($report['rows'], ($pageNo - 1) * $perPage, $perPage);
$groups = gabaybatas_flex_groups();
$caseTypes = gabaybatas_flex_case_types($conn, $currentBarangay);
$stages = kp_stages();
$statusesByStage = [];
foreach (kp_statuses() as $key => $info) {
    $statusesByStage[$info['stage']][$key] = $info['label'];
}

$downloadUrl = 'generate_report.php?' . http_build_query(array_filter($params, static fn($v) => $v !== ''));
$allDataUrl = 'generate_report.php?period=all';
// Selected cases are shown back in the Search box as case numbers (ids are kept in $params['cases']).
$caseCodes = $params['cases'] === '' ? '' : implode(', ', array_map(static fn($id) => 'C-' . str_pad($id, 4, '0', STR_PAD_LEFT), explode(',', $params['cases'])));
?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-title">Flexible Reports</div>
        <div class="topbar-right">
            <div class="icon-btn" title="Notifications">
                <i class="uil uil-bell"></i>
            </div>
            <div class="topbar-divider"></div>
            <div class="user-pill">
                <div class="user-avatar"><i class="uil uil-user"></i></div>
                <div class="user-text">
                    <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Barangay Official') ?></strong>
                    <span>Barangay Administrator</span>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="page-header">
            <div>
                <div class="page-header-sub">Choose exactly what data to preview and download. Archived cases are not included.</div>
            </div>
        </div>

        <div class="card-box filter-card">
            <form method="GET" class="filter-form" id="reportFilterForm">
                <div class="filter-group">
                    <label class="filter-label" for="periodInput">Period</label>
                    <select class="filter-input" name="period" id="periodInput">
                        <option value="all" <?= $params['period'] === 'all' ? 'selected' : '' ?>>All Periods</option>
                        <option value="month" <?= $params['period'] === 'month' ? 'selected' : '' ?>>This Month</option>
                        <option value="quarter" <?= $params['period'] === 'quarter' ? 'selected' : '' ?>>This Quarter</option>
                        <option value="year" <?= $params['period'] === 'year' ? 'selected' : '' ?>>This Year</option>
                        <option value="custom" <?= $params['period'] === 'custom' ? 'selected' : '' ?>>Custom Dates</option>
                    </select>
                </div>

                <div class="filter-group filter-date">
                    <label class="filter-label" for="dateFromInput">From</label>
                    <input class="filter-input" type="date" name="date_from" id="dateFromInput" value="<?= htmlspecialchars($params['date_from']) ?>">
                </div>

                <div class="filter-group filter-date">
                    <label class="filter-label" for="dateToInput">To</label>
                    <input class="filter-input" type="date" name="date_to" id="dateToInput" value="<?= htmlspecialchars($params['date_to']) ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="statusInput">Status</label>
                    <select class="filter-input" name="status" id="statusInput">
                        <option value="">All Statuses</option>
                        <optgroup label="Status groups">
                            <?php foreach ($groups as $key => $group): ?>
                            <option value="group:<?= $key ?>" <?= $params['status'] === 'group:' . $key ? 'selected' : '' ?>><?= htmlspecialchars($group['label']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php foreach ($statusesByStage as $stage => $statusList): ?>
                        <optgroup label="<?= htmlspecialchars($stages[$stage]['label'] ?? ucfirst($stage)) ?>">
                            <?php foreach ($statusList as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $params['status'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="caseTypeInput">Case Type</label>
                    <select class="filter-input" name="case_type" id="caseTypeInput">
                        <option value="">All Types</option>
                        <?php foreach ($caseTypes as $typeOpt): ?>
                        <option value="<?= htmlspecialchars($typeOpt) ?>" <?= $params['case_type'] === $typeOpt ? 'selected' : '' ?>><?= htmlspecialchars($typeOpt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="searchInput">Search</label>
                    <input class="filter-input" type="text" name="search" id="searchInput" value="<?= htmlspecialchars($caseCodes !== '' ? $caseCodes : $params['search']) ?>" placeholder="Case no., name, or several: C-0037, C-0055" title="Type a case number or name. To list specific cases, type several case numbers separated by commas.">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-action btn-apply"><i class="uil uil-filter"></i> Apply Filters</button>
                    <a href="reports.php" class="btn-action btn-reset"><i class="uil uil-redo"></i> Reset</a>
                    <a href="<?= htmlspecialchars($downloadUrl) ?>" class="btn-action btn-download"><i class="uil uil-file-download"></i> Download Filtered PDF</a>
                    <a href="<?= htmlspecialchars($allDataUrl) ?>" class="btn-action btn-all"><i class="uil uil-database"></i> Download All Data</a>
                </div>
            </form>
            <div class="applied-row">
                <strong>Applied:</strong>
                <?php if ($report['applied']): ?>
                    <?php foreach ($report['applied'] as $filterLabel): ?>
                    <span class="applied-pill"><?= htmlspecialchars($filterLabel) ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="applied-note">No filters. Showing all non-archived cases of Barangay <?= htmlspecialchars(KP_BARANGAY_NAME) ?>.</span>
                <?php endif; ?>
                <?php if ($params['cases'] !== ''): ?>
                    <span class="applied-note">Only these cases are listed; the other filters are not used.</span>
                    <a href="reports.php" class="applied-clear"><i class="uil uil-times"></i> Clear</a>
                <?php endif; ?>
            </div>
        </div>

        <?php gabaybatas_summary_cards($report['total'], $report['counts'], $report['period_label']); ?>

        <div class="card-box">
            <div class="table-card-header">
                <div>
                    <div class="section-heading">Preview of Report Data</div>
                    <div class="section-heading-sub">These are the cases included in the downloaded PDF.</div>
                </div>
                <div class="record-count">
                    <?= $report['total'] ?> case<?= $report['total'] === 1 ? '' : 's' ?> in the PDF
                </div>
            </div>

            <div class="table-wrap">
                <table class="table-dashboard">
                    <thead>
                        <tr>
                            <th class="gb-sel-col"><input type="checkbox" class="gb-sel-all" aria-label="Select all cases on this page" title="Select all on this page"></th>
                            <th>Case No.</th>
                            <th>Complainant</th>
                            <th>Respondent</th>
                            <th>Case Type</th>
                            <th>Status</th>
                            <th>Date Filed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($previewRows): ?>
                            <?php foreach ($previewRows as $row):
                                $color = kp_status_color($row['kp_status']);
                                $dateTs = strtotime((string)$row['date_submitted']);
                            ?>
                            <?php $rowCode = gabaybatas_format_case_id($row['case_id'] ?? '', $row['id'] ?? ''); ?>
                            <tr>
                                <td class="gb-sel-col"><input type="checkbox" class="gb-sel" value="<?= (int)$row['id'] ?>" data-code="<?= htmlspecialchars($rowCode) ?>" aria-label="Select <?= htmlspecialchars($rowCode) ?>"></td>
                                <td><strong><?= htmlspecialchars($rowCode) ?></strong></td>
                                <td><?= htmlspecialchars((string)($row['name'] ?: '—')) ?></td>
                                <td><?= htmlspecialchars((string)($row['complained_person'] ?: '—')) ?></td>
                                <td><?= htmlspecialchars((string)($row['case_type'] ?: '—')) ?></td>
                                <td><span class="status-badge" style="background:<?= $color ?>18;color:<?= $color ?>;border:1px solid <?= $color ?>33;"><?= htmlspecialchars(kp_status_label($row['kp_status'])) ?></span></td>
                                <td class="rp-date"><?php if ($dateTs): ?><strong><?= date('M d, Y', $dateTs) ?></strong><small><?= date('h:i A', $dateTs) ?></small><?php else: ?>N/A<?php endif; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty-row">No cases match the selected filters.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php gabaybatas_pagination($report['total'], $pageNo, $perPage, $params, 'cases'); ?>
            <?php gabaybatas_case_selection_bar('generate_report.php', 'reports.php'); ?>
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
            input.closest('.filter-group').classList.toggle('is-disabled', !custom);
        });
    }
    period.addEventListener('change', syncDates);
    syncDates();
})();
</script>

<style>
.page-header { margin-bottom: 18px; }
.page-header-sub { font-size: 13px; color: var(--text-light); margin-top: 3px; }

.filter-card { margin-bottom: 16px; }
.filter-form { display: grid; grid-template-columns: 1fr 150px 150px 1.3fr 1fr 1.2fr; gap: 10px; align-items: end; }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-group.is-disabled { opacity: .45; }
.filter-label { font-size: 11px; text-transform: uppercase; letter-spacing: .6px; color: var(--text-muted); font-weight: 700; }
.filter-input { width: 100%; border: 1px solid #dbe3ef; border-radius: 8px; padding: 9px 10px; background: #fff; font-size: 12.5px; color: #1f2937; box-sizing: border-box; }
.filter-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }

.filter-actions { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 8px; }
.btn-action { display: inline-flex; align-items: center; gap: 6px; border-radius: 8px; padding: 9px 12px; font-size: 12.5px; font-weight: 600; text-decoration: none; border: 1px solid transparent; }
.btn-apply { background: #1d4ed8; color: #fff; border-color: #1d4ed8; cursor: pointer; }
.btn-reset { background: #fff; color: #334155; border-color: #dbe3ef; }
.btn-download { background: #0f766e; color: #fff; border-color: #0f766e; }
.btn-all { background: #7c3aed; color: #fff; border-color: #7c3aed; }

.applied-row { margin-top: 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12.5px; color: #334155; }
.applied-pill { background: #eef2ff; border: 1px solid #c7d2fe; color: #3730a3; border-radius: 999px; padding: 3px 10px; font-size: 11.5px; font-weight: 700; }
.applied-note { color: #64748b; }

.stats-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px; }
.stat-card { display: flex; align-items: center; justify-content: space-between; }
.stat-total { border-top: 3px solid #0e2242; }
.stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: .7px; color: #64748b; font-weight: 700; }
.stat-value { font-family: 'Source Serif 4', serif; font-size: 30px; color: #0f172a; line-height: 1.1; }
.stat-sub { font-size: 11.5px; color: #94a3b8; }

.table-card-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px; gap: 10px; }
.section-heading { font-family: 'Source Serif 4', serif; font-size: 16px; font-weight: 700; color: #0f172a; }
.section-heading-sub { font-size: 12.5px; color: #64748b; margin-top: 2px; }
.record-count { font-size: 12px; color: #64748b; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 999px; padding: 5px 10px; white-space: nowrap; }

.table-wrap { overflow-x: auto; }
.table-dashboard { width: 100%; border-collapse: collapse; }
.table-dashboard th { background: #f8fafc; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .7px; font-weight: 700; padding: 10px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; }
.table-dashboard td { font-size: 13px; color: #1f2937; padding: 11px 12px; border-bottom: 1px solid #eef2f7; }
.table-dashboard tr:last-child td { border-bottom: none; }
.card-box .gb-pg { margin: 14px -24px -22px; border-radius: 0 0 14px 14px; }
/* UI refresh: clearer filters, one navy button style */
.gb-ui .page-header-sub { font-size: 13.5px; color: #4f5f75; }
.gb-ui .filter-label { font-size: 13px; text-transform: none; letter-spacing: .1px; color: #334155; }
.gb-ui .filter-input { min-height: 42px; font-size: 14px; border: 1.5px solid #d6deea; border-radius: 10px; }
.gb-ui .filter-input:focus { border-color: #24508f; box-shadow: 0 0 0 4px rgba(36,80,143,.12); }
.gb-ui .btn-action { min-height: 42px; padding: 10px 15px; font-size: 13.5px; font-weight: 700; border-radius: 10px; }
.gb-ui .btn-apply { background: linear-gradient(135deg, #24508f, #0e2242); border-color: transparent; box-shadow: 0 6px 14px rgba(26,58,107,.2); }
.gb-ui .btn-reset { border: 1.5px solid #d6deea; }
.gb-ui .btn-download, .gb-ui .btn-all { background: #fff; color: #1a3a6b; border: 1.5px solid #c9d6ea; }
.gb-ui .btn-download:hover, .gb-ui .btn-all:hover { background: #f6f9ff; border-color: #1a3a6b; }
.gb-ui .applied-row { font-size: 13.5px; }
.gb-ui .applied-pill { background: #eef3fb; border-color: #d6e2f5; color: #1a3a6b; font-size: 12.5px; }
.gb-ui .record-count { background: #eef3fb; border-color: #d6e2f5; color: #1a3a6b; font-weight: 700; font-size: 12.5px; }
.gb-ui .status-badge { font-size: 12px; padding: 4px 11px; }
.applied-clear { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; font-weight: 700; color: #b91c1c; text-decoration: none; }
.rp-date strong { display: block; font-weight: 600; white-space: nowrap; }
.rp-date small { display: block; font-size: 12.5px; color: #6f7f96; }
.empty-row { text-align: center; color: #94a3b8; padding: 24px 10px; }

.status-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }

@media (max-width: 1200px) {
    .filter-form { grid-template-columns: repeat(3, minmax(130px, 1fr)); }
    .stats-row { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 760px) {
    .filter-form { grid-template-columns: 1fr; }
    .stats-row { grid-template-columns: 1fr 1fr; }
}
</style>

</body>
</html>
