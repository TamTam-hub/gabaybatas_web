<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/archive.php';
gabaybatas_archive_ensure_schema($conn);
require_once '../config/archive.php';
gabaybatas_archive_ensure_schema($conn);

$userColumns = [];
$userColResult = $conn->query("SHOW COLUMNS FROM users");
if ($userColResult) {
    while ($col = $userColResult->fetch_assoc()) {
        $userColumns[] = $col['Field'];
    }
}

$hasColumn = static function (string $column) use ($userColumns): bool {
    return in_array($column, $userColumns, true);
};

$idColumn = $hasColumn('id') ? 'id' : ($userColumns[0] ?? null);
$userId = trim((string)($_GET['id'] ?? ''));
$error = '';
$success = '';
$reason = trim((string)($_POST['archive_reason'] ?? ''));
$user = null;

if ($idColumn === null) {
    $error = 'Users table is missing an identifier column.';
}

if ($error === '' && $userId === '') {
    $error = 'Missing user id.';
}

if ($error === '') {
    $selectFields = [];
    if ($hasColumn('name'))     { $selectFields[] = 'name'; }
    if ($hasColumn('email'))    { $selectFields[] = 'email'; }
    if ($hasColumn('role'))     { $selectFields[] = 'role'; }

    if (!$selectFields) {
        $error = 'No readable user fields are available.';
    } else {
        $sql = 'SELECT ' . implode(', ', $selectFields) . " FROM users WHERE $idColumn = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }

        if (!$user) {
            $error = 'User not found.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && $user !== null) {
    $confirmToken = (string)($_POST['confirm_token'] ?? '');
    $expectedToken = md5($userId . session_id());

    if ($confirmToken !== $expectedToken) {
        $error = 'Invalid confirmation. Please try again.';
    } else {
        if ($reason === '') {
            $error = 'Please provide a reason for archiving this user.';
        }
    }

    if ($error === '') {
        $sql = "UPDATE users
            SET archive_previous_status = status, status = 'Archived', archived_at = NOW(), archived_by = ?, archive_reason = ?
            WHERE $idColumn = ? AND (archived_at IS NULL)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $stmt->bind_param('iss', $adminId, $reason, $userId);
            if ($stmt->execute()) {
                if ($stmt->affected_rows === 1) {
                    gabaybatas_archive_audit($conn, 'user_archived', 'user', $userId, $reason, $user, [
                        'status' => 'Archived',
                        'archived_at' => date('Y-m-d H:i:s'),
                        'archive_reason' => $reason,
                    ]);
                    header('Location: manage_users.php?archived=1');
                    exit();
                }
                $error = 'This user is already archived or could not be updated.';
            } else {
                $error = 'Unable to archive user. Please try again.';
            }
            $stmt->close();
        } else {
            $error = 'Unable to prepare delete query.';
        }
    }
}

$confirmToken = md5($userId . session_id());
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
<div class="topbar">
    <div class="topbar-left">
        <div class="topbar-title">Archive User</div>
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

<div class="page-body du-body">

    <div class="du-head fade-up" style="--d:0s">
        <p class="du-sub">Deactivate a user account while preserving its historical relationships.</p>
        <a href="manage_users.php" class="du-back-btn"><i class="uil uil-arrow-left"></i> Back to Users</a>
    </div>

    <div class="du-card fade-up" style="--d:.08s">

        <?php if ($error !== ''): ?>
            <div class="du-alert error"><i class="uil uil-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($error !== '' && $user === null): ?>
            <div class="du-empty">
                <i class="uil uil-folder-open"></i>
                <div class="du-empty-title"><?= htmlspecialchars($error) ?></div>
                <a href="manage_users.php" class="du-back-link">Return to user list</a>
            </div>
        <?php else: ?>
            <div class="du-warning">
                <div class="du-warning-icon"><i class="uil uil-exclamation-triangle"></i></div>
                <div class="du-warning-content">
                    <div class="du-warning-title">Confirm user archiving</div>
                    <p class="du-warning-text">
                        This account will no longer be able to log in or appear as an active user. Its historical records will be preserved.
                    </p>
                </div>
            </div>

            <div class="du-user-info">
                <div class="du-info-item">
                    <span class="du-label">Name</span>
                    <span class="du-value"><?= htmlspecialchars((string)($user['name'] ?? 'N/A')) ?></span>
                </div>
                <div class="du-info-item">
                    <span class="du-label">Email</span>
                    <span class="du-value"><?= htmlspecialchars((string)($user['email'] ?? 'N/A')) ?></span>
                </div>
                <div class="du-info-item">
                    <span class="du-label">Role</span>
                    <span class="du-value"><?php $duRole = strtolower(trim((string)($user['role'] ?? ''))); ?><?= htmlspecialchars(['admin' => 'Administrator', 'barangay' => 'Barangay Official', 'resident' => 'Resident'][$duRole] ?? ($duRole !== '' ? ucwords($duRole) : 'N/A')) ?></span>
                </div>
            </div>

            <form method="POST" class="du-form" id="duDeleteForm">
                <input type="hidden" name="confirm_token" value="<?= htmlspecialchars($confirmToken) ?>">
                <label class="du-label" for="archive_reason">Reason for archiving</label>
                <textarea id="archive_reason" name="archive_reason" required maxlength="500" class="du-reason" placeholder="Explain why this user is being archived"><?= htmlspecialchars($reason) ?></textarea>
                <div class="du-actions">
                    <a href="manage_users.php" class="du-cancel">Cancel</a>
                    <button type="button" class="du-delete" id="duDeleteBtn"><i class="uil uil-archive"></i> Archive User</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
</div>

<script>
const duObserver = new IntersectionObserver(entries => {
    entries.forEach(en => {
        if (en.isIntersecting) {
            en.target.classList.add('visible');
            duObserver.unobserve(en.target);
        }
    });
}, { threshold: 0.1 });
document.querySelectorAll('.fade-up').forEach(el => duObserver.observe(el));

const duDeleteBtn = document.getElementById('duDeleteBtn');
const duDeleteForm = document.getElementById('duDeleteForm');
if (duDeleteBtn && duDeleteForm && window.AdminConfirm) {
    duDeleteBtn.addEventListener('click', async function () {
        const ok = await window.AdminConfirm.confirm({
            title: 'Archive This User?',
            message: 'The account will be deactivated, while historical records are preserved.',
            confirmText: 'Archive User',
            cancelText: 'Cancel'
        });
        if (ok) duDeleteForm.submit();
    });
}
</script>

<style>
.du-body { padding: 24px 28px 40px; }
.fade-up { opacity: 0; transform: translateY(20px); transition: opacity .5s ease var(--d,0s), transform .5s ease var(--d,0s); }
.fade-up.visible { opacity: 1; transform: none; }

.du-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 14px; }
.du-sub { font-size: 12.5px; color: var(--text-sub); margin: 0; }
.du-back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; font-size: 12.5px; font-weight: 600;
    color: #1d4ed8; background: #eff6ff; border: 1px solid #dbeafe;
    border-radius: 9px; padding: 8px 13px; transition: all .2s;
}
.du-back-btn:hover { background: #dbeafe; }

.du-card {
    background: #fff; border: 1px solid #f1f5f9; border-radius: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04); padding: 20px;
}

.du-alert {
    display: flex; align-items: center; gap: 8px;
    border-radius: 10px; padding: 10px 12px; font-size: 12.5px;
    margin-bottom: 12px;
}
.du-reason { display:block; width:100%; min-height:78px; resize:vertical; margin:7px 0 14px; border:1px solid #cbd5e1; border-radius:9px; padding:9px 10px; font:inherit; font-size:12.5px; color:#1e293b; }
.du-alert.error { background: #fff1f2; border: 1px solid #fecdd3; color: #be123c; }

.du-warning {
    display: flex; gap: 12px; background: #fef2f2; border: 1px solid #fecdd3; border-radius: 12px;
    padding: 14px 16px; margin-bottom: 16px;
}
.du-warning-icon {
    font-size: 20px; color: #dc2626; flex-shrink: 0; display: flex; align-items: center;
}
.du-warning-content { flex: 1; }
.du-warning-title { font-size: 13px; font-weight: 700; color: #dc2626; margin-bottom: 4px; }
.du-warning-text { font-size: 12.5px; color: #7f1d1d; line-height: 1.5; margin: 0; }

.du-user-info {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 14px 16px; margin-bottom: 16px;
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
}
.du-info-item { display: flex; flex-direction: column; gap: 4px; }
.du-label { font-size: 11px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: var(--text-sub); }
.du-value { font-size: 13px; color: var(--text-main); }

.du-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.du-cancel {
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12.5px; font-weight: 600; text-decoration: none;
    border: 1px solid #e2e8f0; color: #475569; background: #fff;
    border-radius: 9px; padding: 9px 14px; cursor: pointer;
}
.du-delete {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    border: none; border-radius: 9px; cursor: pointer;
    font-size: 12.5px; font-weight: 700; color: #fff;
    background: #dc2626; padding: 9px 15px;
}
.du-delete:hover { background: #b91c1c; }

.du-empty { text-align: center; padding: 28px 12px; }
.du-empty i { font-size: 34px; color: #94a3b8; margin-bottom: 8px; display: block; }
.du-empty-title { font-size: 14px; color: var(--text-main); margin-bottom: 10px; }
.du-back-link { font-size: 12.5px; color: #1d4ed8; text-decoration: none; font-weight: 600; }

@media (max-width: 900px) {
    .du-body { padding: 16px; }
    .du-head { flex-direction: column; align-items: flex-start; }
    .du-user-info { grid-template-columns: 1fr; }
}
</style>
