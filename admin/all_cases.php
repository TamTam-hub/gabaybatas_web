<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header('Location: ../login.php'); exit(); }
require_once '../config/database.php';
require_once '../config/kp.php';
require_once '../config/barangays.php';
require_once '../config/archive.php';
require_once '../includes/flexible_report.php';
require_once '../includes/barangay_auth.php';
require_once '../includes/pagination.php';
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

// Every case type from config/case_types.php, plus any older type still on a case.
$typeOptions = gabaybatas_flex_case_types($conn, '');

// Status groups shared with the Home dashboard and Flexible Reports (includes/flexible_report.php).
$groups = gabaybatas_flex_groups();
$kpStatusSql = "COALESCE(NULLIF(kp_status, ''), 'filed')";

// Counts for active records only; archived cases remain available in Records Archive.
$activeCaseWhere = 'archived_at IS NULL';
$totalCases  = (int)$conn->query("SELECT COUNT(*) as t FROM cases WHERE $activeCaseWhere")->fetch_assoc()['t'];
$groupCounts = gabaybatas_case_group_counts($conn, '')['counts'];

$search       = trim($_GET['search'] ?? '');
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterType   = trim((string)($_GET['type'] ?? ''));
$sort         = trim((string)($_GET['sort'] ?? 'newest'));
$allowedSorts = ['newest', 'oldest', 'resident_asc', 'resident_desc', 'status_asc'];
$isGroupFilter = str_starts_with($filterStatus, 'group:') && isset($groups[substr($filterStatus, 6)]);
if (!$isGroupFilter && !array_key_exists($filterStatus, kp_statuses())) $filterStatus = '';
if (!in_array($sort, $allowedSorts, true)) $sort = 'newest';
$pageNo       = max(1, (int)($_GET['page'] ?? 1));
$perPage      = gabaybatas_per_page(10);

$quoteList = static fn(array $keys): string => implode(',', array_map(static fn($k) => "'" . $conn->real_escape_string($k) . "'", $keys));

$whereParts = [];
$whereParts[] = 'archived_at IS NULL';
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $fields = [$residentColumn, $idColumn];
    if ($complainedColumn) $fields[] = $complainedColumn;
    if ($typeColumn)       $fields[] = $typeColumn;
    $whereParts[] = '(' . implode(" OR ", array_map(fn($f) => "$f LIKE '%$s%'", $fields)) . ')';
}
if ($isGroupFilter) {
    $whereParts[] = "$kpStatusSql IN (" . $quoteList($groups[substr($filterStatus, 6)]['statuses']) . ")";
} elseif ($filterStatus !== '') {
    $whereParts[] = "$kpStatusSql = '" . $conn->real_escape_string($filterStatus) . "'";
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
} elseif ($sort === 'status_asc') {
    // KP order (intake → mediation → … → disposition), as listed in config/kp.php.
    $orderBy = "FIELD($kpStatusSql, " . $quoteList(array_keys(kp_statuses())) . "), $dateColumn DESC";
}

$selectBase = "SELECT
    {$rowIdColumn} AS row_id, {$idColumn} AS display_case_id,
    {$residentColumn} AS display_resident_name,
    " . ($complainedColumn ? "{$complainedColumn} AS display_complained_person" : "'' AS display_complained_person") . ",
    " . ($typeColumn     ? "{$typeColumn} AS case_type"       : "'' AS case_type") . ",
    {$dateColumn} AS display_date,
    {$kpStatusSql} AS kp_status
FROM cases {$where}";

