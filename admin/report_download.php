<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/barangays.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

$statsFile = __DIR__ . '/report_download_stats.json';
$stats = ['total' => 0, 'monthly' => 0, 'summary' => 0, 'quarterly' => 0];
if (is_file($statsFile)) {
    $stored = json_decode((string)file_get_contents($statsFile), true);
    if (is_array($stored)) {
        $stats = array_merge($stats, array_intersect_key($stored, $stats));
    }
}

$caseColumns = [];
$caseColResult = $conn->query('SHOW COLUMNS FROM cases');
if ($caseColResult) {
    while ($col = $caseColResult->fetch_assoc()) {
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

$rows = [];
$selectSql = 'SELECT ' . implode(', ', $selectParts) . ' FROM cases ' . $whereSql . ' ORDER BY ' . $orderColumn . ' DESC';
$result = $conn->query($selectSql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

$reportTitles = [
    'all' => 'Comprehensive Case Report',
    'monthly' => 'Monthly Case Report',
    'quarterly' => 'Quarterly Case Report',
    'yearly' => 'Yearly Case Report',
];
$reportPeriods = [
    'all' => 'All Periods',
    'monthly' => date('F Y'),
    'quarterly' => 'Quarter ' . (int)ceil((int)date('n') / 3) . ', ' . date('Y'),
    'yearly' => 'Year ' . date('Y'),
];
$filenameMap = [
    'all' => 'gabaybatas_all_data_report_' . date('Ymd') . '.pdf',
    'monthly' => 'gabaybatas_monthly_report_' . date('Ymd') . '.pdf',
    'quarterly' => 'gabaybatas_quarterly_report_' . date('Ymd') . '.pdf',
    'yearly' => 'gabaybatas_yearly_report_' . date('Ymd') . '.pdf',
];

$appliedFilters = [];
if (!$includeAll) {
    if ($reportType !== 'all') $appliedFilters[] = ucfirst($reportType);
    if ($statusFilter !== '') $appliedFilters[] = 'Status: ' . $statusFilter;
    if ($caseTypeFilter !== '') $appliedFilters[] = 'Type: ' . $caseTypeFilter;
    if ($search !== '') $appliedFilters[] = 'Search: ' . $search;
    if ($dateFrom !== '') $appliedFilters[] = 'From: ' . $dateFrom;
    if ($dateTo !== '') $appliedFilters[] = 'To: ' . $dateTo;
}

$stats['total'] = (int)($stats['total'] ?? 0) + 1;
$stats[$reportType] = (int)($stats[$reportType] ?? 0) + 1;
file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

$esc = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$rowsHtml = '';
if ($rows) {
    foreach ($rows as $row) {
        $dateRaw = trim((string)($row['case_date'] ?? ''));
        $dateTs = $dateRaw !== '' ? strtotime($dateRaw) : false;
        $dateDisplay = $dateTs !== false ? date('M d, Y h:i A', $dateTs) : ($dateRaw !== '' ? $dateRaw : 'N/A');
        $rowsHtml .= '<tr>'
            . '<td>' . $esc(gabaybatas_format_case_id($row['display_case_id'] ?? '', $row['row_id'] ?? '')) . '</td>'
            . '<td>' . $esc(trim((string)($row['resident_name'] ?? '')) !== '' ? (string)$row['resident_name'] : 'N/A') . '</td>'
            . '<td>' . $esc(trim((string)($row['case_type'] ?? '')) !== '' ? (string)$row['case_type'] : 'N/A') . '</td>'
            . '<td>' . $esc(trim((string)($row['case_status'] ?? '')) !== '' ? (string)$row['case_status'] : 'N/A') . '</td>'
            . '<td>' . $esc(trim((string)($row['barangay'] ?? '')) !== '' ? (string)$row['barangay'] : 'N/A') . '</td>'
            . '<td>' . $esc($dateDisplay) . '</td>'
            . '</tr>';
    }
} else {
    $rowsHtml = '<tr><td colspan="6" style="text-align:center;">No case records matched the selected filters.</td></tr>';
}

$title = $reportTitles[$reportType] ?? 'Comprehensive Case Report';
$period = $includeAll ? 'All Available Data' : ($reportPeriods[$reportType] ?? 'All Periods');
$filterText = $includeAll ? 'All data mode selected.' : ($appliedFilters ? implode(' | ', $appliedFilters) : 'No extra filters applied.');
$generatedAt = date('F d, Y h:i A');

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $esc($title) . '</title>'
    . '<style>'
    . '@page { size: 13in 8.5in; margin: 24pt; }'
    . 'body{font-family:Arial,Helvetica,sans-serif;font-size:10pt;color:#111;}'
    . '.title{font-size:18pt;font-weight:700;margin-bottom:2pt;}'
    . '.subtitle{font-size:10pt;margin-bottom:8pt;}'
    . '.meta{font-size:9pt;margin-bottom:8pt;}'
    . 'table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:8.7pt;}'
    . 'th,td{border:1px solid #222;padding:5pt 4pt;text-align:left;vertical-align:top;word-wrap:break-word;}'
    . 'th{background:#f2f2f2;font-weight:700;}'
    . '.footer{margin-top:8pt;font-size:9pt;font-weight:700;}'
    . '</style></head><body>'
    . '<div class="title">GabayBatas - ' . $esc($title) . '</div>'
    . '<div class="subtitle">Administrative Case Monitoring and Documentation Summary</div>'
    . '<div class="meta">Generated: ' . $esc($generatedAt) . ' | Prepared by: ' . $esc((string)($_SESSION['name'] ?? 'Admin')) . '</div>'
    . '<div class="meta">Period: ' . $esc($period) . '</div>'
    . '<div class="meta">Filters: ' . $esc($filterText) . '</div>'
    . '<table><thead><tr>'
    . '<th style="width:9%;">Case ID</th>'
    . '<th style="width:18%;">Resident</th>'
    . '<th style="width:16%;">Type</th>'
    . '<th style="width:12%;">Status</th>'
    . '<th style="width:18%;">Barangay</th>'
    . '<th style="width:17%;">Date Filed</th>'
    . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
    . '<div class="footer">Total Records: ' . (int)count($rows) . '</div>'
    . '</body></html>';

$options = new Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'Arial');
$options->set('isPhpEnabled', false);

$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper([0, 0, 936, 612], 'landscape');
$dompdf->render();
$dompdf->stream($filenameMap[$reportType] ?? 'gabaybatas_report.pdf', ['Attachment' => true]);
exit;
