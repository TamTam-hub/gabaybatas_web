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
$general_statuses = ['Pending', 'In Progress', 'Resolved'];
$kp_status_map = function_exists('kp_statuses') ? kp_statuses() : [];
$allowed_statuses = array_merge($general_statuses, array_keys($kp_status_map));
$filter = in_array($filter_raw, $allowed_statuses, true) ? $conn->real_escape_string($filter_raw) : '';
$showUpdatedToast = isset($_GET['updated']) && $_GET['updated'] === '1';

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
if ($filter !== '') {
    if (in_array($filter, $general_statuses, true)) {
        $query .= " AND status='{$filter}'";
    } else {
        $query .= " AND kp_status='{$filter}'";
    }
}
$query .= " ORDER BY date_submitted DESC";

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
                            <optgroup label="General Status">
                                <option value="Pending" <?= ($filter_raw === 'Pending') ? 'selected' : '' ?>>All Pending</option>
                                <option value="In Progress" <?= ($filter_raw === 'In Progress') ? 'selected' : '' ?>>All In Progress</option>
                                <option value="Resolved" <?= ($filter_raw === 'Resolved') ? 'selected' : '' ?>>All Resolved</option>
                            </optgroup>
                            <optgroup label="Intake & Review">
                                <option value="filed" <?= ($filter_raw === 'filed') ? 'selected' : '' ?>>Filed</option>
                                <option value="under_review" <?= ($filter_raw === 'under_review') ? 'selected' : '' ?>>Under Review</option>
                                <option value="additional_info_required" <?= ($filter_raw === 'additional_info_required') ? 'selected' : '' ?>>Additional Info Required</option>
                                <option value="accepted" <?= ($filter_raw === 'accepted') ? 'selected' : '' ?>>Accepted for KP</option>
                                <option value="not_eligible" <?= ($filter_raw === 'not_eligible') ? 'selected' : '' ?>>Not Eligible for KP</option>
                            </optgroup>
                            <optgroup label="Mediation & Conciliation">
                                <option value="mediation_scheduled" <?= ($filter_raw === 'mediation_scheduled') ? 'selected' : '' ?>>Mediation Scheduled</option>
                                <option value="mediation_ongoing" <?= ($filter_raw === 'mediation_ongoing') ? 'selected' : '' ?>>Mediation Ongoing</option>
                                <option value="mediation_settled" <?= ($filter_raw === 'mediation_settled') ? 'selected' : '' ?>>Settled in Mediation</option>
                                <option value="pangkat_constitution" <?= ($filter_raw === 'pangkat_constitution') ? 'selected' : '' ?>>Pangkat Constitution</option>
                                <option value="conciliation_scheduled" <?= ($filter_raw === 'conciliation_scheduled') ? 'selected' : '' ?>>Conciliation Scheduled</option>
                                <option value="conciliation_ongoing" <?= ($filter_raw === 'conciliation_ongoing') ? 'selected' : '' ?>>Conciliation Ongoing</option>
                                <option value="conciliation_settled" <?= ($filter_raw === 'conciliation_settled') ? 'selected' : '' ?>>Settled in Conciliation</option>
                                <option value="mediation_noshow_complainant" <?= ($filter_raw === 'mediation_noshow_complainant') ? 'selected' : '' ?>>Complainant Absent (Mediation)</option>
                                <option value="conciliation_noshow_complainant" <?= ($filter_raw === 'conciliation_noshow_complainant') ? 'selected' : '' ?>>Complainant Absent (Conciliation)</option>
                                <option value="conciliation_noshow_respondent" <?= ($filter_raw === 'conciliation_noshow_respondent') ? 'selected' : '' ?>>Respondent Absent (Conciliation)</option>
                            </optgroup>
                            <optgroup label="Settlement & Disposition">
                                <option value="settlement_repudiation" <?= ($filter_raw === 'settlement_repudiation') ? 'selected' : '' ?>>Repudiation Window</option>
                                <option value="settlement_final" <?= ($filter_raw === 'settlement_final') ? 'selected' : '' ?>>Settlement Final & Executory</option>
                                <option value="compliance_monitoring" <?= ($filter_raw === 'compliance_monitoring') ? 'selected' : '' ?>>Compliance Monitoring</option>
                                <option value="execution_pending" <?= ($filter_raw === 'execution_pending') ? 'selected' : '' ?>>Execution Pending</option>
                                <option value="execution_completed" <?= ($filter_raw === 'execution_completed') ? 'selected' : '' ?>>Execution Completed</option>
                                <option value="certified_to_court" <?= ($filter_raw === 'certified_to_court') ? 'selected' : '' ?>>Certified to Court</option>
                                <option value="dismissed" <?= ($filter_raw === 'dismissed') ? 'selected' : '' ?>>Dismissed</option>
                                <option value="closed" <?= ($filter_raw === 'closed') ? 'selected' : '' ?>>Closed</option>
                            </optgroup>
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
                <div class="record-count"><i class="uil uil-folder-open"></i> <?= $result ? $result->num_rows : 0 ?> records</div>
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
.filter-group-sm { flex:0 0 180px; }
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
