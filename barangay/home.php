<?php
session_start();

require_once '../config/database.php';
require_once '../config/kp.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/flexible_report.php';

gabaybatas_require_role(['barangay'], '../login.php');
gabaybatas_block_barangay_delete_requests();

$currentBarangay = gabaybatas_get_user_barangay($conn);
$hasCaseBarangay = gabaybatas_table_has_column($conn, 'cases', 'barangay');
$hasArchivedAt = gabaybatas_table_has_column($conn, 'cases', 'archived_at');
$hasKpStatus = gabaybatas_table_has_column($conn, 'cases', 'kp_status');

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
$descColumn = $hasColumn('description') ? 'description' : ($hasColumn('details') ? 'details' : ($hasColumn('remarks') ? 'remarks' : ($hasColumn('notes') ? 'notes' : null)));

$scopeSql = '';
if ($hasCaseBarangay && $currentBarangay !== '') {
    $scopeSql = " AND LOWER(barangay) = LOWER('" . $conn->real_escape_string($currentBarangay) . "')";
}

$totalCases = 0;
$pendingCases = 0;
$resolvedCases = 0;
$recentCases = false;
$homeError = '';

if ($idColumn === null || $displayIdColumn === null || $residentColumn === null) {
    $homeError = 'Cases table is missing required columns to display dashboard data.';
} else {
    $whereBase = ' WHERE 1=1' . ($hasArchivedAt ? ' AND archived_at IS NULL' : '') . $scopeSql;

    $totalResult = $conn->query("SELECT COUNT(*) AS c FROM cases" . $whereBase);
    $totalCases = (int)($totalResult ? ($totalResult->fetch_assoc()['c'] ?? 0) : 0);

    if ($statusColumn) {
        $pendingResult = $conn->query("SELECT COUNT(*) AS c FROM cases" . $whereBase . " AND status='Pending'");
        $resolvedResult = $conn->query("SELECT COUNT(*) AS c FROM cases" . $whereBase . " AND status='Resolved'");
        $pendingCases = (int)($pendingResult ? ($pendingResult->fetch_assoc()['c'] ?? 0) : 0);
        $resolvedCases = (int)($resolvedResult ? ($resolvedResult->fetch_assoc()['c'] ?? 0) : 0);
    }

    $recentFields = [
        "$idColumn AS row_id",
        "$displayIdColumn AS display_case_id",
        "$residentColumn AS resident_name",
        ($typeColumn ? "$typeColumn AS case_type" : "'' AS case_type"),
        ($statusColumn ? "$statusColumn AS case_status" : "'' AS case_status"),
        ($hasKpStatus ? "kp_status AS case_kp_status" : "'' AS case_kp_status"),
        ($dateColumn ? "$dateColumn AS case_date" : "'' AS case_date"),
        ($descColumn ? "$descColumn AS case_description" : "'' AS case_description"),
    ];

    $recentSql = "SELECT " . implode(', ', $recentFields) . " FROM cases" . $whereBase;
    if ($dateColumn) {
        $recentSql .= " ORDER BY $dateColumn DESC";
    } else {
        $recentSql .= " ORDER BY $idColumn DESC";
    }
    $recentSql .= " LIMIT 5";

    $recentCases = $conn->query($recentSql);
}
?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-title">Home</div>
        <div class="topbar-right">
            <div class="icon-btn" title="Notifications">
                <i class="uil uil-bell"></i>
            </div>
            <div class="topbar-divider"></div>
            <div class="user-pill">
                <div class="user-avatar">
                    <i class="uil uil-user"></i>
                </div>
                <div class="user-text">
                    <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Barangay Official') ?></strong>
                    <span>Barangay Administrator</span>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">

        <!-- WELCOME BANNER -->
        <div class="welcome-banner hm-banner">
            <div class="hm-banner-main">
                <span class="hm-seal"><img src="../images/seal_barangay_munoz.png" alt=""></span>
                <div>
                    <h5>Welcome back, <?= htmlspecialchars($_SESSION['name'] ?? 'Barangay Official') ?></h5>
                    <p>Here is the current status of cases under your barangay.</p>
                </div>
            </div>
            <div class="banner-date hm-date">
                <i class="uil uil-calendar-alt"></i>
                <div>
                    <div><?php echo date('l'); ?></div>
                    <strong><?php echo date('F d, Y'); ?></strong>
                </div>
            </div>
        </div>

        <!-- CASE SUMMARY (same cards as the admin Home page and Flexible Reports) -->
        <?php $summary = gabaybatas_case_group_counts($conn, $currentBarangay); ?>
        <?php gabaybatas_summary_cards($summary['total'], $summary['counts'], 'Barangay ' . KP_BARANGAY_NAME . ', all periods'); ?>

        <!-- KP MONTHLY REPORT WIDGET -->
        <?php
        // Calculate monthly report due date (5th of next month)
        $kpDueDate = date('Y-m-d', mktime(0, 0, 0, (int)date('n') + 1, 5, (int)date('Y')));
        $today = new DateTime(date('Y-m-d'));
        $kpDaysLeft = max(0, (int)$today->diff(new DateTime($kpDueDate))->format('%r%a'));

        // Cases nearing a KP time limit: only non-archived cases of this
        // barangay, and only the deadline of the stage each case is in
        // (same rule as the warning on the Case Process page).
        $kpDeadlineAlerts = [];
        $dlq = $conn->query(
            "SELECT id, case_id, name, kp_status,
                    CASE WHEN kp_status IN ('accepted','mediation_scheduled','mediation_ongoing') THEN mediation_deadline
                         ELSE conciliation_deadline END AS stage_deadline,
                    CASE WHEN kp_status IN ('accepted','mediation_scheduled','mediation_ongoing') THEN 'Mediation'
                         ELSE 'Conciliation' END AS stage_label
             FROM cases" . $whereBase . "
               AND (
                    (kp_status IN ('accepted','mediation_scheduled','mediation_ongoing') AND mediation_deadline IS NOT NULL)
                 OR (kp_status IN ('conciliation_scheduled','conciliation_ongoing') AND conciliation_deadline IS NOT NULL)
               )
             HAVING stage_deadline <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY stage_deadline ASC
             LIMIT 5"
        );
        if ($dlq) {
            while ($dlRow = $dlq->fetch_assoc()) {
                $kpDeadlineAlerts[] = [
                    'case_id' => $dlRow['case_id'] ?: ('C-' . str_pad((string)$dlRow['id'], 4, '0', STR_PAD_LEFT)),
                    'id' => $dlRow['id'],
                    'name' => $dlRow['name'],
                    'type' => $dlRow['stage_label'],
                    'deadline' => $dlRow['stage_deadline'],
                    // Whole calendar days; 0 means the period ends today.
                    'days_left' => (int)$today->diff(new DateTime($dlRow['stage_deadline']))->format('%r%a'),
                ];
            }
        }
        ?>

        <?php if (!empty($kpDeadlineAlerts) || $kpDaysLeft <= 10): ?>
        <div class="kp-dashboard-widgets hm-widgets">

            <!-- Monthly Report Widget -->
            <?php if ($kpDaysLeft <= 10): $reportTone = $kpDaysLeft <= 3 ? 'is-red' : ($kpDaysLeft <= 5 ? 'is-amber' : 'is-navy'); ?>
            <div class="card-box hm-widget <?= $reportTone ?>">
                <div class="hm-widget-row">
                    <span class="hm-widget-icon"><i class="uil uil-file-graph"></i></span>
                    <div class="hm-widget-text">
                        <strong>Monthly Report Due</strong>
                        <p>KP Monitoring Form No. 1, prepared within the first 5 days of the month</p>
                        <span class="hm-due">
                            <?php if ($kpDaysLeft <= 0): ?>
                                <i class="uil uil-exclamation-triangle"></i> Due <strong>today</strong> (<?= date('M j', strtotime($kpDueDate)) ?>)
                            <?php else: ?>
                                <i class="uil uil-clock"></i> <?= $kpDaysLeft ?> day<?= $kpDaysLeft > 1 ? 's' : '' ?> left (<?= date('M j', strtotime($kpDueDate)) ?>)
                            <?php endif; ?>
                        </span>
                    </div>
                    <a href="kp_reports.php" class="hm-widget-btn">View <i class="uil uil-arrow-right"></i></a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Deadline Alerts -->
            <?php if (!empty($kpDeadlineAlerts)): ?>
            <div class="card-box hm-widget is-amber">
                <div class="hm-widget-title"><span class="hm-widget-icon"><i class="uil uil-exclamation-triangle"></i></span><strong>Upcoming KP Deadlines</strong></div>
                <div class="hm-deadlines">
                <?php foreach ($kpDeadlineAlerts as $alert): ?>
                <a href="case_process.php?id=<?= (int)$alert['id'] ?>" class="hm-deadline">
                    <span class="hm-deadline-case"><?= htmlspecialchars($alert['case_id']) ?></span>
                    <span class="hm-deadline-text"><?= $alert['type'] ?> period ends <?= date('M j', strtotime($alert['deadline'])) ?></span>
                    <span class="hm-deadline-left <?= $alert['days_left'] <= 3 ? 'is-red' : 'is-amber' ?>">
                        <?php if ($alert['days_left'] < 0): ?>
                            Ended
                        <?php elseif ($alert['days_left'] === 0): ?>
                            Ends today
                        <?php else: ?>
                            <?= $alert['days_left'] ?>d left
                        <?php endif; ?>
                    </span>
                </a>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- RECENT CASES TABLE -->
        <div class="card-box">
            <div class="hm-table-head">
                <div>
                    <div class="section-heading">Recent Cases</div>
                    <div class="section-heading-sub">Showing the latest case entries for your barangay</div>
                </div>
                <a href="cases.php" class="hm-all-link">View all cases <i class="uil uil-arrow-right"></i></a>
            </div>

            <div class="table-wrap">
                <table class="table-dashboard">
                    <thead>
                        <tr>
                            <th>Case ID</th>
                            <th>Resident Name</th>
                            <th>Case Type</th>
                            <th>Status</th>
                            <th>Date & Time Filed</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($homeError !== ''): ?>
                        <tr>
                            <td colspan="6"><?= htmlspecialchars($homeError) ?></td>
                        </tr>
                        <?php elseif ($recentCases && $recentCases->num_rows > 0): ?>
                            <?php while ($row = $recentCases->fetch_assoc()):
                                $legacyStatus = trim((string)($row['case_status'] ?? 'Pending'));
                                $kpStatusValue = trim((string)($row['case_kp_status'] ?? ''));
                                $status = $kpStatusValue !== '' ? kp_status_label($kpStatusValue) : $legacyStatus;
                                $statusColor = $kpStatusValue !== '' ? kp_status_color($kpStatusValue) : ($legacyStatus === 'Resolved' ? '#15803d' : ($legacyStatus === 'Pending' ? '#92400e' : '#4338ca'));
                                $statusClass = $legacyStatus === 'Pending' ? 'pending' : ($legacyStatus === 'Resolved' ? 'resolved' : 'progress');
                                $dateRaw = (string)($row['case_date'] ?? '');
                                $dateTs = strtotime($dateRaw);
                                $dateDisplay = $dateTs !== false ? date('M d, Y h:i A', $dateTs) : ($dateRaw !== '' ? $dateRaw : 'N/A');
                                $residentName = (string)($row['resident_name'] ?? 'N/A');
                                $caseTypeDisplay = (string)($row['case_type'] ?? 'N/A');
                                $caseDescription = trim((string)($row['case_description'] ?? ''));
                                $displayCaseId = gabaybatas_format_case_id($row['display_case_id'] ?? '', $row['row_id'] ?? '');
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($displayCaseId) ?></strong></td>
                                <td><?= htmlspecialchars($residentName) ?></td>
                                <td><?= htmlspecialchars($caseTypeDisplay) ?></td>
                                <td><span class="badge-status" style="color: <?= htmlspecialchars($statusColor) ?>; background: <?= htmlspecialchars($statusColor) ?>18;"><?= htmlspecialchars($status) ?></span></td>
                                <td class="hm-date-cell"><?php if ($dateTs !== false): ?><strong><?= date('M d, Y', $dateTs) ?></strong><small><?= date('h:i A', $dateTs) ?></small><?php else: ?><?= htmlspecialchars($dateDisplay) ?><?php endif; ?></td>
                                <td>
                                    <button
                                        type="button"
                                        class="table-action home-view-btn"
                                        data-case-id="<?= htmlspecialchars($displayCaseId) ?>"
                                        data-resident="<?= htmlspecialchars($residentName) ?>"
                                        data-type="<?= htmlspecialchars($caseTypeDisplay) ?>"
                                        data-status="<?= htmlspecialchars($status) ?>"
                                        data-date="<?= htmlspecialchars($dateDisplay) ?>"
                                        data-description="<?= htmlspecialchars($caseDescription !== '' ? $caseDescription : 'No description available.') ?>"
                                    ><i class="uil uil-eye"></i> View</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="6">No recent cases found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /page-body -->
