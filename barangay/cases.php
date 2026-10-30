<?php
session_start();

$active_page = 'cases';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/kp.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/flexible_report.php';
gabaybatas_block_barangay_delete_requests();

$currentBarangay = gabaybatas_get_user_barangay($conn);
$hasCaseBarangay = gabaybatas_table_has_column($conn, 'cases', 'barangay');
$caseIdDisplayColumn = gabaybatas_table_has_column($conn, 'cases', 'case_id') ? 'case_id' : 'id';

$search_raw = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_raw = isset($_GET['status']) ? trim($_GET['status']) : '';

$search = $conn->real_escape_string($search_raw);
$searchCaseIdRaw = preg_replace('/^\s*C\s*-\s*/i', '', $search_raw);
$searchCaseId = ctype_digit((string)$searchCaseIdRaw) ? $conn->real_escape_string($searchCaseIdRaw) : '';
$hasKpStatus = gabaybatas_table_has_column($conn, 'cases', 'kp_status');
$hasArchivedAt = gabaybatas_table_has_column($conn, 'cases', 'archived_at');
// Status groups shared with Home, Flexible Reports and the admin pages (includes/flexible_report.php).
$groups = gabaybatas_flex_groups();
$isGroupFilter = str_starts_with($filter_raw, 'group:') && isset($groups[substr($filter_raw, 6)]);
if (!$isGroupFilter && !array_key_exists($filter_raw, kp_statuses())) $filter_raw = '';
$filter = $filter_raw;
$statusesByStage = [];
foreach (kp_statuses() as $key => $info) {
    $statusesByStage[$info['stage']][$key] = $info['label'];
}
$stages = kp_stages();
$showUpdatedToast = isset($_GET['updated']) && $_GET['updated'] === '1';
// Case number of a case just added on add_case.php (e.g. C-0056).
$createdCaseCode = preg_match('/^C-\d{4,}$/', (string)($_GET['created'] ?? '')) ? (string)$_GET['created'] : '';

$query = "SELECT id, {$caseIdDisplayColumn} AS display_case_id, name, complained_person, case_type, status" . ($hasKpStatus ? ", kp_status" : "") . ($hasCaseBarangay ? ", barangay" : "") . ", date_submitted FROM cases WHERE 1";
if ($hasArchivedAt) {
    $query .= " AND archived_at IS NULL";
}
if ($hasCaseBarangay && $currentBarangay !== '') {
    $query .= " AND LOWER(barangay) = LOWER('" . $conn->real_escape_string($currentBarangay) . "')";
}
if ($search_raw !== '') {
    $query .= " AND (name LIKE '%{$search}%' OR case_type LIKE '%{$search}%' OR CAST(id AS CHAR) LIKE '%{$search}%'";
    if ($searchCaseId !== '') {
        $query .= " OR CAST(id AS CHAR) = '{$searchCaseId}'";
    }
    $query .= ")";
}
if ($filter !== '' && $hasKpStatus) {
    $kpStatusSql = "COALESCE(NULLIF(kp_status, ''), 'filed')";
    $keys = $isGroupFilter ? $groups[substr($filter, 6)]['statuses'] : [$filter];
    $query .= " AND {$kpStatusSql} IN ('" . implode("','", array_map([$conn, 'real_escape_string'], $keys)) . "')";
}
// One page at a time (includes/pagination.php).
$totalFiltered = (int)($conn->query("SELECT COUNT(*) AS t FROM ($query) q")->fetch_assoc()['t'] ?? 0);
$perPage = gabaybatas_per_page(10);
$pageNo = min(max(1, (int)($_GET['page'] ?? 1)), max(1, (int)ceil($totalFiltered / $perPage)));
$offset = ($pageNo - 1) * $perPage;
$query .= " ORDER BY date_submitted DESC LIMIT {$offset}, {$perPage}";

