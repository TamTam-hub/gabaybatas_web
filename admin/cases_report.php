<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/barangays.php';

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

$formatCaseId = static function ($value, $fallback = ''): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        $raw = trim((string)$fallback);
    }
    if ($raw === '') {
        return 'N/A';
    }

    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits !== '') {
        $digits = substr($digits, -4);
        return 'C-' . str_pad($digits, 4, '0', STR_PAD_LEFT);
    }

    $normalized = preg_replace('/^\s*C\s*-\s*/i', '', $raw);
    return 'C-' . strtoupper($normalized);
};

$idColumn = $hasColumn('case_id') ? 'case_id' : ($hasColumn('id') ? 'id' : ($caseColumns[0] ?? 'id'));
$rowIdColumn = $hasColumn('id') ? 'id' : $idColumn;
$residentColumn = $hasColumn('resident_name') ? 'resident_name' : ($hasColumn('name') ? 'name' : $idColumn);
$complainedColumn = $hasColumn('complained_person') ? 'complained_person' : ($hasColumn('respondent_name') ? 'respondent_name' : ($hasColumn('against_person') ? 'against_person' : null));
$typeColumn = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$priorityColumn = $hasColumn('priority') ? 'priority' : null;
$statusColumn = $hasColumn('status') ? 'status' : null;
$barangayColumn = $hasColumn('barangay') ? 'barangay' : null;
$dateColumn = $hasColumn('created_at') ? 'created_at' : ($hasColumn('date_submitted') ? 'date_submitted' : ($hasColumn('updated_at') ? 'updated_at' : $idColumn));

