<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
gabaybatas_block_barangay_delete_requests();

$caseIdParam = trim((string)($_GET['id'] ?? ''));
$case = null;
$error = '';
$canEditCase = false;

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

$idColumn       = $hasColumn('id') ? 'id' : ($hasColumn('case_id') ? 'case_id' : null);
$displayIdCol   = $hasColumn('case_id') ? 'case_id' : ($hasColumn('id') ? 'id' : null);
$residentColumn = $hasColumn('name') ? 'name' : ($hasColumn('resident_name') ? 'resident_name' : null);
$complainedColumn = $hasColumn('complained_person') ? 'complained_person' : ($hasColumn('respondent_name') ? 'respondent_name' : ($hasColumn('against_person') ? 'against_person' : null));
$typeColumn     = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$statusColumn   = $hasColumn('status') ? 'status' : null;
$dateColumn     = $hasColumn('date_submitted') ? 'date_submitted' : ($hasColumn('created_at') ? 'created_at' : null);
$descColumn     = $hasColumn('description') ? 'description' : ($hasColumn('details') ? 'details' : null);

if ($caseIdParam === '') {
    $error = 'Missing case id.';
} elseif ($idColumn === null) {
    $error = 'Case table is missing an identifiable primary column.';
} else {
    $selectFields = [];
    if ($displayIdCol)   { $selectFields[] = "$displayIdCol AS display_case_id"; }
    if ($residentColumn) { $selectFields[] = "$residentColumn AS resident_name"; }
    if ($complainedColumn) { $selectFields[] = "$complainedColumn AS complained_person"; }
    if ($typeColumn)     { $selectFields[] = "$typeColumn AS case_type"; }
    if ($statusColumn)   { $selectFields[] = "$statusColumn AS case_status"; }
    if ($dateColumn)     { $selectFields[] = "$dateColumn AS case_date"; }
    if ($descColumn)     { $selectFields[] = "$descColumn AS case_description"; }
    if ($hasColumn('barangay')) { $selectFields[] = "barangay AS case_barangay"; }

    if (!$selectFields) {
        $error = 'No readable fields found in case table.';
    } else {
        $sql = "SELECT " . implode(', ', $selectFields) . " FROM cases WHERE $idColumn = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $caseIdParam);
            $stmt->execute();
            $res = $stmt->get_result();
            $case = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }

        if (!$case) {
            $error = 'Case not found.';
        }

        if ($error === '' && $_SESSION['role'] === 'barangay') {
            $currentBarangay = gabaybatas_get_user_barangay($conn);
            if ($hasColumn('barangay') && $currentBarangay !== '') {
                $canEditCase = gabaybatas_barangay_matches($case, $currentBarangay);
            } else {
                $canEditCase = true;
            }
        } else {
            $canEditCase = true;
        }
    }
}
?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">

    <div class="topbar">
        <div class="topbar-title">Case Details</div>
        <div class="topbar-right">
            <div class="icon-btn" title="Notifications">
                <i class="uil uil-bell"></i>
            </div>
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

    <div class="page-body vc-body">

        <div class="vc-head">
            <p class="vc-sub">Review complete information for the selected case.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                <?php if ($canEditCase && $error === ''): ?>
                <a href="update_case.php?id=<?= urlencode($caseIdParam) ?>" class="vc-update-btn"><i class="uil uil-edit"></i> Update Case</a>
                <?php endif; ?>
                <a href="cases.php" class="vc-back-btn"><i class="uil uil-arrow-left"></i> Back to Cases</a>
            </div>
        </div>

        <div class="card-box vc-card">
            <?php if ($error !== ''): ?>
                <div class="vc-empty">
                    <i class="uil uil-exclamation-circle"></i>
                    <div class="vc-empty-title"><?= htmlspecialchars($error) ?></div>
                    <a href="cases.php" class="vc-back-link">Return to case list</a>
                </div>
            <?php else: ?>
                <?php
                $status = trim((string)($case['case_status'] ?? 'N/A'));
                $statusClass = $status === 'Pending' ? 'pending' : ($status === 'Resolved' ? 'resolved' : 'progress');
                $displayCaseId = gabaybatas_format_case_id($case['display_case_id'] ?? '', $caseIdParam);
                $dateRaw = (string)($case['case_date'] ?? '');
                $dateTs = strtotime($dateRaw);
                $dateDisplay = $dateTs !== false ? date('M d, Y h:i A', $dateTs) : ($dateRaw !== '' ? $dateRaw : 'N/A');
                ?>
                <div class="vc-grid">
                    <div class="vc-item">
                        <label>Case ID</label>
                        <div class="vc-value"><?= htmlspecialchars($displayCaseId) ?></div>
                    </div>
                    <div class="vc-item">
                        <label>Resident Name</label>
                        <div class="vc-value"><?= htmlspecialchars((string)($case['resident_name'] ?? 'N/A')) ?></div>
                    </div>
                    <div class="vc-item">
                        <label>Person Being Complained</label>
                        <div class="vc-value"><?= htmlspecialchars((string)($case['complained_person'] ?? 'N/A')) ?></div>
                    </div>
                    <div class="vc-item">
                        <label>Case Type</label>
                        <div class="vc-value"><?= htmlspecialchars((string)($case['case_type'] ?? 'N/A')) ?></div>
                    </div>
                    <div class="vc-item">
                        <label>Status</label>
                        <div class="vc-value">
                            <span class="vc-badge <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
                        </div>
                    </div>
                    <div class="vc-item vc-item-full">
                        <label>Date & Time Filed</label>
                        <div class="vc-value"><?= htmlspecialchars($dateDisplay) ?></div>
                    </div>
                    <div class="vc-item vc-item-full">
                        <label>Description</label>
                        <div class="vc-value vc-multiline"><?= nl2br(htmlspecialchars((string)($case['case_description'] ?? 'No description available.'))) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<style>
