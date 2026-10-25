<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/barangays.php';

$userColumns = [];
$userColResult = $conn->query("SHOW COLUMNS FROM users");
if ($userColResult) {
    while ($col = $userColResult->fetch_assoc()) {
        $userColumns[] = $col['Field'];
    }
}
if (!in_array('status', $userColumns, true)) {
    $conn->query("ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active'");
    $userColResult = $conn->query("SHOW COLUMNS FROM users");
    $userColumns = [];
    if ($userColResult) {
        while ($col = $userColResult->fetch_assoc()) {
            $userColumns[] = $col['Field'];
        }
    }
}

$hasColumn = static function (string $column) use ($userColumns): bool {
    return in_array($column, $userColumns, true);
};

$idColumn = $hasColumn('id') ? 'id' : ($userColumns[0] ?? null);
$userId = trim((string)($_GET['id'] ?? ''));
$error = '';
$success = '';
$toastType = '';
$toastTitle = '';
$toastMessage = '';
$user = null;

// Check if page loaded after successful save
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $success = 'User account updated successfully.';
    $toastType = 'success';
    $toastTitle = 'User Updated';
    $toastMessage = 'Account details were saved successfully.';
}

if ($idColumn === null) {
    $error = 'Users table is missing an identifier column.';
}

if ($error === '' && $userId === '') {
    $error = 'Missing user id.';
}

