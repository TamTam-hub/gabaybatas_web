<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header('Location: ../login.php'); exit(); }
require_once '../config/database.php';
require_once '../config/barangays.php';
require_once '../config/archive.php';
require_once '../includes/pagination.php';
gabaybatas_archive_ensure_schema($conn);

$userColumns = [];
$userColResult = $conn->query("SHOW COLUMNS FROM users");
if ($userColResult) { while ($col = $userColResult->fetch_assoc()) $userColumns[] = $col['Field']; }
if (!in_array('status', $userColumns, true)) {
    $conn->query("ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active'");
    $userColResult = $conn->query("SHOW COLUMNS FROM users");
    $userColumns = [];
    if ($userColResult) { while ($col = $userColResult->fetch_assoc()) $userColumns[] = $col['Field']; }
}

$hasUserColumn = static function (string $column) use ($userColumns): bool { return in_array($column, $userColumns, true); };

$idColumn       = $hasUserColumn('id')       ? 'id'       : ($userColumns[0] ?? 'id');
$nameColumn     = $hasUserColumn('name')     ? 'name'     : ($hasUserColumn('username') ? 'username' : $idColumn);
$emailColumn    = $hasUserColumn('email')    ? 'email'    : "''";
$roleColumn     = $hasUserColumn('role')     ? 'role'     : "''";
$barangayColumn = $hasUserColumn('barangay') ? 'barangay' : "''";
$ageColumn      = $hasUserColumn('age')      ? 'age'      : "''";
$statusColumn   = $hasUserColumn('status')   ? 'status'   : "''";
$createdColumn  = $hasUserColumn('created_at') ? 'created_at' : null;
$lastLoginColumn = $hasUserColumn('last_login') ? 'last_login' : ($hasUserColumn('login_at') ? 'login_at' : ($hasUserColumn('logged_in_at') ? 'logged_in_at' : null));

$totalUsers   = (int)$conn->query("SELECT COUNT(*) as t FROM users WHERE archived_at IS NULL")->fetch_assoc()['t'];
$activeUsers  = $hasUserColumn('status') ? (int)$conn->query("SELECT COUNT(*) as t FROM users WHERE archived_at IS NULL AND LOWER(status)='active'")->fetch_assoc()['t'] : $totalUsers;
$adminCount   = $hasUserColumn('role')   ? (int)$conn->query("SELECT COUNT(*) as t FROM users WHERE archived_at IS NULL AND LOWER(role)='admin'")->fetch_assoc()['t']   : 0;

$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$barangayFilter = '';
$sort = trim($_GET['sort'] ?? 'newest');
$pageNo = max(1, (int)($_GET['page'] ?? 1));
$perPage = gabaybatas_per_page(10);
$archived = isset($_GET['archived']) ? (int)$_GET['archived'] : 0;

$conditions = [];
$conditions[] = 'archived_at IS NULL';
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    if ($hasUserColumn('email')) {
        $conditions[] = "({$nameColumn} LIKE '%$s%' OR {$emailColumn} LIKE '%$s%')";
    } else {
        $conditions[] = "{$nameColumn} LIKE '%$s%'";
    }
}

if ($roleFilter !== '' && $hasUserColumn('role')) {
    $rf = $conn->real_escape_string($roleFilter);
    $conditions[] = "LOWER(role) = LOWER('$rf')";
}

if ($statusFilter !== '' && $hasUserColumn('status')) {
    $sf = $conn->real_escape_string($statusFilter);
    $conditions[] = "status = '$sf'";
}

$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$roleOptions = [];
if ($hasUserColumn('role')) {
    $roleRes = $conn->query("SELECT DISTINCT role AS v FROM users WHERE role IS NOT NULL AND TRIM(role) <> '' ORDER BY role ASC");
    if ($roleRes) {
        while ($r = $roleRes->fetch_assoc()) {
            $roleOptions[] = strtolower(trim((string)$r['v']));
        }
    }
}

foreach (['resident', 'barangay', 'admin'] as $baseRole) {
    if (!in_array($baseRole, $roleOptions, true)) {
        $roleOptions[] = $baseRole;
    }
}
sort($roleOptions);

