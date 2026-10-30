<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/kp.php';

$caseIdParam = trim((string)($_GET['id'] ?? ''));
$case = null;
$error = '';
$evidence = [];

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
$residentColumn = $hasColumn('resident_name') ? 'resident_name' : ($hasColumn('name') ? 'name' : null);
$complainedColumn = $hasColumn('complained_person') ? 'complained_person' : ($hasColumn('respondent_name') ? 'respondent_name' : ($hasColumn('against_person') ? 'against_person' : null));
$typeColumn     = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$statusColumn   = $hasColumn('status') ? 'status' : null;
$barangayColumn = $hasColumn('barangay') ? 'barangay' : null;
$dateColumn     = $hasColumn('created_at') ? 'created_at' : ($hasColumn('date_submitted') ? 'date_submitted' : ($hasColumn('updated_at') ? 'updated_at' : null));
$descColumn     = $hasColumn('description') ? 'description' : ($hasColumn('details') ? 'details' : null);

if ($caseIdParam === '') {
    $error = 'Missing case id.';
} elseif ($idColumn === null) {
    $error = 'Case table is missing an identifiable primary column.';
} else {
    $selectFields = [];
    if ($hasColumn('id')) { $selectFields[] = "id AS numeric_case_id"; }
    if ($hasColumn('case_id')) { $selectFields[] = "case_id AS case_code"; }
    if ($displayIdCol)   { $selectFields[] = "$displayIdCol AS display_case_id"; }
    if ($residentColumn) { $selectFields[] = "$residentColumn AS resident_name"; }
    if ($complainedColumn) { $selectFields[] = "$complainedColumn AS complained_person"; }
    if ($typeColumn)     { $selectFields[] = "$typeColumn AS case_type"; }
    if ($statusColumn)   { $selectFields[] = "$statusColumn AS case_status"; }
    if ($hasColumn("kp_status")) { $selectFields[] = "kp_status AS case_kp_status"; }
    if ($barangayColumn) { $selectFields[] = "$barangayColumn AS case_barangay"; }
    if ($dateColumn)     { $selectFields[] = "$dateColumn AS case_date"; }
    if ($descColumn)     { $selectFields[] = "$descColumn AS case_description"; }
    if ($hasColumn('archived_at')) { $selectFields[] = "archived_at AS case_archived_at, archive_reason AS case_archive_reason"; }

    if (!$selectFields) {
        $error = 'No readable fields found in case table.';
    } else {
        $whereClause = "$idColumn = ?";
        if ($hasColumn('id') && $hasColumn('case_id')) {
            $whereClause = "(id = ? OR case_id = ?)";
        }
        $sql = "SELECT " . implode(', ', $selectFields) . " FROM cases WHERE $whereClause LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($whereClause === "(id = ? OR case_id = ?)") {
                $stmt->bind_param('ss', $caseIdParam, $caseIdParam);
            } else {
                $stmt->bind_param('s', $caseIdParam);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $case = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }

        if (!$case) {
            $error = 'Case not found.';
        }

        if ($error === '') {
            $numId = (int)($case['numeric_case_id'] ?? (is_numeric($caseIdParam) ? $caseIdParam : 0));
            $codeId = (string)($case['case_code'] ?? $case['display_case_id'] ?? $caseIdParam);
            $evidenceStmt = $conn->prepare(
                'SELECT id, evidence_type, original_filename, mime_type, file_size
                 FROM api_case_evidence WHERE case_id = ? OR case_id = ? ORDER BY id ASC'
            );
            if ($evidenceStmt) {
                $evidenceStmt->bind_param('is', $numId, $codeId);
                $evidenceStmt->execute();
                $evidenceResult = $evidenceStmt->get_result();
                $evidence = $evidenceResult ? $evidenceResult->fetch_all(MYSQLI_ASSOC) : [];
                $evidenceStmt->close();
            }
        }
    }
}
// Archived cases are read-only here: no Edit, and Back returns to the Records Archive.
$isArchived = !empty($case['case_archived_at']);
$backUrl = $isArchived ? 'archive.php?tab=cases' : 'all_cases.php';
$backLabel = $isArchived ? 'Back to Records Archive' : 'Back to Manage Cases';
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <div class="topbar-title">Case Details</div>
            <span class="topbar-badge">Admin</span>
        </div>
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

    <div class="page-body cv-body">
        <div class="cv-head fade-up" style="--d:0s">
            <p class="cv-sub">Review complete information for the selected case record.</p>
            <div class="cv-head-actions">
                <?php if ($error === '' && $case !== null && !$isArchived): ?>
                <a href="edit_case.php?id=<?= urlencode($caseIdParam) ?>" class="cv-edit-btn"><i class="uil uil-edit"></i> Edit Case</a>
                <?php endif; ?>
                <a href="<?= $backUrl ?>" class="cv-back-btn"><i class="uil uil-arrow-left"></i> <?= $backLabel ?></a>
            </div>
        </div>

        <?php if ($isArchived): ?>
        <div class="cv-archived fade-up" style="--d:.04s">
            <i class="uil uil-archive"></i>
            <div>
                <strong>This case is archived and read-only.</strong>
                <span>Archived on <?= htmlspecialchars(date('M d, Y h:i A', strtotime((string)$case['case_archived_at']))) ?><?= !empty($case['case_archive_reason']) ? ' · Reason: ' . htmlspecialchars((string)$case['case_archive_reason']) : '' ?>. Restore it from the Records Archive to edit it again.</span>
            </div>
        </div>
        <?php endif; ?>

        <div class="cv-card fade-up" style="--d:.08s">
            <?php if ($error !== ''): ?>
                <div class="cv-empty">
                    <i class="uil uil-exclamation-circle"></i>
                    <div class="cv-empty-title"><?= htmlspecialchars($error) ?></div>
                    <a href="all_cases.php" class="cv-back-link">Return to case list</a>
                </div>
            <?php else: ?>
                <?php
                $rawKp = trim((string)($case['case_kp_status'] ?? ''));
                $status = ($rawKp !== '' && function_exists('kp_status_label')) ? kp_status_label($rawKp) : trim((string)($case['case_status'] ?? 'N/A'));
                $statusColor = ($rawKp !== '' && function_exists('kp_status_color')) ? kp_status_color($rawKp) : (($case['case_status'] ?? '') === 'Resolved' ? '#16a34a' : ((($case['case_status'] ?? '') === 'In Progress') ? '#2563eb' : '#f59e0b'));
                $statusClass = ($case['case_status'] ?? '') === 'Pending' ? 'pending' : ((($case['case_status'] ?? '') === 'Resolved') ? 'resolved' : 'progress');
                ?>
                <div class="cv-grid">
                    <div class="cv-item">
                        <label>Case ID</label>
                        <div class="cv-value"><?= htmlspecialchars((string)($case['display_case_id'] ?? $caseIdParam)) ?></div>
                    </div>
                    <div class="cv-item">
                        <label>Resident Name</label>
                        <div class="cv-value"><?= htmlspecialchars((string)($case['resident_name'] ?? 'N/A')) ?></div>
                    </div>
                    <div class="cv-item">
                        <label>Person Being Complained</label>
                        <div class="cv-value"><?= htmlspecialchars((string)($case['complained_person'] ?? 'N/A')) ?></div>
                    </div>
                    <div class="cv-item">
                        <label>Case Type</label>
                        <div class="cv-value"><?= htmlspecialchars((string)($case['case_type'] ?? 'N/A')) ?></div>
                    </div>
                    <div class="cv-item">
                        <label>Status</label>
                        <div class="cv-value">
                            <span class="cv-badge" style="background:<?= $statusColor ?>18; color:<?= $statusColor ?>; border:1px solid <?= $statusColor ?>35;"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:<?= $statusColor ?>;margin-right:5px;"></span><?= htmlspecialchars($status) ?></span>
                        </div>
                    </div>
                    <div class="cv-item">
                        <label>Barangay</label>
                        <div class="cv-value"><?= htmlspecialchars((string)($case['case_barangay'] ?? 'N/A')) ?></div>
                    </div>
                    <div class="cv-item cv-item-full">
                        <label>Submitted / Updated Date</label>
                        <div class="cv-value"><?= htmlspecialchars((string)($case['case_date'] ?? 'N/A')) ?></div>
                    </div>
                    <div class="cv-item cv-item-full">
                        <label>Description</label>
                        <div class="cv-value cv-multiline"><?= nl2br(htmlspecialchars((string)($case['case_description'] ?? 'No description available.'))) ?></div>
                    </div>
                    <div class="cv-item cv-item-full">
                        <div class="cv-evidence-heading">
                            <div><label>Evidence</label><p>Files submitted with this case for administrative review.</p></div>
                            <span class="cv-evidence-count"><?= count($evidence) ?> file<?= count($evidence) === 1 ? '' : 's' ?></span>
                        </div>
                        <?php if (!$evidence): ?>
                            <div class="cv-evidence-empty"><i class="uil uil-file-slash"></i><span>No evidence uploaded for this case.</span></div>
                        <?php else: ?>
                            <div class="cv-evidence-list">
                                <?php foreach ($evidence as $item): ?>
                                    <?php $isVideo = strtolower((string)$item['evidence_type']) === 'video'; ?>
                                    <div class="cv-evidence">
                                        <span class="cv-evidence-icon <?= $isVideo ? 'video' : 'image' ?>"><i class="uil <?= $isVideo ? 'uil-video' : 'uil-image' ?>"></i></span>
                                        <span class="cv-evidence-info"><strong><?= htmlspecialchars((string)$item['original_filename']) ?></strong><small><?= $isVideo ? 'Video evidence' : 'Image evidence' ?> &middot; <?= number_format(((int)$item['file_size']) / 1048576, 1) ?> MB</small></span>
                                        <a class="cv-evidence-view" href="../api/v1/case_evidence.php?id=<?= (int)$item['id'] ?>" target="_blank" rel="noopener"><i class="uil uil-eye"></i> View</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const cvObserver = new IntersectionObserver(entries => {
    entries.forEach(en => {
        if (en.isIntersecting) {
            en.target.classList.add('visible');
            cvObserver.unobserve(en.target);
        }
    });
}, { threshold: 0.1 });
document.querySelectorAll('.fade-up').forEach(el => cvObserver.observe(el));
</script>

