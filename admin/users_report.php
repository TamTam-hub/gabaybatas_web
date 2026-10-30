<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/barangays.php';

$userColumns = [];
$userColResult = $conn->query('SHOW COLUMNS FROM users');
if ($userColResult) {
    while ($col = $userColResult->fetch_assoc()) {
        $userColumns[] = $col['Field'];
    }
}

$hasUserColumn = static function (string $column) use ($userColumns): bool {
    return in_array($column, $userColumns, true);
};

$idColumn = $hasUserColumn('id') ? 'id' : ($userColumns[0] ?? 'id');
$nameColumn = $hasUserColumn('name') ? 'name' : ($hasUserColumn('username') ? 'username' : $idColumn);
$emailColumn = $hasUserColumn('email') ? 'email' : "''";
$roleColumn = $hasUserColumn('role') ? 'role' : "''";
$barangayColumn = $hasUserColumn('barangay') ? 'barangay' : "''";
$ageColumn = $hasUserColumn('age') ? 'age' : "''";
$statusColumn = $hasUserColumn('status') ? 'status' : "''";
$createdColumn = $hasUserColumn('created_at') ? 'created_at' : 'NULL';

$search = trim((string)($_GET['search'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$barangayFilter = '';
$sort = trim((string)($_GET['sort'] ?? 'newest'));
$includeAll = (string)($_GET['include_all'] ?? '0') === '1';
$download = (string)($_GET['download'] ?? '') === 'pdf';

$conditions = [];
// Archived accounts are never reported, same as the case reports.
if ($hasUserColumn('archived_at')) {
    $conditions[] = 'archived_at IS NULL';
}
if (!$includeAll) {
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
        $conditions[] = "LOWER({$roleColumn}) = LOWER('$rf')";
    }

    if ($statusFilter !== '' && $hasUserColumn('status')) {
        $sf = $conn->real_escape_string($statusFilter);
        $conditions[] = "LOWER({$statusColumn}) = LOWER('$sf')";
    }
}


$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$orderBy = "{$idColumn} DESC";
if ($sort === 'oldest') {
    $orderBy = "{$idColumn} ASC";
} elseif ($sort === 'name_asc') {
    $orderBy = "{$nameColumn} ASC";
} elseif ($sort === 'name_desc') {
    $orderBy = "{$nameColumn} DESC";
}

$queryBase = "SELECT {$idColumn} AS user_id, {$nameColumn} AS display_name,
    {$emailColumn} AS display_email, {$roleColumn} AS display_role,
    {$barangayColumn} AS display_barangay, {$ageColumn} AS display_age, {$statusColumn} AS display_status,
    {$createdColumn} AS display_created
    FROM users {$where}";

$roleOptions = [];
if ($hasUserColumn('role')) {
    $roleRes = $conn->query("SELECT DISTINCT {$roleColumn} AS v FROM users WHERE {$roleColumn} IS NOT NULL AND TRIM({$roleColumn}) <> '' ORDER BY {$roleColumn} ASC");
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
    $statusRes = $conn->query("SELECT DISTINCT {$statusColumn} AS v FROM users WHERE {$statusColumn} IS NOT NULL AND TRIM({$statusColumn}) <> '' ORDER BY {$statusColumn} ASC");
    if ($statusRes) {
        while ($r = $statusRes->fetch_assoc()) {
            $statusOptions[] = (string)$r['v'];
        }
    }
}

$barangayOptions = gabaybatas_get_barangays();

$activeFilters = [];
if ($includeAll) {
    $activeFilters[] = 'All data mode';
} else {
    if ($search !== '') $activeFilters[] = 'Search: ' . $search;
    if ($roleFilter !== '') $activeFilters[] = 'Role: ' . $roleFilter;
    if ($statusFilter !== '') $activeFilters[] = 'Status: ' . $statusFilter;
    if ($barangayFilter !== '') $activeFilters[] = 'Barangay: ' . $barangayFilter;
}
$activeFilters[] = 'Sort: ' . $sort;
$filterText = implode(' | ', $activeFilters);

$allRows = [];
$allRes = $conn->query($queryBase . " ORDER BY {$orderBy}");
if ($allRes) {
    while ($row = $allRes->fetch_assoc()) {
        $allRows[] = [
            'user_id' => trim((string)($row['user_id'] ?? '')),
            'name' => trim((string)($row['display_name'] ?? '')),
            'email' => trim((string)($row['display_email'] ?? '')),
            'role' => trim((string)($row['display_role'] ?? '')),
            'barangay' => trim((string)($row['display_barangay'] ?? '')),
            'age' => trim((string)($row['display_age'] ?? '')),
            'status' => trim((string)($row['display_status'] ?? '')),
            'created' => trim((string)($row['display_created'] ?? '')),
        ];
    }
}

if ($download) {
    require_once __DIR__ . '/../config/kp.php';
    require_once __DIR__ . '/../vendor/autoload.php';

    // Same Barangay Muñoz letterhead, footer and signature layout as the Case Report (barangay/generate_report.php).
    $nowManila = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $generatedAt = $nowManila->format('F d, Y \a\t h:i A');
    $filename = 'Barangay_Munoz_User_Report_' . $nowManila->format('F_j_Y') . '.pdf';

    $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $upper = static fn(string $v): string => mb_strtoupper($v, 'UTF-8');
    $orDash = static fn(string $v): string => $v !== '' ? $v : '—';
    $roleLabels = ['admin' => 'Administrator', 'barangay' => 'Barangay Official', 'resident' => 'Resident'];
    $roleLabel = static fn(string $r): string => $roleLabels[strtolower($r)] ?? ucwords($r);
    $sortLabels = ['newest' => 'newest first', 'oldest' => 'oldest first', 'name_asc' => 'by name, A–Z', 'name_desc' => 'by name, Z–A'];

    $seal = static function (string $file): string {
        $path = __DIR__ . '/../images/' . $file;
        return is_file($path)
            ? '<img src="data:image/png;base64,' . base64_encode((string)file_get_contents($path)) . '" style="height:70px;">'
            : '';
    };

    $applied = [];
    if (!$includeAll) {
        if ($search !== '') $applied[] = 'Search: ' . $search;
        if ($roleFilter !== '') $applied[] = 'Role: ' . $roleLabel($roleFilter);
        if ($statusFilter !== '') $applied[] = 'Status: ' . ucfirst(strtolower($statusFilter));
    }
    $filterSummaryText = ($applied ? implode('; ', $applied) : 'None (all accounts)')
        . '. Sorted ' . ($sortLabels[$sort] ?? 'newest first') . '.';

    $counts = ['resident' => 0, 'barangay' => 0, 'admin' => 0, 'active' => 0, 'inactive' => 0];
    $htmlRows = '';
    $rowNum = 1;
    foreach ($allRows as $r) {
        $role = strtolower($r['role']);
        if (isset($counts[$role])) $counts[$role]++;
        $status = strtolower($r['status']);
        if (isset($counts[$status])) $counts[$status]++;

        $createdTs = $r['created'] !== '' ? strtotime($r['created']) : false;
        $htmlRows .= '<tr' . ($rowNum % 2 === 0 ? ' class="alt"' : '') . '>'
            . '<td class="c">' . $rowNum . '</td>'
            . '<td><b>' . $e($orDash($r['name'])) . '</b></td>'
            . '<td>' . $e($orDash($r['email'])) . '</td>'
            . '<td>' . $e($r['role'] !== '' ? $roleLabel($r['role']) : '—') . '</td>'
            . '<td>' . $e($orDash($r['barangay'])) . '</td>'
            . '<td class="c">' . $e($r['status'] !== '' ? ucfirst(strtolower($r['status'])) : '—') . '</td>'
            . '<td class="c nowrap">' . ($createdTs ? date('m/d/Y', $createdTs) : '—') . '</td>'
            . '</tr>';
        $rowNum++;
    }
    if ($rowNum === 1) {
        $htmlRows = '<tr><td colspan="8" class="c empty">No user accounts matched the selected filters.</td></tr>';
    }
    $userCount = $rowNum - 1;

    $adminName = GABAYBATAS_ADMIN_NAME;
    $captain = defined('KP_PUNONG_BARANGAY') ? KP_PUNONG_BARANGAY : '';

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Registry Report - Barangay ' . $e(KP_BARANGAY_NAME) . '</title>
<style>
    @page { margin: 0; }
    body { margin: 0; padding: 36px 46px 62px; font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #111; }

    .head { width: 470px; border-collapse: collapse; margin: 0 auto; }
    .head td { vertical-align: middle; padding: 0; }
    .head .seal { width: 92px; text-align: center; }
    .lgu { text-align: center; font-size: 10px; line-height: 1.4; }
    .lgu b { font-size: 12px; letter-spacing: .5px; }
    .lgu .office { font-size: 9px; font-weight: bold; letter-spacing: 1px; margin-top: 3px; color: #1a3a6b; }
    .rule { border-top: 2px solid #1a3a6b; border-bottom: 1px solid #c2560c; height: 2px; margin: 10px 0 12px; }

    h1 { text-align: center; font-size: 15px; letter-spacing: 1px; margin: 0; color: #0e2242; }
    .period { text-align: center; font-size: 9.5px; margin: 3px 0 12px; }

    .meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .meta td { border: 1px solid #9aa7b8; padding: 5px 7px; font-size: 8.5px; width: 25%; vertical-align: top; }
    .meta .lbl { display: block; font-size: 7px; font-weight: bold; letter-spacing: .8px; text-transform: uppercase; color: #5a6a7e; margin-bottom: 1px; }

    .section { font-size: 9.5px; font-weight: bold; letter-spacing: .8px; text-transform: uppercase; color: #0e2242;
               border-left: 4px solid #c2560c; padding-left: 7px; margin: 14px 0 6px; }

    .stats { width: 100%; border-collapse: collapse; }
    .stats td { border: 1px solid #9aa7b8; text-align: center; padding: 6px 4px; width: 16.66%; }
    .stats .num { font-size: 15px; font-weight: bold; color: #0e2242; }
    .stats .lbl { font-size: 7.5px; letter-spacing: .6px; text-transform: uppercase; color: #5a6a7e; }

    table.users { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.users th { background: #1a3a6b; color: #fff; font-size: 7.5px; letter-spacing: .5px; text-transform: uppercase;
                     border: 1px solid #1a3a6b; padding: 5px 4px; }
    table.users td { border: 1px solid #9aa7b8; padding: 4px 5px; font-size: 8px; vertical-align: top; word-wrap: break-word; }
    table.users tr.alt td { background: #f4f6fa; }
    .c { text-align: center; }
    .nowrap { white-space: nowrap; }
    .empty { padding: 18px; font-style: italic; color: #5a6a7e; }

    .cert { margin-top: 14px; font-size: 8.5px; line-height: 1.5; text-align: justify; }
    .sign { width: 100%; border-collapse: collapse; margin-top: 26px; page-break-inside: avoid; }
    .sign td { width: 50%; vertical-align: top; font-size: 8.5px; padding: 0; }
    .sign .sigblock { width: 230px; margin: 0 auto; }
    .sign .sig-gap { height: 36px; }
    .sign .line { margin-top: 34px; width: 230px; border-top: 1px solid #111; text-align: center; padding-top: 3px; }

    .footer { position: fixed; left: 46px; right: 46px; bottom: 24px; border-top: 1px solid #9aa7b8; padding-top: 4px;
              font-size: 7.5px; color: #5a6a7e; }
</style>
</head>
<body>

<div class="footer">
    <table style="width:100%;border-collapse:collapse;"><tr>
        <td>Barangay ' . $e(KP_BARANGAY_NAME) . ', City of ' . $e(KP_MUNICIPALITY) . ', ' . $e(KP_PROVINCE) . ' &bull; Generated ' . $e($generatedAt) . ' through GabayBatas</td>
    </tr></table>
</div>

<table class="head"><tr>
    <td class="seal">' . $seal('seal_city_catbalogan.png') . '</td>
    <td class="lgu">
        Republic of the Philippines<br>
        PROVINCE OF ' . $e($upper(KP_PROVINCE)) . '<br>
        CITY OF ' . $e($upper(KP_MUNICIPALITY)) . '<br>
        <b>BARANGAY ' . $e($upper(KP_BARANGAY_NAME)) . '</b>
        <div class="office">OFFICE OF THE PUNONG BARANGAY</div>
    </td>
    <td class="seal">' . $seal('seal_barangay_munoz.png') . '</td>
</tr></table>
<div class="rule"></div>

<h1>USER REGISTRY REPORT</h1>
<div class="period">Registered GabayBatas accounts as of <u>&nbsp;' . $e($nowManila->format('F d, Y')) . '&nbsp;</u></div>

<table class="meta"><tr>
    <td><span class="lbl">Prepared for</span>Barangay ' . $e(KP_BARANGAY_NAME) . '</td>
    <td><span class="lbl">Date generated</span>' . $e($generatedAt) . '</td>
    <td><span class="lbl">Records listed</span>' . $userCount . ' ' . ($userCount === 1 ? 'account' : 'accounts') . '</td>
    <td><span class="lbl">Filters applied</span>' . $e($filterSummaryText) . '</td>
</tr></table>

<div class="section">Summary of Accounts</div>
<table class="stats"><tr>
    <td><div class="num">' . $userCount . '</div><div class="lbl">Total accounts</div></td>
    <td><div class="num">' . $counts['resident'] . '</div><div class="lbl">Residents</div></td>
    <td><div class="num">' . $counts['barangay'] . '</div><div class="lbl">Barangay officials</div></td>
    <td><div class="num">' . $counts['admin'] . '</div><div class="lbl">Administrators</div></td>
    <td><div class="num">' . $counts['active'] . '</div><div class="lbl">Active</div></td>
    <td><div class="num">' . $counts['inactive'] . '</div><div class="lbl">Inactive</div></td>
</tr></table>

<div class="section">List of Registered Accounts</div>
<table class="users">
    <thead><tr>
        <th style="width:5%;">No.</th>
        <th style="width:21%;">Name</th>
        <th style="width:26%;">Email</th>
        <th style="width:13%;">Role</th>
        <th style="width:16%;">Barangay</th>
        <th style="width:8%;">Status</th>
        <th style="width:11%;">Date Registered</th>
    </tr></thead>
    <tbody>' . $htmlRows . '</tbody>
</table>

<div class="cert">
    I hereby certify that the foregoing is a true and correct list of the user accounts registered in the GabayBatas
    system for Barangay ' . $e(KP_BARANGAY_NAME) . ', as of the date of generation. Archived accounts are not included.
</div>

<table class="sign"><tr>
    <td><div class="sigblock">Prepared by:<div class="sig-gap"></div><div class="line"><b>' . ($adminName !== '' ? $e($upper($adminName)) : '&nbsp;') . '</b><br>System Administrator</div></div></td>
    <td><div class="sigblock">Noted by:<div class="sig-gap"></div><div class="line"><b>' . $e($captain) . '</b><br>Punong Barangay</div></div></td>
</tr></table>

</body>
</html>';

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isPhpEnabled', false);

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    // Long bond (8.5in × 13in) portrait; margins are set on the body.
    $dompdf->setPaper([0, 0, 612, 936], 'portrait');
    $dompdf->render();
    // "Page X of Y" is stamped after rendering; CSS cannot count the total pages.
    $canvas = $dompdf->getCanvas();
    $canvas->page_text(
        $canvas->get_width() - 46 - 62, $canvas->get_height() - 22,
        'Page {PAGE_NUM} of {PAGE_COUNT}',
        $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.35, 0.42, 0.49]
    );
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

$previewRows = array_slice($allRows, 0, 50);

include('../includes/header_admin.php');
include('../includes/sidebar_admin.php');
?>

<div class="main">
<div class="topbar">
    <div class="topbar-left"><div class="topbar-title">User Reports</div></div>
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
        <p class="ur-sub">Build a custom dataset and download it as a formal Barangay Muñoz report (long bond).</p>
        <?php // Opened from the Reports page: Back returns there (kept through Apply and Reset).
        $fromReports = ($_GET['from'] ?? '') === 'reports'; ?>
        <a href="<?= $fromReports ? 'reports.php' : 'manage_users.php' ?>" class="ur-back"><i class="uil uil-arrow-left"></i> Back to <?= $fromReports ? 'Reports' : 'Manage Users' ?></a>
    </div>

    <div class="ur-card">
        <form method="GET" class="ur-form">
            <?php if ($fromReports): ?><input type="hidden" name="from" value="reports"><?php endif; ?>
            <div class="ur-grid">
                <div class="ur-field">
                    <label>Search</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name or email">
                </div>
                <div class="ur-field">
                    <label>Role</label>
                    <select name="role">
                        <option value="">All Roles</option>
                        <?php foreach ($roleOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= strtolower($roleFilter) === strtolower($opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ur-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= strtolower($statusFilter) === strtolower($opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ur-field">
                    <label>Sort</label>
                    <select name="sort">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                    </select>
                </div>
                <div class="ur-field ur-check">
                    <label class="ur-check-label"><input type="checkbox" name="include_all" value="1" <?= $includeAll ? 'checked' : '' ?>> Ignore filters and include all users</label>
                </div>
            </div>
            <div class="ur-actions">
                <button type="submit" class="ur-btn apply"><i class="uil uil-filter"></i> Apply</button>
                <a href="users_report.php<?= $fromReports ? '?from=reports' : '' ?>" class="ur-btn reset"><i class="uil uil-redo"></i> Reset</a>
                <a href="users_report.php?<?= htmlspecialchars(http_build_query([
                    'search' => $search,
                    'role' => $roleFilter,
                    'status' => $statusFilter,
                    'barangay' => $barangayFilter,
                    'sort' => $sort,
                    'include_all' => $includeAll ? '1' : '0',
                    'download' => 'pdf',
                ])) ?>" class="ur-btn download"><i class="uil uil-file-download"></i> Download PDF </a>
            </div>
        </form>
    </div>

    <div class="ur-card">
        <div class="ur-meta"><strong>Applied Filters:</strong> <?= htmlspecialchars($filterText) ?></div>
        <div class="ur-meta"><strong>Total Records in Download:</strong> <?= (int)count($allRows) ?></div>

        <div class="ur-table-wrap">
            <table class="ur-table">
                <thead>
                    <tr><th>User ID</th><th>Name</th><th>Email</th><th>Role</th><th>Barangay</th><th>Age</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if ($previewRows): ?>
                        <?php foreach ($previewRows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['user_id'] !== '' ? $r['user_id'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['name'] !== '' ? $r['name'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['email'] !== '' ? $r['email'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['role'] !== '' ? $r['role'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['barangay'] !== '' ? $r['barangay'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['age'] !== '' ? $r['age'] : 'N/A') ?></td>
                            <td><?= htmlspecialchars($r['status'] !== '' ? $r['status'] : 'N/A') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="7" class="ur-empty">No records matched the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($allRows) > 50): ?>
        <div class="ur-note">Preview shows first 50 rows only. Download includes all <?= (int)count($allRows) ?> rows.</div>
        <?php endif; ?>
    </div>
</div>
</div>

<style>
.ur-body{padding:24px 28px 40px;}
.ur-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;}
.ur-sub{font-size:12.5px;color:var(--text-sub);margin:0;}
.ur-back{display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:12.5px;font-weight:600;color:#1d4ed8;background:#eff6ff;border:1px solid #dbeafe;border-radius:9px;padding:8px 13px;}
.ur-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:12px;}
.ur-form{display:flex;flex-direction:column;gap:12px;}
.ur-grid{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:10px;}
.ur-field{display:flex;flex-direction:column;gap:6px;}
.ur-field label{font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#6b7280;}
.ur-field input,.ur-field select{width:100%;height:36px;border:1px solid #d1d5db;border-radius:8px;padding:0 10px;font-size:12.5px;color:#111827;background:#fff;}
.ur-check{justify-content:flex-end;}
.ur-check-label{display:flex;align-items:center;gap:7px;font-size:12.5px;color:#111827;text-transform:none!important;letter-spacing:0!important;font-weight:600!important;}
.ur-check-label input[type="checkbox"]{width:13px;height:13px;accent-color:#1d4ed8;}
.ur-actions{display:flex;flex-wrap:wrap;gap:8px;}
.ur-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 12px;border-radius:8px;text-decoration:none;font-size:12.5px;font-weight:600;border:1px solid #d1d5db;background:#fff;color:#111827;}
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
