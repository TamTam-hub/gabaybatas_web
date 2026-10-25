<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/archive.php';
gabaybatas_archive_ensure_schema($conn);

$message = '';
$error = '';
$formatArchiveDate = static function ($value): string {
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('M d, Y h:i A', $timestamp) : 'N/A';
};
$caseSearch = trim((string)($_GET['case_search'] ?? ''));
$caseStatus = trim((string)($_GET['case_status'] ?? ''));
$userSearch = trim((string)($_GET['user_search'] ?? ''));
$userRole = trim((string)($_GET['user_role'] ?? ''));
$caseConditions = ['archived_at IS NOT NULL'];
$userConditions = ['archived_at IS NOT NULL'];
if ($caseSearch !== '') {
    $q = $conn->real_escape_string($caseSearch);
    $caseConditions[] = "(case_id LIKE '%$q%' OR name LIKE '%$q%' OR archive_reason LIKE '%$q%')";
}
if (in_array($caseStatus, ['Pending', 'In Progress', 'Resolved'], true)) {
    $caseConditions[] = "LOWER(status) = LOWER('" . $conn->real_escape_string($caseStatus) . "')";
} else {
    $caseStatus = '';
}
if ($userSearch !== '') {
    $q = $conn->real_escape_string($userSearch);
    $userConditions[] = "(name LIKE '%$q%' OR email LIKE '%$q%' OR archive_reason LIKE '%$q%')";
}
if ($userRole !== '') {
    $userConditions[] = "LOWER(role) = LOWER('" . $conn->real_escape_string($userRole) . "')";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entity = (string)($_POST['entity'] ?? '');
    $id = trim((string)($_POST['id'] ?? ''));
    $action = (string)($_POST['action'] ?? '');

    if ($id === '' || !in_array($entity, ['case', 'user'], true) || $action !== 'restore') {
        $error = 'Invalid archive action.';
    } elseif ($entity === 'case') {
        $stmt = $conn->prepare('UPDATE cases SET archived_at = NULL, archived_by = NULL, archive_reason = NULL WHERE id = ? AND archived_at IS NOT NULL');
        if (!$stmt) {
            $error = 'Unable to prepare case restoration.';
        } else {
            $stmt->bind_param('s', $id);
            $ok = $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($ok && $changed === 1) {
                gabaybatas_archive_audit($conn, 'case_restored', 'case', $id);
                $message = 'Case restored to the active case list.';
            } else {
                $error = 'Case was not restored. It may no longer be archived.';
            }
        }
    } else {
        $stmt = $conn->prepare(
            "UPDATE users
             SET status = COALESCE(NULLIF(archive_previous_status, ''), 'Inactive'),
                 archive_previous_status = NULL, archived_at = NULL, archived_by = NULL, archive_reason = NULL
             WHERE id = ? AND archived_at IS NOT NULL"
        );
        if (!$stmt) {
            $error = 'Unable to prepare user restoration.';
        } else {
            $stmt->bind_param('s', $id);
            $ok = $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($ok && $changed === 1) {
                gabaybatas_archive_audit($conn, 'user_restored', 'user', $id);
                $message = 'User restored to the active user list.';
            } else {
                $error = 'User was not restored. It may no longer be archived.';
            }
        }
    }
}

