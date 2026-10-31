<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/flexible_report.php';

gabaybatas_block_barangay_delete_requests();

$userId = (int)($_SESSION['user_id'] ?? 0);
$profileError = '';
$profileSuccess = '';

$userColumns = [];
$colResult = $conn->query("SHOW COLUMNS FROM users");
if ($colResult) {
    while ($col = $colResult->fetch_assoc()) {
        $userColumns[] = $col['Field'];
    }
}

$hasUserColumn = static function (string $column) use ($userColumns): bool {
    return in_array($column, $userColumns, true);
};

$hasUserEmail = $hasUserColumn('email');
$hasUserPhone = $hasUserColumn('phone');
$hasUserBarangay = $hasUserColumn('barangay');

if ($userId <= 0) {
    $profileError = 'User session is invalid. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile' && $profileError === '') {
    $nameInput = trim((string)($_POST['name'] ?? ''));
    $emailInput = trim((string)($_POST['email'] ?? ''));
    $phoneInput = trim((string)($_POST['phone'] ?? ''));

    if ($nameInput === '') {
        $profileError = 'Full name is required.';
    } elseif ($emailInput !== '' && !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $profileError = 'Please enter a valid email address.';
    } else {
        $existingName = '';
        $existingEmail = '';
        $existingPhone = '';

        $currentFields = ['name'];
        if ($hasUserEmail) {
            $currentFields[] = 'email';
        }
        if ($hasUserPhone) {
            $currentFields[] = 'phone';
        }

        $currentStmt = $conn->prepare('SELECT ' . implode(', ', $currentFields) . ' FROM users WHERE id = ? LIMIT 1');
        if ($currentStmt) {
            $currentStmt->bind_param('i', $userId);
            $currentStmt->execute();
            $currentRes = $currentStmt->get_result();
            $currentRow = $currentRes ? $currentRes->fetch_assoc() : null;
            $currentStmt->close();

            if ($currentRow) {
                $existingName = trim((string)($currentRow['name'] ?? ''));
                $existingEmail = trim((string)($currentRow['email'] ?? ''));
                $existingPhone = trim((string)($currentRow['phone'] ?? ''));
            }
        }

        $hasChanges = $nameInput !== $existingName;
        if ($hasUserEmail && $emailInput !== $existingEmail) {
            $hasChanges = true;
        }
        if ($hasUserPhone && $phoneInput !== $existingPhone) {
            $hasChanges = true;
        }

        if (!$hasChanges) {
            $profileSuccess = 'No profile changes detected.';
        } else {
        $setParts = ['name = ?'];
        $types = 's';
        $values = [$nameInput];

        if ($hasUserEmail) {
            $setParts[] = 'email = ?';
            $types .= 's';
            $values[] = $emailInput;
        }

        if ($hasUserPhone) {
            $setParts[] = 'phone = ?';
            $types .= 's';
            $values[] = $phoneInput;
        }

        $sql = 'UPDATE users SET ' . implode(', ', $setParts) . ' WHERE id = ? LIMIT 1';
        $types .= 'i';
        $values[] = $userId;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $profileError = 'Unable to update profile right now.';
        } else {
            $stmt->bind_param($types, ...$values);
            if ($stmt->execute()) {
                $_SESSION['name'] = $nameInput;
                $profileSuccess = 'Profile updated successfully.';
            } else {
                $profileError = 'Unable to save profile changes.';
            }
            $stmt->close();
        }
        }
    }
}

$displayName = trim((string)($_SESSION['name'] ?? 'Barangay Official'));
$displayEmail = 'Not set';
$displayPhone = 'Not set';
$displayBarangay = 'Barangay';
$accountCreatedDisplay = 'Not available';

