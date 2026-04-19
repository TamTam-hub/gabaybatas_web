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

$search = trim((string)($_GET['search'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$barangayFilter = trim((string)($_GET['barangay'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'newest'));
$includeAll = (string)($_GET['include_all'] ?? '0') === '1';
$download = (string)($_GET['download'] ?? '') === 'pdf';

$conditions = [];
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

    if ($barangayFilter !== '' && $hasUserColumn('barangay')) {
        $bf = $conn->real_escape_string($barangayFilter);
        $conditions[] = "LOWER({$barangayColumn}) = LOWER('$bf')";
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
    {$barangayColumn} AS display_barangay, {$ageColumn} AS display_age, {$statusColumn} AS display_status
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
        ];
    }
}

if ($download) {
    require_once __DIR__ . '/../vendor/autoload.php';

    $esc = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $rowsHtml = '';
    if ($allRows) {
        foreach ($allRows as $r) {
            $rowsHtml .= '<tr>'
                . '<td>' . $esc($r['user_id'] !== '' ? $r['user_id'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['name'] !== '' ? $r['name'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['email'] !== '' ? $r['email'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['role'] !== '' ? $r['role'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['barangay'] !== '' ? $r['barangay'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['age'] !== '' ? $r['age'] : 'N/A') . '</td>'
                . '<td>' . $esc($r['status'] !== '' ? $r['status'] : 'N/A') . '</td>'
                . '</tr>';
        }
    } else {
        $rowsHtml = '<tr><td colspan="7" style="text-align:center;">No user records matched the selected filters.</td></tr>';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Official User Registry Report</title>'
        . '<style>'
        . '@page { size: 13in 8.5in; margin: 26pt; }'
        . 'body{font-family:Arial,Helvetica,sans-serif;font-size:10.5pt;color:#111;}'
        . '.title{font-size:18pt;font-weight:700;margin-bottom:2pt;}'
        . '.subtitle{font-size:10pt;margin-bottom:8pt;}'
        . '.meta{font-size:9pt;margin-bottom:10pt;}'
        . 'table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:9pt;}'
        . 'th,td{border:1px solid #222;padding:6pt 5pt;text-align:left;vertical-align:top;word-wrap:break-word;}'
        . 'th{background:#f2f2f2;font-weight:700;}'
        . '.footer{margin-top:8pt;font-size:9pt;font-weight:700;}'
        . '</style></head><body>'
        . '<div class="title">GabayBatas - Official User Registry Report</div>'
        . '<div class="subtitle">Administrative User Management and Access Summary</div>'
        . '<div class="meta">Generated: ' . $esc(date('F d, Y h:i A')) . ' | Prepared by: ' . $esc((string)($_SESSION['name'] ?? 'Admin')) . '</div>'
        . '<div class="meta">Filters: ' . $esc($filterText) . '</div>'
        . '<table><thead><tr>'
        . '<th style="width:8%;">User ID</th>'
        . '<th style="width:20%;">Name</th>'
        . '<th style="width:24%;">Email</th>'
        . '<th style="width:12%;">Role</th>'
        . '<th style="width:18%;">Barangay</th>'
        . '<th style="width:8%;">Age</th>'
        . '<th style="width:10%;">Status</th>'
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
    $dompdf->stream('GabayBatas_Official_User_Registry_Report_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
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
        <p class="ur-sub">Build a custom dataset and export a formal Long Bond Landscape report.</p>
        <a href="manage_users.php" class="ur-back"><i class="uil uil-arrow-left"></i> Back to Manage Users</a>
    </div>

    <div class="ur-card">
        <form method="GET" class="ur-form">
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
                    <label>Barangay</label>
                    <select name="barangay">
                        <option value="">All Barangays</option>
                        <?php foreach ($barangayOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= strtolower($barangayFilter) === strtolower($opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
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
                <a href="users_report.php" class="ur-btn reset"><i class="uil uil-redo"></i> Reset</a>
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