$archivedCases = $conn->query(
    "SELECT id, case_id, name, status, archive_reason, archived_at
     FROM cases WHERE " . implode(' AND ', $caseConditions) . " ORDER BY archived_at DESC"
);
$archivedUsers = $conn->query(
    "SELECT id, name, email, role, archive_reason, archived_at
     FROM users WHERE " . implode(' AND ', $userConditions) . " ORDER BY archived_at DESC"
);
$roleOptions = [];
$roleResult = $conn->query("SELECT DISTINCT role FROM users WHERE archived_at IS NOT NULL AND role IS NOT NULL AND TRIM(role) <> '' ORDER BY role");
if ($roleResult) {
    while ($roleRow = $roleResult->fetch_assoc()) {
        $roleOptions[] = (string)$roleRow['role'];
    }
}
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left"><div class="topbar-title">Records Archive</div><span class="topbar-badge">Admin</span></div>
        <div class="topbar-right"><div class="user-pill"><div class="user-avatar"><i class="uil uil-user"></i></div><div class="user-text"><strong><?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></strong><span>Administrator</span></div></div></div>
    </div>
    <div class="page-body ar-body">
        <div class="ar-head">
            <div><p>Archived records remain preserved for official and legal reference.</p></div>
            <a href="dashboard.php" class="ar-back"><i class="uil uil-arrow-left"></i> Home Dashboard</a>
        </div>
        <?php if ($message !== ''): ?><div class="ar-alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="ar-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <section class="ar-card">
            <div class="ar-section-head"><div><h2>Archived Cases</h2><span><?= $archivedCases ? $archivedCases->num_rows : 0 ?> records</span></div></div>
            <form method="GET" class="ar-filter-bar">
                <label class="ar-search"><i class="uil uil-search"></i><input name="case_search" value="<?= htmlspecialchars($caseSearch) ?>" placeholder="Search case ID, resident, or reason"></label>
                <select name="case_status"><option value="">All statuses</option><?php foreach (['Pending','In Progress','Resolved'] as $status): ?><option value="<?= $status ?>" <?= $caseStatus === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select>
                <input type="hidden" name="user_search" value="<?= htmlspecialchars($userSearch) ?>"><input type="hidden" name="user_role" value="<?= htmlspecialchars($userRole) ?>">
                <button type="submit" class="ar-filter-btn"><i class="uil uil-filter"></i> Filter</button>
                <a class="ar-clear" href="archive.php">Clear</a>
            </form>
            <div class="ar-table-wrap"><table class="ar-table"><thead><tr><th>Case ID</th><th>Resident</th><th>Status</th><th>Archived</th><th>Reason</th><th></th></tr></thead><tbody>
            <?php if ($archivedCases && $archivedCases->num_rows > 0): while ($row = $archivedCases->fetch_assoc()): ?>
                <tr><td><?= htmlspecialchars((string)($row['case_id'] ?: 'C-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT))) ?></td><td><?= htmlspecialchars((string)$row['name']) ?></td><td><?= htmlspecialchars((string)$row['status']) ?></td><td><?= htmlspecialchars($formatArchiveDate($row['archived_at'])) ?></td><td><?= htmlspecialchars((string)$row['archive_reason']) ?></td><td><form method="POST" class="ar-restore-form"><input type="hidden" name="entity" value="case"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="restore"><button class="ar-restore" type="submit">Restore</button></form></td></tr>
            <?php endwhile; else: ?><tr><td colspan="6" class="ar-empty">No archived cases.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>

        <section class="ar-card">
            <div class="ar-section-head"><div><h2>Archived Users</h2><span><?= $archivedUsers ? $archivedUsers->num_rows : 0 ?> records</span></div></div>
            <form method="GET" class="ar-filter-bar">
                <label class="ar-search"><i class="uil uil-search"></i><input name="user_search" value="<?= htmlspecialchars($userSearch) ?>" placeholder="Search name, email, or reason"></label>
                <select name="user_role"><option value="">All roles</option><?php foreach ($roleOptions as $role): ?><option value="<?= htmlspecialchars($role) ?>" <?= $userRole === $role ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($role)) ?></option><?php endforeach; ?></select>
                <input type="hidden" name="case_search" value="<?= htmlspecialchars($caseSearch) ?>"><input type="hidden" name="case_status" value="<?= htmlspecialchars($caseStatus) ?>">
                <button type="submit" class="ar-filter-btn"><i class="uil uil-filter"></i> Filter</button>
                <a class="ar-clear" href="archive.php">Clear</a>
            </form>
            <div class="ar-table-wrap"><table class="ar-table"><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Archived</th><th>Reason</th><th></th></tr></thead><tbody>
            <?php if ($archivedUsers && $archivedUsers->num_rows > 0): while ($row = $archivedUsers->fetch_assoc()): ?>
                <tr><td><?= htmlspecialchars((string)$row['name']) ?></td><td><?= htmlspecialchars((string)$row['email']) ?></td><td><?= htmlspecialchars((string)$row['role']) ?></td><td><?= htmlspecialchars($formatArchiveDate($row['archived_at'])) ?></td><td><?= htmlspecialchars((string)$row['archive_reason']) ?></td><td><form method="POST" class="ar-restore-form"><input type="hidden" name="entity" value="user"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="restore"><button class="ar-restore" type="submit">Restore</button></form></td></tr>
            <?php endwhile; else: ?><tr><td colspan="6" class="ar-empty">No archived users.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </div>
</div>

<script>
document.querySelectorAll('.ar-restore-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (window.AdminConfirm) {
            const confirmed = await window.AdminConfirm.confirm({
                title: 'Restore this record?',
                message: 'The record will return to the active system views. Its archive history will remain in the audit log.',
                confirmText: 'Restore Record',
                cancelText: 'Cancel'
            });
            if (!confirmed) return;
        }
        form.submit();
    });
});
</script>
<style>
.ar-body{padding:28px}.ar-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px}.ar-head p{margin:5px 0 0;color:#64748b;font-size:13px}.ar-back{color:#1d4ed8;background:#eff6ff;border:1px solid #dbeafe;border-radius:9px;padding:9px 13px;text-decoration:none;font-size:12px;font-weight:700}.ar-alert{padding:11px 14px;border-radius:9px;margin-bottom:14px;font-size:13px}.ar-alert.success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.ar-alert.error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}.ar-card{background:#fff;border:1px solid #e5eaf1;border-radius:14px;margin-bottom:18px;overflow:hidden;box-shadow:0 5px 18px rgba(15,23,42,.04)}.ar-section-head{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:1px solid #edf1f5}.ar-section-head h2{margin:0;font-size:15px;color:#172b4d}.ar-section-head span{font-size:12px;color:#64748b}.ar-filter-bar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;padding:13px 18px;background:#fbfdff;border-bottom:1px solid #edf1f5}.ar-search{position:relative;flex:1 1 260px;min-width:200px}.ar-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#94a3b8}.ar-search input,.ar-filter-bar select{height:36px;width:100%;border:1px solid #dbe3ef;border-radius:8px;background:#fff;color:#334155;font-size:12px;padding:0 10px}.ar-search input{padding-left:32px}.ar-filter-bar select{width:auto;min-width:145px}.ar-filter-btn,.ar-clear{height:36px;border-radius:8px;padding:0 12px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:5px;text-decoration:none}.ar-filter-btn{border:1px solid #2563eb;background:#2563eb;color:#fff;cursor:pointer}.ar-clear{border:1px solid #dbe3ef;background:#fff;color:#64748b}.ar-table-wrap{overflow:auto}.ar-table{width:100%;border-collapse:collapse;font-size:12.5px}.ar-table th,.ar-table td{text-align:left;padding:12px 14px;border-bottom:1px solid #f1f5f9;vertical-align:top}.ar-table th{color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.4px;background:#f8fafc}.ar-table td{color:#334155}.ar-empty{text-align:center;color:#94a3b8;padding:25px!important}.ar-restore{border:1px solid #bbf7d0;background:#f0fdf4;color:#15803d;border-radius:7px;padding:6px 10px;font-size:12px;font-weight:700;cursor:pointer}
@media(max-width:760px){.ar-body{padding:18px 12px}.ar-head{align-items:flex-start;flex-direction:column}.ar-card{border-radius:10px}.ar-table{min-width:760px}.ar-section-head{padding:13px 14px}.ar-filter-bar{align-items:stretch;padding:12px}.ar-search,.ar-filter-bar select,.ar-filter-btn,.ar-clear{width:100%;flex-basis:100%}}
</style>
