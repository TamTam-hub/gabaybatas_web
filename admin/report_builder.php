<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/barangays.php';
require_once __DIR__ . '/../includes/barangay_auth.php';

$caseColumns = [];
$colResult = $conn->query('SHOW COLUMNS FROM cases');
if ($colResult) {
    while ($col = $colResult->fetch_assoc()) {
        $caseColumns[] = $col['Field'];
    }
}

$hasColumn = static function (string $column) use ($caseColumns): bool {
    return in_array($column, $caseColumns, true);
};

$idColumn = $hasColumn('id') ? 'id' : ($hasColumn('case_id') ? 'case_id' : ($caseColumns[0] ?? 'id'));
$displayIdColumn = $hasColumn('case_id') ? 'case_id' : ($hasColumn('id') ? 'id' : $idColumn);
$residentColumn = $hasColumn('name') ? 'name' : ($hasColumn('resident_name') ? 'resident_name' : null);
$typeColumn = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$statusColumn = $hasColumn('status') ? 'status' : null;
$dateColumn = $hasColumn('date_submitted') ? 'date_submitted' : ($hasColumn('created_at') ? 'created_at' : ($hasColumn('updated_at') ? 'updated_at' : null));
$barangayColumn = $hasColumn('barangay') ? 'barangay' : null;

$search = trim((string)($_GET['search'] ?? ''));
$reportType = strtolower(trim((string)($_GET['type'] ?? 'all')));
$allowedTypes = ['all', 'monthly', 'quarterly', 'yearly'];
if (!in_array($reportType, $allowedTypes, true)) {
    $reportType = 'all';
}

$statusFilter = '';
$statusRaw = trim((string)($_GET['status'] ?? ''));
foreach (['Pending', 'In Progress', 'Resolved'] as $allowedStatus) {
    if (strcasecmp($statusRaw, $allowedStatus) === 0) {
        $statusFilter = $allowedStatus;
        break;
    }
}

