<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';

gabaybatas_block_barangay_delete_requests();

$message = '';
$messageType = '';
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $hasUpper = preg_match('/[A-Z]/', $newPassword) === 1;
    $hasLower = preg_match('/[a-z]/', $newPassword) === 1;
    $hasNumber = preg_match('/\d/', $newPassword) === 1;
    $hasSymbol = preg_match('/[^A-Za-z0-9]/', $newPassword) === 1;

    // Validation
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $message = 'All fields are required.';
        $messageType = 'error';
    } elseif (strlen($newPassword) < 8) {
        $message = 'New password must be at least 8 characters long.';
        $messageType = 'error';
    } elseif (!$hasUpper || !$hasLower || !$hasNumber || !$hasSymbol) {
        $message = 'New password must include uppercase, lowercase, number, and symbol.';
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'New passwords do not match.';
        $messageType = 'error';
    } elseif ($currentPassword === $newPassword) {
        $message = 'New password must be different from current password.';
        $messageType = 'error';
    } else {
        if ($userId > 0) {
            $verifyStmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            if (!$verifyStmt) {
                $message = 'Unable to verify your account. Please try again.';
                $messageType = 'error';
            } else {
                $verifyStmt->bind_param('i', $userId);
                $verifyStmt->execute();
                $verifyResult = $verifyStmt->get_result();
                $userRow = $verifyResult ? $verifyResult->fetch_assoc() : null;
                $verifyStmt->close();

                if (!$userRow) {
                    $message = 'User account not found. Please log in again.';
                    $messageType = 'error';
                } else {
                    $storedPassword = (string)($userRow['password'] ?? '');
                    $isCurrentPasswordValid = password_verify($currentPassword, $storedPassword) || $currentPassword === $storedPassword;

                    if (!$isCurrentPasswordValid) {
                        $message = 'Current password is incorrect.';
                        $messageType = 'error';
                    } else {
                        $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $updateStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ? LIMIT 1');

                        if (!$updateStmt) {
                            $message = 'Error updating password. Please try again.';
                            $messageType = 'error';
                        } else {
                            $updateStmt->bind_param('si', $hashedNewPassword, $userId);
                            if ($updateStmt->execute()) {
                                $message = 'Password changed successfully!';
                                $messageType = 'success';
                            } else {
                                $message = 'Error updating password. Please try again.';
                                $messageType = 'error';
                            }
                            $updateStmt->close();
                        }
                    }
                }
            }
        } else {
            $message = 'User session error. Please log in again.';
            $messageType = 'error';
        }
    }
}

$currentBarangay = gabaybatas_get_user_barangay($conn);
include('../includes/header.php');
include('../includes/sidebar_barangay.php');
?>

