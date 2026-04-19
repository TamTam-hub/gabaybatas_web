<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
gabaybatas_block_barangay_delete_requests();

$currentBarangay = gabaybatas_get_user_barangay($conn);

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

$idColumn = $hasColumn('id') ? 'id' : ($hasColumn('case_id') ? 'case_id' : null);
$displayIdColumn = $hasColumn('case_id') ? 'case_id' : ($hasColumn('id') ? 'id' : null);
$residentColumn = $hasColumn('name') ? 'name' : ($hasColumn('resident_name') ? 'resident_name' : null);
$typeColumn = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$statusColumn = $hasColumn('status') ? 'status' : null;
$dateColumn = $hasColumn('date_submitted') ? 'date_submitted' : ($hasColumn('created_at') ? 'created_at' : ($hasColumn('updated_at') ? 'updated_at' : null));
$hasCaseBarangay = $hasColumn('barangay');

$reportType = strtolower(trim((string)($_GET['type'] ?? 'all')));
$allowedReportTypes = ['all', 'monthly', 'quarterly', 'yearly'];
if (!in_array($reportType, $allowedReportTypes, true)) {
    $reportType = 'all';
}

$statusFilterRaw = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['Pending', 'In Progress', 'Resolved'];
$statusFilter = '';
foreach ($allowedStatuses as $allowed) {
    if (strcasecmp($statusFilterRaw, $allowed) === 0) {
        $statusFilter = $allowed;
        break;
    }
}

$caseTypeFilter = trim((string)($_GET['case_type'] ?? ''));
$searchRaw = trim((string)($_GET['search'] ?? ''));
$dateFromRaw = trim((string)($_GET['date_from'] ?? ''));
$dateToRaw = trim((string)($_GET['date_to'] ?? ''));
$includeAll = (string)($_GET['include_all'] ?? '0') === '1';

