<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header('Location: ../login.php'); exit(); }
require_once '../config/database.php';
require_once '../config/kp.php';
require_once '../config/barangays.php';
require_once '../config/archive.php';
gabaybatas_archive_ensure_schema($conn);

$caseColumns = [];
$colResult = $conn->query("SHOW COLUMNS FROM cases");
if ($colResult) { while ($col = $colResult->fetch_assoc()) $caseColumns[] = $col['Field']; }

$hasColumn = static function (string $column) use ($caseColumns): bool { return in_array($column, $caseColumns, true); };

$idColumn       = $hasColumn('case_id') ? 'case_id' : ($hasColumn('id') ? 'id' : ($caseColumns[0] ?? 'id'));
$rowIdColumn    = $hasColumn('id') ? 'id' : $idColumn;
$residentColumn = $hasColumn('resident_name') ? 'resident_name' : ($hasColumn('name') ? 'name' : $idColumn);
$complainedColumn = $hasColumn('complained_person') ? 'complained_person' : ($hasColumn('respondent_name') ? 'respondent_name' : ($hasColumn('against_person') ? 'against_person' : null));
$typeColumn     = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$statusColumn   = $hasColumn('status') ? 'status' : null;
$barangayColumn = $hasColumn('barangay') ? 'barangay' : null;
$dateColumn     = $hasColumn('created_at') ? 'created_at' : ($hasColumn('date_submitted') ? 'date_submitted' : ($hasColumn('updated_at') ? 'updated_at' : $idColumn));

// Dynamic filter options
$typeOptions = [];
if ($typeColumn) {
    $optRes = $conn->query("SELECT DISTINCT {$typeColumn} AS v FROM cases WHERE {$typeColumn} IS NOT NULL AND TRIM({$typeColumn}) <> '' ORDER BY {$typeColumn} ASC");
    if ($optRes) {
        while ($r = $optRes->fetch_assoc()) $typeOptions[] = (string)$r['v'];
    }
}

// Counts for active records only; archived cases remain available in Records Archive.
$activeCaseWhere = 'archived_at IS NULL';
$totalCases    = (int)$conn->query("SELECT COUNT(*) as t FROM cases WHERE $activeCaseWhere")->fetch_assoc()['t'];
$pendingCount  = $statusColumn ? (int)$conn->query("SELECT COUNT(*) as t FROM cases WHERE $activeCaseWhere AND LOWER(status)='pending'")->fetch_assoc()['t'] : 0;
$progressCount = $statusColumn ? (int)$conn->query("SELECT COUNT(*) as t FROM cases WHERE $activeCaseWhere AND LOWER(status)='in progress'")->fetch_assoc()['t'] : 0;
$resolvedCount = $statusColumn ? (int)$conn->query("SELECT COUNT(*) as t FROM cases WHERE $activeCaseWhere AND LOWER(status)='resolved'")->fetch_assoc()['t'] : 0;

$search       = trim($_GET['search'] ?? '');
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterType   = trim((string)($_GET['type'] ?? ''));
$sort         = trim((string)($_GET['sort'] ?? 'newest'));
$allowedStatuses = array_merge(['', 'Pending', 'In Progress', 'Resolved'], array_keys(kp_statuses()));
$allowedSorts = ['newest', 'oldest', 'resident_asc', 'resident_desc', 'status_asc'];
if (!in_array($filterStatus, $allowedStatuses, true)) $filterStatus = '';
if (!in_array($sort, $allowedSorts, true)) $sort = 'newest';
$pageNo       = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 12;

