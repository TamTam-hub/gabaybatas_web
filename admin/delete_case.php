<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/archive.php';
require_once '../config/kp.php';
gabaybatas_archive_ensure_schema($conn);

$caseIdParam = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$error = '';
$reason = trim((string)($_POST['archive_reason'] ?? ''));

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
$residentColumn = $hasColumn('resident_name') ? 'resident_name' : ($hasColumn('name') ? 'name' : null);
$typeColumn = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$statusColumn = $hasColumn('status') ? 'status' : null;
$barangayColumn = $hasColumn('barangay') ? 'barangay' : null;

$case = null;

if ($caseIdParam === '') {
    $error = 'Missing case id.';
} elseif ($idColumn === null) {
    $error = 'Case table is missing an identifiable primary column.';
} else {
    $selectFields = [];
    if ($displayIdColumn)   { $selectFields[] = "$displayIdColumn AS display_case_id"; }
    if ($residentColumn) { $selectFields[] = "$residentColumn AS resident_name"; }
    if ($typeColumn)     { $selectFields[] = "$typeColumn AS case_type"; }
    if ($statusColumn)   { $selectFields[] = "$statusColumn AS case_status"; }
    if ($barangayColumn) { $selectFields[] = "$barangayColumn AS case_barangay"; }
    if ($hasColumn('kp_status')) { $selectFields[] = "kp_status AS case_kp_status"; }

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
        if ($reason === '') {
            $error = 'Please provide a reason for archiving this case.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
        $archiveSql = "UPDATE cases
            SET archived_at = NOW(), archived_by = ?, archive_reason = ?
            WHERE $idColumn = ? AND (archived_at IS NULL) LIMIT 1";
        $stmt = $conn->prepare($archiveSql);
        if (!$stmt) {
            $error = 'Unable to prepare archive query.';
        } else {
            $adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $stmt->bind_param('iss', $adminId, $reason, $caseIdParam);
            if ($stmt->execute()) {
                if ($stmt->affected_rows === 1) {
                    gabaybatas_archive_audit($conn, 'case_archived', 'case', $caseIdParam, $reason, $case, [
                        'archived_at' => date('Y-m-d H:i:s'),
                        'archive_reason' => $reason,
                    ]);
                    header('Location: all_cases.php?archived=1');
                    exit();
                }
                $error = 'This case is already archived or could not be updated.';
            }
            if ($stmt->errno !== 0) {
                $error = 'Unable to archive this case. Please try again.';
            }
            $stmt->close();
        }
    }
}
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <div class="topbar-title">Archive Case</div>
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

    <div class="page-body dc-body">
        <div class="dc-head fade-up" style="--d:0s">
            <p class="dc-sub">Move a case out of active records while preserving its official history.</p>
            <a href="all_cases.php" class="dc-back-btn"><i class="uil uil-arrow-left"></i> Back to Manage Cases</a>
        </div>

        <div class="dc-card fade-up" style="--d:.08s">
            <?php if ($error !== ''): ?>
                <div class="dc-empty">
                    <i class="uil uil-exclamation-circle"></i>
                    <div class="dc-empty-title"><?= htmlspecialchars($error) ?></div>
                    <a href="all_cases.php" class="dc-back-link">Return to case list</a>
                </div>
            <?php else: ?>
                <div class="dc-warning">
                    <div class="dc-warning-icon"><i class="uil uil-exclamation-triangle"></i></div>
                    <div>
                        <div class="dc-warning-title">Confirm case archiving</div>
                        <div class="dc-warning-text">Case <?= htmlspecialchars((string)($case['display_case_id'] ?? $caseIdParam)) ?> will be hidden from active records. Its case history and evidence will be preserved.</div>
                    </div>
                </div>

                <div class="dc-grid">
                    <div class="dc-item"><span>Resident</span><strong><?= htmlspecialchars((string)($case['resident_name'] ?? 'N/A')) ?></strong></div>
                    <div class="dc-item"><span>Case Type</span><strong><?= htmlspecialchars((string)($case['case_type'] ?? 'N/A')) ?></strong></div>
                    <?php $dcKp = trim((string)($case['case_kp_status'] ?? '')); ?>
                    <div class="dc-item"><span>Status</span><strong<?= $dcKp !== '' ? ' style="color:' . htmlspecialchars(kp_status_color($dcKp)) . '"' : '' ?>><?= htmlspecialchars($dcKp !== '' ? kp_status_label($dcKp) : (string)($case['case_status'] ?? 'N/A')) ?></strong></div>
                    <div class="dc-item"><span>Barangay</span><strong><?= htmlspecialchars((string)($case['case_barangay'] ?? 'N/A')) ?></strong></div>
                </div>

                <form method="POST" class="dc-actions">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($caseIdParam) ?>">
                    <label class="dc-reason-label" for="archive_reason">Reason for archiving</label>
                    <textarea id="archive_reason" name="archive_reason" required maxlength="500" class="dc-reason" placeholder="Explain why this case is being archived"><?= htmlspecialchars($reason) ?></textarea>
                    <a href="all_cases.php" class="dc-cancel">Cancel</a>
                    <button type="submit" class="dc-delete"><i class="uil uil-archive"></i> Archive Case</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const dcObserver = new IntersectionObserver(entries => {
    entries.forEach(en => {
        if (en.isIntersecting) {
            en.target.classList.add('visible');
            dcObserver.unobserve(en.target);
        }
    });
}, { threshold: 0.1 });
document.querySelectorAll('.fade-up').forEach(el => dcObserver.observe(el));
</script>