.vc-body { padding: 24px 28px; }
.vc-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; }
.vc-sub { font-size: 13px; color: #6b7280; margin: 0; }
.vc-back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600; text-decoration: none;
    color: #1d4ed8; background: #eff6ff; border: 1px solid #dbeafe;
    border-radius: 9px; padding: 8px 13px;
}
.vc-back-btn:hover { background: #dbeafe; }
.vc-update-btn {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600; text-decoration: none;
    color: #0f766e; background: #ecfdf5; border: 1px solid #a7f3d0;
    border-radius: 9px; padding: 8px 13px;
}
.vc-update-btn:hover { background: #d1fae5; }

.vc-card { padding: 20px; }
.vc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.vc-item label {
    display: block; font-size: 11px; font-weight: 700; letter-spacing: .6px;
    text-transform: uppercase; color: #64748b; margin-bottom: 6px;
}
.vc-value {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 10px 12px; font-size: 13px; color: #1f2937;
}
.vc-item-full { grid-column: 1 / -1; }
.vc-multiline { line-height: 1.55; }

.vc-badge { display: inline-flex; align-items: center; font-size: 11px; font-weight: 700; padding: 4px 11px; border-radius: 999px; }
.vc-badge.pending { background: #fffbeb; color: #92400e; }
.vc-badge.progress { background: #eef2ff; color: #4338ca; }
.vc-badge.resolved { background: #dcfce7; color: #15803d; }

.vc-empty { text-align: center; padding: 28px 12px; }
.vc-empty i { font-size: 34px; color: #94a3b8; margin-bottom: 8px; display: block; }
.vc-empty-title { font-size: 14px; color: #1f2937; margin-bottom: 10px; }
.vc-back-link { font-size: 12.5px; color: #1d4ed8; text-decoration: none; font-weight: 600; }

@media (max-width: 900px) {
    .vc-body { padding: 16px; }
    .vc-head { flex-direction: column; align-items: flex-start; }
    .vc-grid { grid-template-columns: 1fr; }
}
</style>