$result = $conn->query($query);
?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-title">Cases</div>
        <div class="topbar-right">
            <div class="icon-btn" title="Notifications"><i class="uil uil-bell"></i></div>
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
                <div class="page-header-title">Cases Management</div>
                <div class="page-header-sub">Search and manage all recorded cases in your barangay</div>
            </div>
            <a href="add_case.php" class="btn-add-case"><i class="uil uil-plus"></i> Add New Case</a>
        </div>

        <div class="card-box filter-card">
            <form method="GET" class="filter-form">
                <div class="filter-group filter-group-sm">
                    <label class="filter-field-label">Status</label>
                    <div class="input-icon-wrap">
                        <i class="input-icon uil uil-clock"></i>
                        <select name="status" class="filter-input filter-select">
                            <option value="">All Statuses</option>
                            <optgroup label="Status groups">
                                <?php foreach ($groups as $gKey => $g): ?>
                                <option value="group:<?= $gKey ?>" <?= $filter_raw === 'group:' . $gKey ? 'selected' : '' ?>><?= htmlspecialchars($g['label']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php foreach ($statusesByStage as $stage => $statusList): ?>
                            <optgroup label="<?= htmlspecialchars($stages[$stage]['label'] ?? ucfirst($stage)) ?>">
                                <?php foreach ($statusList as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= $filter_raw === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="filter-group">
                    <label class="filter-field-label">Search</label>
                    <div class="input-icon-wrap">
                        <i class="input-icon uil uil-search"></i>
                        <input type="text" name="search" class="filter-input" placeholder="Search by resident name or case type..." value="<?= htmlspecialchars($search_raw) ?>">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter"><i class="uil uil-search"></i> Search</button>
                    <?php if ($search_raw !== '' || $filter_raw !== ''): ?>
                    <a href="cases.php" class="btn-reset"><i class="uil uil-times"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card-box">
            <div class="table-card-header">
                <div>
                    <div class="section-heading">All Cases</div>
                    <div class="section-heading-sub"><?= ($search_raw !== '' || $filter_raw !== '') ? 'Filtered results' : 'All recorded cases in your barangay' ?></div>
                </div>
                <div class="record-count"><i class="uil uil-folder-open"></i> <?= $totalFiltered ?> record<?= $totalFiltered === 1 ? '' : 's' ?></div>
            </div>

            <div class="table-wrap">
                <table class="table-dashboard">
                    <thead>
                        <tr>
                            <th>Case ID</th>
                            <th>Resident Name</th>
                            <th>Complained Person</th>
                            <th>Case Type</th>
                            <th>Status</th>
                            <th>Date & Time Filed</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()):
                                $status = trim((string)($row['status'] ?? 'Pending'));
                                $rawKpStatus = trim((string)($row['kp_status'] ?? ''));
                                $statusLabel = ($rawKpStatus !== '' && function_exists('kp_status_label'))
                                    ? kp_status_label($rawKpStatus)
                                    : $status;
                                $statusColor = ($rawKpStatus !== '' && function_exists('kp_status_color'))
                                    ? kp_status_color($rawKpStatus)
                                    : ($status === 'Resolved' ? '#16a34a' : ($status === 'In Progress' ? '#2563eb' : '#f59e0b'));
                                $rowBarangay = trim((string)($row['barangay'] ?? ''));
                                $canEditCase = !$hasCaseBarangay || $currentBarangay === '' || $rowBarangay === '' || strcasecmp($rowBarangay, $currentBarangay) === 0;
                                $dateRaw = (string)($row['date_submitted'] ?? '');
                                $dateTs = strtotime($dateRaw);
                                $dateDisplay = $dateTs !== false ? date('M d, Y h:i A', $dateTs) : ($dateRaw !== '' ? $dateRaw : 'N/A');
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars(gabaybatas_format_case_id($row['display_case_id'] ?? '', $row['id'] ?? '')) ?></strong></td>
                                <td><?= htmlspecialchars((string)($row['name'] ?? 'N/A')) ?></td>
                                <td><?= htmlspecialchars((string)($row['complained_person'] ?? 'N/A')) ?></td>
                                <td><?= htmlspecialchars((string)($row['case_type'] ?? 'N/A')) ?></td>
                                <td><span class="badge-status kp-styled" style="background:<?= $statusColor ?>18; color:<?= $statusColor ?>; border:1px solid <?= $statusColor ?>35;"><?= htmlspecialchars($statusLabel) ?></span></td>
                                <td><?= htmlspecialchars($dateDisplay) ?></td>
                                <td>
                                    <div class="action-group">
                                        <a href="view_case.php?id=<?= (int)($row['id'] ?? 0) ?>" class="table-action"><i class="uil uil-eye"></i> View</a>
                                        <?php if ($canEditCase): ?>
                                        <a href="update_case.php?id=<?= (int)($row['id'] ?? 0) ?>" class="table-action"><i class="uil uil-edit"></i> Edit</a>
                                        <?php endif; ?>
                                        <a href="case_process.php?id=<?= (int)($row['id'] ?? 0) ?>" class="table-action table-action-kp"><i class="uil uil-balance-scale"></i> Process</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <div class="empty-title">No cases found</div>
                                    <div class="empty-sub"><?= ($search_raw !== '' || $filter_raw !== '') ? 'Try adjusting your search or filter criteria.' : 'No cases have been recorded yet.' ?></div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php gabaybatas_pagination($totalFiltered, $pageNo, $perPage, ['search' => $search_raw, 'status' => $filter_raw], 'cases'); ?>
        </div>
    </div>
</div>

<style>
.page-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:20px; gap:12px; }
.page-header-title { font-family:'Source Serif 4',serif; font-size:20px; font-weight:700; color:var(--text-main); }
.page-header-sub { font-size:13px; color:var(--text-light); margin-top:3px; }
.btn-add-case { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; background:var(--navy); color:#fff; text-decoration:none; border-radius:7px; border:1px solid var(--navy-dark); font-size:13px; font-weight:600; white-space:nowrap; }
.btn-add-case:hover { background:var(--navy-dark); }
.filter-card { margin-bottom:18px; }
.filter-form { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; }
.filter-group { flex:1; min-width:200px; }
.filter-group-sm { flex:0 0 230px; }
.filter-field-label { display:block; font-size:11.5px; font-weight:600; color:var(--text-muted); margin-bottom:6px; }
.input-icon-wrap { position:relative; }
.input-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--text-light); pointer-events:none; font-size:15px; }
.filter-input { width:100%; padding:11px 12px 11px 34px; border:1px solid var(--border); border-radius:8px; background:#fff; font-size:13px; color:var(--text-main); outline:none; }
.filter-input:focus { border-color:var(--navy); box-shadow:0 0 0 3px rgba(26,58,107,.08); }
.filter-actions { display:flex; gap:8px; margin-left:auto; }
.btn-filter, .btn-reset { display:inline-flex; align-items:center; gap:6px; padding:10px 13px; border-radius:8px; font-size:12.5px; font-weight:600; text-decoration:none; border:1px solid var(--border); }
.btn-filter { color:#fff; background:var(--navy); border-color:var(--navy); cursor:pointer; }
.btn-reset { color:var(--text-main); background:#fff; }
.table-card-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:14px; gap:12px; }
.section-heading { font-family:'Source Serif 4',serif; font-size:16px; font-weight:600; color:var(--text-main); margin-bottom:4px; }
.section-heading-sub { font-size:12.5px; color:var(--text-light); }
.record-count { display:inline-flex; align-items:center; gap:6px; color:var(--text-muted); font-size:12.5px; font-weight:600; white-space:nowrap; }
.table-wrap { overflow-x:auto; margin-top:14px; }
.table-dashboard { width:100%; border-collapse:collapse; }
.table-dashboard thead th { background:#f4f7fb; color:var(--text-muted); font-size:11.5px; font-weight:600; letter-spacing:.8px; text-transform:uppercase; padding:11px 16px; border-bottom:1px solid var(--border); white-space:nowrap; text-align:center; }
.table-dashboard tbody td { padding:13px 16px; border-bottom:1px solid #edf1f7; color:var(--text-main); font-size:13.5px; vertical-align:middle; text-align:center; }
.table-dashboard tbody tr:last-child td { border-bottom:none; }
.table-dashboard tbody tr:hover td { background:#f9fbff; }
.badge-status { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:4px; font-size:11.5px; font-weight:600; letter-spacing:.2px; }
.badge-status::before { content:''; width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.badge-status.kp-styled::before { background: currentColor !important; }
.pending { background:var(--orange-bg); color:var(--orange); }
.pending::before { background:var(--orange); }
.progress { background:var(--blue-soft); color:var(--blue-text); }
.progress::before { background:var(--navy); }
.resolved { background:var(--green-bg); color:var(--green); }
.resolved::before { background:var(--green); }
.action-group { display:inline-flex; align-items:center; gap:8px; justify-content:center; white-space:nowrap; }
.table-action { display:inline-flex; align-items:center; gap:5px; color:var(--navy); background:#ffffff; text-decoration:none; font-size:12.5px; font-weight:600; padding:6px 12px; border:1px solid var(--border); border-radius:7px; transition:all .15s ease; white-space:nowrap; }
.table-action:hover { background:var(--navy-light); border-color:var(--navy); color:var(--navy); }
.table-action-kp {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: #ffffff !important;
    border: 1px solid #1e3a8a;
    border-radius: 7px;
    padding: 6px 13px;
    font-size: 12.5px;
    font-weight: 700;
    letter-spacing: .25px;
    text-decoration: none;
    box-shadow: 0 2px 5px rgba(37, 99, 235, 0.25), 0 1px 2px rgba(0, 0, 0, 0.05);
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.table-action-kp i {
    font-size: 15px;
    transition: transform 0.2s ease;
}
.table-action-kp:hover {
    background: linear-gradient(135deg, #172554 0%, #1d4ed8 100%);
    border-color: #172554;
    color: #ffffff !important;
    transform: translateY(-1.5px);
    box-shadow: 0 5px 12px rgba(37, 99, 235, 0.38), 0 2px 4px rgba(0, 0, 0, 0.08);
}
.table-action-kp:hover i {
    transform: rotate(-8deg) scale(1.12);
}
.table-action-kp:active {
    transform: translateY(0);
    box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
}
.table-action i { font-size:14px; }
.card-box .gb-pg { margin:14px -24px -22px; border-radius:0 0 8px 8px; }
.empty-state { padding:24px 12px; text-align:center; }
.empty-title { font-size:14px; font-weight:700; color:var(--text-main); margin-bottom:6px; }
.empty-sub { font-size:12.5px; color:var(--text-light); }
@media (max-width:768px) { .page-header, .table-card-header { flex-direction:column; } .filter-actions { margin-left:0; } }
</style>

<?php if ($showUpdatedToast): ?>
<script>
if (window.AdminToast) {
    window.AdminToast.show('success', 'Case Updated', 'Case updated successfully.');
}
</script>
<?php endif; ?>

<?php if ($createdCaseCode !== ''): ?>
<script>
if (window.AdminToast) {
    window.AdminToast.show(
        'success',
        'Case Added',
        <?= json_encode('Case ' . $createdCaseCode . ' was saved and is now Under Review.', JSON_HEX_TAG) ?>,
        5000
    );
}
</script>
<?php endif; ?>

<?php if ($showUpdatedToast || isset($_GET['created'])): ?>
<script>
// Drop the one-time flag from the address bar so a refresh does not repeat the message.
(function () {
    const url = new URL(window.location.href);
    url.searchParams.delete('created');
    url.searchParams.delete('updated');
    window.history.replaceState(null, '', url.pathname + url.search + url.hash);
})();
</script>
<?php endif; ?>