<style>
.cv-body { padding: 24px 28px 40px; }
.fade-up { opacity: 0; transform: translateY(20px); transition: opacity .5s ease var(--d,0s), transform .5s ease var(--d,0s); }
.fade-up.visible { opacity: 1; transform: none; }

.cv-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 14px; }
.cv-head-actions { display: inline-flex; align-items: center; gap: 8px; }
.cv-archived { display: flex; align-items: flex-start; gap: 10px; padding: 12px 15px; margin-bottom: 14px; border: 1px solid #fde68a; border-radius: 10px; background: #fffbeb; color: #92400e; font-size: 13px; }
.cv-archived i { font-size: 18px; margin-top: 1px; }
.cv-archived strong { display: block; color: #78350f; }
.cv-archived span { display: block; margin-top: 2px; }
.cv-sub { font-size: 12.5px; color: var(--text-sub); margin: 0; }
.cv-edit-btn {
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; font-size: 12.5px; font-weight: 600;
    color: #0f766e; background: #ccfbf1; border: 1px solid #99f6e4;
    border-radius: 9px; padding: 8px 13px; transition: all .2s;
}
.cv-edit-btn:hover { background: #99f6e4; }
.cv-back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; font-size: 12.5px; font-weight: 600;
    color: #1d4ed8; background: #eff6ff; border: 1px solid #dbeafe;
    border-radius: 9px; padding: 8px 13px; transition: all .2s;
}
.cv-back-btn:hover { background: #dbeafe; }

.cv-card {
    background: #fff; border: 1px solid #f1f5f9; border-radius: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04); padding: 20px;
}

.cv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.cv-item label {
    display: block; font-size: 11px; letter-spacing: .6px; text-transform: uppercase;
    color: var(--text-sub); font-weight: 700; margin-bottom: 6px;
}
.cv-value {
    font-size: 13px; color: var(--text-main); background: #fafbff;
    border: 1px solid #eef2f7; border-radius: 10px; padding: 10px 12px;
}
.cv-item-full { grid-column: 1 / -1; }
.cv-multiline { line-height: 1.5; }
.cv-evidence-heading { display:flex; align-items:flex-end; justify-content:space-between; gap:12px; margin-bottom:8px; }
.cv-evidence-heading label { margin-bottom:2px; }
.cv-evidence-heading p { margin:0; color:var(--text-sub); font-size:11px; }
.cv-evidence-count { padding:5px 9px; border-radius:999px; background:#ccfbf1; color:#0f766e; font-size:10px; font-weight:700; white-space:nowrap; }
.cv-evidence-list { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:9px; }
.cv-evidence { display:flex; align-items:center; gap:10px; min-width:0; padding:10px; border:1px solid #e2e8f0; border-radius:10px; background:#fff; transition:border-color .15s,box-shadow .15s,transform .15s; }
.cv-evidence:hover { border-color:#99f6e4; box-shadow:0 5px 14px rgba(15,118,110,.08); transform:translateY(-1px); }
.cv-evidence-icon { width:34px; height:34px; flex:0 0 34px; display:grid; place-items:center; border-radius:9px; font-size:17px; }
.cv-evidence-icon.image { background:#ecfeff; color:#0891b2; }
.cv-evidence-icon.video { background:#f5f3ff; color:#7c3aed; }
.cv-evidence-info { display:flex; flex-direction:column; min-width:0; flex:1; }
.cv-evidence-info strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--text-main); font-size:12px; }
.cv-evidence-info small { margin-top:3px; color:var(--text-sub); font-size:10px; }
.cv-evidence-view { display:inline-flex; align-items:center; gap:4px; flex:0 0 auto; padding:6px 8px; border-radius:6px; background:#ccfbf1; color:#0f766e; font-size:10px; font-weight:700; text-decoration:none; }
.cv-evidence-view:hover { background:#99f6e4; }
.cv-evidence-empty { display:flex; align-items:center; gap:8px; padding:13px; border:1px dashed #cbd5e1; border-radius:10px; color:var(--text-sub); font-size:12px; }
.cv-evidence-empty i { font-size:18px; }

.cv-badge { display: inline-flex; align-items: center; font-size: 11px; font-weight: 700; padding: 4px 11px; border-radius: 999px; }
.cv-badge.pending { background: #fffbeb; color: #92400e; }
.cv-badge.progress { background: #eef2ff; color: #4338ca; }
.cv-badge.resolved { background: #dcfce7; color: #15803d; }

.cv-empty { text-align: center; padding: 28px 12px; }
.cv-empty i { font-size: 34px; color: #94a3b8; margin-bottom: 8px; display: block; }
.cv-empty-title { font-size: 14px; color: var(--text-main); margin-bottom: 10px; }
.cv-back-link { font-size: 12.5px; color: #1d4ed8; text-decoration: none; font-weight: 600; }

@media (max-width: 900px) {
    .cv-body { padding: 16px; }
    .cv-head { flex-direction: column; align-items: flex-start; }
    .cv-head-actions { width: 100%; flex-wrap: wrap; }
    .cv-grid { grid-template-columns: 1fr; }
}
@media (max-width: 520px) {
    .cv-evidence-heading { align-items:flex-start; flex-direction:column; gap:5px; }
    .cv-evidence { align-items:flex-start; }
    .cv-evidence-view { margin-left:auto; }
}
</style>