<style>
.dc-body { padding: 24px 28px 40px; }
.fade-up { opacity: 0; transform: translateY(20px); transition: opacity .5s ease var(--d,0s), transform .5s ease var(--d,0s); }
.fade-up.visible { opacity: 1; transform: none; }
.dc-head { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:14px; }
.dc-sub { font-size:12.5px; color:var(--text-sub); margin:0; }
.dc-back-btn { display:inline-flex; align-items:center; gap:6px; text-decoration:none; font-size:12.5px; font-weight:600; color:#1d4ed8; background:#eff6ff; border:1px solid #dbeafe; border-radius:9px; padding:8px 13px; }
.dc-card { background:#fff; border:1px solid #f1f5f9; border-radius:16px; box-shadow:0 1px 4px rgba(0,0,0,.04); padding:20px; }
.dc-warning { display:flex; gap:12px; align-items:flex-start; padding:14px 16px; border-radius:12px; background:#fff7ed; border:1px solid #fed7aa; margin-bottom:14px; }
.dc-warning-icon { width:36px; height:36px; border-radius:10px; background:#ffedd5; color:#c2410c; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.dc-warning-title { font-size:14px; font-weight:700; color:#7c2d12; margin-bottom:4px; }
.dc-warning-text { font-size:13px; color:#9a3412; line-height:1.5; }
.dc-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
.dc-item { border:1px solid #e2e8f0; background:#fafbff; border-radius:10px; padding:12px 14px; }
.dc-item span { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:var(--text-sub); font-weight:700; margin-bottom:6px; }
.dc-item strong { font-size:13px; color:var(--text-main); }
.dc-actions { display:flex; justify-content:flex-end; gap:8px; }
.dc-reason-label { flex-basis:100%; font-size:12px; font-weight:700; color:#475569; }
.dc-reason { flex-basis:100%; min-height:78px; resize:vertical; border:1px solid #cbd5e1; border-radius:9px; padding:9px 10px; font:inherit; font-size:12.5px; color:#1e293b; }
.dc-cancel, .dc-delete { display:inline-flex; align-items:center; justify-content:center; gap:6px; border-radius:9px; padding:9px 14px; font-size:12.5px; font-weight:600; text-decoration:none; }
.dc-cancel { border:1px solid #e2e8f0; color:#475569; background:#fff; }
.dc-delete { border:none; background:#dc2626; color:#fff; cursor:pointer; }
.dc-delete:hover { background:#b91c1c; }
.dc-empty { text-align:center; padding:28px 12px; }
.dc-empty i { font-size:34px; color:#94a3b8; margin-bottom:8px; display:block; }
.dc-empty-title { font-size:14px; color:var(--text-main); margin-bottom:10px; }
.dc-back-link { font-size:12.5px; color:#1d4ed8; text-decoration:none; font-weight:600; }
@media (max-width:900px) { .dc-body{padding:16px;} .dc-head{flex-direction:column;align-items:flex-start;} .dc-grid{grid-template-columns:1fr;} }
</style>