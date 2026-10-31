<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/barangays.php';
$error = '';
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin')    { header('Location: admin/dashboard.php'); exit(); }
    if ($_SESSION['role'] === 'barangay') { header('Location: barangay/home.php'); exit(); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($user) {
                $storedPassword = $user['password'] ?? '';
                $isValidPassword = password_verify($password, $storedPassword) || $password === $storedPassword;
                if ($isValidPassword) {
                    $rawRole = strtolower(trim((string)($user['role'] ?? '')));
                    $role = '';
                    if ($rawRole === 'admin' || $rawRole === 'administrator') $role = 'admin';
                    elseif ($rawRole === 'barangay' || $rawRole === 'barangay official') $role = 'barangay';
                    elseif (strtolower($username) === 'admin') $role = 'admin';
                    if ($role !== '') {
                        // Shown as "Last Login" on Manage Users (the app's login records it the same way).
                        $loginUpdate = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ? LIMIT 1');
                        if ($loginUpdate) {
                            $loginUserId = (int)($user['id'] ?? 0);
                            $loginUpdate->bind_param('i', $loginUserId);
                            $loginUpdate->execute();
                            $loginUpdate->close();
                        }
                        $_SESSION['user_id'] = $user['id'] ?? null;
                        $_SESSION['name']    = $user['name'] ?? ($user['username'] ?? $username);
                        $_SESSION['barangay']= 'Barangay Muñoz';
                        $_SESSION['role']    = $role;
                        header('Location: ' . ($role === 'admin' ? 'admin/dashboard.php' : 'barangay/home.php'));
                        exit();
                    }
                    $error = 'Account role is not configured correctly.';
                } else { $error = 'Invalid username or password.'; }
            } else { $error = 'Invalid username or password.'; }
        } else { $error = 'Unable to process login request.'; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GabayBatas — Sign In</title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@400;600;700&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://unicons.iconscout.com/release/v4.0.8/css/line.css" rel="stylesheet">
<style>
:root {
    --navy-900: #0b1a33;
    --navy-800: #0e2242;
    --navy-700: #1a3a6b;
    --navy-600: #24508f;
    --gold: #c9a227;
    --accent: #c2560c;
    --ink: #0f172a;
    --muted: #64748b;
    --line: #dbe3ef;
    --bg: #eef2f8;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 32px 16px 20px; font-family: 'Source Sans 3', sans-serif; color: var(--ink); background: var(--bg);
    position: relative; overflow-x: hidden;
}
/* Soft background shapes, like the sample, in the site's navy and blue */
body::before, body::after {
    content: ''; position: fixed; z-index: 0; border-radius: 46% 54% 60% 40% / 52% 40% 60% 48%; filter: blur(2px);
}
body::before { width: 620px; height: 620px; top: -220px; left: -160px; background: radial-gradient(circle at 40% 40%, #c7d7f2, #dbe6f7 60%, transparent 70%); }
body::after { width: 560px; height: 560px; bottom: -240px; right: -140px; background: radial-gradient(circle at 60% 50%, #d6e2f5, #e4ecf8 60%, transparent 70%); }
.flag-bar { position: fixed; top: 0; left: 0; right: 0; height: 4px; z-index: 2; background: linear-gradient(90deg, var(--navy-700) 0 33%, #b91c1c 33% 66%, var(--gold) 66%); }

.shell {
    position: relative; z-index: 1; width: 100%; max-width: 1040px; min-height: 580px; display: grid; grid-template-columns: 1.08fr 1fr;
    background: #fff; border-radius: 22px; overflow: hidden; box-shadow: 0 30px 80px rgba(14, 34, 66, .18), 0 2px 6px rgba(14, 34, 66, .06);
    animation: rise .5s ease-out both;
}
@keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }

/* ── Left: the barangay ── */
.brand {
    position: relative; display: flex; flex-direction: column; padding: 34px 38px 30px; color: #fff;
    background: radial-gradient(circle at 15% 0%, rgba(255,255,255,.10), transparent 40%), linear-gradient(160deg, var(--navy-700) 0%, var(--navy-800) 55%, var(--navy-900) 100%);
}
.seals { position: relative; z-index: 1; display: flex; align-items: center; gap: 14px; }
.seal { width: 64px; height: 64px; border-radius: 50%; background: #fff; display: grid; place-items: center; padding: 4px; box-shadow: 0 0 0 3px rgba(255,255,255,.18), 0 8px 20px rgba(0,0,0,.25); }
.seal img { width: 100%; height: 100%; object-fit: contain; border-radius: 50%; }
.lgu { line-height: 1.35; }
.lgu small { display: block; font-size: 11px; letter-spacing: 1.6px; text-transform: uppercase; color: rgba(255,255,255,.65); }
.lgu strong { display: block; font-family: 'Source Serif 4', serif; font-size: 21px; font-weight: 700; letter-spacing: .3px; }
.lgu span { display: block; font-size: 12.5px; color: rgba(255,255,255,.75); }

.stage {
    position: relative; z-index: 1; flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; margin: 22px 0 14px;
}
.stage-card {
    width: 100%; max-width: 380px; aspect-ratio: 4 / 3; border-radius: 18px; background: #fff; display: grid; place-items: center;
    box-shadow: 0 18px 40px rgba(0,0,0,.28); position: relative; overflow: hidden;
}
.stage-card::before { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 34%; background: linear-gradient(180deg, transparent, #f1f5fb); }
#justiceAnim { position: relative; width: 92%; height: 92%; }
.stage-fallback { font-size: 72px; color: var(--navy-700); }
.tagline { margin-top: 20px; text-align: center; max-width: 380px; }
.tagline h2 { font-family: 'Source Serif 4', serif; font-size: 19px; font-weight: 600; }
.tagline p { margin-top: 5px; font-size: 13px; color: rgba(255,255,255,.72); line-height: 1.55; }
.brand-foot { position: relative; z-index: 1; display: flex; align-items: center; gap: 8px; font-size: 11.5px; color: rgba(255,255,255,.6); }
.brand-foot i { color: var(--gold); font-size: 15px; }

/* ── Right: sign in ── */
.panel { display: flex; flex-direction: column; justify-content: center; padding: 44px 48px 30px; }
.app-mark { display: inline-flex; align-items: center; gap: 9px; margin-bottom: 26px; }
.app-mark i { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; background: var(--navy-700); color: #fff; font-size: 18px; }
.app-mark b { font-family: 'Source Serif 4', serif; font-size: 18px; color: var(--navy-800); }
.app-mark small { display: block; font-size: 11px; color: var(--muted); letter-spacing: .3px; }
.panel h1 { font-family: 'Source Serif 4', serif; font-size: 30px; font-weight: 700; color: var(--ink); line-height: 1.15; }
.panel .lead { margin: 8px 0 24px; font-size: 13.5px; color: var(--muted); line-height: 1.55; }

.error-box { display: flex; align-items: center; gap: 9px; padding: 11px 13px; margin-bottom: 16px; border-radius: 10px; background: #fff1f2; border: 1px solid #fecdd3; color: #be123c; font-size: 13px; font-weight: 600; }
.error-box i { font-size: 17px; }

.field { margin-bottom: 15px; }
.field label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: #475569; }
.input-wrap { position: relative; }
.input-wrap > i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 18px; color: #94a3b8; pointer-events: none; transition: color .15s; }
.input-wrap input {
    width: 100%; height: 48px; padding: 0 44px; border: 1.5px solid var(--line); border-radius: 12px; background: #f8fafc;
    font: inherit; font-size: 14.5px; color: var(--ink); outline: none; transition: border-color .15s, box-shadow .15s, background .15s;
}
.input-wrap input:focus { border-color: var(--navy-600); background: #fff; box-shadow: 0 0 0 4px rgba(36, 80, 143, .12); }
.input-wrap input:focus + i, .input-wrap:focus-within > i { color: var(--navy-600); }
.toggle-pw { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 34px; height: 34px; border: 0; border-radius: 8px; background: transparent; color: #94a3b8; font-size: 18px; cursor: pointer; }
.toggle-pw:hover { background: #eef2f8; color: var(--navy-700); }

.btn-login {
    width: 100%; height: 50px; margin-top: 8px; border: 0; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    background: linear-gradient(135deg, var(--navy-600), var(--navy-800)); color: #fff; font: inherit; font-size: 15px; font-weight: 700; letter-spacing: .3px;
    cursor: pointer; box-shadow: 0 10px 24px rgba(26, 58, 107, .28); transition: transform .15s, box-shadow .15s, filter .15s;
}
.btn-login:hover { transform: translateY(-1px); box-shadow: 0 14px 28px rgba(26, 58, 107, .34); filter: brightness(1.06); }
.btn-login:disabled { cursor: progress; filter: saturate(.7); transform: none; }
.btn-login .spin { display: none; width: 16px; height: 16px; border-radius: 50%; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; animation: spin .8s linear infinite; }
.btn-login.is-busy .spin { display: inline-block; }
.btn-login.is-busy i { display: none; }
@keyframes spin { to { transform: rotate(360deg); } }

.note { display: flex; gap: 9px; margin-top: 18px; padding: 11px 13px; border-radius: 10px; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 12.5px; color: #475569; line-height: 1.5; }
.note i { color: var(--navy-600); font-size: 16px; margin-top: 1px; }
.divider { display: flex; align-items: center; gap: 12px; margin: 20px 0 14px; font-size: 11.5px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
.divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
.track-link {
    display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1.5px solid var(--line); border-radius: 12px; text-decoration: none; color: var(--ink);
    transition: border-color .15s, background .15s;
}
.track-link:hover { border-color: #93c5fd; background: #f8fbff; }
.track-link .ti { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; background: #fff7ed; color: var(--accent); font-size: 18px; }
.track-link strong { display: block; font-size: 13.5px; }
.track-link small { display: block; font-size: 12px; color: var(--muted); }
.track-link .go { margin-left: auto; color: #94a3b8; font-size: 20px; }

.page-foot { position: relative; z-index: 1; margin-top: 18px; font-size: 12px; color: #7b8aa3; text-align: center; }

@media (max-width: 880px) {
    .shell { grid-template-columns: 1fr; max-width: 480px; min-height: 0; }
    .brand { padding: 24px 24px 22px; }
    .stage, .brand-foot { display: none; }
    .panel { padding: 30px 26px 26px; }
}
@media (prefers-reduced-motion: reduce) { .shell { animation: none; } }
</style>
</head>
<body>
<div class="flag-bar"></div>

<main class="shell">
    <section class="brand" aria-label="Barangay Muñoz">
        <div class="seals">
            <span class="seal"><img src="images/seal_city_catbalogan.png" alt="Seal of the City of Catbalogan"></span>
            <span class="seal"><img src="images/seal_barangay_munoz.png" alt="Seal of Barangay Muñoz"></span>
            <div class="lgu">
                <small>Republic of the Philippines</small>
                <strong>Barangay Muñoz</strong>
                <span>City of Catbalogan, Samar</span>
            </div>
        </div>

        <div class="stage">
            <div class="stage-card">
                <div id="justiceAnim" aria-hidden="true"><i class="uil uil-balance-scale stage-fallback"></i></div>
            </div>
            <div class="tagline">
                <h2>Katarungang Pambarangay, made accessible</h2>
                <p>File, track and settle community disputes the lawful way, through the Lupong Tagapamayapa of Barangay Muñoz.</p>
            </div>
        </div>

        <div class="brand-foot"><i class="uil uil-shield-check"></i> Official case management portal of the Office of the Punong Barangay</div>
    </section>

    <section class="panel">
        <div class="app-mark">
            <i class="uil uil-shield-check"></i>
            <span><b>GabayBatas</b><small>Legal Information &amp; Referral System</small></span>
        </div>

        <h1>Welcome back</h1>
        <p class="lead">Sign in with your official account to manage barangay cases and records.</p>

        <form method="POST" action="login.php" autocomplete="off" id="loginForm">
            <?php if ($error !== ''): ?>
            <div class="error-box" role="alert"><i class="uil uil-exclamation-triangle"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="field">
                <label for="username">Username</label>
                <div class="input-wrap">
                    <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    <i class="uil uil-user"></i>
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <i class="uil uil-lock"></i>
                    <button type="button" class="toggle-pw" id="togglePw" aria-label="Show password"><i class="uil uil-eye-slash" id="pwIcon"></i></button>
                </div>
            </div>

            <button type="submit" class="btn-login" id="loginBtn"><span class="spin"></span><i class="uil uil-sign-in-alt"></i> <span class="label">Sign in</span></button>
        </form>

        <div class="note"><i class="uil uil-info-circle"></i><span>For barangay officials and system administrators only. Residents use the GabayBatas mobile app.</span></div>

        <div class="divider">Residents</div>
        <a href="track.php" class="track-link">
            <span class="ti"><i class="uil uil-search-alt"></i></span>
            <span><strong>Track your case status</strong><small>Enter your case number to see its progress</small></span>
            <i class="uil uil-angle-right go"></i>
        </a>
    </section>
</main>

<div class="page-foot">&copy; <?= date('Y') ?> GabayBatas &middot; Barangay Muñoz, City of Catbalogan, Samar</div>

<script src="assets/vendor/lottie/lottie_light.min.js"></script>
<script>
// Show or hide the password
document.getElementById('togglePw').addEventListener('click', function () {
    const field = document.getElementById('password');
    const show = field.type === 'password';
    field.type = show ? 'text' : 'password';
    document.getElementById('pwIcon').className = show ? 'uil uil-eye' : 'uil uil-eye-slash';
    this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});

// Busy state while signing in
document.getElementById('loginForm').addEventListener('submit', function () {
    const btn = document.getElementById('loginBtn');
    btn.classList.add('is-busy');
    btn.querySelector('.label').textContent = 'Signing in…';
    setTimeout(function () { btn.disabled = true; }, 0);
});
window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    const btn = document.getElementById('loginBtn');
    btn.classList.remove('is-busy');
    btn.disabled = false;
    btn.querySelector('.label').textContent = 'Sign in';
});

// Justice illustration (assets/lottie/justice-scale.json, credits in assets/lottie/CREDITS.txt)
(function () {
    const box = document.getElementById('justiceAnim');
    if (!window.lottie || !box) return;
    const still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const anim = lottie.loadAnimation({container: box, renderer: 'svg', loop: !still, autoplay: !still, path: 'assets/lottie/justice-scale.json'});
    anim.addEventListener('DOMLoaded', function () {
        const fallback = box.querySelector('.stage-fallback');
        if (fallback) fallback.remove();
        if (still) anim.goToAndStop(Math.floor(anim.totalFrames * 0.6), true);
    });
})();
</script>
</body>
</html>