</div><!-- /main -->

<div id="homeCaseModal" class="home-modal" aria-hidden="true">
    <div class="home-modal-card" role="dialog" aria-modal="true" aria-label="Case details">
        <div class="home-modal-head">
            <div class="home-modal-title">Case Details</div>
            <button type="button" class="home-modal-close" id="homeCaseModalClose" aria-label="Close"><i class="uil uil-times"></i></button>
        </div>
        <div class="home-modal-body">
            <div class="home-modal-grid">
                <div class="home-modal-item"><span>Case ID</span><strong id="modalCaseId">-</strong></div>
                <div class="home-modal-item"><span>Resident</span><strong id="modalResident">-</strong></div>
                <div class="home-modal-item"><span>Type</span><strong id="modalType">-</strong></div>
                <div class="home-modal-item"><span>Status</span><strong id="modalStatus">-</strong></div>
                <div class="home-modal-item home-modal-item-full"><span>Date & Time Filed</span><strong id="modalDate">-</strong></div>
                <div class="home-modal-item home-modal-item-full"><span>Description</span><strong id="modalDescription">-</strong></div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('homeCaseModal');
    const closeBtn = document.getElementById('homeCaseModalClose');
    const viewButtons = document.querySelectorAll('.home-view-btn');

    if (!modal || viewButtons.length === 0) return;

    const fields = {
        caseId: document.getElementById('modalCaseId'),
        resident: document.getElementById('modalResident'),
        type: document.getElementById('modalType'),
        status: document.getElementById('modalStatus'),
        date: document.getElementById('modalDate'),
        description: document.getElementById('modalDescription')
    };

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    }

    function openModal(button) {
        fields.caseId.textContent = button.dataset.caseId || '-';
        fields.resident.textContent = button.dataset.resident || '-';
        fields.type.textContent = button.dataset.type || '-';
        fields.status.textContent = button.dataset.status || '-';
        fields.date.textContent = button.dataset.date || '-';
        fields.description.textContent = button.dataset.description || '-';
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    }

    viewButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(button);
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });
})();
</script>