$whereParts = [];
$whereParts[] = 'archived_at IS NULL';
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $fields = [$residentColumn, $idColumn];
    if ($typeColumn)     $fields[] = $typeColumn;
    if ($statusColumn)   $fields[] = $statusColumn;
    if ($barangayColumn) $fields[] = $barangayColumn;
    $whereParts[] = '(' . implode(" OR ", array_map(fn($f) => "$f LIKE '%$s%'", $fields)) . ')';
}
if ($filterStatus !== '' && $statusColumn) {
    $fs = $conn->real_escape_string($filterStatus);
    $whereParts[] = "LOWER($statusColumn) = LOWER('$fs')";
}
if ($filterType !== '' && $typeColumn) {
    $ft = $conn->real_escape_string($filterType);
    $whereParts[] = "LOWER($typeColumn) = LOWER('$ft')";
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$orderBy = "$dateColumn DESC";
if ($sort === 'oldest') {
    $orderBy = "$dateColumn ASC";
} elseif ($sort === 'resident_asc') {
    $orderBy = "$residentColumn ASC";
} elseif ($sort === 'resident_desc') {
    $orderBy = "$residentColumn DESC";
} elseif ($sort === 'status_asc' && $statusColumn) {
    $orderBy = "$statusColumn ASC, $dateColumn DESC";
}

$selectBase = "SELECT
    {$rowIdColumn} AS row_id, {$idColumn} AS display_case_id,
    {$residentColumn} AS display_resident_name,
    " . ($complainedColumn ? "{$complainedColumn} AS display_complained_person" : "'' AS display_complained_person") . ",
    " . ($typeColumn     ? "{$typeColumn} AS case_type"       : "'' AS case_type") . ",
    " . ($statusColumn   ? "{$statusColumn} AS status"        : "'' AS status") . ",
    " . ($barangayColumn ? "{$barangayColumn} AS barangay"    : "'' AS barangay") . ",
    {$dateColumn} AS display_date,
    cases.kp_status AS kp_status
FROM cases {$where}";

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $exp = $conn->query($selectBase . " ORDER BY {$orderBy}");
    $rows = [];
    $rows[] = ['GabayBatas - Official Case Registry Report', '', '', '', '', '', ''];
    $rows[] = ['Administrative Case Monitoring and Documentation Summary', '', '', '', '', '', ''];
    $rows[] = ['Generated: ' . date('F d, Y h:i A') . ' | Prepared by: ' . (string)($_SESSION['name'] ?? 'Admin'), '', '', '', '', '', ''];
    $rows[] = ['', '', '', '', '', '', ''];
    $rows[] = ['Case ID', 'Resident', 'Complained Person', 'Type', 'Status', 'Barangay', 'Date Filed'];

    $rowCount = 0;
    if ($exp) {
        while ($row = $exp->fetch_assoc()) {
            $rowCount++;
            $rows[] = [
                (string)($row['display_case_id'] ?? '-'),
                (string)($row['display_resident_name'] ?? '-'),
                (string)($row['display_complained_person'] ?? '-'),
                (string)($row['case_type'] ?? '-'),
                (string)($row['status'] ?? '-'),
                (string)($row['barangay'] ?? '-'),
                (string)($row['display_date'] ?? '-'),
            ];
        }
    }

    if ($rowCount === 0) {
        $rows[] = ['No case records matched the selected filters.', '', '', '', '', '', ''];
    }

    $rows[] = ['', '', '', '', '', '', ''];
    $rows[] = ['Total Records: ' . $rowCount . ' | This document is system-generated for official administrative reference.', '', '', '', '', '', ''];

    $esc = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };

    $sheetRowsXml = '';
    foreach ($rows as $idx => $r) {
        $sheetRowsXml .= '<row r="' . ($idx + 1) . '">';
        foreach ($r as $cIdx => $cellVal) {
            $col = chr(65 + $cIdx);
            $cellRef = $col . ($idx + 1);
            $styleId = '3';
            if ($idx === 0) $styleId = '1';
            elseif ($idx === 1 || $idx === 2 || $idx === count($rows) - 1) $styleId = '2';
            elseif ($idx === 4) $styleId = '4';

            $sheetRowsXml .= '<c r="' . $cellRef . '" s="' . $styleId . '" t="inlineStr"><is><t>' . $esc((string)$cellVal) . '</t></is></c>';
        }
        $sheetRowsXml .= '</row>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<cols>'
        . '<col min="1" max="1" width="14" customWidth="1"/>'
        . '<col min="2" max="2" width="22" customWidth="1"/>'
        . '<col min="3" max="3" width="22" customWidth="1"/>'
        . '<col min="4" max="4" width="22" customWidth="1"/>'
        . '<col min="5" max="5" width="14" customWidth="1"/>'
        . '<col min="6" max="6" width="16" customWidth="1"/>'
        . '<col min="7" max="7" width="22" customWidth="1"/>'
        . '<col min="8" max="8" width="24" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . $sheetRowsXml . '</sheetData>'
        . '<mergeCells count="4">'
        . '<mergeCell ref="A1:H1"/>'
        . '<mergeCell ref="A2:H2"/>'
        . '<mergeCell ref="A3:H3"/>'
        . '<mergeCell ref="A' . count($rows) . ':H' . count($rows) . '"/>'
        . '</mergeCells>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="16"/><name val="Calibri"/></font>'
        . '<font><i/><sz val="10"/><color rgb="FF475569"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Case Registry" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $xlsxName = 'GabayBatas_Official_Case_Registry_Report_' . date('Ymd_His') . '.xlsx';

    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'gabaybatas_xlsx_');
        $zip = new ZipArchive();
        if ($tmp !== false && $zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('[Content_Types].xml', $contentTypesXml);
            $zip->addFromString('_rels/.rels', $rootRelsXml);
            $zip->addFromString('xl/workbook.xml', $workbookXml);
            $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
            $zip->addFromString('xl/styles.xml', $stylesXml);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
            $zip->close();

            if (is_file($tmp)) {
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $xlsxName . '"');
                header('Content-Length: ' . filesize($tmp));
                header('Cache-Control: max-age=0');
                readfile($tmp);
                @unlink($tmp);
                exit;
            }
        }
    }

    // Fallback if ZipArchive is not available
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="GabayBatas_Official_Case_Registry_Report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['GabayBatas - Official Case Registry Report']);
    fputcsv($out, ['Administrative Case Monitoring and Documentation Summary']);
    fputcsv($out, ['Generated: ' . date('F d, Y h:i A') . ' | Prepared by: ' . (string)($_SESSION['name'] ?? 'Admin')]);
    fputcsv($out, []);
    fputcsv($out, ['Case ID', 'Resident', 'Complained Person', 'Type', 'Status', 'Barangay', 'Date Filed']);
    if ($exp) {
        $exp->data_seek(0);
        while ($row = $exp->fetch_assoc()) {
            fputcsv($out, [
                (string)($row['display_case_id'] ?? '-'),
                (string)($row['display_resident_name'] ?? '-'),
                (string)($row['display_complained_person'] ?? '-'),
                (string)($row['case_type'] ?? '-'),
                (string)($row['status'] ?? '-'),
                (string)($row['barangay'] ?? '-'),
                (string)($row['display_date'] ?? '-'),
            ]);
        }
    }
    fclose($out);
    exit;
}