<div class="main">
    <div class="topbar">
        <div class="topbar-title">Change Password</div>
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

    <div class="page-body">
        <div class="page-header">
            <div>
                <div class="page-header-title">Change Password</div>
                <div class="page-header-sub">Update your account password to keep your account secure</div>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <i class="uil uil-<?= ($messageType === 'success') ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <div class="password-layout">
            <div class="card-box form-card">
                <form method="POST" class="change-password-form" id="changePasswordForm">
                    <div class="form-group">
                        <label for="currentPassword" class="form-label">Current Password</label>
                        <div class="input-icon-wrap">
                            <i class="input-icon uil uil-lock-alt"></i>
                            <input 
                                type="password" 
                                id="currentPassword" 
                                name="current_password" 
                                class="form-input" 
                                placeholder="Enter your current password"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="newPassword" class="form-label">New Password</label>
                        <div class="input-icon-wrap">
                            <i class="input-icon uil uil-lock"></i>
                            <input 
                                type="password" 
                                id="newPassword" 
                                name="new_password" 
                                class="form-input" 
                                placeholder="Enter your new password (minimum 8 characters)"
                                required
                            >
                        </div>
                        <div class="form-hint">Must be at least 8 characters and different from your current password.</div>

                        <div class="password-health" id="passwordHealth" aria-live="polite">
                            <div class="password-health-head">
                                <span class="password-health-title">Password Health</span>
                                <span class="password-health-badge is-empty" id="healthBadge">Not Checked</span>
                            </div>
                            <div class="password-health-bar-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="healthBarWrap">
                                <div class="password-health-bar is-empty" id="healthBar"></div>
                            </div>
                            <div class="password-health-score" id="healthScoreText">Start typing a new password to see health score.</div>
                            <ul class="password-health-list" id="healthChecklist">
                                <li id="ruleLen">At least 8 characters</li>
                                <li id="ruleUpper">At least one uppercase letter</li>
                                <li id="ruleLower">At least one lowercase letter</li>
                                <li id="ruleNumber">At least one number</li>
                                <li id="ruleSymbol">At least one symbol</li>
                                <li id="ruleDiff">Different from current password</li>
                                <li id="ruleMatch">Matches confirmation password</li>
                            </ul>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirmPassword" class="form-label">Confirm New Password</label>
                        <div class="input-icon-wrap">
                            <i class="input-icon uil uil-lock"></i>
                            <input 
                                type="password" 
                                id="confirmPassword" 
                                name="confirm_password" 
                                class="form-input" 
                                placeholder="Re-enter your new password"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit" id="btnUpdatePassword" disabled>
                            <i class="uil uil-check"></i>
                            Update Password
                        </button>
                        <a href="profile.php" class="btn-cancel">
                            <i class="uil uil-times"></i>
                            Cancel
                        </a>
                    </div>
                </form>
            </div>

            <div class="card-box tips-card">
                <div class="tips-title"><i class="uil uil-info-circle"></i>Password Security Tips</div>
                <ul class="tips-list">
                    <li>Use a combination of uppercase letters, lowercase letters, numbers, and symbols.</li>
                    <li>Avoid using personal information like your name or barangay details.</li>
                    <li>Do not reuse your previous passwords.</li>
                    <li>Never share your password with anyone, including administrators.</li>
                    <li>Change your password regularly (recommended every 60–90 days).</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('changePasswordForm');
    const currentInput = document.getElementById('currentPassword');
    const newInput = document.getElementById('newPassword');
    const confirmInput = document.getElementById('confirmPassword');
    const submitBtn = document.getElementById('btnUpdatePassword');

    const badge = document.getElementById('healthBadge');
    const barWrap = document.getElementById('healthBarWrap');
    const bar = document.getElementById('healthBar');
    const scoreText = document.getElementById('healthScoreText');

    const ruleLen = document.getElementById('ruleLen');
    const ruleUpper = document.getElementById('ruleUpper');
    const ruleLower = document.getElementById('ruleLower');
    const ruleNumber = document.getElementById('ruleNumber');
    const ruleSymbol = document.getElementById('ruleSymbol');
    const ruleDiff = document.getElementById('ruleDiff');
    const ruleMatch = document.getElementById('ruleMatch');

    if (!form || !currentInput || !newInput || !confirmInput || !submitBtn || !badge || !bar || !barWrap || !scoreText) {
        return;
    }

    const commonWordPattern = /(password|qwerty|admin|welcome|gabay|barangay|1234|12345|123456)/i;
    const repeatedCharsPattern = /(.)\1{2,}/;

    function markRule(el, ok) {
        if (!el) return;
        el.classList.toggle('ok', ok);
    }

    function scorePassword(password, currentPassword) {
        const hasLen = password.length >= 8;
        const hasUpper = /[A-Z]/.test(password);
        const hasLower = /[a-z]/.test(password);
        const hasNumber = /\d/.test(password);
        const hasSymbol = /[^A-Za-z0-9]/.test(password);
        const hasLongLen = password.length >= 12;
        const hasCommonWord = commonWordPattern.test(password);
        const hasRepeatedChars = repeatedCharsPattern.test(password);
        const isDifferentFromCurrent = password !== '' && password !== currentPassword;

        let score = 0;
        if (hasLen) score += 20;
        if (hasUpper) score += 12;
        if (hasLower) score += 12;
        if (hasNumber) score += 16;
        if (hasSymbol) score += 16;
        if (hasLongLen) score += 12;
        if (!hasCommonWord) score += 8;
        if (!hasRepeatedChars) score += 4;
        if (isDifferentFromCurrent) score += 8;

        if (score > 100) score = 100;

        let level = 'Very Weak';
        let levelClass = 'is-very-weak';
        if (score >= 85) {
            level = 'Strong';
            levelClass = 'is-strong';
        } else if (score >= 70) {
            level = 'Good';
            levelClass = 'is-good';
        } else if (score >= 50) {
            level = 'Fair';
            levelClass = 'is-fair';
        } else if (score >= 30) {
            level = 'Weak';
            levelClass = 'is-weak';
        }

        return {
            score,
            level,
            levelClass,
            checks: {
                hasLen,
                hasUpper,
                hasLower,
                hasNumber,
                hasSymbol,
                isDifferentFromCurrent,
            },
        };
    }

    function refreshHealth() {
        const currentPassword = currentInput.value;
        const newPassword = newInput.value;
        const confirmPassword = confirmInput.value;

        if (newPassword === '') {
            badge.className = 'password-health-badge is-empty';
            badge.textContent = 'Not Checked';
            bar.className = 'password-health-bar is-empty';
            bar.style.width = '0%';
            barWrap.setAttribute('aria-valuenow', '0');
            scoreText.textContent = 'Start typing a new password to see health score.';

            markRule(ruleLen, false);
            markRule(ruleUpper, false);
            markRule(ruleLower, false);
            markRule(ruleNumber, false);
            markRule(ruleSymbol, false);
            markRule(ruleDiff, false);
            markRule(ruleMatch, false);

            submitBtn.disabled = true;
            return;
        }

        const result = scorePassword(newPassword, currentPassword);
        const isMatch = confirmPassword !== '' && newPassword === confirmPassword;

        badge.className = 'password-health-badge ' + result.levelClass;
        badge.textContent = result.level;

        bar.className = 'password-health-bar ' + result.levelClass;
        bar.style.width = result.score + '%';
        barWrap.setAttribute('aria-valuenow', String(result.score));

        scoreText.textContent = 'Health score: ' + result.score + '/100';

        markRule(ruleLen, result.checks.hasLen);
        markRule(ruleUpper, result.checks.hasUpper);
        markRule(ruleLower, result.checks.hasLower);
        markRule(ruleNumber, result.checks.hasNumber);
        markRule(ruleSymbol, result.checks.hasSymbol);
        markRule(ruleDiff, result.checks.isDifferentFromCurrent);
        markRule(ruleMatch, isMatch);

        const readyToSubmit = result.checks.hasLen
            && result.checks.hasUpper
            && result.checks.hasLower
            && result.checks.hasNumber
            && result.checks.hasSymbol
            && result.checks.isDifferentFromCurrent
            && isMatch
            && currentPassword !== '';

        submitBtn.disabled = !readyToSubmit;
    }

    currentInput.addEventListener('input', refreshHealth);
    newInput.addEventListener('input', refreshHealth);
    confirmInput.addEventListener('input', refreshHealth);
    refreshHealth();
})();
</script>

