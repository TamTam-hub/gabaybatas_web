<?php
session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';

gabaybatas_require_role(['barangay'], '../login.php');
gabaybatas_block_barangay_delete_requests();

$currentBarangay = gabaybatas_get_user_barangay($conn);
$hasCaseBarangay = gabaybatas_table_has_column($conn, 'cases', 'barangay');

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
    $whereBase = ' WHERE 1=1' . $scopeSql;

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
        <div class="welcome-banner">
            <div>
                <h5>Welcome back, <?= htmlspecialchars($_SESSION['name'] ?? 'Barangay Official') ?></h5>
                <p>Here is the current status of cases under your barangay.</p>
            </div>
            <div class="banner-date">
                <div><?php echo date('l'); ?></div>
                <div><?php echo date('F d, Y'); ?></div>
            </div>
        </div>

        <!-- STAT CARDS -->
        <div class="stat-cards-row">
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Total Cases</div>
                    <div class="stat-value"><?= (int)$totalCases ?></div>
                    <div class="stat-sub">All recorded cases</div>
                </div>
                <div class="stat-icon stat-blue">
                    <i class="uil uil-folder-open"></i>
                </div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Pending Cases</div>
                    <div class="stat-value"><?= (int)$pendingCases ?></div>
                    <div class="stat-sub">Awaiting action</div>
                </div>
                <div class="stat-icon stat-orange">
                    <i class="uil uil-clock-three"></i>
                </div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Resolved Cases</div>
                    <div class="stat-value"><?= (int)$resolvedCases ?></div>
                    <div class="stat-sub">Successfully closed</div>
                </div>
                <div class="stat-icon stat-green">
                    <i class="uil uil-check-circle"></i>
                </div>
            </div>
        </div>

        <!-- RECENT CASES TABLE -->
        <div class="card-box">
            <div class="section-heading">Recent Cases</div>
            <div class="section-heading-sub">Showing the latest case entries for your barangay</div>

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
                                $status = trim((string)($row['case_status'] ?? 'Pending'));
                                $statusClass = $status === 'Pending' ? 'pending' : ($status === 'Resolved' ? 'resolved' : 'progress');
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
                                <td><span class="badge-status <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span></td>
                                <td><?= htmlspecialchars($dateDisplay) ?></td>
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
</style>

</body>
</html>