$stages = kp_stages();
$statusesByStage = [];
foreach (kp_statuses() as $key => $info) {
    $statusesByStage[$info['stage']][$key] = $info['label'];
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
    <div class="topbar-left"><div class="topbar-title">Manage Cases</div></div>
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
        <a href="all_cases.php?<?= htmlspecialchars($buildQuery($queryBase, ['status' => '', 'page' => 1])) ?>" class="ac-tab <?= $filterStatus===''?'active':'' ?>">All <span class="ac-tab-count"><?= $totalCases ?></span></a>
        <?php foreach ($groups as $gKey => $g): ?>
        <a href="all_cases.php?<?= htmlspecialchars($buildQuery($queryBase, ['status' => 'group:' . $gKey, 'page' => 1])) ?>" class="ac-tab <?= $filterStatus==='group:' . $gKey?'active':'' ?>" style="--tab-color:<?= $g['color'] ?>"><span class="ac-tab-dot"></span><?= htmlspecialchars($g['label']) ?> <span class="ac-tab-count"><?= (int)($groupCounts[$gKey] ?? 0) ?></span></a>
        <?php endforeach; ?>
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

                <select name="status" class="ac-select ac-select-status" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <optgroup label="Status groups">
                        <?php foreach ($groups as $gKey => $g): ?>
                        <option value="group:<?= $gKey ?>" <?= $filterStatus === 'group:' . $gKey ? 'selected' : '' ?>><?= htmlspecialchars($g['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php foreach ($statusesByStage as $stage => $statusList): ?>
                    <optgroup label="<?= htmlspecialchars($stages[$stage]['label'] ?? ucfirst($stage)) ?>">
                        <?php foreach ($statusList as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
                <select name="sort" class="ac-select" onchange="this.form.submit()">
                    <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest</option>
                    <option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>Oldest</option>
                    <option value="resident_asc" <?= $sort==='resident_asc'?'selected':'' ?>>Resident A-Z</option>
                    <option value="resident_desc" <?= $sort==='resident_desc'?'selected':'' ?>>Resident Z-A</option>
                    <option value="status_asc" <?= $sort==='status_asc'?'selected':'' ?>>Status (KP order)</option>
                </select>

                <a href="cases_report.php?<?= htmlspecialchars($buildQuery(['status' => $filterStatus, 'case_type' => $filterType, 'search' => $search])) ?>" class="ac-btn-ghost" title="Open the Flexible Report with these filters"><i class="uil uil-file-download"></i> Case Report</a>
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
                    <tr><th>Case ID</th><th>Resident</th><th>Complained Person</th><th>Type</th><th>Status</th><th>Date Filed</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php if ($cases && $cases->num_rows > 0): ?>
                <?php while ($row = $cases->fetch_assoc()):
                    $caseIdDisplay = gabaybatas_format_case_id($row['display_case_id'] ?? '', $row['row_id'] ?? '');
                    $resName = (string)($row['display_resident_name'] ?? '-');
                    $initial = strtoupper(substr($resName,0,1)) ?: '?';
                    $dateRaw = (string)($row['display_date'] ?? '');
                    $dateTs = strtotime($dateRaw);
                    $kpStKey = (string)$row['kp_status'];
                    $badgeColor = kp_status_color($kpStKey);
                ?>
                <tr>
                    <td><span class="ac-case-id"><?= htmlspecialchars($caseIdDisplay) ?></span></td>
                    <td>
                        <div class="ac-name-cell">
                            <div class="ac-avatar"><?= $initial ?></div>
                            <?= htmlspecialchars($resName) ?>
                        </div>
                    </td>
                    <td class="ac-muted"><?= htmlspecialchars((string)($row['display_complained_person'] ?: '—')) ?></td>
                    <td class="ac-muted"><?= htmlspecialchars((string)($row['case_type'] ?: '—')) ?></td>
                    <td>
                        <span class="ac-badge" style="background:<?= htmlspecialchars($badgeColor) ?>18;color:<?= htmlspecialchars($badgeColor) ?>;border-color:<?= htmlspecialchars($badgeColor) ?>44;">
                            <span class="ac-bdot"></span><?= htmlspecialchars(kp_status_label($kpStKey)) ?>
                        </span>
                    </td>
                    <td class="ac-date">
                        <?php if ($dateTs !== false): ?><strong><?= date('M d, Y', $dateTs) ?></strong><small><?= date('h:i A', $dateTs) ?></small><?php else: ?>—<?php endif; ?>
                    </td>
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
                <tr><td colspan="7" class="ac-empty">
                    <i class="uil uil-folder-open" style="font-size:28px;display:block;margin-bottom:8px;color:#cbd5e1"></i>
                    No cases found.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php gabaybatas_pagination($totalFiltered, $pageNo, $perPage, $queryBase, 'cases'); ?>
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
.ac-tab-dot{width:8px;height:8px;border-radius:50%;background:var(--tab-color,#94a3b8);flex-shrink:0;}
.ac-tab.active .ac-tab-dot{background:#fff;}
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
.ac-select{height:38px;min-width:120px;max-width:220px;padding:0 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;font-size:13px;color:#334155;outline:none;transition:border .2s;cursor:pointer;white-space:nowrap;flex-shrink:0;}
.ac-select:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}
.ac-btn-ghost{text-decoration:none;white-space:nowrap;flex-shrink:0;display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;color:var(--text-sub);cursor:pointer;transition:all .2s;}
.ac-btn-ghost:hover{border-color:#3b82f6;color:#3b82f6;background:#f0f7ff;}

/* Table */
.ac-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
.ac-table{width:100%;border-collapse:collapse;font-size:13px;min-width:980px;}
.ac-table th{padding:14px 16px;text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-sub);font-weight:700;background:#fafbff;border-bottom:2px solid #e5e7eb;white-space:nowrap;}
.ac-table td{padding:14px 16px;border-bottom:1px solid #f3f4f6;vertical-align:middle;text-align:left;}
.ac-table th:nth-child(1),.ac-table td:nth-child(1){width:96px;}
.ac-table th:nth-child(5),.ac-table td:nth-child(5){width:250px;}
.ac-table th:nth-child(6),.ac-table td:nth-child(6){width:120px;}
.ac-table th:last-child,.ac-table td:last-child{width:64px;text-align:center;}
.ac-table tbody tr{transition:background .15s ease;}
.ac-table tbody tr:last-child td{border-bottom:none;}
.ac-table tbody tr:hover td{background:#f8fafc;}
.ac-case-id{font-family:monospace;font-size:11.5px;white-space:nowrap;font-weight:600;background:#f1f5f9;color:#475569;padding:4px 10px;border-radius:6px;display:inline-block;}
.ac-name-cell{display:flex;align-items:center;gap:10px;}
.ac-avatar{width:32px;height:32px;border-radius:50%;background:#e0e7ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;}
.ac-muted{font-size:13px;color:var(--text-sub);}
.ac-date strong{display:block;font-size:12.5px;font-weight:600;color:#334155;white-space:nowrap;}.ac-date small{display:block;font-size:11.5px;color:#94a3b8;margin-top:2px;}
.ac-nil{color:#cbd5e1;font-weight:500;}

/* Badges: colour per KP stage (config/kp.php); long labels wrap inside the pill */
.ac-badge{display:inline-flex;align-items:center;gap:6px;max-width:100%;font-size:12px;font-weight:600;line-height:1.35;padding:4px 11px;border-radius:999px;border:1px solid transparent;}
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
  .ac-table th,.ac-table td{padding:12px 14px;}
  .ac-dropdown{right:-10px;}
}
@media(max-width:480px){
  .ac-body{padding:14px 12px 32px;}
  .ac-tabs{grid-template-columns:1fr;}
  .ac-tab{width:100%;}
}
</style>