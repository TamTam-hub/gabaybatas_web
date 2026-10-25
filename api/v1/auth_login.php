<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');
api_ensure_mobile_tables($conn);
api_ensure_user_profile_columns($conn);

$payload = api_input();
$credential = trim((string)($payload['email'] ?? ''));
$password = (string)($payload['password'] ?? '');

if ($credential === '' || $password === '') {
    api_error('Email and password are required.', 422);
}

$user = api_user_by_credential($conn, $credential);
if (!$user) {
    api_error('Invalid email or password.', 401);
}

$userColumns = api_user_columns($conn);
$passwordColumn = api_pick_column($userColumns, ['password']);
$statusColumn = api_pick_column($userColumns, ['status']);
$verifiedColumn = api_pick_column($userColumns, ['email_verified']);

if ($passwordColumn === null) {
    api_error('Users table is missing password support.', 500);
}

$stmt = $conn->prepare('SELECT ' . $passwordColumn . ' AS password_hash FROM users WHERE id = ? LIMIT 1');
if (!$stmt) {
    api_error('Unable to verify credentials.', 500);
}

$userId = (string)($user['user_id'] ?? '');
$stmt->bind_param('s', $userId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

$storedPassword = (string)($row['password_hash'] ?? '');
if (!password_verify($password, $storedPassword) && $password !== $storedPassword) {
    api_error('Invalid email or password.', 401);
}

if ($statusColumn !== null) {
    $status = strtolower(trim((string)($user['status'] ?? '')));
    if ($status !== '' && in_array($status, ['inactive', 'disabled', 'suspended'], true)) {
        api_error('Account is inactive.', 403);
    }
}

// THIS IS MOVED OUTSIDE THE statusColumn NULL CHECK!
if ($verifiedColumn !== null &&
    strtolower((string)($user['role'] ?? '')) === 'resident' &&
    (int)($user['email_verified'] ?? 0) !== 1) {
    api_error('Please verify your Gmail address before signing in.', 403);
}

$loginUpdate = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ? LIMIT 1');
if ($loginUpdate) {
    $loginUpdate->bind_param('s', $userId);
    $loginUpdate->execute();
    $loginUpdate->close();
}

$tokenData = api_issue_token($conn, $userId);
if (!$tokenData) {
    api_error('Unable to create access token.', 500);
}

$_SESSION['user_id'] = $userId;
$_SESSION['name'] = (string)($user['name'] ?? '');
$_SESSION['role'] = (string)($user['role'] ?? 'resident');
$_SESSION['barangay'] = 'Barangay MuÃ±oz';

api_success([
    'message' => 'Login successful.',
    'data' => [
        'token' => $tokenData['token'],
        'token_type' => $tokenData['token_type'],
        'expires_at' => $tokenData['expires_at'],
        'user' => api_user_public_payload($user),
    ],
], 200);
