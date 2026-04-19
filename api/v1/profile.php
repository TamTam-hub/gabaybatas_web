<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized. Token is invalid or expired.', 401);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$userColumns = api_user_columns($conn);

if ($method === 'GET') {
    api_success([
        'message' => 'Profile retrieved.',
        'data' => api_user_public_payload($user),
    ], 200);
}

if ($method !== 'PUT' && $method !== 'PATCH') {
    api_error('Method not allowed.', 405);
}

$payload = api_input();
$current = api_user_public_payload($user);

$name = trim((string)($payload['name'] ?? $current['name'] ?? ''));
$email = trim((string)($payload['email'] ?? $current['email'] ?? ''));
$contactNumber = trim((string)($payload['contact_number'] ?? $current['contact_number'] ?? ''));
$age = trim((string)($payload['age'] ?? $current['age'] ?? ''));
$barangay = trim((string)($payload['barangay'] ?? $current['barangay'] ?? ''));

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_error('Please enter a valid email address.', 422);
}

$nameColumn = api_pick_column($userColumns, ['name']);
$emailColumn = api_pick_column($userColumns, ['email']);
$contactColumn = api_pick_column($userColumns, ['contact_number', 'contact', 'phone']);
$ageColumn = api_pick_column($userColumns, ['age']);
$barangayColumn = api_pick_column($userColumns, ['barangay']);
$updatedAtColumn = api_pick_column($userColumns, ['updated_at']);

$setParts = [];
$types = '';
$values = [];

if ($nameColumn && $name !== (string)$current['name']) {
    $setParts[] = $nameColumn . ' = ?';
    $types .= 's';
    $values[] = $name;
}

if ($emailColumn && $email !== (string)$current['email']) {
    $dupe = api_user_by_credential($conn, $email);
    if ($dupe && (int)($dupe['user_id'] ?? 0) !== (int)($current['user_id'] ?? 0)) {
        api_error('Email already in use by another account.', 422);
    }
    $setParts[] = $emailColumn . ' = ?';
    $types .= 's';
    $values[] = $email;
}

if ($contactColumn && $contactNumber !== (string)$current['contact_number']) {
    $setParts[] = $contactColumn . ' = ?';
    $types .= 's';
    $values[] = $contactNumber;
}

if ($ageColumn && $age !== (string)$current['age']) {
    $setParts[] = $ageColumn . ' = ?';
    $types .= 's';
    $values[] = $age;
}

if ($barangayColumn && $barangay !== (string)$current['barangay']) {
    $setParts[] = $barangayColumn . ' = ?';
    $types .= 's';
    $values[] = $barangay;
}

if (!$setParts) {
    api_success([
        'message' => 'No changes detected.',
        'data' => api_user_public_payload($user),
    ], 200);
}

if ($updatedAtColumn) {
    $setParts[] = $updatedAtColumn . ' = ?';
    $types .= 's';
    $values[] = date('Y-m-d H:i:s');
}

$sql = 'UPDATE users SET ' . implode(', ', $setParts) . ' WHERE id = ? LIMIT 1';
$types .= 's';
$values[] = (string)$current['user_id'];

$stmt = $conn->prepare($sql);
if (!$stmt) {
    api_error('Unable to prepare profile update query.', 500);
}

$stmt->bind_param($types, ...$values);
if (!$stmt->execute()) {
    $stmt->close();
    api_error('Unable to update profile right now.', 500);
}

$stmt->close();

$fresh = api_user_by_id($conn, (string)$current['user_id']) ?? $user;

api_success([
    'message' => 'Profile updated successfully.',
    'data' => api_user_public_payload($fresh),
], 200);