$isValidDate = static function (string $value): bool {
    if ($value === '') {
        return false;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
};

$dateFrom = $isValidDate($dateFromRaw) ? $dateFromRaw : '';
$dateTo = $isValidDate($dateToRaw) ? $dateToRaw : '';
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$scopeSql = '';
if ($hasCaseBarangay && $currentBarangay !== '') {
    $scopeSql = " AND LOWER(barangay) = LOWER('" . $conn->real_escape_string($currentBarangay) . "')";
}

$typeOptions = [];
if ($typeColumn) {
    $typeSql = "SELECT DISTINCT {$typeColumn} AS v FROM cases WHERE 1=1{$scopeSql} AND {$typeColumn} IS NOT NULL AND TRIM({$typeColumn}) <> '' ORDER BY {$typeColumn} ASC";
    $typeResult = $conn->query($typeSql);
    if ($typeResult) {
        while ($row = $typeResult->fetch_assoc()) {
            $typeOptions[] = trim((string)($row['v'] ?? ''));
        }
    }
}

$whereParts = ['1=1'];
if ($scopeSql !== '') {
    $whereParts[] = ltrim($scopeSql, ' AND');
}

$appliedFilters = [];
if (!$includeAll) {
    $nowYear = (int)date('Y');
    $nowMonth = (int)date('n');

    if ($reportType === 'monthly' && $dateColumn) {
        $whereParts[] = "MONTH({$dateColumn}) = {$nowMonth} AND YEAR({$dateColumn}) = {$nowYear}";
        $appliedFilters[] = 'This Month';
    } elseif ($reportType === 'quarterly' && $dateColumn) {
        $currentQuarter = (int)ceil($nowMonth / 3);
        $quarterStart = (($currentQuarter - 1) * 3) + 1;
        $quarterEnd = $quarterStart + 2;
        $whereParts[] = "MONTH({$dateColumn}) BETWEEN {$quarterStart} AND {$quarterEnd} AND YEAR({$dateColumn}) = {$nowYear}";
        $appliedFilters[] = 'Quarter ' . $currentQuarter;
    } elseif ($reportType === 'yearly' && $dateColumn) {
        $whereParts[] = "YEAR({$dateColumn}) = {$nowYear}";
        $appliedFilters[] = 'Year ' . $nowYear;
    }

    if ($statusFilter !== '' && $statusColumn) {
        $safeStatus = $conn->real_escape_string($statusFilter);
        $whereParts[] = "LOWER({$statusColumn}) = LOWER('{$safeStatus}')";
        $appliedFilters[] = 'Status: ' . $statusFilter;
    }

    if ($caseTypeFilter !== '' && $typeColumn) {
        $safeType = $conn->real_escape_string($caseTypeFilter);
        $whereParts[] = "LOWER({$typeColumn}) = LOWER('{$safeType}')";
        $appliedFilters[] = 'Type: ' . $caseTypeFilter;
    }

    if ($searchRaw !== '') {
        $safeSearch = $conn->real_escape_string($searchRaw);
        $searchCaseDigits = preg_replace('/^\s*C\s*-\s*/i', '', $searchRaw);
        $searchCaseDigits = preg_replace('/\D+/', '', (string)$searchCaseDigits);
        $searchParts = [];

        if ($residentColumn) {
            $searchParts[] = "{$residentColumn} LIKE '%{$safeSearch}%'";
        }
        if ($typeColumn) {
            $searchParts[] = "{$typeColumn} LIKE '%{$safeSearch}%'";
        }
        if ($displayIdColumn) {
            $searchParts[] = "CAST({$displayIdColumn} AS CHAR) LIKE '%{$safeSearch}%'";
        }
        if ($idColumn) {
            $searchParts[] = "CAST({$idColumn} AS CHAR) LIKE '%{$safeSearch}%'";
        }
        if ($searchCaseDigits !== '' && $displayIdColumn) {
            $safeDigits = $conn->real_escape_string($searchCaseDigits);
            $searchParts[] = "CAST({$displayIdColumn} AS CHAR) LIKE '%{$safeDigits}%'";
        }

        if ($searchParts) {
            $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
            $appliedFilters[] = 'Search';
        }
    }

    if ($dateColumn && $dateFrom !== '') {
        $safeFrom = $conn->real_escape_string($dateFrom);
        $whereParts[] = "DATE({$dateColumn}) >= '{$safeFrom}'";
        $appliedFilters[] = 'From ' . $dateFrom;
    }
    if ($dateColumn && $dateTo !== '') {
        $safeTo = $conn->real_escape_string($dateTo);
        $whereParts[] = "DATE({$dateColumn}) <= '{$safeTo}'";
        $appliedFilters[] = 'To ' . $dateTo;
    }
}

$whereSql = ' WHERE ' . implode(' AND ', $whereParts);

$total = 0;
$pending = 0;
$resolved = 0;
$inProgress = 0;
$thisMonth = 0;

$totalResult = $conn->query('SELECT COUNT(*) AS total FROM cases' . $whereSql);
if ($totalResult) {
    $total = (int)($totalResult->fetch_assoc()['total'] ?? 0);
}

if ($statusColumn) {
    $pendingResult = $conn->query("SELECT COUNT(*) AS total FROM cases{$whereSql} AND LOWER({$statusColumn}) = 'pending'");
    $progressResult = $conn->query("SELECT COUNT(*) AS total FROM cases{$whereSql} AND LOWER({$statusColumn}) = 'in progress'");
    $resolvedResult = $conn->query("SELECT COUNT(*) AS total FROM cases{$whereSql} AND LOWER({$statusColumn}) = 'resolved'");

    $pending = (int)($pendingResult ? ($pendingResult->fetch_assoc()['total'] ?? 0) : 0);
    $inProgress = (int)($progressResult ? ($progressResult->fetch_assoc()['total'] ?? 0) : 0);
    $resolved = (int)($resolvedResult ? ($resolvedResult->fetch_assoc()['total'] ?? 0) : 0);
}

if ($dateColumn) {
    $month = (int)date('n');
    $year = (int)date('Y');
    $monthResult = $conn->query("SELECT COUNT(*) AS total FROM cases{$whereSql} AND MONTH({$dateColumn}) = {$month} AND YEAR({$dateColumn}) = {$year}");
    $thisMonth = (int)($monthResult ? ($monthResult->fetch_assoc()['total'] ?? 0) : 0);
}

$reportRows = [];
if ($idColumn && $displayIdColumn) {
    $selectParts = [
        "{$idColumn} AS row_id",
        "{$displayIdColumn} AS display_case_id",
    ];
    if ($residentColumn) {
        $selectParts[] = "{$residentColumn} AS resident_name";
    }
    if ($typeColumn) {
        $selectParts[] = "{$typeColumn} AS case_type";
    }
    if ($statusColumn) {
        $selectParts[] = "{$statusColumn} AS case_status";
    }
    if ($dateColumn) {
        $selectParts[] = "{$dateColumn} AS case_date";
    }

    $orderSql = $dateColumn ? " ORDER BY {$dateColumn} DESC" : " ORDER BY {$idColumn} DESC";
    $previewSql = 'SELECT ' . implode(', ', $selectParts) . ' FROM cases' . $whereSql . $orderSql . ' LIMIT 200';
    $previewResult = $conn->query($previewSql);
    if ($previewResult) {
        while ($row = $previewResult->fetch_assoc()) {
            $reportRows[] = $row;
        }
    }
}

$queryBase = [
    'type' => $reportType,
    'status' => $statusFilter,
    'case_type' => $caseTypeFilter,
    'search' => $searchRaw,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'include_all' => $includeAll ? '1' : '0',
];

$downloadQuery = $queryBase;
$downloadQuery['format'] = 'pdf';
$downloadUrl = 'generate_report.php?' . http_build_query($downloadQuery);

$allDataQuery = [
    'type' => 'all',
    'include_all' => '1',
    'format' => 'pdf',
];
$allDataUrl = 'generate_report.php?' . http_build_query($allDataQuery);
?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-title">Reports</div>
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
                <div class="page-header-title">Flexible Reports</div>
                <div class="page-header-sub">Choose exactly what data to preview and download.</div>
            </div>
        </div>

        <div class="card-box filter-card">
            <form method="GET" class="filter-form" id="reportFilterForm">
                <div class="filter-group">
                    <label class="filter-label">Report Scope</label>
                    <select class="filter-input" name="type" id="reportTypeInput">
                        <option value="all" <?= $reportType === 'all' ? 'selected' : '' ?>>All Periods</option>
                        <option value="monthly" <?= $reportType === 'monthly' ? 'selected' : '' ?>>This Month</option>
                        <option value="quarterly" <?= $reportType === 'quarterly' ? 'selected' : '' ?>>This Quarter</option>
                        <option value="yearly" <?= $reportType === 'yearly' ? 'selected' : '' ?>>This Year</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select class="filter-input" name="status" id="statusInput">
                        <option value="">All Statuses</option>
                        <?php foreach ($allowedStatuses as $statusOpt): ?>
                        <option value="<?= htmlspecialchars($statusOpt) ?>" <?= $statusFilter === $statusOpt ? 'selected' : '' ?>><?= htmlspecialchars($statusOpt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Case Type</label>
                    <select class="filter-input" name="case_type" id="caseTypeInput">
                        <option value="">All Types</option>
                        <?php foreach ($typeOptions as $typeOpt): ?>
                        <option value="<?= htmlspecialchars($typeOpt) ?>" <?= $caseTypeFilter === $typeOpt ? 'selected' : '' ?>><?= htmlspecialchars($typeOpt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Search</label>
                    <input class="filter-input" type="text" name="search" id="searchInput" value="<?= htmlspecialchars($searchRaw) ?>" placeholder="Resident, type, or case ID">
                </div>

                <div class="filter-group filter-date">
                    <label class="filter-label">From</label>
                    <input class="filter-input" type="date" name="date_from" id="dateFromInput" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>

                <div class="filter-group filter-date">
                    <label class="filter-label">To</label>
                    <input class="filter-input" type="date" name="date_to" id="dateToInput" value="<?= htmlspecialchars($dateTo) ?>">
                </div>

                <div class="filter-group filter-check">
                    <label class="check-wrap">
                        <input type="checkbox" name="include_all" value="1" id="includeAllInput" <?= $includeAll ? 'checked' : '' ?>>
                        <span>Generate everything (ignore filters)</span>
                    </label>
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
                <?php if ($includeAll): ?>
                    <span class="applied-pill">All Data (Barangay Scope)</span>
                <?php elseif ($appliedFilters): ?>
                    <?php foreach ($appliedFilters as $filterLabel): ?>
                    <span class="applied-pill"><?= htmlspecialchars($filterLabel) ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="applied-note">No extra filters applied. Showing all available barangay records.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="stats-row">
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Dataset Total</div>
                    <div class="stat-value"><?= (int)$total ?></div>
                    <div class="stat-sub">records in generated scope</div>
                </div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?= (int)$pending ?></div>
                    <div class="stat-sub">awaiting action</div>
                </div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">In Progress</div>
                    <div class="stat-value"><?= (int)$inProgress ?></div>
                    <div class="stat-sub">active handling</div>
                </div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Resolved</div>
                    <div class="stat-value"><?= (int)$resolved ?></div>
                    <div class="stat-sub">closed cases</div>
                </div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">This Month</div>
                    <div class="stat-value"><?= (int)$thisMonth ?></div>
                    <div class="stat-sub">in current month</div>
                </div>
            </div>
        </div>

        <div class="card-box">
            <div class="table-card-header">
                <div>
                    <div class="section-heading">Preview of Report Data</div>
                    <div class="section-heading-sub">These are the exact rows that will be downloaded.</div>
                </div>
                <div class="record-count"><?= count($reportRows) ?> preview rows (max 200)</div>
            </div>

            <div class="table-wrap">
                <table class="table-dashboard">
                    <thead>
                        <tr>
                            <th>Case ID</th>
                            <th>Resident Name</th>
                            <th>Case Type</th>
                            <th>Status</th>
                            <th>Date Filed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($reportRows): ?>
                            <?php foreach ($reportRows as $row):
                                $statusText = trim((string)($row['case_status'] ?? ''));
                                $statusClass = strtolower(str_replace(' ', '-', $statusText));
                                $dateRaw = (string)($row['case_date'] ?? '');
                                $dateTs = strtotime($dateRaw);
                                $dateDisplay = $dateTs !== false ? date('M d, Y h:i A', $dateTs) : ($dateRaw !== '' ? $dateRaw : 'N/A');
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars(gabaybatas_format_case_id($row['display_case_id'] ?? '', $row['row_id'] ?? '')) ?></strong></td>
                                <td><?= htmlspecialchars((string)($row['resident_name'] ?? 'N/A')) ?></td>
                                <td><?= htmlspecialchars((string)($row['case_type'] ?? 'N/A')) ?></td>
                                <td><span class="status-badge status-<?= htmlspecialchars($statusClass ?: 'pending') ?>"><?= htmlspecialchars($statusText !== '' ? $statusText : 'N/A') ?></span></td>
                                <td><?= htmlspecialchars($dateDisplay) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty-row">No records matched your selected filters.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const includeAll = document.getElementById('includeAllInput');
    const controls = [
        document.getElementById('reportTypeInput'),
        document.getElementById('statusInput'),
        document.getElementById('caseTypeInput'),
        document.getElementById('searchInput'),
        document.getElementById('dateFromInput'),
        document.getElementById('dateToInput')
    ].filter(Boolean);

    if (!includeAll) return;

    function syncDisabledState() {
        const disabled = includeAll.checked;
        controls.forEach(function (control) {
            control.disabled = disabled;
        });
    }

    includeAll.addEventListener('change', syncDisabledState);
    syncDisabledState();
})();
</script>

<style>
.page-header { margin-bottom: 18px; }
.page-header-title { font-family: 'Source Serif 4', serif; font-size: 21px; font-weight: 700; color: var(--text-main); }
.page-header-sub { font-size: 13px; color: var(--text-light); margin-top: 3px; }

.filter-card { margin-bottom: 16px; }
.filter-form { display: grid; grid-template-columns: repeat(6, minmax(130px, 1fr)); gap: 10px; align-items: end; }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-date { max-width: 170px; }
.filter-label { font-size: 11px; text-transform: uppercase; letter-spacing: .6px; color: var(--text-muted); font-weight: 700; }
.filter-input { width: 100%; border: 1px solid #dbe3ef; border-radius: 8px; padding: 9px 10px; background: #fff; font-size: 12.5px; color: #1f2937; }
.filter-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.filter-check { grid-column: span 2; }
.check-wrap { display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; color: #334155; }

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
.empty-row { text-align: center; color: #94a3b8; padding: 24px 10px; }

.status-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 700; }
.status-pending { background: #fffbeb; color: #92400e; }
.status-in-progress { background: #eef2ff; color: #3730a3; }
.status-resolved { background: #dcfce7; color: #166534; }

@media (max-width: 1200px) {
    .filter-form { grid-template-columns: repeat(3, minmax(130px, 1fr)); }
    .stats-row { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 760px) {
    .filter-form { grid-template-columns: 1fr; }
    .filter-check { grid-column: auto; }
    .stats-row { grid-template-columns: 1fr 1fr; }
}
</style>

</body>
</html>