if ($error === '') {
    $selectFields = [];
    if ($hasColumn('name'))     { $selectFields[] = 'name'; }
    if ($hasColumn('username')) { $selectFields[] = 'username'; }
    if ($hasColumn('email'))    { $selectFields[] = 'email'; }
    if ($hasColumn('age'))      { $selectFields[] = 'age'; }
    if ($hasColumn('role'))     { $selectFields[] = 'role'; }
    if ($hasColumn('barangay')) { $selectFields[] = 'barangay'; }
    if ($hasColumn('status'))   { $selectFields[] = 'status'; }
    if ($hasColumn('created_at')) { $selectFields[] = 'created_at'; }
    if ($hasColumn('last_login')) { $selectFields[] = 'last_login'; }

    if (!$selectFields) {
        $error = 'No editable user fields are available.';
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

$form = [
    'name' => '',
    'username' => '',
    'email' => '',
    'age' => '',
    'role' => 'resident',
    'barangay' => 'Barangay Muñoz',
    'status' => 'Active',
];

if ($user) {
    $form['name'] = trim((string)($user['name'] ?? ''));
    $form['username'] = trim((string)($user['username'] ?? ''));
    $form['email'] = trim((string)($user['email'] ?? ''));
    $form['age'] = trim((string)($user['age'] ?? ''));
    $form['role'] = strtolower(trim((string)($user['role'] ?? 'resident')));
    if ($form['role'] === '') {
        $form['role'] = 'resident';
    }
    $form['barangay'] = 'Barangay Muñoz';
    $form['status'] = trim((string)($user['status'] ?? 'Active'));
    
    // Normalize barangay to match valid options (case-insensitive)
    if ($form['barangay'] !== '') {
        $validBarangays = gabaybatas_get_barangays();
        foreach ($validBarangays as $validBrgy) {
            if (strtolower($form['barangay']) === strtolower($validBrgy)) {
                $form['barangay'] = $validBrgy;
                break;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $form['name'] = trim((string)($_POST['name'] ?? ''));
    $form['email'] = trim((string)($_POST['email'] ?? ''));
    $form['age'] = trim((string)($_POST['age'] ?? ''));
    $form['role'] = strtolower(trim((string)($_POST['role'] ?? 'resident')));
    $form['barangay'] = 'Barangay Muñoz';
    $form['status'] = trim((string)($_POST['status'] ?? 'Active'));

    // Normalize barangay to match valid options (case-insensitive)
    if ($form['barangay'] !== '') {
        $validBarangays = gabaybatas_get_barangays();
        foreach ($validBarangays as $validBrgy) {
            if (strtolower($form['barangay']) === strtolower($validBrgy)) {
                $form['barangay'] = $validBrgy;
                break;
            }
        }
    }

    $allowedRoles = ['resident', 'barangay', 'admin'];
    if (!in_array($form['role'], $allowedRoles, true)) {
        $form['role'] = 'resident';
    }

    {
        $setParts = [];
        $types = '';
        $values = [];

        $current = [
            'name' => trim((string)($user['name'] ?? '')),
            'username' => trim((string)($user['username'] ?? '')),
            'email' => trim((string)($user['email'] ?? '')),
            'age' => trim((string)($user['age'] ?? '')),
            'role' => trim((string)($user['role'] ?? '')),
            'barangay' => trim((string)($user['barangay'] ?? '')),
            'status' => trim((string)($user['status'] ?? '')),
        ];

        // Normalize barangay in current values to match form values (case-insensitive)
        if ($current['barangay'] !== '') {
            $validBarangays = gabaybatas_get_barangays();
            foreach ($validBarangays as $validBrgy) {
                if (strtolower($current['barangay']) === strtolower($validBrgy)) {
                    $current['barangay'] = $validBrgy;
                    break;
                }
            }
        }

        if ($hasColumn('name')) {
            if ($current['name'] !== $form['name']) {
                $setParts[] = 'name = ?';
                $types .= 's';
                $values[] = $form['name'];
            }
        }
        if ($hasColumn('email')) {
            if ($current['email'] !== $form['email']) {
                $setParts[] = 'email = ?';
                $types .= 's';
                $values[] = $form['email'];
            }
            if ($hasColumn('age') && $current['age'] !== $form['age']) {
                $setParts[] = 'age = ?';
                $types .= 's';
                $values[] = $form['age'];
            }
        }
        if ($hasColumn('role')) {
            if ($current['role'] !== $form['role']) {
                $setParts[] = 'role = ?';
                $types .= 's';
                $values[] = $form['role'];
            }
        }
        if ($hasColumn('barangay')) {
            if ($current['barangay'] !== $form['barangay']) {
                $setParts[] = 'barangay = ?';
                $types .= 's';
                $values[] = $form['barangay'];
            }
        }
        if ($hasColumn('status')) {
            if ($current['status'] !== $form['status']) {
                $setParts[] = 'status = ?';
                $types .= 's';
                $values[] = $form['status'];
            }
        }
        if (!$setParts) {
            $success = 'No changes detected.';
            $toastType = 'info';
            $toastTitle = 'No Changes';
            $toastMessage = 'No fields were changed, so nothing was updated.';
        } else {
            $sql = 'UPDATE users SET ' . implode(', ', $setParts) . " WHERE $idColumn = ?";
            $types .= 's';
            $values[] = $userId;

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $error = 'Unable to prepare update query.';
            } else {
                $stmt->bind_param($types, ...$values);
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $success = 'User account updated successfully.';
                        $toastType = 'success';
                        $toastTitle = 'User Updated';
                        $toastMessage = 'Account details were saved successfully.';
                        
                        // Redirect to prevent POST resubmission
                        $stmt->close();
                        header('Location: edit_user.php?id=' . urlencode($userId) . '&saved=1');
                        exit();
                    } else {
                        $success = 'No changes detected.';
                        $toastType = 'info';
                        $toastTitle = 'No Changes';
                        $toastMessage = 'No fields were changed, so nothing was updated.';
                    }
                } else {
                    $error = 'Unable to update user.';
                }
                $stmt->close();

                if ($error === '') {
                    if ($hasColumn('name')) { $user['name'] = $form['name']; }
                    if ($hasColumn('username')) { $user['username'] = $form['username']; }
                    if ($hasColumn('email')) { $user['email'] = $form['email']; }
                    if ($hasColumn('role')) { $user['role'] = $form['role']; }
                    if ($hasColumn('barangay')) { $user['barangay'] = $form['barangay']; }
                    if ($hasColumn('status')) { $user['status'] = $form['status']; }
                }
            }
        }
    }
}
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
<div class="topbar">
    <div class="topbar-left">
        <div class="topbar-title">Edit User</div>
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

<div class="page-body eu-body">

    <div class="eu-head fade-up" style="--d:0s">
        <p class="eu-sub">Update user profile details, role assignment, and account status.</p>
        <a href="manage_users.php" class="eu-back-btn"><i class="uil uil-arrow-left"></i> Back to Users</a>
    </div>

    <div class="eu-card fade-up" style="--d:.08s">

        <?php if ($error !== ''): ?>
            <div class="eu-alert error"><i class="uil uil-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($error === '' || $user !== null): ?>
        <div class="eu-account-meta">
            <div><span>Account Created</span><strong><?= !empty($user['created_at']) && strtotime((string)$user['created_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string)$user['created_at']))) : 'N/A' ?></strong></div>
            <div><span>Last Login</span><strong><?= !empty($user['last_login']) && strtotime((string)$user['last_login']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string)$user['last_login']))) : 'Never' ?></strong></div>
        </div>
        <form method="POST" class="eu-form">
            <div class="eu-grid">

                <div class="eu-field">
                    <label>Full Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($form['name']) ?>" placeholder="Enter full name">
                </div>

                <div class="eu-field">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($form['email']) ?>" placeholder="name@example.com">
                </div>

                <div class="eu-field">
                    <label>Age</label>
                    <input type="text" name="age" value="<?= htmlspecialchars($form['age'] ?? '') ?>" placeholder="Enter age">
                </div>

                <div class="eu-field">
                    <label>Role</label>
                    <select name="role">
                        <option value="resident" <?= strtolower($form['role']) === 'resident' ? 'selected' : '' ?>>Resident</option>
                        <option value="barangay" <?= strtolower($form['role']) === 'barangay' ? 'selected' : '' ?>>Barangay</option>
                        <option value="admin" <?= strtolower($form['role']) === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>

                <div class="eu-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="Active" <?= strtolower($form['status']) === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= strtolower($form['status']) === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="eu-field eu-field-full">
                    <label>Barangay</label>
                    <input type="text" value="Barangay Muñoz" readonly>
                    <input type="hidden" name="barangay" value="Barangay Muñoz">
                </div>

            </div>

            <div class="eu-actions">
                <a href="manage_users.php" class="eu-cancel">Cancel</a>
                <button type="submit" class="eu-submit"><i class="uil uil-save"></i> Save Changes</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
</div>

<script>
const euObserver = new IntersectionObserver(entries => {
    entries.forEach(en => {
        if (en.isIntersecting) {
            en.target.classList.add('visible');
            euObserver.unobserve(en.target);
        }
    });
}, { threshold: 0.1 });
document.querySelectorAll('.fade-up').forEach(el => euObserver.observe(el));
<?php if ($toastType !== ''): ?>
if (window.AdminToast) {
    window.AdminToast.show('<?= htmlspecialchars($toastType, ENT_QUOTES) ?>', '<?= htmlspecialchars($toastTitle, ENT_QUOTES) ?>', '<?= htmlspecialchars($toastMessage, ENT_QUOTES) ?>');
}
<?php endif; ?>
</script>

<style>
.eu-body { padding: 24px 28px 40px; }
.fade-up { opacity: 0; transform: translateY(20px); transition: opacity .5s ease var(--d,0s), transform .5s ease var(--d,0s); }
.fade-up.visible { opacity: 1; transform: none; }

.eu-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 14px; }
.eu-sub { font-size: 12.5px; color: var(--text-sub); margin: 0; }
.eu-back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; font-size: 12.5px; font-weight: 600;
    color: #1d4ed8; background: #eff6ff; border: 1px solid #dbeafe;
    border-radius: 9px; padding: 8px 13px; transition: all .2s;
}
.eu-back-btn:hover { background: #dbeafe; }

.eu-card {
    background: #fff; border: 1px solid #f1f5f9; border-radius: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04); padding: 20px;
}

.eu-alert {
    display: flex; align-items: center; gap: 8px;
    border-radius: 10px; padding: 10px 12px; font-size: 12.5px;
    margin-bottom: 12px;
}
.eu-alert.error { background: #fff1f2; border: 1px solid #fecdd3; color: #be123c; }
.eu-account-meta { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px; }
.eu-account-meta > div { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:11px 13px; }
.eu-account-meta span { display:block; color:var(--text-sub); font-size:10px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; margin-bottom:5px; }
.eu-account-meta strong { color:var(--text-main); font-size:13px; font-weight:600; }

.eu-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.eu-field { display: flex; flex-direction: column; gap: 6px; }
.eu-field label {
    font-size: 11px; letter-spacing: .6px; text-transform: uppercase;
    color: var(--text-sub); font-weight: 700;
}
.eu-field input,
.eu-field select {
    width: 100%; background: #fafbff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 10px 12px; font-size: 13px; color: var(--text-main); outline: none;
}
.eu-field input:focus,
.eu-field select:focus { border-color: #3b82f6; }
.eu-field-full { grid-column: 1 / -1; }

.eu-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.eu-cancel {
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12.5px; font-weight: 600; text-decoration: none;
    border: 1px solid #e2e8f0; color: #475569; background: #fff;
    border-radius: 9px; padding: 9px 14px;
}
.eu-submit {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    border: none; border-radius: 9px; cursor: pointer;
    font-size: 12.5px; font-weight: 700; color: #fff;
    background: #1d4ed8; padding: 9px 15px;
}
.eu-submit:hover { background: #1e40af; }

@media (max-width: 900px) {
    .eu-body { padding: 16px; }
    .eu-head { flex-direction: column; align-items: flex-start; }
    .eu-grid { grid-template-columns: 1fr; }
    .eu-account-meta { grid-template-columns: 1fr; }
}
</style>
