<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);

/* ── GET: Show the reset form ─────────────────────────────────────────────── */
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
    $token = htmlspecialchars((string)($_GET['token'] ?? ''), ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset GabayBatas Password</title>
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4ff;color:#1f2937;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:24px}
        .card{background:white;border-radius:20px;box-shadow:0 8px 32px rgba(47,110,255,.10);padding:40px 32px;width:100%;max-width:400px}
        .logo{text-align:center;font-size:22px;font-weight:700;color:#2f6eff;margin-bottom:6px}
        .subtitle{text-align:center;color:#6b7280;font-size:14px;margin-bottom:30px}
        label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;margin-top:18px}
        .wrap{position:relative}
        input[type=password]{width:100%;padding:13px 48px 13px 16px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:16px;transition:border-color .2s,box-shadow .2s;background:#f9fafb}
        input[type=password]:focus{outline:none;border-color:#2f6eff;box-shadow:0 0 0 3px rgba(47,110,255,.15);background:white}
        .eye{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;font-size:18px;padding:4px;line-height:1}
        .sbar{height:4px;border-radius:4px;background:#e5e7eb;margin-top:8px;overflow:hidden}
        .sfill{height:100%;border-radius:4px;width:0%;transition:width .3s,background .3s}
        .stxt{font-size:11px;color:#9ca3af;margin-top:4px;min-height:16px}
        #errmsg{color:#ef4444;font-size:13px;text-align:center;margin-top:14px;min-height:18px}
        .btn{display:block;width:100%;margin-top:28px;padding:15px;background:linear-gradient(135deg,#2f6eff,#1f49d8);color:white;border:none;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;transition:opacity .2s,transform .1s;box-shadow:0 4px 16px rgba(47,110,255,.25)}
        .btn:hover{opacity:.92}
        .btn:active{transform:scale(.98)}
        .btn:disabled{opacity:.6;cursor:not-allowed}
    </style>
</head>
<body>
<div class="card">
    <div class="logo">GabayBatas</div>
    <p class="subtitle">Enter and confirm your new password below.</p>

    <label for="pw">New Password</label>
    <div class="wrap">
        <input id="pw" type="password" minlength="8" required placeholder="Min. 8 characters" oninput="chkStr(this.value)">
        <button type="button" class="eye" onclick="tog('pw',this)">&#128065;</button>
    </div>
    <div class="sbar"><div class="sfill" id="sfill"></div></div>
    <div class="stxt" id="stxt"></div>

    <label for="cf">Confirm Password</label>
    <div class="wrap">
        <input id="cf" type="password" minlength="8" required placeholder="Re-enter password">
        <button type="button" class="eye" onclick="tog('cf',this)">&#128065;</button>
    </div>

    <div id="errmsg"></div>
    <button class="btn" id="sbtn" onclick="send()">Update Password</button>
</div>
<script>
var TK="TOKEN_PLACEHOLDER";
function tog(id,b){var i=document.getElementById(id);i.type=i.type==="password"?"text":"password";b.innerHTML=i.type==="password"?"&#128065;":"&#128064;";}
function chkStr(v){
    var b=document.getElementById("sfill"),t=document.getElementById("stxt");
    if(!v){b.style.width="0%";t.textContent="";return;}
    var s=0;
    if(v.length>=8)s++;if(v.length>=12)s++;
    if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
    var L=["","Weak","Fair","Good","Strong","Very strong"];
    var C=["","#ef4444","#f97316","#eab308","#22c55e","#16a34a"];
    b.style.width=(s*20)+"%";b.style.background=C[s]||"#e5e7eb";
    t.style.color=C[s]||"#9ca3af";t.textContent=L[s]||"";
}
function send(){
    var p=document.getElementById("pw").value,c=document.getElementById("cf").value;
    var e=document.getElementById("errmsg"),b=document.getElementById("sbtn");
    e.textContent="";
    if(p.length<8){e.textContent="Password must be at least 8 characters.";return;}
    if(p!==c){e.textContent="Passwords do not match.";return;}
    b.disabled=true;b.textContent="Updating...";
    fetch(window.location.href,{
        method:"POST",
        headers:{"Content-Type":"application/json"},
        body:JSON.stringify({token:TK,password:p,password_confirmation:c})
    })
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.success){document.open();document.write(successPage());document.close();}
        else{e.textContent=d.message||"Something went wrong.";b.disabled=false;b.textContent="Update Password";}
    })
    .catch(function(){e.textContent="Network error. Please try again.";b.disabled=false;b.textContent="Update Password";});
}
function successPage(){
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Password Updated</title><style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4ff;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:24px}.card{background:white;border-radius:20px;box-shadow:0 8px 32px rgba(47,110,255,.10);padding:48px 32px;max-width:400px;width:100%;text-align:center}.icon{font-size:60px;color:#10b981;margin-bottom:16px}h1{color:#10b981;font-size:26px;margin-bottom:12px}p{color:#6b7280;font-size:15px;line-height:1.6}</style></head><body><div class="card"><div class="icon">&#10003;</div><h1>Password Updated!</h1><p>Your password has been changed successfully. You can now return to the GabayBatas app and sign in with your new password.</p></div></body></html>';
}
</script>
</body>
</html>
HTML;
    $html = str_replace('TOKEN_PLACEHOLDER', $token, $html);
    echo $html;
    exit();
}

/* ── POST: Process the password change ────────────────────────────────────── */
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if ($method !== 'POST') {
    api_error('Method not allowed.', 405);
}

$payload = api_input();
$token   = trim((string)($payload['token'] ?? ''));
$pass    = (string)($payload['password'] ?? '');
$confirm = (string)($payload['password_confirmation'] ?? '');

if ($token === '' || strlen($token) !== 64) {
    api_error('This password reset link is invalid.', 422);
}
if (strlen($pass) < 8) {
    api_error('Password must be at least 8 characters.', 422);
}
if ($pass !== $confirm) {
    api_error('Password confirmation does not match.', 422);
}

$tokenHash = hash('sha256', $token);
$stmt = $conn->prepare(
    'SELECT id, user_id FROM api_password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
);
if (!$stmt) {
    api_error('Unable to validate the password reset link.', 500);
}
$stmt->bind_param('s', $tokenHash);
$stmt->execute();
$result = $stmt->get_result();
$reset  = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$reset) {
    api_error('This password reset link is invalid or expired.', 422);
}

$columns        = api_user_columns($conn);
$passwordColumn = api_pick_column($columns, ['password', 'password_hash']);
if (!$passwordColumn) {
    api_error('Password recovery is not available for this account.', 503);
}

$passwordHash = password_hash($pass, PASSWORD_DEFAULT);
$userId = (string)$reset['user_id'];
$update = $conn->prepare("UPDATE users SET {$passwordColumn} = ? WHERE id = ? LIMIT 1");
$update->bind_param('ss', $passwordHash, $userId);
$update->execute();
$update->close();

$used    = $conn->prepare('UPDATE api_password_reset_tokens SET used_at = NOW() WHERE id = ?');
$resetId = (string)$reset['id'];
$used->bind_param('s', $resetId);
$used->execute();
$used->close();

api_success(['message' => 'Your password has been updated successfully.']);