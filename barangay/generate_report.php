<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['barangay', 'admin'], true)) {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
gabaybatas_block_barangay_delete_requests();

use Dompdf\Dompdf;
use Dompdf\Options;

// ── FILTER INPUTS ────────────────────────────────────────────────
$reportType = strtolower(trim((string)($_GET['type'] ?? 'all')));
if ($reportType === 'summary') {
    $reportType = 'yearly';
}
$allowedTypes = ['all', 'monthly', 'quarterly', 'yearly'];
if (!in_array($reportType, $allowedTypes, true)) {
    $reportType = 'all';
}

$statusFilterRaw = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['Pending', 'In Progress', 'Resolved'];
$statusFilter = '';
foreach ($allowedStatuses as $allowedStatus) {
    if (strcasecmp($statusFilterRaw, $allowedStatus) === 0) {
        $statusFilter = $allowedStatus;
        break;
    }
}

$caseTypeFilter = trim((string)($_GET['case_type'] ?? ''));
$searchRaw = trim((string)($_GET['search'] ?? ''));
$dateFromRaw = trim((string)($_GET['date_from'] ?? ''));
$dateToRaw = trim((string)($_GET['date_to'] ?? ''));
$includeAll = (string)($_GET['include_all'] ?? '0') === '1';

// The monthly report is the official KP Monitoring Form No. 1 for the current month.
if ($reportType === 'monthly' && !$includeAll) {
    $nowForMonthly = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    header('Location: kp_monitoring_report.php?month=' . $nowForMonthly->format('n') . '&year=' . $nowForMonthly->format('Y'));
    exit();
}

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

$appTimezone = new DateTimeZone('Asia/Manila');
$nowManila = new DateTimeImmutable('now', $appTimezone);

$month       = $nowManila->format('m');
$year        = $nowManila->format('Y');
$generatedAt = $nowManila->format('F d, Y \\a\\t h:i A');
$currentBarangay = gabaybatas_get_user_barangay($conn);

$caseColumns = [];
$colResult = $conn->query("SHOW COLUMNS FROM cases");
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
$isBarangayUser = ($_SESSION['role'] ?? '') === 'barangay';

$scopeSql = '';
if ($isBarangayUser && $hasCaseBarangay && $currentBarangay !== '') {
    $scopeSql = " AND LOWER(barangay) = LOWER('" . $conn->real_escape_string($currentBarangay) . "')";
}

$whereParts = ['1=1'];
if ($scopeSql !== '') {
    $whereParts[] = ltrim($scopeSql, ' AND');
}

$appliedFilters = [];
if (!$includeAll) {
    $month = (int)$nowManila->format('n');
    $year = (int)$nowManila->format('Y');

    if ($reportType === 'monthly' && $dateColumn) {
        $whereParts[] = "MONTH({$dateColumn}) = {$month} AND YEAR({$dateColumn}) = {$year}";
        $appliedFilters[] = 'This Month';
    } elseif ($reportType === 'quarterly' && $dateColumn) {
        $q = (int)ceil($month / 3);
        $startMonth = (($q - 1) * 3) + 1;
        $endMonth = $startMonth + 2;
        $whereParts[] = "MONTH({$dateColumn}) BETWEEN {$startMonth} AND {$endMonth} AND YEAR({$dateColumn}) = {$year}";
        $appliedFilters[] = 'Quarter ' . $q;
    } elseif ($reportType === 'yearly' && $dateColumn) {
        $whereParts[] = "YEAR({$dateColumn}) = {$year}";
        $appliedFilters[] = 'Year ' . $year;
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
        $searchDigits = preg_replace('/^\s*C\s*-\s*/i', '', $searchRaw);
        $searchDigits = preg_replace('/\D+/', '', (string)$searchDigits);

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
        if ($searchDigits !== '' && $displayIdColumn) {
            $safeDigits = $conn->real_escape_string($searchDigits);
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

// ── STATISTICS ────────────────────────────────────────────────────
$totalResult = $conn->query("SELECT COUNT(*) AS c FROM cases" . $whereSql);
$totalCases = (int)($totalResult ? ($totalResult->fetch_assoc()['c'] ?? 0) : 0);

$pendingCases = 0;
$inProgCases = 0;
$resolvedCases = 0;
if ($statusColumn) {
    $pendingRes = $conn->query("SELECT COUNT(*) AS c FROM cases" . $whereSql . " AND LOWER({$statusColumn})='pending'");
    $inProgRes = $conn->query("SELECT COUNT(*) AS c FROM cases" . $whereSql . " AND LOWER({$statusColumn})='in progress'");
    $resolvedRes = $conn->query("SELECT COUNT(*) AS c FROM cases" . $whereSql . " AND LOWER({$statusColumn})='resolved'");
    $pendingCases = (int)($pendingRes ? ($pendingRes->fetch_assoc()['c'] ?? 0) : 0);
    $inProgCases = (int)($inProgRes ? ($inProgRes->fetch_assoc()['c'] ?? 0) : 0);
    $resolvedCases = (int)($resolvedRes ? ($resolvedRes->fetch_assoc()['c'] ?? 0) : 0);
}

// ── CASE QUERY ────────────────────────────────────────────────────
$selectFields = [];
if ($idColumn)       { $selectFields[] = "$idColumn AS row_id"; }
if ($displayIdColumn){ $selectFields[] = "$displayIdColumn AS display_case_id"; }
if ($residentColumn) { $selectFields[] = "$residentColumn AS resident_name"; }
if ($typeColumn)     { $selectFields[] = "$typeColumn AS case_type"; }
if ($statusColumn)   { $selectFields[] = "$statusColumn AS case_status"; }
if ($dateColumn)     { $selectFields[] = "$dateColumn AS case_date"; }

if (!$selectFields) {
    $selectFields[] = "'' AS row_id";
}

$sql = "SELECT " . implode(', ', $selectFields) . " FROM cases" . $whereSql;
$sql .= $dateColumn ? " ORDER BY $dateColumn DESC" : ($idColumn ? " ORDER BY $idColumn DESC" : '');
$result = $conn->query($sql);

// ── LABELS ────────────────────────────────────────────────────────
$reportTitles = [
    'all'       => 'Comprehensive Case Report',
    'monthly'   => 'Monthly Case Report',
    'yearly'    => 'Yearly Case Report',
    'quarterly' => 'Quarterly Case Report',
];

$reportPeriods = [
    'all'       => 'All Periods',
    'monthly'   => $nowManila->format('F Y'),
    'yearly'    => 'Year ' . $nowManila->format('Y'),
    'quarterly' => 'Quarter ' . (int)ceil((int)$nowManila->format('n') / 3) . ', ' . $nowManila->format('Y'),
];

$filenameMap = [
    'all'       => 'gabaybatas_all_data_report_'  . $nowManila->format('Ymd') . '.pdf',
    'monthly'   => 'gabaybatas_monthly_report_'   . $nowManila->format('Ymd') . '.pdf',
    'yearly'    => 'gabaybatas_yearly_report_'    . $nowManila->format('Ymd') . '.pdf',
    'quarterly' => 'gabaybatas_quarterly_report_' . $nowManila->format('Ymd') . '.pdf',
];

$reportTitle  = $reportTitles[$reportType];
$reportPeriod = $includeAll ? 'All Available Data' : $reportPeriods[$reportType];
$filename     = $filenameMap[$reportType];
$filterSummaryText = $includeAll
    ? 'All data mode selected. No additional filters were applied.'
    : ($appliedFilters ? implode(' | ', $appliedFilters) : 'No extra filters applied.');

// ── LOGO SVG ─────────────────────────────────────────────────────
// Matches the login page: dark navy rounded square, white shield, white checkmark.
$logoSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">'
    . '<rect width="100" height="100" rx="14" ry="14" fill="#1a3a6b"/>'
    . '<path d="M50 18 L74 27 L74 48 C74 64 62 75 50 80 C38 75 26 64 26 48 L26 27 Z"'
    .   ' fill="none" stroke="#ffffff" stroke-width="4" stroke-linejoin="round"/>'
    . '<polyline points="37,51 46,61 65,40"'
    .   ' fill="none" stroke="#ffffff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>'
    . '</svg>';

$logoDataUri = 'data:image/svg+xml;base64,' . base64_encode($logoSvg);

// ── BUILD TABLE ROWS ──────────────────────────────────────────────
$htmlRows = '';
$rowNum   = 1;
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $statusText  = (string)($row['case_status'] ?? 'Pending');
        $status      = htmlspecialchars($statusText);
        $statusClass = strtolower(str_replace(' ', '-', $statusText));
        $residentName = htmlspecialchars((string)($row['resident_name'] ?? ''));
        $typeText = htmlspecialchars((string)($row['case_type'] ?? ''));
        $dateRaw = (string)($row['case_date'] ?? '');
        $dateTs = strtotime($dateRaw);
        $dateDisplay = htmlspecialchars($dateTs !== false ? date('M d, Y h:i A', $dateTs) : $dateRaw);
        $caseIdText = htmlspecialchars(gabaybatas_format_case_id($row['display_case_id'] ?? '', $row['row_id'] ?? ''));
        $rowBg       = ($rowNum % 2 === 0) ? '#f7f9fc' : '#ffffff';
        $htmlRows   .= '<tr style="background:' . $rowBg . ';">'
            . '<td style="padding:8px 10px;border-bottom:1px solid #dde4ee;font-weight:700;color:#12284a;white-space:nowrap;">'
                . $caseIdText . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #dde4ee;">'
                . $residentName . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #dde4ee;">'
                . $typeText . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #dde4ee;">'
                . '<span class="status ' . $statusClass . '">' . $status . '</span></td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #dde4ee;white-space:nowrap;">'
                . $dateDisplay . '</td>'
            . '</tr>';
        $rowNum++;
    }
} else {
    $htmlRows = '<tr><td colspan="5" style="text-align:center;padding:28px;color:#7a8a9e;font-style:italic;">'
    . 'No case records found for the selected filters.</td></tr>';
}

$caseCount = $rowNum - 1;

// ── HTML ──────────────────────────────────────────────────────────
$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>' . htmlspecialchars($reportTitle) . '</title>
<style>
    /*
     * MARGIN FIX
     * Dompdf does not reliably honour @page margin in most versions.
     * Solution: set @page margin to 0, put ALL spacing on body padding.
     * Dompdf always respects body padding correctly.
     */
    @page {
        size: 8.5in 13in;
        margin: 0;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 10.5pt;
        color: #1a2535;
        background: #ffffff;
        line-height: 1.45;
        /* ← Margins controlled entirely here (≈ 0.75in on sides, 0.67in top/bottom) */
        padding: 48pt 54pt 48pt 54pt;
    }

    /* ── HEADER ── */
    .header-table {
        width: 100%;
        border-bottom: 2px solid #1a3a6b;
        padding-bottom: 10px;
        margin-bottom: 4px;
    }

    .logo-cell {
        width: 82px;
        vertical-align: middle;
        padding-right: 10px;
    }

    .header-center {
        text-align: center;
        vertical-align: middle;
        padding: 0 14px;
    }

    .gov-label {
        font-size: 8.5pt;
        letter-spacing: 1.2px;
        text-transform: uppercase;
        color: #5e7292;
        margin-bottom: 4px;
    }

    .doc-title {
        font-size: 17pt;
        font-weight: bold;
        color: #0e2242;
        font-family: Georgia, "Times New Roman", serif;
        line-height: 1.2;
    }

    .doc-subtitle {
        font-size: 10pt;
        color: #5a6a7e;
        margin-top: 3px;
    }

    .report-tag-cell {
        width: 80px;
        vertical-align: middle;
        text-align: right;
    }

    .report-tag {
        display: inline-block;
        background: #1a3a6b;
        color: #ffffff;
        font-size: 7.5pt;
        font-weight: bold;
        letter-spacing: 1px;
        text-transform: uppercase;
        padding: 4px 8px;
        border-radius: 3px;
    }

    .accent-line {
        height: 3px;
        background-color: #c9a83c;
        margin-bottom: 14px;
    }

    /* ── META INFO ── */
    .meta-table {
        width: 100%;
        margin-bottom: 14px;
        border: 1px solid #d4dbe8;
        background: #f7f9fc;
    }

    .meta-table td {
        padding: 9px 14px;
        font-size: 10pt;
        vertical-align: top;
        border-right: 1px solid #d4dbe8;
    }

    .meta-table td:last-child { border-right: none; }

    .meta-lbl {
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #7a8a9e;
        margin-bottom: 2px;
        font-weight: bold;
    }

    .meta-val {
        font-weight: bold;
        color: #1a2535;
        font-size: 10.5pt;
    }

    /* ── SECTION HEADING ── */
    .section-heading {
        font-family: Georgia, "Times New Roman", serif;
        font-size: 11.5pt;
        font-weight: bold;
        color: #0e2242;
        border-bottom: 1px solid #d4dbe8;
        padding-bottom: 5px;
        margin-top: 16px;
        margin-bottom: 10px;
    }

    /* ── STAT CARDS ── */
    .stats-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 16px;
    }

    .stat-box {
        border: 1px solid #d4dbe8;
        border-top: 3px solid #1a3a6b;
        padding: 10px 12px;
        background: #fafcff;
        text-align: center;
    }

    .stat-box.orange { border-top-color: #b55e00; }
    .stat-box.slate  { border-top-color: #4a6080; }
    .stat-box.green  { border-top-color: #0e7c45; }

    .stat-num {
        font-family: Georgia, "Times New Roman", serif;
        font-size: 26pt;
        font-weight: bold;
        color: #0e2242;
        line-height: 1;
    }

    .stat-lbl {
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #7a8a9e;
        margin-top: 4px;
        font-weight: bold;
    }

    /* ── CASE TABLE ── */
    table.cases {
        width: 100%;
        border-collapse: collapse;
        font-size: 10pt;
    }

    table.cases thead tr { background: #1a3a6b; }

    table.cases thead th {
        color: #ffffff;
        padding: 9px 10px;
        text-align: left;
        font-size: 8.5pt;
        font-weight: bold;
        letter-spacing: 0.4px;
        border: 1px solid #122d54;
    }

    table.cases tbody td { vertical-align: middle; }

    table.cases tbody tr:last-child td { border-bottom: 2px solid #d4dbe8; }

    .status {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 3px;
        font-size: 8.5pt;
        font-weight: bold;
        letter-spacing: 0.3px;
        text-transform: uppercase;
    }
    .status.pending     { background: #fff1ca; color: #7a4f00; }
    .status.in-progress { background: #dce8ff; color: #1a3a6b; }
    .status.resolved    { background: #d4f5e2; color: #0a6035; }

    /* ── NOTES ── */
    .notes-table {
        width: 100%;
        margin-top: 16px;
        border-collapse: collapse;
    }

    .note-box {
        border: 1px solid #d4dbe8;
        padding: 10px 12px;
        background: #fafcff;
        font-size: 9.5pt;
        color: #3a4e68;
        line-height: 1.55;
        vertical-align: top;
    }

    .note-title {
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #7a8a9e;
        font-weight: bold;
        margin-bottom: 5px;
    }

    /* ── SIGNATURES ── */
    .sig-table {
        width: 70%;
        margin: 24px auto 0;
        border-collapse: collapse;
    }

    .sig-cell {
        width: 50%;
        padding: 0 24px;
        vertical-align: top;
        text-align: center;
    }

    .sig-label {
        font-size: 8pt;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #7a8a9e;
        margin-bottom: 52px;
        font-weight: bold;
    }

    .sig-line {
        border-top: 1px solid #2c3e55;
        margin-bottom: 6px;
    }

    .sig-name {
        font-weight: bold;
        font-size: 10.5pt;
        color: #0e2242;
        text-transform: uppercase;
    }

    .sig-role {
        font-size: 9pt;
        color: #5a6a7e;
        margin-top: 1px;
    }

    /* ── FOOTER ── */
    .doc-footer {
        margin-top: 20px;
        padding-top: 8px;
        border-top: 1px solid #d4dbe8;
        text-align: center;
        font-size: 8pt;
        color: #8a9bb0;
        letter-spacing: 0.3px;
    }

    .page-break { page-break-before: always; }
</style>
</head>
<body>

<!-- HEADER -->
<table class="header-table" cellpadding="0" cellspacing="0">
    <tr>
        <td class="logo-cell">
            <img src="' . $logoDataUri . '" alt="GabayBatas Logo" style="width:72px;height:72px;display:block;">
        </td>
        <td class="header-center">
            <div class="gov-label">Republic of the Philippines &bull; City of Catbalogan, Samar</div>
            <div class="doc-title">GabayBatas Legal Information &amp; Referral System</div>
            <div class="doc-subtitle">' . htmlspecialchars($reportTitle) . ' &mdash; Official Barangay Document</div>
        </td>
        <td class="report-tag-cell">
            <span class="report-tag">' . strtoupper($reportType) . '</span>
        </td>
    </tr>
</table>

<div class="accent-line"></div>

<!-- META INFO -->
<table class="meta-table" cellpadding="0" cellspacing="0">
    <tr>
        <td style="width:40%;">
            <div class="meta-lbl">Report Period</div>
            <div class="meta-val">' . htmlspecialchars($reportPeriod) . '</div>
        </td>
        <td style="width:35%;">
            <div class="meta-lbl">Date Generated</div>
            <div class="meta-val">' . htmlspecialchars($generatedAt) . '</div>
        </td>
        <td style="width:25%;">
            <div class="meta-lbl">Total Records</div>
            <div class="meta-val">' . $caseCount . ' ' . ($caseCount === 1 ? 'case' : 'cases') . '</div>
        </td>
    </tr>
</table>

<div style="margin:0 0 14px;border:1px solid #d4dbe8;background:#fafcff;padding:8px 12px;font-size:9pt;color:#3a4e68;">
    <strong style="display:inline-block;margin-right:6px;color:#0e2242;">Applied Filters:</strong>
    ' . htmlspecialchars($filterSummaryText) . '
</div>

<!-- CASE STATISTICS -->
<div class="section-heading">Case Statistics</div>

<table class="stats-table" cellpadding="0" cellspacing="0">
    <tr>
        <td style="width:25%;padding-right:5px;">
            <div class="stat-box">
                <div class="stat-num">' . $totalCases . '</div>
                <div class="stat-lbl">Total Cases</div>
            </div>
        </td>
        <td style="width:25%;padding:0 3px;">
            <div class="stat-box orange">
                <div class="stat-num">' . $pendingCases . '</div>
                <div class="stat-lbl">Pending</div>
            </div>
        </td>
        <td style="width:25%;padding:0 3px;">
            <div class="stat-box slate">
                <div class="stat-num">' . $inProgCases . '</div>
                <div class="stat-lbl">In Progress</div>
            </div>
        </td>
        <td style="width:25%;padding-left:5px;">
            <div class="stat-box green">
                <div class="stat-num">' . $resolvedCases . '</div>
                <div class="stat-lbl">Resolved</div>
            </div>
        </td>
    </tr>
</table>

<!-- CASE LIST -->
<div class="section-heading">Case List</div>

<table class="cases" cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <th style="width:13%;">Case ID</th>
            <th style="width:28%;">Resident Name</th>
            <th style="width:22%;">Case Type</th>
            <th style="width:16%;">Status</th>
            <th style="width:21%;">Date Filed</th>
        </tr>
    </thead>
    <tbody>' . $htmlRows . '</tbody>
</table>

<!-- NOTES -->
<table class="notes-table" cellpadding="0" cellspacing="0">
    <tr>
        <td class="note-box" style="width:49%;">
            <div class="note-title">Report Notes</div>
            This document is system-generated from the GabayBatas database.
            It is intended for official barangay review, filing, and case management use only.
        </td>
        <td style="width:2%;"></td>
        <td class="note-box" style="width:49%;">
            <div class="note-title">Certification</div>
            The statistics and case records reflected herein are accurate as of the generation date.
            Any discrepancies should be reported to the system administrator.
        </td>
    </tr>
</table>

<!-- SIGNATURES -->
<div class="section-heading" style="margin-top:28px;">Certification and Approval</div>

<table class="sig-table" cellpadding="0" cellspacing="0">
    <tr>
        <td class="sig-cell">
            <div class="sig-label">Prepared By</div>
            <div class="sig-line"></div>
            <div class="sig-name">Barangay Secretary</div>
            <div class="sig-role">Report Preparation and Verification</div>
        </td>
        <td class="sig-cell">
            <div class="sig-label">Approved By</div>
            <div class="sig-line"></div>
            <div class="sig-name">Barangay Captain</div>
            <div class="sig-role">Official Approval and Certification</div>
        </td>
    </tr>
</table>

<!-- FOOTER -->
<div class="doc-footer">
    GabayBatas Legal Information &amp; Referral System &bull; Catbalogan City Barangay Government &bull; Official Government Record
</div>

</body>
</html>';

// ── RENDER ────────────────────────────────────────────────────────
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'Arial');
$options->set('isPhpEnabled', false);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

// 8.5in × 13in in points. Margins are handled by body padding above.
$dompdf->setPaper([0, 0, 612, 936], 'portrait');

$dompdf->render();
$dompdf->stream($filename, ['Attachment' => true]);
exit();