$statusOptions = [];
if ($hasUserColumn('status')) {
    $statusRes = $conn->query("SELECT DISTINCT status AS v FROM users WHERE archived_at IS NULL AND status IS NOT NULL AND TRIM(status) <> '' ORDER BY status ASC");
    if ($statusRes) {
        while ($r = $statusRes->fetch_assoc()) {
            $statusOptions[] = (string)$r['v'];
        }
    }
}

$barangayOptions = gabaybatas_get_barangays();

$orderBy = "{$idColumn} DESC";
if ($sort === 'oldest') {
    $orderBy = "{$idColumn} ASC";
} elseif ($sort === 'name_asc') {
    $orderBy = "{$nameColumn} ASC";
} elseif ($sort === 'name_desc') {
    $orderBy = "{$nameColumn} DESC";
}

$totalFiltered = 0;
$countResult = $conn->query("SELECT COUNT(*) AS t FROM users {$where}");
if ($countResult) {
    $totalFiltered = (int)($countResult->fetch_assoc()['t'] ?? 0);
}
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($pageNo > $totalPages) {
    $pageNo = $totalPages;
}
$offset = ($pageNo - 1) * $perPage;

$queryBase = "SELECT {$idColumn} AS user_id, {$nameColumn} AS display_name,
    {$emailColumn} AS display_email, {$roleColumn} AS display_role,
    {$barangayColumn} AS display_barangay, {$ageColumn} AS display_age, {$statusColumn} AS display_status,
    " . ($createdColumn ? "{$createdColumn} AS display_created_at" : "NULL AS display_created_at") . ",
    " . ($lastLoginColumn ? "{$lastLoginColumn} AS display_last_login" : "NULL AS display_last_login") . "
    FROM users {$where}";

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportRes = $conn->query($queryBase . " ORDER BY {$orderBy}");

    $rows = [];
    $rows[] = ['GabayBatas - Official User Registry Report', '', '', '', '', '', ''];
    $rows[] = ['Administrative User Management and Access Summary', '', '', '', '', '', ''];

    $activeFilters = [];
    if ($search !== '') $activeFilters[] = 'Search: ' . $search;
    if ($roleFilter !== '') $activeFilters[] = 'Role: ' . $roleFilter;
    if ($statusFilter !== '') $activeFilters[] = 'Status: ' . $statusFilter;
    if ($barangayFilter !== '') $activeFilters[] = 'Barangay: ' . $barangayFilter;
    if ($sort !== '') $activeFilters[] = 'Sort: ' . $sort;
    $filterText = $activeFilters ? implode(' | ', $activeFilters) : 'No filters applied';

    $rows[] = ['Generated: ' . date('F d, Y h:i A') . ' | Prepared by: ' . (string)($_SESSION['name'] ?? 'Admin') . ' | ' . $filterText, '', '', '', '', '', ''];
    $rows[] = ['', '', '', '', '', '', ''];
    $rows[] = ['User ID', 'Name', 'Email', 'Role', 'Barangay', 'Age', 'Status'];

    $rowCount = 0;
    if ($exportRes) {
        while ($row = $exportRes->fetch_assoc()) {
            $rowCount++;
            $idValue = trim((string)($row['user_id'] ?? ''));
            $nameValue = trim((string)($row['display_name'] ?? ''));
            $emailValue = trim((string)($row['display_email'] ?? ''));
            $roleValue = trim((string)($row['display_role'] ?? ''));
            $barangayValue = trim((string)($row['display_barangay'] ?? ''));
            $ageValue = trim((string)($row['display_age'] ?? ''));
            $statusValue = trim((string)($row['display_status'] ?? ''));

            $rows[] = [
                $idValue !== '' ? $idValue : 'N/A',
                $nameValue !== '' ? $nameValue : 'N/A',
                $emailValue !== '' ? $emailValue : 'N/A',
                $roleValue !== '' ? $roleValue : 'N/A',
                $barangayValue !== '' ? $barangayValue : 'N/A',
                $ageValue !== '' ? $ageValue : 'N/A',
                $statusValue !== '' ? $statusValue : 'N/A',
            ];
        }
    }

    if ($rowCount === 0) {
        $rows[] = ['No user records matched the selected filters.', '', '', '', '', '', ''];
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
        . '<col min="1" max="1" width="12" customWidth="1"/>'
        . '<col min="2" max="2" width="24" customWidth="1"/>'
        . '<col min="3" max="3" width="30" customWidth="1"/>'
        . '<col min="4" max="4" width="14" customWidth="1"/>'
        . '<col min="5" max="5" width="22" customWidth="1"/>'
        . '<col min="6" max="6" width="10" customWidth="1"/>'
        . '<col min="7" max="7" width="14" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . $sheetRowsXml . '</sheetData>'
        . '<mergeCells count="4">'
        . '<mergeCell ref="A1:G1"/>'
        . '<mergeCell ref="A2:G2"/>'
        . '<mergeCell ref="A3:G3"/>'
        . '<mergeCell ref="A' . count($rows) . ':G' . count($rows) . '"/>'
        . '</mergeCells>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="16"/><name val="Calibri"/></font>'
        . '<font><i/><sz val="10"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1" applyBorder="1"><alignment horizontal="left"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyAlignment="1" applyFill="1" applyBorder="1"><alignment horizontal="left"/></xf>'
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
        . '<sheets><sheet name="User Registry" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $xlsxName = 'GabayBatas_Official_User_Registry_Report_' . date('Ymd_His') . '.xlsx';

    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'gabaybatas_users_xlsx_');
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

    // Fallback if ZipArchive is unavailable: emit formal Excel-compatible HTML (.xls)
    $xlsName = 'GabayBatas_Official_User_Registry_Report_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $xlsName . '"');
    header('Cache-Control: max-age=0');

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<style>'
        . 'body{font-family:Calibri,Arial,sans-serif;color:#111;margin:20px;}'
        . '.title{font-size:20px;font-weight:700;margin-bottom:4px;}'
        . '.sub{font-size:13px;color:#222;margin-bottom:10px;}'
        . '.meta{font-size:12px;color:#222;margin-bottom:14px;}'
        . 'table{border-collapse:collapse;width:100%;font-size:12px;}'
        . 'th,td{border:1px solid #222;padding:8px 10px;text-align:left;vertical-align:top;}'
        . 'th{background:#f2f2f2;color:#111;font-weight:700;}'
        . '.foot{margin-top:12px;font-size:12px;color:#111;font-weight:600;}'
        . '</style></head><body>'
        . '<div class="title">GabayBatas - Official User Registry Report</div>'
        . '<div class="sub">Administrative User Management and Access Summary</div>'
        . '<div class="meta">Generated: ' . $esc(date('F d, Y h:i A')) . ' | Prepared by: ' . $esc((string)($_SESSION['name'] ?? 'Admin')) . ' | ' . $esc($filterText) . '</div>'
        . '<table><thead><tr>'
        . '<th>User ID</th><th>Name</th><th>Email</th><th>Role</th><th>Barangay</th><th>Age</th><th>Status</th>'
        . '</tr></thead><tbody>';

    if ($rowCount > 0) {
        foreach ($rows as $idx => $sheetRow) {
            if ($idx >= 5 && $idx < count($rows) - 2) {
                $html .= '<tr>'
                    . '<td>' . $esc(trim((string)($sheetRow[0] ?? '')) !== '' ? (string)$sheetRow[0] : 'N/A') . '</td>'
                    . '<td>' . $esc(trim((string)($sheetRow[1] ?? '')) !== '' ? (string)$sheetRow[1] : 'N/A') . '</td>'
                    . '<td>' . $esc(trim((string)($sheetRow[2] ?? '')) !== '' ? (string)$sheetRow[2] : 'N/A') . '</td>'
                    . '<td>' . $esc(trim((string)($sheetRow[3] ?? '')) !== '' ? (string)$sheetRow[3] : 'N/A') . '</td>'
                    . '<td>' . $esc(trim((string)($sheetRow[4] ?? '')) !== '' ? (string)$sheetRow[4] : 'N/A') . '</td>'
                    . '<td>' . $esc(trim((string)($sheetRow[5] ?? '')) !== '' ? (string)$sheetRow[5] : 'N/A') . '</td>'
                    . '<td>' . $esc(trim((string)($sheetRow[6] ?? '')) !== '' ? (string)$sheetRow[6] : 'N/A') . '</td>'
                    . '</tr>';
            }
        }
    } else {
        $html .= '<tr><td colspan="7">No user records matched the selected filters.</td></tr>';
    }

    $html .= '</tbody></table>'
        . '<div class="foot">Total Records: ' . $esc((string)$rowCount) . ' | This document is system-generated for official administrative reference.</div>'
        . '</body></html>';

    echo $html;
    exit;
}