if ($profileError === '' && $userId > 0) {
    $selectFields = ['name', 'username', 'created_at'];
    if ($hasUserEmail) $selectFields[] = 'email';
    if ($hasUserPhone) $selectFields[] = 'phone';
    if ($hasUserBarangay) $selectFields[] = 'barangay';

    $stmt = $conn->prepare('SELECT ' . implode(', ', $selectFields) . ' FROM users WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $userRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($userRow) {
            $displayName = trim((string)($userRow['name'] ?? $displayName));
            
            // Update session with fresh data
            $_SESSION['name'] = $displayName;

            $emailVal = trim((string)($userRow['email'] ?? ''));
            if ($emailVal !== '') {
                $displayEmail = $emailVal;
                $_SESSION['email'] = $displayEmail;
            }

            $phoneVal = trim((string)($userRow['phone'] ?? ''));
            if ($phoneVal !== '') {
                $displayPhone = $phoneVal;
                $_SESSION['phone'] = $displayPhone;
            }

            $rowBarangay = trim((string)($userRow['barangay'] ?? ''));
            if ($rowBarangay !== '') {
                $displayBarangay = $rowBarangay;
                $_SESSION['barangay'] = $displayBarangay;
            }

            $createdRaw = trim((string)($userRow['created_at'] ?? ''));
            $createdTs = strtotime($createdRaw);
            if ($createdTs !== false) {
                $accountCreatedDisplay = date('M d, Y h:i A', $createdTs);
            }
        }
    }
}

$currentBarangay = gabaybatas_get_user_barangay($conn);
if ($displayBarangay === 'Barangay' && $currentBarangay !== '') {
    $displayBarangay = $currentBarangay;
    $_SESSION['barangay'] = $displayBarangay;
}

// Same KP status groups as Home, Flexible Reports and Analytics; archived cases are not counted.
$caseSummary = gabaybatas_case_group_counts($conn, $currentBarangay);
$totalCases = $caseSummary['total'];
$resolvedCases = $caseSummary['counts']['settled'];
$resolutionRate = $totalCases > 0 ? (int)round(($resolvedCases / $totalCases) * 100) : 0;

$profileNameForForm = $displayName;
$profileEmailForForm = $displayEmail === 'Not set' ? '' : $displayEmail;
$profilePhoneForForm = $displayPhone === 'Not set' ? '' : $displayPhone;
$showEditSection = $profileError !== '';

include('../includes/header.php');
include('../includes/sidebar_barangay.php');
?>

