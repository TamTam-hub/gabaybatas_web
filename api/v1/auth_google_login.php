<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');
api_ensure_mobile_tables($conn);
api_ensure_user_profile_columns($conn);

$payload = api_input();
$idToken = trim((string)($payload['id_token'] ?? ''));
$clientName = trim((string)($payload['name'] ?? ''));
$clientEmail = trim((string)($payload['email'] ?? ''));

if ($idToken === '') {
    api_error('Google ID token is required.', 422);
}

// ---------- Verify ID token with Google ----------
$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
$ch = curl_init($verifyUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    api_error('Google token verification failed: ' . ($curlError ?: 'HTTP ' . $httpCode), 401);
}

$tokenData = json_decode($response, true);
if (!is_array($tokenData) || empty($tokenData['email'])) {
    api_error('Invalid Google token payload.', 401);
}

$googleEmail = strtolower(trim((string)$tokenData['email']));
$googleName  = trim((string)($tokenData['name'] ?? $clientName));

if (!($tokenData['email_verified'] ?? false)) {
    api_error('Google account email is not verified.', 403);
}

// ---------- Find existing user by email ----------
$user = api_user_by_credential($conn, $googleEmail);

if (!$user) {
    // ---------- Auto-register as a resident ----------
    $userColumns = api_user_columns($conn);
    if (!$userColumns) {
        api_error('Unable to access users table.', 500);
    }

    $fields = [];
    $placeholders = [];
    $types = '';
    $values = [];

    // name
    $nameCol = api_pick_column($userColumns, ['name', 'username']);
    if ($nameCol) {
        $fields[] = $nameCol;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $googleName !== '' ? $googleName : $googleEmail;
    }

    // email
    if (in_array('email', $userColumns, true)) {
        $fields[] = 'email';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $googleEmail;
    }

    // email_verified — set to 1 since Google already verified it
    if (in_array('email_verified', $userColumns, true)) {
        $fields[] = 'email_verified';
        $placeholders[] = '1';
    }

    // password — set a random unusable hash
    $passCol = api_pick_column($userColumns, ['password']);
    if ($passCol) {
        $fields[] = $passCol;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    // role
    if (in_array('role', $userColumns, true)) {
        $fields[] = 'role';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = 'resident';
    }

    // status
    if (in_array('status', $userColumns, true)) {
        $fields[] = 'status';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = 'active';
    }

    // timestamps
    $now = date('Y-m-d H:i:s');
    if (in_array('created_at', $userColumns, true)) {
        $fields[] = 'created_at';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $now;
    }
    if (in_array('updated_at', $userColumns, true)) {
        $fields[] = 'updated_at';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $now;
    }

    $sql = 'INSERT INTO users (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        api_error('Unable to create account: ' . $conn->error, 500);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        api_error('Unable to create account: ' . $conn->error, 500);
    }

    $newUserId = (string)$stmt->insert_id;
    $stmt->close();

    $user = api_user_by_id($conn, $newUserId);
    if (!$user) {
        api_error('Account was created but could not be retrieved.', 500);
    }
}

// ---------- Check account status ----------
$statusColumn = api_pick_column(api_user_columns($conn), ['status']);
if ($statusColumn !== null) {
    $status = strtolower(trim((string)($user['status'] ?? '')));
    if ($status !== '' && in_array($status, ['inactive', 'disabled', 'suspended'], true)) {
        api_error('Account is inactive.', 403);
    }
}

// ---------- Issue token ----------
$userId = (string)($user['user_id'] ?? $user['id'] ?? '');
if ($userId === '') {
    api_error('Unable to determine user ID.', 500);
}

$loginUpdate = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ? LIMIT 1');
if ($loginUpdate) {
    $loginUpdate->bind_param('s', $userId);
    $loginUpdate->execute();
    $loginUpdate->close();
}

$tokenResult = api_issue_token($conn, $userId);
if (!$tokenResult) {
    api_error('Unable to create access token.', 500);
}

$_SESSION['user_id']  = $userId;
$_SESSION['name']     = (string)($user['name'] ?? '');
$_SESSION['role']     = (string)($user['role'] ?? 'resident');
$_SESSION['barangay'] = 'Barangay Muñoz';

api_success([
    'message' => 'Google login successful.',
    'data'    => [
        'token'      => $tokenResult['token'],
        'token_type' => $tokenResult['token_type'],
        'expires_at' => $tokenResult['expires_at'],
        'user'       => api_user_public_payload($user),
    ],
], 200);