<style>
.page-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; padding:0; }
.page-header-title { font-family:'Source Serif 4',serif; font-size:22px; font-weight:700; color:var(--text-main); margin:0 0 4px; }
.page-header-sub { font-size:13.5px; color:var(--text-muted); margin:0; }
.password-layout { display:grid; grid-template-columns: minmax(420px, 1.5fr) minmax(320px, 1fr); gap:20px; align-items:start; }

.alert { padding:14px 16px; border-radius:6px; display:flex; align-items:center; gap:12px; margin-bottom:20px; font-size:13.5px; font-weight:500; }
.alert-success { background:var(--green-bg); color:var(--green); border:1px solid #a7f3cb; }
.alert-error { background:#fed7d7; color:#c53030; border:1px solid #fca5a5; }
.alert i { flex-shrink:0; font-size:18px; }

.form-card { min-height:100%; }
.change-password-form { display:flex; flex-direction:column; gap:18px; }
.form-group { display:flex; flex-direction:column; gap:6px; }
.form-label { font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.8px; color:var(--text-muted); }
.form-input { width:100%; padding:10px 12px 10px 38px; border:1px solid var(--border); border-radius:6px; font-size:13.5px; color:var(--text-main); background:#fff; transition:border-color .2s; }
.form-input:focus { outline:none; border-color:var(--navy); box-shadow:0 0 0 3px var(--blue-soft); }
.input-icon-wrap { position:relative; display:flex; align-items:center; }
.input-icon { position:absolute; left:12px; font-size:16px; color:var(--text-muted); pointer-events:none; }
.form-hint { font-size:12px; color:var(--text-muted); }
.form-actions { display:flex; gap:10px; margin-top:10px; }
.btn-submit, .btn-cancel { display:inline-flex; align-items:center; gap:7px; padding:10px 18px; border-radius:6px; font-size:13.5px; font-weight:600; text-decoration:none; transition:background .2s, border-color .2s; cursor:pointer; border:none; }
.btn-submit { background:var(--navy); color:#fff; }
.btn-submit:hover { background:var(--navy-dark); }
.btn-submit:disabled { opacity:.6; cursor:not-allowed; background:#94a3b8; }
.btn-cancel { background:var(--border); color:var(--text-main); border:1px solid var(--border); }
.btn-cancel:hover { background:#f0f3f8; }

.password-health { margin-top:10px; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; background:#f8fafc; }
.password-health-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:8px; }
.password-health-title { font-size:12px; font-weight:700; color:#334155; text-transform:uppercase; letter-spacing:.6px; }
.password-health-badge { font-size:11px; font-weight:700; border-radius:999px; padding:3px 8px; }
.password-health-badge.is-empty { background:#e2e8f0; color:#64748b; }
.password-health-badge.is-very-weak { background:#fee2e2; color:#b91c1c; }
.password-health-badge.is-weak { background:#ffedd5; color:#c2410c; }
.password-health-badge.is-fair { background:#fef9c3; color:#a16207; }
.password-health-badge.is-good { background:#dcfce7; color:#15803d; }
.password-health-badge.is-strong { background:#dbeafe; color:#1d4ed8; }
.password-health-bar-wrap { height:8px; border-radius:999px; overflow:hidden; background:#e2e8f0; margin-bottom:8px; }
.password-health-bar { height:100%; width:0; transition:width .25s ease, background-color .25s ease; }
.password-health-bar.is-empty { background:#cbd5e1; }
.password-health-bar.is-very-weak { background:#ef4444; }
.password-health-bar.is-weak { background:#f97316; }
.password-health-bar.is-fair { background:#eab308; }
.password-health-bar.is-good { background:#22c55e; }
.password-health-bar.is-strong { background:#2563eb; }
.password-health-score { font-size:12px; color:#64748b; margin-bottom:8px; }
.password-health-list { list-style:none; margin:0; padding:0; display:grid; gap:6px; }
.password-health-list li { font-size:12px; color:#64748b; display:flex; align-items:center; gap:7px; }
.password-health-list li::before { content:'•'; color:#94a3b8; font-size:13px; }
.password-health-list li.ok { color:#0f766e; }
.password-health-list li.ok::before { content:'✓'; color:#10b981; font-weight:700; }

.tips-card { background:#f7f9fc; border-left:4px solid var(--navy); min-height:100%; }
.tips-title { display:flex; align-items:center; gap:8px; font-size:13.5px; font-weight:700; color:var(--navy); margin-bottom:12px; }
.tips-title i { font-size:18px; }
.tips-list { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:8px; }
.tips-list li { display:flex; align-items:flex-start; gap:8px; font-size:13px; color:var(--text-main); }
.tips-list li::before { content:'✓'; color:var(--green); font-weight:700; margin-top:2px; }

@media (max-width: 980px) {
    .password-layout { grid-template-columns: 1fr; }
}

@media (max-width: 600px) {
    .page-header { flex-direction:column; }
}
</style>