<div class="main">
    <div class="topbar">
        <div class="topbar-title">My Profile</div>
        <div class="topbar-right">
            <div class="icon-btn" title="Notifications">
                <i class="uil uil-bell"></i>
            </div>
            <div class="topbar-divider"></div>
            <div class="user-pill">
                <div class="user-avatar"><i class="uil uil-user"></i></div>
                <div class="user-text">
                    <strong><?= htmlspecialchars($displayName) ?></strong>
                    <span>Barangay Administrator</span>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <?php if ($profileError !== ''): ?>
            <div class="profile-alert profile-alert-error"><i class="uil uil-exclamation-circle"></i> <?= htmlspecialchars($profileError) ?></div>
        <?php endif; ?>

        <?php if ($profileSuccess !== ''): ?>
            <div class="profile-alert profile-alert-success" id="profileSuccessAlert"><i class="uil uil-check-circle"></i> <?= htmlspecialchars($profileSuccess) ?></div>
        <?php endif; ?>

        <div class="card-box profile-hero">
            <div class="hero-left">
                <div class="profile-avatar"><?= strtoupper(substr($displayName, 0, 2)) ?></div>
                <div class="hero-info">
                    <h2><?= htmlspecialchars($displayName) ?></h2>
                    <p class="hero-location">
                        <i class="uil uil-map-marker"></i>
                        <?= htmlspecialchars($displayBarangay) ?>
                    </p>
                    <div class="hero-badges">
                        <span class="badge-active"><i class="uil uil-check-circle"></i>Active</span>
                        <span class="badge-role"><i class="uil uil-user-circle"></i>Barangay Official</span>
                    </div>
                </div>
            </div>
            <a class="btn-edit" href="#" id="btnEditProfile">
                <i class="uil uil-edit"></i>
                Edit Profile
            </a>
        </div>

        <div class="card-box section-card">
            <div class="section-heading"><i class="uil uil-user"></i>Personal Information</div>
            <div class="info-grid">
                <div class="info-item">
                    <label>Full Name</label>
                    <div class="info-value"><span class="info-icon"><i class="uil uil-user"></i></span><?= htmlspecialchars($displayName) ?></div>
                </div>
                <div class="info-item">
                    <label>Email Address</label>
                    <div class="info-value"><span class="info-icon"><i class="uil uil-envelope"></i></span><?= htmlspecialchars($displayEmail) ?></div>
                </div>
                <div class="info-item">
                    <label>Phone Number</label>
                    <div class="info-value"><span class="info-icon"><i class="uil uil-phone"></i></span><?= htmlspecialchars($displayPhone) ?></div>
                </div>
                <div class="info-item">
                    <label>Barangay</label>
                    <div class="info-value"><span class="info-icon"><i class="uil uil-map-marker"></i></span><?= htmlspecialchars($displayBarangay) ?></div>
                </div>
            </div>
        </div>

        <div class="card-box section-card edit-profile-card<?= $showEditSection ? ' show' : '' ?>" id="edit-profile-section">
            <div class="section-heading"><i class="uil uil-pen"></i>Edit Profile</div>
            <form method="POST" class="edit-profile-form">
                <input type="hidden" name="action" value="update_profile">
                <div class="info-grid">
                    <div class="info-item">
                        <label>Full Name <span>*</span></label>
                        <input class="profile-input" type="text" name="name" value="<?= htmlspecialchars($profileNameForForm) ?>" required>
                    </div>
                    <div class="info-item">
                        <label>Email Address</label>
                        <input class="profile-input" type="email" name="email" value="<?= htmlspecialchars($profileEmailForForm) ?>" placeholder="you@example.com">
                    </div>
                    <div class="info-item">
                        <label>Phone Number</label>
                        <input class="profile-input" type="text" name="phone" value="<?= htmlspecialchars($profilePhoneForForm) ?>" placeholder="09xx xxx xxxx">
                    </div>
                </div>
                <div class="edit-actions">
                    <button type="button" class="btn-cancel-profile" id="btnCancelEditProfile"><i class="uil uil-times"></i> Cancel</button>
                    <button type="submit" class="btn-save-profile"><i class="uil uil-save"></i> Save Profile</button>
                </div>
            </form>
        </div>

        <div class="card-box section-card">
            <div class="section-heading"><i class="uil uil-chart"></i>Account Statistics</div>
            <div class="profile-stats">
                <div class="stat-mini stat-mini-blue">
                    <div class="stat-mini-icon"><i class="uil uil-folder-open"></i></div>
                    <div class="stat-num"><?= (int)$totalCases ?></div>
                    <div class="stat-text">Total Cases Handled</div>
                </div>
                <div class="stat-mini stat-mini-green">
                    <div class="stat-mini-icon"><i class="uil uil-check-circle"></i></div>
                    <div class="stat-num"><?= (int)$resolvedCases ?></div>
                    <div class="stat-text">Settled / Awarded</div>
                </div>
                <div class="stat-mini stat-mini-purple">
                    <div class="stat-mini-icon"><i class="uil uil-chart-line"></i></div>
                    <div class="stat-num"><?= (int)$resolutionRate ?>%</div>
                    <div class="stat-text">Settled or awarded, of all cases</div>
                    <div class="rate-bar-wrap"><div class="rate-bar" style="width:<?= (int)$resolutionRate ?>%;"></div></div>
                </div>
            </div>
        </div>

        <div class="card-box section-card">
            <div class="section-heading"><i class="uil uil-shield-check"></i>Security</div>
            <div class="security-grid">
                <div class="security-panel">
                    <div class="security-title"><i class="uil uil-shield"></i>Account Security Tips</div>
                    <ul class="security-list">
                        <li><i class="uil uil-check"></i>Use at least 8 characters.</li>
                        <li><i class="uil uil-check"></i>Combine uppercase, lowercase, numbers, and symbols.</li>
                        <li><i class="uil uil-check"></i>Avoid using your name or barangay details.</li>
                        <li><i class="uil uil-check"></i>Do not reuse your previous password.</li>
                    </ul>
                    <div class="security-meta">
                        <div class="security-meta-item"><i class="uil uil-clock"></i><span><strong>Account Created:</strong> <?= htmlspecialchars($accountCreatedDisplay) ?></span></div>
                        <div class="security-meta-item"><i class="uil uil-user-check"></i><span><strong>Account Role:</strong> Barangay Official</span></div>
                        <div class="security-meta-item"><i class="uil uil-exclamation-triangle"></i><span><strong>Recommendation:</strong> Change every 60-90 days</span></div>
                    </div>
                </div>

                <div class="security-side">
                    <div class="security-side-card">
                        <div class="security-side-title"><i class="uil uil-lock"></i>Password Health</div>
                        <div class="health-row">
                            <span class="health-label">Strength</span>
                            <span class="health-badge" id="profileHealthBadge">Not Checked</span>
                        </div>
                        <div class="health-bar-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="profileHealthBarWrap"><div class="health-bar" id="profileHealthBar" style="width:0%;"></div></div>
                        <div class="health-note" id="profileHealthNote">Type a candidate password below to test its health score.</div>
                        <div class="profile-health-checker">
                            <input type="password" class="profile-health-input" id="profileHealthInput" placeholder="Try a password to check strength" autocomplete="off">
                            <ul class="profile-health-list">
                                <li id="profileRuleLen">At least 8 characters</li>
                                <li id="profileRuleUpper">Has uppercase letter</li>
                                <li id="profileRuleLower">Has lowercase letter</li>
                                <li id="profileRuleNumber">Has number</li>
                                <li id="profileRuleSymbol">Has symbol</li>
                            </ul>
                        </div>
                    </div>

                    <div class="security-side-card">
                        <div class="security-side-title"><i class="uil uil-keyhole-circle"></i>Quick Action</div>
                        <p class="security-side-text">Change your account password now to keep your barangay portal account protected.</p>
                        <button class="btn-change-password" type="button" id="btnChangePassword">
                            <i class="uil uil-lock-alt"></i>
                            Change Password
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const btnEditProfile = document.getElementById('btnEditProfile');
const editProfileSection = document.getElementById('edit-profile-section');
const btnCancelEditProfile = document.getElementById('btnCancelEditProfile');
const profileSuccessAlert = document.getElementById('profileSuccessAlert');