<style>
.home-view-btn{cursor:pointer;background:#fff;}
.home-modal{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:center;justify-content:center;padding:16px;z-index:210;}
.home-modal.show{display:flex;}
.home-modal-card{width:100%;max-width:620px;background:#fff;border:1px solid #dbe3ef;border-radius:14px;box-shadow:0 18px 45px rgba(15,23,42,.24);overflow:hidden;}
.home-modal-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #edf2f7;}
.home-modal-title{font-size:22px;font-weight:700;color:#111827;line-height:1.1;}
.home-modal-close{border:1px solid #dbe3ef;background:#fff;color:#475569;border-radius:8px;padding:6px 8px;cursor:pointer;transition:transform .2s ease, background .2s ease, color .2s ease, border-color .2s ease;}
.home-modal-close:hover{transform:rotate(90deg) scale(1.08);background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;}
.home-modal-close:active{transform:rotate(90deg) scale(.98);}
.home-modal-body{padding:14px 16px;}
.home-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.home-modal-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;display:flex;flex-direction:column;gap:4px;}
.home-modal-item span{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#64748b;font-weight:700;}
.home-modal-item strong{font-size:13px;color:#111827;line-height:1.4;}
.home-modal-item-full{grid-column:1 / -1;}
@media (max-width: 700px){.home-modal-grid{grid-template-columns:1fr;}}

/* ── Home refresh (UI only) ── */
.gb-ui .hm-banner{position:relative;overflow:hidden;border-radius:16px;padding:22px 26px;margin-bottom:20px;background:radial-gradient(circle at 0% 0%,rgba(255,255,255,.10),transparent 45%),linear-gradient(135deg,#1a3a6b 0%,#0e2242 70%,#0b1a33 100%);box-shadow:0 14px 34px rgba(14,34,66,.22);}
.gb-ui .hm-banner::after{content:'';position:absolute;right:-60px;top:-80px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(201,162,39,.18),transparent 65%);pointer-events:none;}
.hm-banner-main{display:flex;align-items:center;gap:16px;position:relative;z-index:1;}
.hm-seal{width:56px;height:56px;border-radius:50%;background:#fff;padding:3px;flex-shrink:0;box-shadow:0 0 0 3px rgba(255,255,255,.16),0 8px 18px rgba(0,0,0,.25);}
.hm-seal img{width:100%;height:100%;object-fit:contain;border-radius:50%;}
.gb-ui .hm-banner h5{font-size:22px;font-weight:700;margin-bottom:3px;}
.gb-ui .hm-banner p{font-size:14px;color:rgba(255,255,255,.78);}
.gb-ui .hm-date{position:relative;z-index:1;display:flex;align-items:center;gap:10px;padding:9px 14px;border-radius:12px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.14);color:rgba(255,255,255,.75);font-size:12.5px;text-align:left;}
.hm-date i{font-size:20px;color:#e9c766;}
.hm-date strong{display:block;color:#fff;font-size:14px;font-weight:700;}

.gb-ui .gb-sum-card{border-radius:14px;border-right-color:#e1e7f0;border-bottom-color:#e1e7f0;border-left-color:#e1e7f0;box-shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px rgba(15,23,42,.045);}
.gb-ui .gb-sum-label{color:#4f5f75;}
.gb-ui .gb-sum-sub{color:#6f7f96;}

.hm-widgets{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:20px;}
.hm-widget{--tone:#24508f;--tone-bg:#eef3fb;border-left:4px solid var(--tone)!important;padding:18px 22px!important;}
.hm-widget.is-amber{--tone:#d97706;--tone-bg:#fff7ed;}
.hm-widget.is-red{--tone:#dc2626;--tone-bg:#fef2f2;}
.hm-widget-row{display:flex;align-items:center;gap:14px;}
.hm-widget-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;flex-shrink:0;background:var(--tone-bg);color:var(--tone);font-size:20px;}
.hm-widget-text{flex:1;min-width:0;}
.hm-widget-text strong,.hm-widget-title strong{font-size:15px;color:#14213a;}
.hm-widget-text p{margin:2px 0 6px;font-size:13px;color:#4f5f75;line-height:1.45;}
.hm-due{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;background:var(--tone-bg);color:var(--tone);font-size:12.5px;font-weight:700;}
.hm-widget-btn{display:inline-flex;align-items:center;gap:5px;padding:9px 14px;border-radius:10px;border:1.5px solid var(--tone);color:var(--tone);background:#fff;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s,color .15s;}
.hm-widget-btn:hover{background:var(--tone);color:#fff;}
.hm-widget-title{display:flex;align-items:center;gap:12px;margin-bottom:10px;}
.hm-widget-title .hm-widget-icon{width:36px;height:36px;font-size:18px;}
.hm-deadlines{display:flex;flex-direction:column;gap:6px;}
.hm-deadline{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;background:#f8fafc;border:1px solid #edf1f7;text-decoration:none;color:#334155;font-size:13.5px;transition:border-color .15s,background .15s;}
.hm-deadline:hover{border-color:#93c5fd;background:#f6f9ff;}
.hm-deadline-case{font-weight:700;color:#1a3a6b;font-family:monospace;font-size:13px;}
.hm-deadline-text{flex:1;}
.hm-deadline-left{padding:3px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;}
.hm-deadline-left.is-amber{background:#fff7ed;color:#b45309;}
.hm-deadline-left.is-red{background:#fef2f2;color:#dc2626;}

.hm-table-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;}
.hm-all-link{display:inline-flex;align-items:center;gap:5px;padding:8px 13px;border-radius:10px;background:#eef3fb;color:#1a3a6b;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s;}
.hm-all-link:hover{background:#dfe8f7;}
.hm-date-cell strong{display:block;font-weight:600;white-space:nowrap;}
.hm-date-cell small{display:block;font-size:12.5px;color:#6f7f96;}

.gb-ui .home-modal-card{border-radius:16px;}
.gb-ui .home-modal-head{padding:16px 20px;}
.gb-ui .home-modal-title{font-family:'Source Serif 4',serif;font-size:20px;color:#0b1a33;}
.gb-ui .home-modal-body{padding:16px 20px 20px;}
.gb-ui .home-modal-item strong{font-size:14px;}
@media (max-width:760px){.gb-ui .hm-banner{flex-direction:column;align-items:flex-start;gap:14px;}.hm-table-head{flex-direction:column;}}
</style>

</body>
</html>