$search = trim((string)($_GET['search'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterType = trim((string)($_GET['type'] ?? ''));
$filterPrio = trim((string)($_GET['priority'] ?? ''));
$filterBrgy = '';
$sort = trim((string)($_GET['sort'] ?? 'newest'));
$includeAll = (string)($_GET['include_all'] ?? '0') === '1';
$download = (string)($_GET['download'] ?? '') === 'pdf';

$typeOptions = [];
if ($typeColumn) {
    $optRes = $conn->query("SELECT DISTINCT {$typeColumn} AS v FROM cases WHERE {$typeColumn} IS NOT NULL AND TRIM({$typeColumn}) <> '' ORDER BY {$typeColumn} ASC");
    if ($optRes) {
        while ($r = $optRes->fetch_assoc()) {
            $typeOptions[] = (string)$r['v'];
        }
    }
}

$statusOptions = [];
if ($statusColumn) {
    $statusRes = $conn->query("SELECT DISTINCT {$statusColumn} AS v FROM cases WHERE {$statusColumn} IS NOT NULL AND TRIM({$statusColumn}) <> '' ORDER BY {$statusColumn} ASC");
    if ($statusRes) {
        while ($r = $statusRes->fetch_assoc()) {
            $statusOptions[] = (string)$r['v'];
        }
    }
}
if (!$statusOptions) {
    $statusOptions = ['Pending', 'In Progress', 'Resolved'];
}

$priorityOptions = [];
if ($priorityColumn) {
    $prioRes = $conn->query("SELECT DISTINCT {$priorityColumn} AS v FROM cases WHERE {$priorityColumn} IS NOT NULL AND TRIM({$priorityColumn}) <> '' ORDER BY {$priorityColumn} ASC");
    if ($prioRes) {
        while ($r = $prioRes->fetch_assoc()) {
            $priorityOptions[] = (string)$r['v'];
        }
    }
}
if (!$priorityOptions) {
    $priorityOptions = ['High', 'Medium', 'Low'];
}

$barangayOptions = gabaybatas_get_barangays();

$whereParts = [];
if (!$includeAll) {
    if ($search !== '') {
        $s = $conn->real_escape_string($search);
        $fields = [$residentColumn, $idColumn];
        if ($typeColumn) $fields[] = $typeColumn;
        if ($statusColumn) $fields[] = $statusColumn;
        if ($barangayColumn) $fields[] = $barangayColumn;
        $whereParts[] = '(' . implode(' OR ', array_map(static fn($f) => "$f LIKE '%$s%'", $fields)) . ')';
    }
    if ($filterStatus !== '' && $statusColumn) {
        $fs = $conn->real_escape_string($filterStatus);
        $whereParts[] = "LOWER({$statusColumn}) = LOWER('{$fs}')";
    }
    if ($filterType !== '' && $typeColumn) {
        $ft = $conn->real_escape_string($filterType);
        $whereParts[] = "LOWER({$typeColumn}) = LOWER('{$ft}')";
    }
    if ($filterPrio !== '' && $priorityColumn) {
        $fp = $conn->real_escape_string($filterPrio);
        $whereParts[] = "LOWER({$priorityColumn}) = LOWER('{$fp}')";
    }
}

$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$orderBy = "{$dateColumn} DESC";
if ($sort === 'oldest') {
    $orderBy = "{$dateColumn} ASC";
} elseif ($sort === 'resident_asc') {
    $orderBy = "{$residentColumn} ASC";
} elseif ($sort === 'resident_desc') {
    $orderBy = "{$residentColumn} DESC";
} elseif ($sort === 'status_asc' && $statusColumn) {
    $orderBy = "{$statusColumn} ASC, {$dateColumn} DESC";
}

$selectBase = "SELECT
    {$rowIdColumn} AS row_id, {$idColumn} AS display_case_id,
    {$residentColumn} AS display_resident_name,
    " . ($complainedColumn ? "{$complainedColumn} AS display_complained_person" : "'' AS display_complained_person") . ",
    " . ($typeColumn ? "{$typeColumn} AS case_type" : "'' AS case_type") . ",
    " . ($priorityColumn ? "{$priorityColumn} AS priority" : "'' AS priority") . ",
    " . ($statusColumn ? "{$statusColumn} AS status" : "'' AS status") . ",
    " . ($barangayColumn ? "{$barangayColumn} AS barangay" : "'' AS barangay") . ",
    {$dateColumn} AS display_date
FROM cases {$where}";

$allRows = [];
$allRes = $conn->query($selectBase . " ORDER BY {$orderBy}");
if ($allRes) {
    while ($row = $allRes->fetch_assoc()) {
        $allRows[] = [
            'case_id' => $formatCaseId($row['display_case_id'] ?? '', $row['row_id'] ?? ''),
            'resident' => trim((string)($row['display_resident_name'] ?? '')),
            'complained' => trim((string)($row['display_complained_person'] ?? '')),
            'type' => trim((string)($row['case_type'] ?? '')),
            'priority' => trim((string)($row['priority'] ?? '')),
            'status' => trim((string)($row['status'] ?? '')),
            'barangay' => trim((string)($row['barangay'] ?? '')),
            'date' => trim((string)($row['display_date'] ?? '')),
        ];
    }
}

$activeFilters = [];
if ($includeAll) {
    $activeFilters[] = 'All data mode';
} else {
    if ($search !== '') $activeFilters[] = 'Search: ' . $search;
    if ($filterStatus !== '') $activeFilters[] = 'Status: ' . $filterStatus;
    if ($filterType !== '') $activeFilters[] = 'Type: ' . $filterType;
    if ($filterPrio !== '') $activeFilters[] = 'Priority: ' . $filterPrio;
    if ($filterBrgy !== '') $activeFilters[] = 'Barangay: ' . $filterBrgy;
}
$activeFilters[] = 'Sort: ' . $sort;
$filterText = implode(' | ', $activeFilters);

if ($download) {
    require_once __DIR__ . '/../vendor/autoload.php';

    $esc = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $rowsHtml = '';
    if ($allRows) {
        foreach ($allRows as $r) {
            $dateRaw = $r['date'];
            $ts = strtotime($dateRaw);
            $dateDisplay = $ts !== false ? date('M d, Y h:i A', $ts) : $dateRaw;

            $rowsHtml .= '<tr>'
                . '<td>' . $esc($r['case_id'] !== '' ? $r['case_id'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['resident'] !== '' ? $r['resident'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['complained'] !== '' ? $r['complained'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['type'] !== '' ? $r['type'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['priority'] !== '' ? $r['priority'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['status'] !== '' ? $r['status'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['barangay'] !== '' ? $r['barangay'] : 'N/A') . '</td>'
                . '<td>' . $esc($dateDisplay !== '' ? $dateDisplay : 'N/A') . '</td>'
                . '</tr>';
        }
    } else {
        $rowsHtml = '<tr><td colspan="8" style="text-align:center;">No case records matched the selected filters.</td></tr>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Official Case Registry Report</title>'
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
        . '<div class="title">GabayBatas - Official Case Registry Report</div>'
        . '<div class="subtitle">Administrative Case Monitoring and Documentation Summary</div>'
        . '<div class="meta">Generated: ' . $esc(date('F d, Y h:i A')) . ' | Prepared by: ' . $esc((string)($_SESSION['name'] ?? 'Admin')) . '</div>'
        . '<div class="meta">Filters: ' . $esc($filterText) . '</div>'
        . '<table><thead><tr>'
        . '<th style="width:9%;">Case ID</th>'
        . '<th style="width:15%;">Resident</th>'
        . '<th style="width:16%;">Complained Person</th>'
        . '<th style="width:12%;">Type</th>'
        . '<th style="width:9%;">Priority</th>'
        . '<th style="width:10%;">Status</th>'
        . '<th style="width:13%;">Barangay</th>'
        . '<th style="width:16%;">Date Filed</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
        . '<div class="footer">Total Records: ' . (int)count($allRows) . '</div>'
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
    $dompdf->stream('GabayBatas_Official_Case_Registry_Report_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
    exit;
}

$previewRows = array_slice($allRows, 0, 50);

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

<div class="page-body cr-body">
    <div class="cr-head">
        <p class="cr-sub">Build a custom case dataset and export a formal Long Bond Landscape report.</p>
        <a href="all_cases.php" class="cr-back"><i class="uil uil-arrow-left"></i> Back to All Cases</a>
    </div>

    <div class="cr-card">
        <form method="GET" class="cr-form">
            <div class="cr-grid">
                <div class="cr-field">
                    <label>Search</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Case ID, resident, type">
                </div>
                <div class="cr-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= strtolower($filterStatus) === strtolower((string)$opt) ? 'selected' : '' ?>><?= htmlspecialchars((string)$opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="cr-field">
                    <label>Type</label>
                    <select name="type">
                        <option value="">All Types</option>
                        <?php foreach ($typeOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= strtolower($filterType) === strtolower((string)$opt) ? 'selected' : '' ?>><?= htmlspecialchars((string)$opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="cr-field">
                    <label>Priority</label>
                    <select name="priority">
                        <option value="">All Priorities</option>
                        <?php foreach ($priorityOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= strtolower($filterPrio) === strtolower((string)$opt) ? 'selected' : '' ?>><?= htmlspecialchars((string)$opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="cr-field">
                    <label>Sort</label>
                    <select name="sort">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
                        <option value="resident_asc" <?= $sort === 'resident_asc' ? 'selected' : '' ?>>Resident A-Z</option>
                        <option value="resident_desc" <?= $sort === 'resident_desc' ? 'selected' : '' ?>>Resident Z-A</option>
                        <?php if ($statusColumn): ?>
                        <option value="status_asc" <?= $sort === 'status_asc' ? 'selected' : '' ?>>Status A-Z</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="cr-field cr-check">
                    <label class="cr-check-label"><input type="checkbox" name="include_all" value="1" <?= $includeAll ? 'checked' : '' ?>> Ignore filters and include all cases</label>
                </div>
            </div>
            <div class="cr-actions">
                <button type="submit" class="cr-btn apply"><i class="uil uil-filter"></i> Apply</button>
                <a href="cases_report.php" class="cr-btn reset"><i class="uil uil-redo"></i> Reset</a>
                <a href="cases_report.php?<?= htmlspecialchars(http_build_query([
                    'search' => $search,
                    'status' => $filterStatus,
                    'type' => $filterType,
                    'priority' => $filterPrio,
                    'barangay' => $filterBrgy,
                    'sort' => $sort,
                    'include_all' => $includeAll ? '1' : '0',
                    'download' => 'pdf',
                ])) ?>" class="cr-btn download"><i class="uil uil-file-download"></i> Download PDF </a>
            </div>
        </form>
    </div>

    <div class="cr-card">
        <div class="cr-meta"><strong>Applied Filters:</strong> <?= htmlspecialchars($filterText) ?></div>
        <div class="cr-meta"><strong>Total Records in Download:</strong> <?= (int)count($allRows) ?></div>

        <div class="cr-table-wrap">
            <table class="cr-table">
                <thead>
                    <tr><th>Case ID</th><th>Resident</th><th>Complained Person</th><th>Type</th><th>Priority</th><th>Status</th><th>Barangay</th><th>Date Filed</th></tr>
                </thead>
                <tbody>
                    <?php if ($previewRows): ?>
                        <?php foreach ($previewRows as $r):
                            $ts = strtotime((string)$r['date']);
                            $dateDisplay = $ts !== false ? date('M d, Y h:i A', $ts) : (string)$r['date'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($r['case_id'] !== '' ? $r['case_id'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['resident'] !== '' ? $r['resident'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['complained'] !== '' ? $r['complained'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['type'] !== '' ? $r['type'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['priority'] !== '' ? $r['priority'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['status'] !== '' ? $r['status'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['barangay'] !== '' ? $r['barangay'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($dateDisplay !== '' ? $dateDisplay : 'N/A') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="8" class="cr-empty">No records matched the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($allRows) > 50): ?>
        <div class="cr-note">Preview shows first 50 rows only. Download includes all <?= (int)count($allRows) ?> rows.</div>
        <?php endif; ?>
    </div>
</div>
</div>

<style>
.cr-body{padding:24px 28px 40px;}
.cr-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;}
.cr-sub{font-size:12.5px;color:var(--text-sub);margin:0;}
.cr-back{display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:12.5px;font-weight:600;color:#1d4ed8;background:#eff6ff;border:1px solid #dbeafe;border-radius:9px;padding:8px 13px;}
.cr-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:12px;}
.cr-form{display:flex;flex-direction:column;gap:12px;}
.cr-grid{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));gap:10px;}
.cr-field{display:flex;flex-direction:column;gap:6px;}
.cr-field label{font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#6b7280;}
.cr-field input,.cr-field select{width:100%;height:36px;border:1px solid #d1d5db;border-radius:8px;padding:0 10px;font-size:12.5px;color:#111827;background:#fff;}
.cr-check{justify-content:flex-end;grid-column:span 2;}
.cr-check-label{display:flex;align-items:center;gap:7px;font-size:12.5px;color:#111827;text-transform:none!important;letter-spacing:0!important;font-weight:600!important;}
.cr-check-label input[type="checkbox"]{width:13px;height:13px;accent-color:#1d4ed8;}
.cr-actions{display:flex;flex-wrap:wrap;gap:8px;}
.cr-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 12px;border-radius:8px;text-decoration:none;font-size:12.5px;font-weight:600;border:1px solid #d1d5db;background:#fff;color:#111827;}
.cr-btn.apply{background:#1d4ed8;border-color:#1d4ed8;color:#fff;cursor:pointer;}
.cr-btn.download{background:#111827;border-color:#111827;color:#fff;}
.cr-meta{font-size:12.5px;color:#334155;margin-bottom:6px;}
.cr-table-wrap{overflow-x:auto;margin-top:10px;}
.cr-table{width:100%;border-collapse:collapse;}
.cr-table th,.cr-table td{border:1px solid #d1d5db;padding:8px 10px;font-size:12.5px;text-align:left;vertical-align:top;}
.cr-table th{background:#f3f4f6;font-weight:700;color:#111827;}
.cr-empty{text-align:center;color:#6b7280;padding:16px;}
.cr-note{margin-top:8px;font-size:12px;color:#6b7280;}
@media(max-width:1300px){.cr-grid{grid-template-columns:repeat(3,minmax(140px,1fr));}.cr-check{grid-column:span 3;}}
@media(max-width:700px){.cr-body{padding:16px;}.cr-head{flex-direction:column;align-items:flex-start;}.cr-grid{grid-template-columns:1fr;}.cr-check{grid-column:auto;}}
</style>

</body>
</html>