if (btnEditProfile && editProfileSection) {
    btnEditProfile.addEventListener('click', function (event) {
        event.preventDefault();
        editProfileSection.classList.add('show');
        editProfileSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
}

if (btnCancelEditProfile && editProfileSection) {
    btnCancelEditProfile.addEventListener('click', function () {
        editProfileSection.classList.remove('show');
    });
}

if (profileSuccessAlert) {
    setTimeout(function () {
        profileSuccessAlert.classList.add('fade-out');
        setTimeout(function () {
            if (profileSuccessAlert && profileSuccessAlert.parentNode) {
                profileSuccessAlert.parentNode.removeChild(profileSuccessAlert);
            }
        }, 350);
    }, 2500);
}

const btnChangePassword = document.getElementById('btnChangePassword');
if (btnChangePassword && window.AdminConfirm) {
    btnChangePassword.addEventListener('click', async function () {
        const ok = await window.AdminConfirm.confirm({
            title: 'Change Password',
            message: 'A new window will open for you to change your password. Do you want to proceed?',
            confirmText: 'Proceed',
            cancelText: 'Cancel'
        });
        if (ok) {
            window.location.href = 'change_password.php';
        }
    });
} else if (btnChangePassword) {
    btnChangePassword.addEventListener('click', function () {
        window.location.href = 'change_password.php';
    });
}

const profileHealthInput = document.getElementById('profileHealthInput');
const profileHealthBadge = document.getElementById('profileHealthBadge');
const profileHealthBar = document.getElementById('profileHealthBar');
const profileHealthBarWrap = document.getElementById('profileHealthBarWrap');
const profileHealthNote = document.getElementById('profileHealthNote');
const profileRuleLen = document.getElementById('profileRuleLen');
const profileRuleUpper = document.getElementById('profileRuleUpper');
const profileRuleLower = document.getElementById('profileRuleLower');
const profileRuleNumber = document.getElementById('profileRuleNumber');
const profileRuleSymbol = document.getElementById('profileRuleSymbol');

if (profileHealthInput && profileHealthBadge && profileHealthBar && profileHealthBarWrap && profileHealthNote) {
    const commonWordPattern = /(password|qwerty|admin|welcome|gabay|barangay|1234|12345|123456)/i;
    const repeatedCharsPattern = /(.)\1{2,}/;

    function setProfileRule(el, ok) {
        if (!el) return;
        el.classList.toggle('ok', ok);
    }

    function getProfileHealth(password) {
        const hasLen = password.length >= 8;
        const hasUpper = /[A-Z]/.test(password);
        const hasLower = /[a-z]/.test(password);
        const hasNumber = /\d/.test(password);
        const hasSymbol = /[^A-Za-z0-9]/.test(password);
        const hasLongLen = password.length >= 12;
        const hasCommonWord = commonWordPattern.test(password);
        const hasRepeatedChars = repeatedCharsPattern.test(password);

        let score = 0;
        if (hasLen) score += 25;
        if (hasUpper) score += 15;
        if (hasLower) score += 15;
        if (hasNumber) score += 15;
        if (hasSymbol) score += 15;
        if (hasLongLen) score += 10;
        if (!hasCommonWord) score += 3;
        if (!hasRepeatedChars) score += 2;

        if (score > 100) score = 100;

        let label = 'Very Weak';
        let cls = 'is-very-weak';
        if (score >= 85) {
            label = 'Strong';
            cls = 'is-strong';
        } else if (score >= 70) {
            label = 'Good';
            cls = 'is-good';
        } else if (score >= 50) {
            label = 'Fair';
            cls = 'is-fair';
        } else if (score >= 30) {
            label = 'Weak';
            cls = 'is-weak';
        }

        return { score, label, cls, hasLen, hasUpper, hasLower, hasNumber, hasSymbol };
    }

    function refreshProfileHealth() {
        const password = profileHealthInput.value;

        if (password === '') {
            profileHealthBadge.className = 'health-badge is-empty';
            profileHealthBadge.textContent = 'Not Checked';
            profileHealthBar.className = 'health-bar is-empty';
            profileHealthBar.style.width = '0%';
            profileHealthBarWrap.setAttribute('aria-valuenow', '0');
            profileHealthNote.textContent = 'Type a candidate password below to test its health score.';
            setProfileRule(profileRuleLen, false);
            setProfileRule(profileRuleUpper, false);
            setProfileRule(profileRuleLower, false);
            setProfileRule(profileRuleNumber, false);
            setProfileRule(profileRuleSymbol, false);
            return;
        }

        const health = getProfileHealth(password);
        profileHealthBadge.className = 'health-badge ' + health.cls;
        profileHealthBadge.textContent = health.label;
        profileHealthBar.className = 'health-bar ' + health.cls;
        profileHealthBar.style.width = health.score + '%';
        profileHealthBarWrap.setAttribute('aria-valuenow', String(health.score));
        profileHealthNote.textContent = 'Health score: ' + health.score + '/100';

        setProfileRule(profileRuleLen, health.hasLen);
        setProfileRule(profileRuleUpper, health.hasUpper);
        setProfileRule(profileRuleLower, health.hasLower);
        setProfileRule(profileRuleNumber, health.hasNumber);
        setProfileRule(profileRuleSymbol, health.hasSymbol);
    }

    profileHealthInput.addEventListener('input', refreshProfileHealth);
    refreshProfileHealth();
}
</script>

<style>
.profile-alert{display:flex;align-items:center;gap:8px;border-radius:10px;padding:10px 12px;font-size:12.5px;margin-bottom:12px;transition:opacity .35s ease, transform .35s ease;}
.profile-alert.fade-out{opacity:0;transform:translateY(-4px);}
.profile-alert-error{background:#fff1f2;border:1px solid #fecdd3;color:#be123c;}
.profile-alert-success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;}
.profile-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; padding:24px 28px; background:var(--navy); border-color:var(--navy); }
/* UI refresh: banner like Home */
.gb-ui .profile-hero { border-radius:16px; border:0; background:radial-gradient(circle at 0% 0%, rgba(255,255,255,.10), transparent 45%), linear-gradient(135deg, #1a3a6b 0%, #0e2242 70%, #0b1a33 100%); box-shadow:0 14px 34px rgba(14,34,66,.22); }
.gb-ui .profile-hero:hover { border-color:transparent; box-shadow:0 14px 34px rgba(14,34,66,.22); }
.gb-ui .info-item label { font-size:12.5px; font-weight:700; text-transform:none; letter-spacing:.1px; color:#334155; }
.hero-left { display:flex; align-items:center; gap:20px; }
.profile-avatar { width:80px; height:80px; border-radius:50%; background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.3); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Source Serif 4',serif; font-size:26px; font-weight:700; flex-shrink:0; letter-spacing:1px; }
.hero-info h2 { font-family:'Source Serif 4',serif; font-size:22px; font-weight:700; color:#fff; margin:0 0 4px; }
.hero-location { display:flex; align-items:center; gap:5px; font-size:13px; color:rgba(255,255,255,.65); margin:0 0 10px; }
.hero-badges { display:flex; gap:8px; }
.badge-active, .badge-role { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:4px; font-size:11.5px; font-weight:600; }
.badge-active { background:rgba(14,124,69,.35); color:#a7f3cb; }
.badge-role { background:rgba(255,255,255,.12); color:rgba(255,255,255,.85); }
.btn-edit { display:inline-flex; align-items:center; gap:7px; border:1px solid rgba(255,255,255,.3); background:rgba(255,255,255,.1); color:#fff; padding:9px 16px; border-radius:6px; font-size:13.5px; font-weight:600; text-decoration:none; cursor:pointer; flex-shrink:0; }
.section-heading { display:flex; align-items:center; gap:8px; font-family:'Source Serif 4',serif; font-size:15px; font-weight:600; color:var(--text-main); border-bottom:1px solid var(--border); padding-bottom:10px; margin-bottom:16px; }
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.info-item label { display:block; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.8px; color:var(--text-muted); margin-bottom:6px; }
.info-item label span{color:#dc2626;}
.info-value { display:flex; align-items:center; gap:10px; border:1px solid var(--border); background:#f7f9fc; border-radius:6px; padding:10px 14px; font-size:13.5px; color:var(--text-main); }
.info-icon { width:28px; height:28px; border-radius:6px; background:var(--navy-light); display:flex; align-items:center; justify-content:center; color:var(--navy); flex-shrink:0; }
.profile-input{width:100%;background:#fafbff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:13px;color:#1f2937;outline:none;}
.profile-input:focus{border-color:#3b82f6;}
.edit-profile-card{display:none;}
.edit-profile-card.show{display:block;animation:fadeInProfileCard .25s ease;}
.edit-profile-form{display:flex;flex-direction:column;gap:12px;}
.edit-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:6px;}
.btn-cancel-profile{display:inline-flex;align-items:center;gap:6px;border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:9px;padding:9px 14px;font-size:12.5px;font-weight:600;cursor:pointer;}
.btn-cancel-profile:hover{background:#f8fafc;}
.btn-save-profile{display:inline-flex;align-items:center;gap:6px;border:none;background:#1d4ed8;color:#fff;border-radius:9px;padding:9px 14px;font-size:12.5px;font-weight:600;cursor:pointer;}
.btn-save-profile:hover{background:#1e40af;}
.profile-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
.stat-mini { border:1px solid var(--border); border-radius:8px; padding:20px 18px 16px; position:relative; overflow:hidden; }
.stat-mini-blue   { border-top:3px solid var(--navy); }
.stat-mini-green  { border-top:3px solid #0e7c45; }
.stat-mini-purple { border-top:3px solid #5b21b6; }
.stat-mini-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; margin-bottom:10px; }
.stat-mini-blue   .stat-mini-icon { background:var(--blue-soft); color:var(--navy); }
.stat-mini-green  .stat-mini-icon { background:var(--green-bg);  color:var(--green); }
.stat-mini-purple .stat-mini-icon { background:#ede9ff; color:#5b21b6; }
.stat-num { font-family:'Source Serif 4',serif; font-size:36px; font-weight:700; color:var(--text-main); line-height:1; }
.stat-text { font-size:12px; color:var(--text-muted); margin-top:4px; }
.rate-bar-wrap { height:4px; background:#e8ecf0; border-radius:99px; margin-top:10px; overflow:hidden; }
.rate-bar { height:100%; background:#5b21b6; border-radius:99px; }
.security-grid { display:grid; grid-template-columns:1.6fr 1fr; gap:14px; align-items:stretch; }
.security-panel { border:1px solid var(--border); border-radius:8px; background:#f7f9fc; padding:16px 18px; }
.security-title { display:flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:var(--navy); margin-bottom:12px; }
.security-list { list-style:none; padding:0; margin:0 0 14px; }
.security-list li { display:flex; align-items:flex-start; gap:8px; font-size:12.5px; color:var(--text-main); padding:4px 0; border-bottom:1px dashed #e4eaf2; }
.security-list li:last-child { border-bottom:none; }
.security-list li i, .security-title i, .security-meta-item i { color: #0e7c45; margin-top:2px; flex-shrink:0; }
.security-meta { padding-top:12px; border-top:1px solid var(--border); display:grid; gap:7px; }
.security-meta-item { display:flex; align-items:center; gap:7px; font-size:12px; color:var(--text-muted); }
.security-meta-item strong { color:var(--text-main); }
.security-side { display:grid; gap:14px; }
.security-side-card { border:1px solid var(--border); border-radius:8px; background:#f7f9fc; padding:14px; }
.security-side-title { display:flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:var(--navy); margin-bottom:10px; }
.security-side-text { font-size:12.5px; color:var(--text-muted); margin:0 0 12px; line-height:1.45; }
.health-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
.health-label { font-size:12px; color:var(--text-muted); }
.health-badge { font-size:11.5px; font-weight:700; border-radius:999px; padding:3px 9px; }
.health-badge.is-empty { color:#475569; background:#e2e8f0; }
.health-badge.is-very-weak { color:#b91c1c; background:#fee2e2; }
.health-badge.is-weak { color:#c2410c; background:#ffedd5; }
.health-badge.is-fair { color:#a16207; background:#fef9c3; }
.health-badge.is-good { color:#15803d; background:#dcfce7; }
.health-badge.is-strong { color:#1d4ed8; background:#dbeafe; }
.health-bar-wrap { height:6px; background:#e8ecf0; border-radius:99px; overflow:hidden; margin-bottom:8px; }
.health-bar { height:100%; border-radius:99px; transition:width .25s ease, background-color .25s ease; }
.health-bar.is-empty { background:#94a3b8; }
.health-bar.is-very-weak { background:#ef4444; }
.health-bar.is-weak { background:#f97316; }
.health-bar.is-fair { background:#eab308; }
.health-bar.is-good { background:#22c55e; }
.health-bar.is-strong { background:#2563eb; }
.health-note { font-size:12px; color:var(--text-muted); line-height:1.45; }
.profile-health-checker { margin-top:10px; border-top:1px dashed #dbe3ef; padding-top:10px; }
.profile-health-input { width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:9px 11px; font-size:12.5px; color:#1e293b; background:#fff; outline:none; }
.profile-health-input:focus { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
.profile-health-list { list-style:none; margin:8px 0 0; padding:0; display:grid; gap:4px; }
.profile-health-list li { font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; }
.profile-health-list li::before { content:'•'; color:#94a3b8; }
.profile-health-list li.ok { color:#0f766e; }
.profile-health-list li.ok::before { content:'✓'; color:#10b981; font-weight:700; }
.btn-change-password { display:inline-flex; align-items:center; gap:7px; border:1px solid var(--border); background:var(--navy); color:#fff; padding:10px 18px; border-radius:6px; font-size:13.5px; font-weight:600; cursor:pointer; margin-top:16px; transition:background .2s, border-color .2s; }
.btn-change-password:hover { background:var(--navy-dark); border-color:var(--navy-dark); }
@keyframes fadeInProfileCard {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}
@media (max-width: 900px) {
    .profile-hero { flex-direction: column; align-items: flex-start; gap: 14px; }
    .info-grid, .profile-stats, .security-grid { grid-template-columns: 1fr; }
}
</style>
