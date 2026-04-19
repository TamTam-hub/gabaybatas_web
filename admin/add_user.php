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

$hasColumn = static function (string $column) use ($userColumns): bool {
    return in_array($column, $userColumns, true);
};

$form = [
    'name' => '',
    'username' => '',
    'email' => '',
    'role' => 'resident',
    'barangay' => '',
    'status' => 'Active',
];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['name'] = trim((string)($_POST['name'] ?? ''));
    $form['username'] = trim((string)($_POST['username'] ?? ''));
    $form['email'] = trim((string)($_POST['email'] ?? ''));
    $form['role'] = strtolower(trim((string)($_POST['role'] ?? 'resident')));
    $form['barangay'] = trim((string)($_POST['barangay'] ?? ''));
    $form['status'] = trim((string)($_POST['status'] ?? 'Active'));
    $passwordRaw = (string)($_POST['password'] ?? '');

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

    if ($hasColumn('username') && $form['username'] === '') {
        $error = 'Username is required.';
    } elseif ($hasColumn('password') && $passwordRaw === '') {
        $error = 'Password is required.';
    } else {
        $columns = [];
        $placeholders = [];
        $types = '';
        $values = [];

        if ($hasColumn('name')) {
            $columns[] = 'name';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $form['name'];
        }
        if ($hasColumn('username')) {
            $columns[] = 'username';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $form['username'];
        }
        if ($hasColumn('email')) {
            $columns[] = 'email';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $form['email'];
        }
        if ($hasColumn('password')) {
            $columns[] = 'password';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = password_hash($passwordRaw, PASSWORD_DEFAULT);
        }
        if ($hasColumn('role')) {
            $columns[] = 'role';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $form['role'];
        }
        if ($hasColumn('barangay')) {
            $columns[] = 'barangay';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $form['barangay'];
        }
        if ($hasColumn('status')) {
            $columns[] = 'status';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $form['status'];
        }

        if (!$columns) {
            $error = 'Users table has no writable columns for this form.';
        } else {
            $sql = 'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $error = 'Unable to prepare user creation query.';
            } else {
                $stmt->bind_param($types, ...$values);
                if ($stmt->execute()) {
                    $success = 'User account created successfully.';
                    $form = [
                        'name' => '',
                        'username' => '',
                        'email' => '',
                        'role' => 'resident',
                        'barangay' => '',
                        'status' => 'Active',
                    ];
                } else {
                    $error = 'Unable to create user. It may already exist or fail constraints.';
                }
                $stmt->close();
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
        <div class="topbar-title">Add User</div>
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

<div class="page-body au-body">

    <div class="au-head fade-up" style="--d:0s">
        <p class="au-sub">Create a new account with role and barangay assignment.</p>
        <a href="manage_users.php" class="au-back-btn"><i class="uil uil-arrow-left"></i> Back to Users</a>
    </div>

    <div class="au-card fade-up" style="--d:.08s">

        <?php if ($error !== ''): ?>
            <div class="au-alert error"><i class="uil uil-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="au-form">
            <div class="au-grid">

                <div class="au-field">
                    <label>Full Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($form['name']) ?>" placeholder="Enter full name">
                </div>

                <div class="au-field">
                    <label>Username<?= $hasColumn('username') ? ' *' : '' ?></label>
                    <input type="text" name="username" value="<?= htmlspecialchars($form['username']) ?>" placeholder="Enter username">
                </div>

                <div class="au-field">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($form['email']) ?>" placeholder="name@example.com">
                </div>

                <div class="au-field">
                    <label>Password<?= $hasColumn('password') ? ' *' : '' ?></label>
                    <input type="password" name="password" placeholder="Enter password">
                </div>

                <div class="au-field">
                    <label>Role</label>
                    <select name="role">
                        <option value="resident" <?= strtolower($form['role']) === 'resident' ? 'selected' : '' ?>>Resident</option>
                        <option value="barangay" <?= strtolower($form['role']) === 'barangay' ? 'selected' : '' ?>>Barangay</option>
                        <option value="admin" <?= strtolower($form['role']) === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>

                <div class="au-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="Active" <?= strtolower($form['status']) === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= strtolower($form['status']) === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="au-field au-field-full">
                    <label>Barangay</label>
                    <select name="barangay">
                        <option value="">-- Select Barangay --</option>
                        <?php foreach (gabaybatas_get_barangays() as $brgy): ?>
                        <option value="<?= htmlspecialchars($brgy) ?>" <?= $form['barangay'] === $brgy ? 'selected' : '' ?>><?= htmlspecialchars($brgy) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                </div>

            </div>

            <div class="au-actions">
                <a href="manage_users.php" class="au-cancel">Cancel</a>
                <button type="submit" class="au-submit"><i class="uil uil-save"></i> Create User</button>
            </div>
        </form>
    </div>
</div>
</div>

<script>
const auObserver = new IntersectionObserver(entries => {
    entries.forEach(en => {
        if (en.isIntersecting) {
            en.target.classList.add('visible');
            auObserver.unobserve(en.target);
        }
    });
}, { threshold: 0.1 });
document.querySelectorAll('.fade-up').forEach(el => auObserver.observe(el));
<?php if ($success !== ''): ?>
if (window.AdminToast) {
    window.AdminToast.show('success', 'User Created', 'The new user account was created successfully.');
}
<?php endif; ?>
</script>

<style>
.au-body { padding: 24px 28px 40px; }
.fade-up { opacity: 0; transform: translateY(20px); transition: opacity .5s ease var(--d,0s), transform .5s ease var(--d,0s); }
.fade-up.visible { opacity: 1; transform: none; }

.au-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 14px; }
.au-sub { font-size: 12.5px; color: var(--text-sub); margin: 0; }
.au-back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; font-size: 12.5px; font-weight: 600;
    color: #1d4ed8; background: #eff6ff; border: 1px solid #dbeafe;
    border-radius: 9px; padding: 8px 13px; transition: all .2s;
}
.au-back-btn:hover { background: #dbeafe; }

.au-card {
    background: #fff; border: 1px solid #f1f5f9; border-radius: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04); padding: 20px;
}

.au-alert {
    display: flex; align-items: center; gap: 8px;
    border-radius: 10px; padding: 10px 12px; font-size: 12.5px;
    margin-bottom: 12px;
}
.au-alert.error { background: #fff1f2; border: 1px solid #fecdd3; color: #be123c; }

.au-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.au-field { display: flex; flex-direction: column; gap: 6px; }
.au-field label {
    font-size: 11px; letter-spacing: .6px; text-transform: uppercase;
    color: var(--text-sub); font-weight: 700;
}
.au-field input,
.au-field select {
    width: 100%; background: #fafbff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 10px 12px; font-size: 13px; color: var(--text-main); outline: none;
}
.au-field input:focus,
.au-field select:focus { border-color: #3b82f6; }
.au-field-full { grid-column: 1 / -1; }

.au-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.au-cancel {
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12.5px; font-weight: 600; text-decoration: none;
    border: 1px solid #e2e8f0; color: #475569; background: #fff;
    border-radius: 9px; padding: 9px 14px;
}
.au-submit {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    border: none; border-radius: 9px; cursor: pointer;
    font-size: 12.5px; font-weight: 700; color: #fff;
    background: #1d4ed8; padding: 9px 15px;
}
.au-submit:hover { background: #1e40af; }

@media (max-width: 900px) {
    .au-body { padding: 16px; }
    .au-head { flex-direction: column; align-items: flex-start; }
    .au-grid { grid-template-columns: 1fr; }
}
</style>