$totalFiltered = 0;
$countRes = $conn->query("SELECT COUNT(*) AS t FROM cases {$where}");
if ($countRes) {
    $totalFiltered = (int)($countRes->fetch_assoc()['t'] ?? 0);
}
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($pageNo > $totalPages) $pageNo = $totalPages;
$offset = ($pageNo - 1) * $perPage;

$cases = $conn->query($selectBase . " ORDER BY {$orderBy} LIMIT {$offset}, {$perPage}");

$queryBase = [
    'search' => $search,
    'status' => $filterStatus,
    'type' => $filterType,
    'sort' => $sort,
];

$buildQuery = static function (array $base, array $override = []): string {
    $q = array_merge($base, $override);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) unset($q[$k]);
    }
    return http_build_query($q);
};
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
<div class="topbar">
    <div class="topbar-left"><div class="topbar-title">All Cases</div></div>
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

<div class="page-body ac-body">

    <div class="ac-header fade-up" style="--d:0s">
        <p class="ac-sub">Browse, filter, and manage all case records</p>
    </div>

    <!-- Status filter tabs -->
    <div class="ac-tabs fade-up" style="--d:.06s">
        <a href="all_cases.php?<?= htmlspecialchars($buildQuery($queryBase, ['status' => ''])) ?>" class="ac-tab <?= $filterStatus===''?'active':'' ?>">All <span class="ac-tab-count"><?= $totalCases ?></span></a>
        <a href="all_cases.php?<?= htmlspecialchars($buildQuery($queryBase, ['status' => 'Pending', 'page' => 1])) ?>" class="ac-tab <?= strtolower($filterStatus)==='pending'?'active':'' ?>">Pending <span class="ac-tab-count"><?= $pendingCount ?></span></a>
        <a href="all_cases.php?<?= htmlspecialchars($buildQuery($queryBase, ['status' => 'In Progress', 'page' => 1])) ?>" class="ac-tab <?= strtolower($filterStatus)==='in progress'?'active':'' ?>">In Progress <span class="ac-tab-count"><?= $progressCount ?></span></a>
        <a href="all_cases.php?<?= htmlspecialchars($buildQuery($queryBase, ['status' => 'Resolved', 'page' => 1])) ?>" class="ac-tab <?= strtolower($filterStatus)==='resolved'?'active':'' ?>">Resolved <span class="ac-tab-count"><?= $resolvedCount ?></span></a>
    </div>

    <!-- Card -->
    <div class="ac-card fade-up" style="--d:.12s">
        <!-- Toolbar -->
        <div class="ac-toolbar">
            <form method="GET" class="ac-search-form" id="acSearchForm">
                <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                <input type="hidden" name="type" value="<?= htmlspecialchars($filterType) ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                <i class="uil uil-search ac-search-icon"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search cases..." class="ac-search-input">
            </form>
            <form method="GET" class="ac-toolbar-right ac-filter-form">
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">

                <?php if ($typeColumn): ?>
                <select name="type" class="ac-select" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <?php foreach ($typeOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>" <?= $filterType === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <?php if ($statusColumn): ?>
                <select name="status" class="ac-select" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <optgroup label="General Status">
                        <option value="Pending" <?= $filterStatus === 'Pending' ? 'selected' : '' ?>>All Pending</option>
                        <option value="In Progress" <?= $filterStatus === 'In Progress' ? 'selected' : '' ?>>All In Progress</option>
                        <option value="Resolved" <?= $filterStatus === 'Resolved' ? 'selected' : '' ?>>All Resolved</option>
                    </optgroup>
                    <optgroup label="Intake & Review">
                        <option value="filed" <?= $filterStatus === 'filed' ? 'selected' : '' ?>>Filed</option>
                        <option value="under_review" <?= $filterStatus === 'under_review' ? 'selected' : '' ?>>Under Review</option>
                        <option value="additional_info_required" <?= $filterStatus === 'additional_info_required' ? 'selected' : '' ?>>Additional Info Required</option>
                        <option value="accepted" <?= $filterStatus === 'accepted' ? 'selected' : '' ?>>Accepted for KP</option>
                        <option value="not_eligible" <?= $filterStatus === 'not_eligible' ? 'selected' : '' ?>>Not Eligible for KP</option>
                    </optgroup>
                    <optgroup label="Mediation & Conciliation">
                        <option value="mediation_scheduled" <?= $filterStatus === 'mediation_scheduled' ? 'selected' : '' ?>>Mediation Scheduled</option>
                        <option value="mediation_ongoing" <?= $filterStatus === 'mediation_ongoing' ? 'selected' : '' ?>>Mediation Ongoing</option>
                        <option value="mediation_settled" <?= $filterStatus === 'mediation_settled' ? 'selected' : '' ?>>Settled in Mediation</option>
                        <option value="pangkat_constitution" <?= $filterStatus === 'pangkat_constitution' ? 'selected' : '' ?>>Pangkat Constitution</option>
                        <option value="conciliation_scheduled" <?= $filterStatus === 'conciliation_scheduled' ? 'selected' : '' ?>>Conciliation Scheduled</option>
                        <option value="conciliation_ongoing" <?= $filterStatus === 'conciliation_ongoing' ? 'selected' : '' ?>>Conciliation Ongoing</option>
                        <option value="conciliation_settled" <?= $filterStatus === 'conciliation_settled' ? 'selected' : '' ?>>Settled in Conciliation</option>
                        <option value="mediation_noshow_complainant" <?= $filterStatus === 'mediation_noshow_complainant' ? 'selected' : '' ?>>Complainant Absent (Mediation)</option>
                        <option value="conciliation_noshow_complainant" <?= $filterStatus === 'conciliation_noshow_complainant' ? 'selected' : '' ?>>Complainant Absent (Conciliation)</option>
                        <option value="conciliation_noshow_respondent" <?= $filterStatus === 'conciliation_noshow_respondent' ? 'selected' : '' ?>>Respondent Absent (Conciliation)</option>
                    </optgroup>
                    <optgroup label="Settlement & Disposition">
                        <option value="arbitration_agreed" <?= $filterStatus === 'arbitration_agreed' ? 'selected' : '' ?>>Arbitration Agreed</option>
                        <option value="arbitration_hearing" <?= $filterStatus === 'arbitration_hearing' ? 'selected' : '' ?>>Arbitration Hearing</option>
                        <option value="arbitration_awarded" <?= $filterStatus === 'arbitration_awarded' ? 'selected' : '' ?>>Arbitration Award Issued</option>
                        <option value="settlement_repudiation" <?= $filterStatus === 'settlement_repudiation' ? 'selected' : '' ?>>Repudiation Window</option>
                        <option value="settlement_final" <?= $filterStatus === 'settlement_final' ? 'selected' : '' ?>>Settlement Final & Executory</option>
                        <option value="compliance_monitoring" <?= $filterStatus === 'compliance_monitoring' ? 'selected' : '' ?>>Compliance Monitoring</option>
                        <option value="execution_pending" <?= $filterStatus === 'execution_pending' ? 'selected' : '' ?>>Execution Pending</option>
                        <option value="execution_completed" <?= $filterStatus === 'execution_completed' ? 'selected' : '' ?>>Execution Completed</option>
                        <option value="certified_to_court" <?= $filterStatus === 'certified_to_court' ? 'selected' : '' ?>>Certified to Court</option>
                        <option value="dismissed" <?= $filterStatus === 'dismissed' ? 'selected' : '' ?>>Dismissed</option>
                        <option value="closed" <?= $filterStatus === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </optgroup>
                </select>
                <?php endif; ?>
                <select name="sort" class="ac-select" onchange="this.form.submit()">
                    <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest</option>
                    <option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>Oldest</option>
                    <option value="resident_asc" <?= $sort==='resident_asc'?'selected':'' ?>>Resident A-Z</option>
                    <option value="resident_desc" <?= $sort==='resident_desc'?'selected':'' ?>>Resident Z-A</option>
                    <?php if ($statusColumn): ?><option value="status_asc" <?= $sort==='status_asc'?'selected':'' ?>>Status A-Z</option><?php endif; ?>
                </select>

                <a href="cases_report.php?<?= htmlspecialchars($buildQuery($queryBase)) ?>" class="ac-btn-ghost" title="Open flexible official case report page"><i class="uil uil-file-download"></i> Case Report</a>
            </form>
        </div>

        <?php if (isset($_GET['archived']) && $_GET['archived'] === '1'): ?>
        <script>
        if (window.AdminToast) {
            window.AdminToast.show('success', 'Case Archived', 'The case was removed from active records and preserved in the archive.');
        }
        </script>
        <?php endif; ?>

        <!-- Table -->
        <div class="ac-table-wrap">
            <table class="ac-table">
                <thead>
                    <tr><th>Case ID</th><th>Resident</th><th>Complained Person</th><th>Type</th><th>Status</th><th>Barangay</th><th>Date & Time</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php if ($cases && $cases->num_rows > 0): ?>
                <?php while ($row = $cases->fetch_assoc()):
                    $statusValue   = trim((string)($row['status']   ?? ''));
                    $sc = $statusValue==='Pending'?'pending':($statusValue==='Resolved'?'resolved':'progress');
                    $caseIdRaw = trim((string)($row['display_case_id'] ?? ''));
                    if ($caseIdRaw === '') {
                        $caseIdRaw = trim((string)($row['row_id'] ?? ''));
                    }
                    $caseIdDigits = preg_replace('/\D+/', '', $caseIdRaw);
                    $caseIdDisplay = $caseIdDigits !== '' ? str_pad(substr($caseIdDigits, -4), 4, '0', STR_PAD_LEFT) : '-';
                    $resName = (string)($row['display_resident_name'] ?? '-');
                    $initial = strtoupper(substr($resName,0,1)) ?: '?';
                    $dateRaw = (string)($row['display_date'] ?? '');
                    $dateTs = strtotime($dateRaw);
                    $dateDisplay = $dateTs !== false ? date('M d, Y h:i A', $dateTs) : ($dateRaw !== '' ? $dateRaw : '-');
                ?>
                <tr>
                    <td><span class="ac-case-id"><?= htmlspecialchars($caseIdDisplay) ?></span></td>
                    <td>
                        <div class="ac-name-cell">
                            <div class="ac-avatar"><?= $initial ?></div>
                            <?= htmlspecialchars($resName) ?>
                        </div>
                    </td>
                    <td class="ac-muted"><?= htmlspecialchars((string)($row['display_complained_person'] ?? 'N/A')) ?></td>
                    <td class="ac-muted"><?= htmlspecialchars((string)($row['case_type']??'N/A')) ?></td>
                    <td>
                        <?php
                        $kpStKey = trim((string)($row['kp_status'] ?? ''));
                        if ($kpStKey === '' || !array_key_exists($kpStKey, kp_statuses())) {
                            $kpStKey = strtolower(str_replace(' ', '_', $statusValue));
                        }
                        $badgeLabel = kp_status_label($kpStKey);
                        if ($badgeLabel === 'Unknown' || $badgeLabel === '') {
                            $badgeLabel = $statusValue !== '' ? $statusValue : 'Pending';
                        }
                        $badgeColor = kp_status_color($kpStKey);
                        ?>
                        <?php if($statusValue!=='' || $kpStKey!==''): ?>
                        <span class="ac-badge" style="background:<?= htmlspecialchars($badgeColor) ?>18;color:<?= htmlspecialchars($badgeColor) ?>;border:1px solid <?= htmlspecialchars($badgeColor) ?>44;font-weight:600;padding:4px 10px;border-radius:999px;display:inline-flex;align-items:center;gap:6px;font-size:12px;">
                            <span class="ac-bdot" style="background:<?= htmlspecialchars($badgeColor) ?>;width:6px;height:6px;border-radius:50%;display:inline-block;"></span>
                            <?= htmlspecialchars($badgeLabel) ?>
                        </span>
                        <?php else: ?><span class="ac-nil">—</span><?php endif; ?>
                    </td>
                    <td class="ac-muted"><?= htmlspecialchars((string)($row['barangay']??'N/A')) ?></td>
                    <td class="ac-muted ac-date"><?= htmlspecialchars($dateDisplay) ?></td>
                    <td>
                        <div class="ac-action-menu">
                            <button class="ac-menu-btn" onclick="toggleMenu(this)" type="button">
                                <i class="uil uil-ellipsis-v"></i>
                            </button>
                            <div class="ac-dropdown">
                                <a href="edit_case.php?id=<?= urlencode((string)($row['row_id']??'')) ?>" class="ac-dropdown-item">
                                    <i class="uil uil-edit"></i> Edit
                                </a>
                                <a href="view_case.php?id=<?= urlencode((string)($row['row_id']??'')) ?>" class="ac-dropdown-item">
                                    <i class="uil uil-eye"></i> View
                                </a>
                                <a href="delete_case.php?id=<?= urlencode((string)($row['row_id']??'')) ?>" class="ac-dropdown-item ac-delete">
                                    <i class="uil uil-archive"></i> Archive
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr><td colspan="9" class="ac-empty">
                    <i class="uil uil-folder-open" style="font-size:28px;display:block;margin-bottom:8px;color:#cbd5e1"></i>
                    No cases found.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="ac-footer">
            <div class="ac-page-meta">Showing <?= $totalFiltered > 0 ? ($offset + 1) : 0 ?>-<?= min($offset + $perPage, $totalFiltered) ?> of <?= $totalFiltered ?> cases</div>
            <div class="ac-pagination">
                <a class="ac-page-btn <?= $pageNo <= 1 ? 'disabled' : '' ?>" href="all_cases.php?<?= htmlspecialchars($buildQuery($queryBase, ['page' => max(1, $pageNo - 1)])) ?>">Prev</a>
                <span class="ac-page-current">Page <?= $pageNo ?> / <?= $totalPages ?></span>
                <a class="ac-page-btn <?= $pageNo >= $totalPages ? 'disabled' : '' ?>" href="all_cases.php?<?= htmlspecialchars($buildQuery($queryBase, ['page' => min($totalPages, $pageNo + 1)])) ?>">Next</a>
            </div>
        </div>
    </div>

</div>
</div>

<script>
// Dropdown menu toggle
function toggleMenu(btn){
  const menu=btn.nextElementSibling;
  const allMenus=document.querySelectorAll('.ac-dropdown.show');
  allMenus.forEach(m=>{if(m!==menu)m.classList.remove('show');});
  menu.classList.toggle('show');
  event.stopPropagation();
}
document.addEventListener('click',e=>{
  const allMenus=document.querySelectorAll('.ac-dropdown.show');
  allMenus.forEach(m=>{
    if(!m.parentElement.contains(e.target))m.classList.remove('show');
  });
});

// Fade-up animation
const fo=new IntersectionObserver(e=>e.forEach(en=>{if(en.isIntersecting){en.target.classList.add('visible');fo.unobserve(en.target);}}),{threshold:.1});
document.querySelectorAll('.fade-up').forEach(el=>fo.observe(el));
</script>

<style>
.ac-body{padding:28px 32px 48px;}
.fade-up{opacity:0;transform:translateY(20px);transition:opacity .5s ease var(--d,0s),transform .5s ease var(--d,0s);}
.fade-up.visible{opacity:1;transform:none;}

.ac-header{margin-bottom:18px;}
.ac-sub{font-size:15px;line-height:1.65;color:#0f172a;font-weight:700;margin:0;max-width:62ch;}

/* Tabs */
.ac-tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;}
.ac-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:11px;font-size:13.5px;font-weight:600;color:var(--text-sub);text-decoration:none;border:1.5px solid transparent;transition:all .2s;background:#fff;border-color:#f1f5f9;cursor:pointer;}
.ac-tab:hover{border-color:#cbd5e1;color:var(--text-main);background:#fafbff;}
.ac-tab.active{background:#3b82f6;color:#fff;border-color:#3b82f6;box-shadow:0 4px 12px rgba(59,130,246,.3);}
.ac-tab-count{font-size:12px;font-weight:700;padding:2px 8px;border-radius:100px;background:rgba(255,255,255,.3);}
.ac-tab:not(.active) .ac-tab-count{background:#f1f5f9;color:#64748b;}

/* Card */
.ac-card{background:#fff;border:1px solid #f1f5f9;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);overflow:hidden;}
.ac-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px 24px;border-bottom:1px solid #f1f5f9;flex-wrap:nowrap;overflow-x:auto;}
.ac-search-form{position:relative;flex:1;max-width:400px;}
.ac-search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:16px;pointer-events:none;}
.ac-search-input{width:100%;padding:11px 14px 11px 40px;border:1px solid #e2e8f0;border-radius:10px;font-size:13.5px;color:var(--text-main);background:#fafbff;outline:none;transition:border .2s;}
.ac-search-input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}
.ac-search-input::placeholder{color:#cbd5e1;}
.ac-toolbar-right{display:flex;gap:12px;align-items:center;flex-wrap:nowrap;justify-content:flex-end;min-width:0;}
.ac-filter-form{flex:1;}
.ac-select{height:38px;min-width:120px;padding:0 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;font-size:13px;color:#334155;outline:none;transition:border .2s;cursor:pointer;white-space:nowrap;flex-shrink:0;}
.ac-select:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}
.ac-btn-ghost{display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;color:var(--text-sub);cursor:pointer;transition:all .2s;}
.ac-btn-ghost:hover{border-color:#3b82f6;color:#3b82f6;background:#f0f7ff;}

/* Table */
.ac-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
.ac-table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:13px;min-width:0;}
.ac-table th{padding:16px 18px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-sub);font-weight:700;background:#fafbff;border-bottom:2px solid #e5e7eb;white-space:nowrap;}
.ac-table th:nth-child(1){width:9%;text-align:center;}
.ac-table th:nth-child(2){width:15%;}
.ac-table th:nth-child(3){width:16%;}
.ac-table th:nth-child(4){width:15%;}
.ac-table th:nth-child(5){width:12%;text-align:center;}
.ac-table th:nth-child(6){width:14%;}
.ac-table th:nth-child(7){width:13%;}
.ac-table th:nth-child(8){width:6%;text-align:center;}
.ac-table td{padding:16px 18px;border-bottom:1px solid #f3f4f6;vertical-align:middle;text-align:left;}
.ac-table td:nth-child(1){text-align:center;}
.ac-table td:nth-child(5){text-align:center;}
.ac-table td:nth-child(6){text-align:center;}
.ac-table td:nth-child(9){text-align:center;}
.ac-table tbody tr{transition:background .15s ease;}
.ac-table tbody tr:last-child td{border-bottom:none;}
.ac-table tbody tr:hover td{background:#f8fafc;}
.ac-case-id{font-family:monospace;font-size:11px;font-weight:600;background:#f1f5f9;color:#475569;padding:4px 10px;border-radius:6px;display:inline-block;}
.ac-name-cell{display:flex;align-items:center;gap:10px;}
.ac-avatar{width:32px;height:32px;border-radius:50%;background:#e0e7ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;}
.ac-muted{font-size:13px;color:var(--text-sub);}
.ac-date{font-size:12.5px;color:#475569;}
.ac-nil{color:#cbd5e1;font-weight:500;}

/* Badges */
.ac-badge{display:inline-flex;align-items:center;justify-content:center;gap:5px;font-size:12px;font-weight:600;padding:5px 12px;border-radius:100px;white-space:nowrap;min-width:90px;}
.ac-badge.pending{background:#fffbeb;color:#92400e;border:1px solid #fcd34d;}
.ac-badge.progress{background:#eef2ff;color:#4338ca;border:1px solid #a5b4fc;}
.ac-badge.resolved{background:#dcfce7;color:#15803d;border:1px solid #86efac;}
.ac-bdot{width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}


.ac-edit-btn{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#0f766e;text-decoration:none;padding:6px 13px;border-radius:8px;border:1px solid #99f6e4;background:#ccfbf1;transition:all .2s;white-space:nowrap;margin-bottom:4px;}
.ac-edit-btn:hover{background:#99f6e4;color:#065f46;}
.ac-view-btn{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#3b82f6;text-decoration:none;padding:6px 13px;border-radius:8px;border:1px solid #dbeafe;background:#eff6ff;transition:all .2s;white-space:nowrap;margin-bottom:4px;}
.ac-view-btn:hover{background:#dbeafe;color:#1e40af;}
.ac-delete-btn{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#dc2626;text-decoration:none;padding:6px 13px;border-radius:8px;border:1px solid #fecaca;background:#fef2f2;transition:all .2s;white-space:nowrap;margin-bottom:4px;}
.ac-delete-btn:hover{background:#fee2e2;color:#b91c1c;}

/* Action Menu */
.ac-action-menu{position:relative;display:inline-block;}
.ac-menu-btn{display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;font-size:18px;cursor:pointer;transition:all .2s;}
.ac-menu-btn:hover{border-color:#cbd5e1;background:#f8fafc;color:#334155;}
.ac-menu-btn:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}
.ac-dropdown{position:absolute;top:100%;right:0;min-width:140px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:100;opacity:0;visibility:hidden;transform:translateY(-8px);transition:all .2s;margin-top:4px;}
.ac-dropdown.show{opacity:1;visibility:visible;transform:translateY(0);}
.ac-dropdown-item{display:flex;align-items:center;gap:10px;padding:12px 16px;color:#334155;text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;border:none;background:none;width:100%;text-align:left;cursor:pointer;}
.ac-dropdown-item:hover{background:#f8fafc;color:#1e293b;}
.ac-dropdown-item:first-child{border-radius:9px 9px 0 0;}
.ac-dropdown-item:last-child{border-radius:0 0 9px 9px;}
.ac-dropdown-item.ac-delete{color:#dc2626;}
.ac-dropdown-item.ac-delete:hover{background:#fef2f2;color:#b91c1c;}
.ac-empty{text-align:center;color:#94a3b8;padding:60px 40px!important;font-size:14px;line-height:1.6;}

.ac-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 24px;border-top:1px solid #f1f5f9;background:#fcfdff;}
.ac-page-meta{font-size:13px;color:#64748b;font-weight:500;}
.ac-pagination{display:flex;align-items:center;gap:12px;}
.ac-page-current{font-size:13px;color:#334155;font-weight:700;}
.ac-page-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe3ef;border-radius:10px;padding:8px 14px;text-decoration:none;font-size:12.5px;font-weight:600;color:#334155;background:#fff;cursor:pointer;transition:all .2s;}
.ac-page-btn:hover:not(.disabled){border-color:#93c5fd;color:#1d4ed8;background:#f0f7ff;}
.ac-page-btn.disabled{pointer-events:none;opacity:.45;cursor:not-allowed;}

@media(max-width:1200px){
  .ac-table{min-width:900px;font-size:12px;}
  .ac-table th,.ac-table td{padding:14px 16px;}
}
@media(max-width:900px){
  .ac-body{padding:16px 20px;}
  .ac-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;}
  .ac-tab{justify-content:space-between;padding:10px 12px;min-width:0;}
  .ac-toolbar{flex-direction:column;align-items:flex-start;gap:12px;}
  .ac-search-form{max-width:none;width:100%;}
  .ac-toolbar-right{width:100%;justify-content:flex-start;}
  .ac-footer{flex-direction:column;align-items:flex-start;}
  .ac-table{min-width:800px;font-size:12px;}
  .ac-table th,.ac-table td{padding:12px 14px;}
  .ac-dropdown{right:-10px;}
}
@media(max-width:480px){
  .ac-body{padding:14px 12px 32px;}
  .ac-tabs{grid-template-columns:1fr;}
  .ac-tab{width:100%;}
}
</style>