$users = $conn->query($queryBase . " ORDER BY {$orderBy} LIMIT {$offset}, {$perPage}");
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
<div class="topbar">
    <div class="topbar-left"><div class="topbar-title">Manage Users</div></div>
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

<div class="page-body um-body">

    <div class="um-header fade-up" style="--d:0s">
        <p class="um-sub">Manage system users, roles, and permissions</p>
        <a href="add_user.php" class="um-add-btn"><i class="uil uil-plus"></i> Add User</a>
    </div>

    <!-- Stats row -->
    <div class="um-stats fade-up" style="--d:.06s">
        <div class="um-stat">
            <div class="um-stat-icon" style="--c:#3b82f6;--cb:#eff6ff"><i class="uil uil-users-alt"></i></div>
            <div><div class="um-stat-num"><?= $totalUsers ?></div><div class="um-stat-label">Total Users</div></div>
        </div>
        <div class="um-stat">
            <div class="um-stat-icon" style="--c:#10b981;--cb:#ecfdf5"><i class="uil uil-check-circle"></i></div>
            <div><div class="um-stat-num"><?= $activeUsers ?></div><div class="um-stat-label">Active</div></div>
        </div>
        <div class="um-stat">
            <div class="um-stat-icon" style="--c:#8b5cf6;--cb:#f5f3ff"><i class="uil uil-shield-check"></i></div>
            <div><div class="um-stat-num"><?= $adminCount ?></div><div class="um-stat-label">Admins</div></div>
        </div>
        <div class="um-stat">
            <div class="um-stat-icon" style="--c:#f59e0b;--cb:#fffbeb"><i class="uil uil-user-minus"></i></div>
            <div><div class="um-stat-num"><?= $totalUsers - $activeUsers ?></div><div class="um-stat-label">Inactive</div></div>
        </div>
    </div>

    <!-- Search + table -->
    <div class="um-card fade-up" style="--d:.12s">
        <div class="um-toolbar">
            <form method="GET" class="um-search-form">
                <i class="uil uil-search um-search-icon"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or email..." class="um-search-input">
                <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
            </form>
            <form method="GET" class="um-toolbar-right um-filter-form">
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">

                <select name="role" class="um-select" onchange="this.form.submit()">
                    <option value="">All Roles</option>
                    <?php foreach ($roleOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $roleFilter === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="um-select" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <?php foreach ($statusOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $statusFilter === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="sort" class="um-select" onchange="this.form.submit()">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                </select>

                <a class="um-btn-ghost" href="users_report.php?<?= htmlspecialchars(http_build_query([
                    'search' => $search,
                    'role' => $roleFilter,
                    'status' => $statusFilter,
                    'barangay' => $barangayFilter,
                    'sort' => $sort,
                ])) ?>"><i class="uil uil-file-alt"></i> User Report</a>
            </form>
        </div>

        <div class="um-table-wrap">
            <table class="um-table">
                <thead>
                    <tr><th>User</th><th>Email</th><th>Role</th><th>Barangay</th><th>Age</th><th>Last Login</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if ($users && $users->num_rows > 0): ?>
                <?php while ($row = $users->fetch_assoc()):
                    $name   = htmlspecialchars((string)($row['display_name']     ?? '-'));
                    $email  = htmlspecialchars((string)($row['display_email']    ?? 'N/A'));
                    $rawRole = strtolower(trim((string)($row['display_role'] ?? '')));
                    $roleLabel = $rawRole !== '' ? $rawRole : 'resident';
                    $role   = htmlspecialchars(['admin' => 'Administrator', 'barangay' => 'Barangay Official', 'resident' => 'Resident'][$roleLabel] ?? ucwords($roleLabel));
                    $brgy   = htmlspecialchars((string)($row['display_barangay'] ?? 'N/A'));
                    $age    = htmlspecialchars((string)($row['display_age'] ?? 'N/A'));
                    $status = trim((string)($row['display_status'] ?? ''));
                    $lastLogin = strtotime((string)($row['display_last_login'] ?? ''));
                    $lastLoginDisplay = $lastLogin ? date('M d, Y h:i A', $lastLogin) : 'Never';
                    $isActive = strtolower($status) === 'active';
                    $initial = strtoupper(substr($name, 0, 1)) ?: '?';
                    $colors  = ['#3b82f6','#6366f1','#8b5cf6','#10b981','#f59e0b','#ec4899'];
                    $clr     = $colors[crc32($name) % count($colors)];
                ?>
                <tr>
                    <td>
                        <div class="um-user-cell">
                            <div class="um-avatar" style="background:<?= $clr ?>22;color:<?= $clr ?>"><?= $initial ?></div>
                            <div>
                                <div class="um-name"><?= $name ?></div>
                                <div class="um-email-sm"><?= $email ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="um-cell-muted"><?= $email ?></td>
                    <td>
                        <span class="um-role-badge <?= htmlspecialchars($roleLabel) ?>"><?= $role ?></span>
                    </td>
                    <td class="um-cell-muted"><?= $brgy ?></td>
                    <td class="um-cell-muted"><?= $age ?></td>
                    <td class="um-cell-muted"><?= htmlspecialchars($lastLoginDisplay) ?></td>
                    <td>
                        <span class="um-status-badge <?= $isActive ? 'active' : 'inactive' ?>">
                            <span class="um-status-dot"></span>
                            <?= $status !== '' ? htmlspecialchars($status) : 'N/A' ?>
                        </span>
                    </td>
                    <td>
                        <div class="um-actions">
                            <a href="edit_user.php?id=<?= urlencode((string)($row['user_id']??'')) ?>" class="um-action-btn edit" title="Edit">
                                <i class="uil uil-edit"></i>
                            </a>
                            <a href="delete_user.php?id=<?= urlencode((string)($row['user_id']??'')) ?>" class="um-action-btn delete" title="Archive">
                                <i class="uil uil-archive"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <?php $umFiltered = $search !== '' || $roleFilter !== '' || $statusFilter !== ''; ?>
                <tr><td colspan="9" class="um-empty">
                    <?= gabaybatas_lottie($umFiltered ? 'search-file' : 'empty-box', 130, 'uil-users-alt') ?>
                    <strong><?= $umFiltered ? 'No users match these filters' : 'No users yet' ?></strong>
                    <span><?= $umFiltered ? 'Try a different search or clear the filters.' : 'Registered accounts will appear here.' ?></span>
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php gabaybatas_pagination($totalFiltered, $pageNo, $perPage, [
            'search' => $search,
            'role' => $roleFilter,
            'status' => $statusFilter,
            'sort' => $sort,
        ], 'users'); ?>
    </div>