$caseTypeFilter = trim((string)($_GET['case_type'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$includeAll = (string)($_GET['include_all'] ?? '0') === '1';

$isValidDate = static function (string $value): bool {
    if ($value === '') return false;
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
};

$dateFrom = $isValidDate($dateFrom) ? $dateFrom : '';
$dateTo = $isValidDate($dateTo) ? $dateTo : '';
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$typeOptions = [];
if ($typeColumn) {
    $typeResult = $conn->query("SELECT DISTINCT {$typeColumn} AS v FROM cases WHERE {$typeColumn} IS NOT NULL AND TRIM({$typeColumn}) <> '' ORDER BY {$typeColumn} ASC");
    if ($typeResult) {
        while ($row = $typeResult->fetch_assoc()) {
            $typeOptions[] = trim((string)($row['v'] ?? ''));
        }
    }
}

$whereParts = [];
if (!$includeAll) {
    if ($reportType !== 'all' && $dateColumn) {
        $nowMonth = (int)date('n');
        $nowYear = (int)date('Y');
        if ($reportType === 'monthly') {
            $whereParts[] = "MONTH({$dateColumn}) = {$nowMonth} AND YEAR({$dateColumn}) = {$nowYear}";
        } elseif ($reportType === 'quarterly') {
            $currentQuarter = (int)ceil($nowMonth / 3);
            $startMonth = (($currentQuarter - 1) * 3) + 1;
            $endMonth = $startMonth + 2;
            $whereParts[] = "MONTH({$dateColumn}) BETWEEN {$startMonth} AND {$endMonth} AND YEAR({$dateColumn}) = {$nowYear}";
        } elseif ($reportType === 'yearly') {
            $whereParts[] = "YEAR({$dateColumn}) = {$nowYear}";
        }
    }

    if ($statusFilter !== '' && $statusColumn) {
        $safe = $conn->real_escape_string($statusFilter);
        $whereParts[] = "LOWER({$statusColumn}) = LOWER('{$safe}')";
    }

    if ($caseTypeFilter !== '' && $typeColumn) {
        $safe = $conn->real_escape_string($caseTypeFilter);
        $whereParts[] = "LOWER({$typeColumn}) = LOWER('{$safe}')";
    }

    if ($search !== '') {
        $safeSearch = $conn->real_escape_string($search);
        $searchParts = [];
        if ($residentColumn) $searchParts[] = "{$residentColumn} LIKE '%{$safeSearch}%'";
        if ($typeColumn) $searchParts[] = "{$typeColumn} LIKE '%{$safeSearch}%'";
        if ($statusColumn) $searchParts[] = "{$statusColumn} LIKE '%{$safeSearch}%'";
        if ($barangayColumn) $searchParts[] = "{$barangayColumn} LIKE '%{$safeSearch}%'";
        if ($displayIdColumn) $searchParts[] = "CAST({$displayIdColumn} AS CHAR) LIKE '%{$safeSearch}%'";
        if ($searchParts) {
            $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    if ($dateColumn && $dateFrom !== '') {
        $safe = $conn->real_escape_string($dateFrom);
        $whereParts[] = "DATE({$dateColumn}) >= '{$safe}'";
    }
    if ($dateColumn && $dateTo !== '') {
        $safe = $conn->real_escape_string($dateTo);
        $whereParts[] = "DATE({$dateColumn}) <= '{$safe}'";
    }
}

$whereSql = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
$orderColumn = $dateColumn ?: $idColumn;

$selectParts = [
    "{$idColumn} AS row_id",
    "{$displayIdColumn} AS display_case_id",
    ($residentColumn ? "{$residentColumn} AS resident_name" : "'' AS resident_name"),
    ($typeColumn ? "{$typeColumn} AS case_type" : "'' AS case_type"),
    ($statusColumn ? "{$statusColumn} AS case_status" : "'' AS case_status"),
    ($barangayColumn ? "{$barangayColumn} AS barangay" : "'' AS barangay"),
    ($dateColumn ? "{$dateColumn} AS case_date" : "NULL AS case_date"),
];

$totalResult = $conn->query('SELECT COUNT(*) AS total FROM cases ' . $whereSql);
$totalCases = (int)($totalResult ? ($totalResult->fetch_assoc()['total'] ?? 0) : 0);

$pending = 0;
$progress = 0;
$resolved = 0;
if ($statusColumn) {
    $pendingRes = $conn->query("SELECT COUNT(*) AS total FROM cases {$whereSql}" . ($whereSql ? ' AND ' : ' WHERE ') . "LOWER({$statusColumn}) = 'pending'");
    $progressRes = $conn->query("SELECT COUNT(*) AS total FROM cases {$whereSql}" . ($whereSql ? ' AND ' : ' WHERE ') . "LOWER({$statusColumn}) = 'in progress'");
    $resolvedRes = $conn->query("SELECT COUNT(*) AS total FROM cases {$whereSql}" . ($whereSql ? ' AND ' : ' WHERE ') . "LOWER({$statusColumn}) = 'resolved'");
    $pending = (int)($pendingRes ? ($pendingRes->fetch_assoc()['total'] ?? 0) : 0);
    $progress = (int)($progressRes ? ($progressRes->fetch_assoc()['total'] ?? 0) : 0);
    $resolved = (int)($resolvedRes ? ($resolvedRes->fetch_assoc()['total'] ?? 0) : 0);
}

$previewRows = [];
$previewSql = 'SELECT ' . implode(', ', $selectParts) . ' FROM cases ' . $whereSql . ' ORDER BY ' . $orderColumn . ' DESC LIMIT 50';
$previewResult = $conn->query($previewSql);
if ($previewResult) {
    while ($row = $previewResult->fetch_assoc()) {
        $previewRows[] = $row;
    }
}

$downloadParams = [
    'type' => $reportType,
    'status' => $statusFilter,
    'case_type' => $caseTypeFilter,
    'search' => $search,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'include_all' => $includeAll ? '1' : '0',
];
$downloadUrl = 'report_download.php?' . http_build_query($downloadParams);

include('../includes/header_admin.php');
include('../includes/sidebar_admin.php');
?>

<div class="main">
<div class="topbar">
    <div class="topbar-left"><div class="topbar-title">Report Builder</div></div>
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

<div class="page-body rb-body">
    <div class="rb-head">
        <div>
            <div class="rb-title">Generate New Report</div>
            <div class="rb-sub">Choose the records, time range, and scope before downloading.</div>
        </div>
        <a href="reports.php" class="rb-back"><i class="uil uil-arrow-left"></i> Back to Reports</a>
    </div>

    <div class="rb-card">
        <form method="GET" class="rb-form">
            <div class="rb-grid">
                <div class="rb-field">
                    <label>Report Scope</label>
                    <select name="type">
                        <option value="all" <?= $reportType === 'all' ? 'selected' : '' ?>>All Periods</option>
                        <option value="monthly" <?= $reportType === 'monthly' ? 'selected' : '' ?>>This Month</option>
                        <option value="quarterly" <?= $reportType === 'quarterly' ? 'selected' : '' ?>>This Quarter</option>
                        <option value="yearly" <?= $reportType === 'yearly' ? 'selected' : '' ?>>This Year</option>
                    </select>
                </div>
                <div class="rb-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach (['Pending', 'In Progress', 'Resolved'] as $statusOpt): ?>
                        <option value="<?= htmlspecialchars($statusOpt) ?>" <?= $statusFilter === $statusOpt ? 'selected' : '' ?>><?= htmlspecialchars($statusOpt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rb-field">
                    <label>Case Type</label>
                    <select name="case_type">
                        <option value="">All Types</option>
                        <?php foreach ($typeOptions as $typeOpt): ?>
                        <option value="<?= htmlspecialchars($typeOpt) ?>" <?= $caseTypeFilter === $typeOpt ? 'selected' : '' ?>><?= htmlspecialchars($typeOpt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rb-field">
                    <label>Search</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Resident, type, barangay, case ID">
                </div>
                <div class="rb-field">
                    <label>From</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="rb-field">
                    <label>To</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="rb-field rb-check">
                    <label class="rb-check-label"><input type="checkbox" name="include_all" value="1" <?= $includeAll ? 'checked' : '' ?>> Ignore scope filters and include all data</label>
                </div>
            </div>
            <div class="rb-actions">
                <button type="submit" class="rb-btn apply"><i class="uil uil-filter"></i> Apply</button>
                <a href="report_builder.php" class="rb-btn reset"><i class="uil uil-redo"></i> Reset</a>
                <a href="<?= htmlspecialchars($downloadUrl) ?>" class="rb-btn download" target="_blank"><i class="uil uil-file-download"></i> Download PDF</a>
            </div>
        </form>
    </div>

    <div class="rb-card">
        <div class="rb-stats">
            <div><strong>Total Records:</strong> <?= (int)$totalCases ?></div>
            <div><strong>Pending:</strong> <?= (int)$pending ?></div>
            <div><strong>In Progress:</strong> <?= (int)$progress ?></div>
            <div><strong>Resolved:</strong> <?= (int)$resolved ?></div>
        </div>
        <div class="rb-preview-title">Preview</div>
        <div class="rb-table-wrap">
            <table class="rb-table">
                <thead>
                    <tr><th>Case ID</th><th>Resident</th><th>Type</th><th>Status</th><th>Barangay</th><th>Date Filed</th></tr>
                </thead>
                <tbody>
                    <?php if ($previewRows): ?>
                        <?php foreach ($previewRows as $row):
                            $dateRaw = trim((string)($row['case_date'] ?? ''));
                            $dateTs = $dateRaw !== '' ? strtotime($dateRaw) : false;
                            $dateDisplay = $dateTs !== false ? date('M d, Y h:i A', $dateTs) : ($dateRaw !== '' ? $dateRaw : 'N/A');
                        ?>
                        <tr>
                            <td><?= htmlspecialchars(gabaybatas_format_case_id($row['display_case_id'] ?? '', $row['row_id'] ?? '')) ?></td>
                            <td><?= htmlspecialchars(trim((string)($row['resident_name'] ?? '')) !== '' ? (string)$row['resident_name'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars(trim((string)($row['case_type'] ?? '')) !== '' ? (string)$row['case_type'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars(trim((string)($row['case_status'] ?? '')) !== '' ? (string)$row['case_status'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars(trim((string)($row['barangay'] ?? '')) !== '' ? (string)$row['barangay'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($dateDisplay) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="6" class="rb-empty">No records match the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="rb-note">Preview shows the first 50 matching records. Download uses the exact same filters.</div>
    </div>
</div>
</div>

<style>
.rb-body{padding:24px 28px 40px;}
.rb-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;}
.rb-title{font-size:24px;font-weight:800;color:var(--text-main);}
.rb-sub{font-size:12.5px;color:var(--text-sub);margin-top:4px;}
.rb-back{display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:12.5px;font-weight:600;color:#1d4ed8;background:#eff6ff;border:1px solid #dbeafe;border-radius:9px;padding:8px 13px;white-space:nowrap;}
.rb-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:12px;}
.rb-form{display:flex;flex-direction:column;gap:12px;}
.rb-grid{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));gap:10px;}
.rb-field{display:flex;flex-direction:column;gap:6px;}
.rb-field label{font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#6b7280;}
.rb-field input,.rb-field select{width:100%;height:36px;border:1px solid #d1d5db;border-radius:8px;padding:0 10px;font-size:12.5px;color:#111827;background:#fff;}
.rb-check{justify-content:flex-end;grid-column:span 2;}
.rb-check-label{display:flex;align-items:center;gap:7px;font-size:12.5px;color:#111827;text-transform:none!important;letter-spacing:0!important;font-weight:600!important;}
.rb-check-label input[type="checkbox"]{width:13px;height:13px;accent-color:#1d4ed8;}
.rb-actions{display:flex;flex-wrap:wrap;gap:8px;}
.rb-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 12px;border-radius:8px;text-decoration:none;font-size:12.5px;font-weight:600;border:1px solid #d1d5db;background:#fff;color:#111827;}
.rb-btn.apply{background:#1d4ed8;border-color:#1d4ed8;color:#fff;cursor:pointer;}
.rb-btn.download{background:#111827;border-color:#111827;color:#fff;}
.rb-stats{display:flex;flex-wrap:wrap;gap:14px;font-size:12.5px;color:#334155;margin-bottom:10px;}
.rb-preview-title{font-size:13px;font-weight:700;color:#111827;margin-bottom:8px;}
.rb-table-wrap{overflow-x:auto;}
.rb-table{width:100%;border-collapse:collapse;}
.rb-table th,.rb-table td{border:1px solid #d1d5db;padding:8px 10px;font-size:12.5px;text-align:left;vertical-align:top;}
.rb-table th{background:#f3f4f6;font-weight:700;color:#111827;}
.rb-empty{text-align:center;color:#6b7280;padding:16px;}
.rb-note{margin-top:8px;font-size:12px;color:#6b7280;}
@media(max-width:1300px){.rb-grid{grid-template-columns:repeat(3,minmax(140px,1fr));}.rb-check{grid-column:span 3;}}
@media(max-width:700px){.rb-body{padding:16px;}.rb-head{flex-direction:column;align-items:flex-start;}.rb-grid{grid-template-columns:1fr;}.rb-check{grid-column:auto;}}
</style>

</body>
</html>
