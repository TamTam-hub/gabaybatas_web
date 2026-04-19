<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');
api_ensure_mobile_tables($conn);

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

$tokenData = api_issue_token($conn, $userId);
if (!$tokenData) {
    api_error('Unable to create access token.', 500);
}

$_SESSION['user_id'] = $userId;
$_SESSION['name'] = (string)($user['name'] ?? '');
$_SESSION['role'] = (string)($user['role'] ?? 'resident');
$_SESSION['barangay'] = (string)($user['barangay'] ?? '');

api_success([
    'message' => 'Login successful.',
    'data' => [
        'token' => $tokenData['token'],
        'token_type' => $tokenData['token_type'],
        'expires_at' => $tokenData['expires_at'],
        'user' => api_user_public_payload($user),
    ],
], 200);