</div>
</div>

<script>
const fo=new IntersectionObserver(e=>e.forEach(en=>{if(en.isIntersecting){en.target.classList.add('visible');fo.unobserve(en.target);}}),{threshold:.1});
document.querySelectorAll('.fade-up').forEach(el=>fo.observe(el));
<?php if ($archived): ?>
if (window.AdminToast) {
    window.AdminToast.show('success', 'User Archived', 'The account was deactivated and preserved in the archive.');
}
<?php endif; ?>
</script>

<style>
.um-body{padding:24px 28px 40px;}
.fade-up{opacity:0;transform:translateY(20px);transition:opacity .5s ease var(--d,0s),transform .5s ease var(--d,0s);}
.fade-up.visible{opacity:1;transform:none;}

.um-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.um-sub{font-size:15px;line-height:1.65;color:#0f172a;font-weight:700;margin:0;max-width:58ch;}
.um-add-btn{display:flex;align-items:center;gap:6px;background:#3b82f6;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;text-decoration:none;transition:background .2s;}
.um-add-btn:hover{background:#2563eb;}

.um-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px;}
.um-stat{background:#fff;border:1px solid #f1f5f9;border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.um-stat-icon{width:42px;height:42px;border-radius:11px;background:var(--cb);color:var(--c);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.um-stat-num{font-size:22px;font-weight:800;color:var(--text-main);line-height:1;}
.um-stat-label{font-size:11.5px;color:var(--text-sub);margin-top:2px;}

.um-card{background:#fff;border:1px solid #f1f5f9;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);overflow:hidden;}
.um-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid #f1f5f9;}
.um-search-form{position:relative;flex:1;max-width:360px;}
.um-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:16px;}
.um-search-input{width:100%;padding:9px 12px 9px 36px;border:1px solid #e2e8f0;border-radius:9px;font-size:13px;color:var(--text-main);background:#fafbff;outline:none;transition:border .2s;}
.um-search-input:focus{border-color:#3b82f6;}
.um-toolbar-right{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end;}
.um-filter-form{flex:1;}
.um-select{height:34px;min-width:118px;padding:0 10px;border:1px solid #e2e8f0;border-radius:9px;background:#fff;font-size:12.5px;color:#334155;outline:none;}
.um-btn-ghost{display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:8px 14px;font-size:12.5px;font-weight:600;color:var(--text-sub);cursor:pointer;transition:all .2s;}
.um-btn-ghost:hover{border-color:#3b82f6;color:#3b82f6;}

.um-table-wrap{overflow-x:auto;}
.um-table{width:100%;min-width:1100px;border-collapse:collapse;font-size:13px;}
.um-table th{padding:12px 16px;text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:var(--text-sub);font-weight:600;background:#fafbff;border-bottom:1px solid #e5e7eb;}
.um-table td{padding:14px 16px;border-bottom:1px solid #f3f4f6;color:var(--text-main);text-align:center;}
.um-table tbody tr:last-child td{border-bottom:none;}
.um-table tbody tr:hover td{background:#fafbff;}
.um-user-cell{display:flex;align-items:center;justify-content:center;gap:10px;}
.um-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;}
.um-name{font-weight:600;font-size:13.5px;color:var(--text-main);}
.um-email-sm{font-size:11.5px;color:var(--text-sub);}
.um-cell-muted{font-size:12.5px;color:var(--text-sub);}
.um-role-badge{display:inline-block;font-size:11px;font-weight:600;padding:3px 10px;border-radius:100px;background:#eef2ff;color:#4338ca;}
.um-status-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:100px;}
.um-status-badge.active{background:#dcfce7;color:#15803d;}
.um-status-badge.inactive{background:#f1f5f9;color:#64748b;}
.um-status-dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.um-actions{display:flex;gap:6px;}
.um-action-btn{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;text-decoration:none;transition:all .2s;}
.um-action-btn.edit{background:#eff6ff;color:#3b82f6;border:1px solid #dbeafe;}
.um-action-btn.edit:hover{background:#dbeafe;}
.um-action-btn.delete{background:#fff1f2;color:#ef4444;border:1px solid #fecdd3;}
.um-action-btn.delete:hover{background:#fecdd3;}
.um-empty{text-align:center!important;color:#94a3b8;padding:32px!important;font-size:13px;}
.um-empty strong{display:block;color:#334155;font-size:14px;}
.um-empty span{display:block;}
.um-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-top:1px solid #f1f5f9;background:#fcfdff;}
.um-page-meta{font-size:12px;color:#64748b;}
.um-pagination{display:flex;align-items:center;gap:10px;}
.um-page-current{font-size:12px;color:#334155;font-weight:600;}
.um-page-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe3ef;border-radius:8px;padding:6px 12px;text-decoration:none;font-size:12px;font-weight:600;color:#334155;background:#fff;}
.um-page-btn:hover{border-color:#93c5fd;color:#1d4ed8;}
.um-page-btn.disabled{pointer-events:none;opacity:.45;}

@media(max-width:900px){.um-stats{grid-template-columns:repeat(2,1fr);}.um-body{padding:16px;}.um-search-form{max-width:none;}.um-footer{flex-direction:column;align-items:flex-start;}.um-table-wrap{overflow-x:auto;}.um-table{min-width:1100px;}}
</style>