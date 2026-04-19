<?php
session_start();
require_once __DIR__ . '/config/database.php';
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
                        $_SESSION['user_id'] = $user['id'] ?? null;
                        $_SESSION['name']    = $user['name'] ?? ($user['username'] ?? $username);
                        $_SESSION['barangay']= $user['barangay'] ?? '';
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
<title>GabayBatas — Login</title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@400;600&family=Source+Sans+3:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://unicons.iconscout.com/release/v4.0.8/css/line.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

body {
    min-height: 100vh;
    font-family: 'Source Sans 3', sans-serif;
    color: #1a2535;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: #050d1a;
    position: relative;
}

/* ── CANVAS PARTICLES ── */
#particles { position:fixed;inset:0;z-index:0;pointer-events:none; }

/* ── AURORA LAYERS ── */
.aurora {
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    overflow: hidden;
}
.aurora-band {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    opacity: .55;
    mix-blend-mode: screen;
    will-change: transform;
}
.aurora-band:nth-child(1) {
    width: 900px; height: 500px;
    top: -200px; left: -200px;
    background: radial-gradient(ellipse, #0a2d6e 0%, transparent 70%);
    animation: aurora1 18s ease-in-out infinite alternate;
}
.aurora-band:nth-child(2) {
    width: 700px; height: 600px;
    top: -100px; right: -150px;
    background: radial-gradient(ellipse, #0f4a8a 0%, transparent 70%);
    animation: aurora2 22s ease-in-out infinite alternate;
    opacity: .4;
}
.aurora-band:nth-child(3) {
    width: 800px; height: 400px;
    bottom: -150px; left: 10%;
    background: radial-gradient(ellipse, #1a3a6b 0%, transparent 70%);
    animation: aurora3 16s ease-in-out infinite alternate;
    opacity: .5;
}
.aurora-band:nth-child(4) {
    width: 500px; height: 500px;
    bottom: -80px; right: -100px;
    background: radial-gradient(ellipse, #b8800a 0%, transparent 70%);
    animation: aurora4 20s ease-in-out infinite alternate;
    opacity: .18;
}

@keyframes aurora1 { from{transform:translate(0,0) scale(1)} to{transform:translate(80px,40px) scale(1.15)} }
@keyframes aurora2 { from{transform:translate(0,0) rotate(0deg)} to{transform:translate(-60px,30px) rotate(15deg)} }
@keyframes aurora3 { from{transform:translate(0,0) scale(1)} to{transform:translate(40px,-60px) scale(1.1)} }
@keyframes aurora4 { from{transform:translate(0,0)} to{transform:translate(-50px,-40px) scale(1.2)} }

/* ── GRID OVERLAY ── */
.grid-overlay {
    position: fixed; inset: 0; z-index: 1; pointer-events: none;
    background-image:
        linear-gradient(rgba(100,160,255,.06) 1px, transparent 1px),
        linear-gradient(90deg, rgba(100,160,255,.06) 1px, transparent 1px);
    background-size: 48px 48px;
    mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 30%, transparent 100%);
    -webkit-mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 30%, transparent 100%);
}

/* ── SCAN LINE ── */
.scanline {
    position: fixed; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, transparent, rgba(100,180,255,.25), transparent);
    z-index: 2; pointer-events: none;
    animation: scan 8s linear infinite;
}
@keyframes scan { 0%{top:-2px} 100%{top:100vh} }

/* ── FLOATING CARDS ── */
.float-cards {
    position: fixed; inset: 0; z-index: 1; pointer-events: none;
}
.fc {
    position: absolute;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 16px;
    backdrop-filter: blur(4px);
    animation: fcFloat linear infinite;
}
.fc:nth-child(1){width:90px;height:90px;top:12%;left:6%;animation-duration:14s;animation-delay:0s;}
.fc:nth-child(2){width:60px;height:60px;top:70%;left:8%;animation-duration:11s;animation-delay:2s;}
.fc:nth-child(3){width:110px;height:60px;top:30%;right:7%;animation-duration:16s;animation-delay:1s;}
.fc:nth-child(4){width:70px;height:70px;top:75%;right:10%;animation-duration:13s;animation-delay:3s;}
.fc:nth-child(5){width:50px;height:80px;top:50%;left:20%;animation-duration:18s;animation-delay:0.5s;}
@keyframes fcFloat {
    0%{transform:translateY(0) rotate(0deg);opacity:.5}
    50%{transform:translateY(-22px) rotate(4deg);opacity:.9}
    100%{transform:translateY(0) rotate(0deg);opacity:.5}
}

/* ── ACCENT LINE TOP ── */
.accent-bar {
    position: fixed; top: 0; left: 0; right: 0; height: 3px; z-index: 10;
    background: linear-gradient(90deg, #1a3a6b, #2a6dd9, #c9a83c, #2a6dd9, #1a3a6b);
    background-size: 300% 100%;
    animation: barShift 6s linear infinite;
}
@keyframes barShift { 0%{background-position:0% 50%} 100%{background-position:300% 50%} }

/* ── CONTENT WRAP ── */
.content-wrap {
    position: relative; z-index: 5;
    display: flex; flex-direction: column; align-items: center;
    padding: 20px;
}

/* ── CARD ── */
.card {
    width: 440px; max-width: 96vw;
    background: rgba(255,255,255,.97);
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,.3);
    box-shadow:
        0 32px 80px rgba(0,0,0,.5),
        0 0 0 1px rgba(255,255,255,.06),
        inset 0 1px 0 rgba(255,255,255,.8);
    animation: cardIn .6s cubic-bezier(.22,1,.36,1) both;
    overflow: hidden;
}
@keyframes cardIn {
    from{opacity:0;transform:translateY(30px) scale(.97)}
    to{opacity:1;transform:translateY(0) scale(1)}
}

/* Card top accent strip */
.card-stripe {
    height: 4px;
    background: linear-gradient(90deg, #1a3a6b 0%, #2a6dd9 50%, #c9a83c 100%);
}

.card-header {
    padding: 28px 32px 20px;
    display: flex; align-items: center; gap: 16px;
}
.shield-icon {
    width: 54px; height: 54px; flex-shrink: 0;
    background: linear-gradient(135deg, #1a3a6b, #2a5298);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; color: #fff;
    box-shadow: 0 8px 20px rgba(26,58,107,.35);
}
.brand-info h1 {
    font-family: 'Source Serif 4', serif;
    font-size: 21px; font-weight: 600;
    color: #0f1f38; letter-spacing: .2px;
}
.brand-info .subtitle {
    font-size: 12px; color: #6b7a90; margin-top: 3px; line-height: 1.5;
}

.card-divider { height: 1px; background: #f0f3f8; margin: 0 32px; }

.card-body { padding: 22px 32px 20px; }

.section-label {
    font-size: 10.5px; font-weight: 700; letter-spacing: 1.8px;
    text-transform: uppercase; color: #8a9bb0; margin-bottom: 16px;
}

.field { margin-bottom: 14px; }
.field label { display:block; font-size:13px; font-weight:600; color:#2c3e55; margin-bottom:6px; }

.input-wrap { position: relative; }
.input-wrap .field-icon {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    font-size: 17px; color: #a0aec0; pointer-events: none; transition: color .2s;
}
.field input {
    width: 100%; background: #f7f9fc;
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 11px 12px 11px 40px;
    font-family: 'Source Sans 3', sans-serif;
    font-size: 14px; color: #1a2535; outline: none;
    transition: border-color .2s, box-shadow .2s, background .2s;
}
.field input::placeholder { color: #b0bec9; }
.field input:focus {
    border-color: #2a5298; background: #fff;
    box-shadow: 0 0 0 3px rgba(42,82,152,.12);
}
.field input:focus + .field-icon { color: #2a5298; }

.toggle-pw {
    position: absolute; right: 11px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: #a0aec0;
    font-size: 17px; cursor: pointer; padding: 2px;
    transition: color .2s;
}
.toggle-pw:hover { color: #2a5298; }

.error-box {
    margin-bottom: 14px; padding: 10px 14px;
    border: 1px solid #fca5a5; background: #fff5f5;
    color: #991b1b; border-radius: 10px; font-size: 13px;
    display: flex; align-items: center; gap: 8px;
}

.btn-login {
    width: 100%; padding: 12px;
    background: linear-gradient(135deg, #1a3a6b 0%, #2a5298 100%);
    border: none; border-radius: 11px; color: #fff;
    font-family: 'Source Sans 3', sans-serif;
    font-size: 14px; font-weight: 700; letter-spacing: .4px;
    cursor: pointer; margin-top: 4px;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 6px 20px rgba(26,58,107,.35);
    transition: all .2s;
}
.btn-login:hover {
    background: linear-gradient(135deg, #153060 0%, #1e407a 100%);
    box-shadow: 0 10px 28px rgba(26,58,107,.45);
    transform: translateY(-1px);
}
.btn-login:active { transform: translateY(0); }

.card-footer {
    padding: 14px 32px 18px;
    background: #f7f9fc;
    border-top: 1px solid #edf2f7;
    display: flex; align-items: flex-start; gap: 10px;
}
.card-footer i { color: #2a5298; font-size: 15px; flex-shrink: 0; margin-top: 1px; }
.card-footer p { font-size: 12px; color: #718096; line-height: 1.6; }
.card-footer p strong { color: #2d3748; }

.page-footer {
    margin-top: 16px; font-size: 12px;
    color: rgba(255,255,255,.3); text-align: center; letter-spacing: .3px;
}

@media(max-width:500px){
    .card-header,.card-body,.card-footer{padding-left:22px;padding-right:22px;}
    .card-divider{margin:0 22px;}
}
</style>
</head>
<body>

<div class="accent-bar"></div>
<canvas id="particles"></canvas>
<div class="aurora">
    <div class="aurora-band"></div>
    <div class="aurora-band"></div>
    <div class="aurora-band"></div>
    <div class="aurora-band"></div>
</div>
<div class="grid-overlay"></div>
<div class="scanline"></div>
<div class="float-cards">
    <div class="fc"></div><div class="fc"></div>
    <div class="fc"></div><div class="fc"></div><div class="fc"></div>
</div>

<div class="content-wrap">
    <div class="card">
        <div class="card-stripe"></div>

        <div class="card-header">
            <div class="shield-icon"><i class="uil uil-shield-check"></i></div>
            <div class="brand-info">
                <h1>GabayBatas</h1>
                <div class="subtitle">Legal Information &amp; Referral System<br>Catbalogan City, Samar</div>
            </div>
        </div>

        <div class="card-divider"></div>

        <div class="card-body">
            <div class="section-label">System Login</div>

            <form method="POST" action="login.php" autocomplete="off">
                <?php if ($error !== ''): ?>
                <div class="error-box">
                    <i class="uil uil-exclamation-triangle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <div class="field">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <input type="text" id="username" name="username"
                            placeholder="Enter your username" required
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        <i class="uil uil-user field-icon"></i>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password"
                            placeholder="Enter your password" required>
                        <i class="uil uil-lock field-icon"></i>
                        <button type="button" class="toggle-pw" onclick="togglePw()" tabindex="-1">
                            <i class="uil uil-eye-slash" id="pwIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="uil uil-sign-in-alt"></i> Login to System
                </button>
            </form>
        </div>

        <div class="card-footer">
            <i class="uil uil-info-circle"></i>
            <p>Use <strong>admin</strong> credentials for Administrator access. Barangay officials must use their assigned barangay account.</p>
        </div>
    </div>

    <div class="page-footer">&copy; <?= date('Y') ?> GabayBatas &mdash; Catbalogan City Government</div>
</div>

<script>
// ── PASSWORD TOGGLE
function togglePw() {
    const f = document.getElementById('password');
    const i = document.getElementById('pwIcon');
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'password' ? 'uil uil-eye-slash' : 'uil uil-eye';
}

// ── PARTICLE SYSTEM
const canvas = document.getElementById('particles');
const ctx = canvas.getContext('2d');
let W, H, particles = [];

function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
}
resize();
window.addEventListener('resize', resize);

class Particle {
    constructor() { this.reset(); }
    reset() {
        this.x  = Math.random() * W;
        this.y  = Math.random() * H;
        this.vx = (Math.random() - .5) * .4;
        this.vy = (Math.random() - .5) * .4;
        this.r  = Math.random() * 1.5 + .5;
        this.alpha = Math.random() * .5 + .15;
        this.life  = 0;
        this.maxLife = 200 + Math.random() * 300;
    }
    update() {
        this.x += this.vx; this.y += this.vy; this.life++;
        if (this.life > this.maxLife || this.x < 0 || this.x > W || this.y < 0 || this.y > H) this.reset();
    }
    draw() {
        const fade = Math.sin((this.life / this.maxLife) * Math.PI);
        ctx.beginPath();
        ctx.arc(this.x, this.y, this.r, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(140,190,255,${this.alpha * fade})`;
        ctx.fill();
    }
}

// Connections between nearby particles
function drawConnections() {
    for (let i = 0; i < particles.length; i++) {
        for (let j = i + 1; j < particles.length; j++) {
            const dx = particles[i].x - particles[j].x;
            const dy = particles[i].y - particles[j].y;
            const d  = Math.sqrt(dx*dx + dy*dy);
            if (d < 120) {
                ctx.beginPath();
                ctx.moveTo(particles[i].x, particles[i].y);
                ctx.lineTo(particles[j].x, particles[j].y);
                ctx.strokeStyle = `rgba(100,170,255,${.12 * (1 - d/120)})`;
                ctx.lineWidth = .8;
                ctx.stroke();
            }
        }
    }
}

for (let i = 0; i < 90; i++) particles.push(new Particle());

function animate() {
    ctx.clearRect(0, 0, W, H);
    drawConnections();
    particles.forEach(p => { p.update(); p.draw(); });
    requestAnimationFrame(animate);
}
animate();
</script>
</body>
</html>