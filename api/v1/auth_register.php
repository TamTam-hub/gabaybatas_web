<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');
api_ensure_mobile_tables($conn);

$payload = api_input();

$name = trim((string)($payload['name'] ?? ''));
$email = trim((string)($payload['email'] ?? ''));
$password = (string)($payload['password'] ?? '');
$passwordConfirmation = (string)($payload['password_confirmation'] ?? '');
$age = trim((string)($payload['age'] ?? ''));
$contactNumber = trim((string)($payload['contact_number'] ?? ''));
$barangay = trim((string)($payload['barangay'] ?? ''));

if ($name === '' || $email === '' || $password === '' || $passwordConfirmation === '' || $barangay === '') {
    api_error('Missing required fields.', 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_error('Please enter a valid email address.', 422);
}

if (strlen($password) < 8) {
    api_error('Password must be at least 8 characters long.', 422);
}

if ($password !== $passwordConfirmation) {
    api_error('Password confirmation does not match.', 422);
}

$userColumns = api_user_columns($conn);
if (!$userColumns) {
    api_error('Users table is not available.', 500);
}

if (api_user_by_credential($conn, $email)) {
    api_error('Email already in use.', 422);
}

$nameColumn = api_pick_column($userColumns, ['name']);
$usernameColumn = api_pick_column($userColumns, ['username']);
$emailColumn = api_pick_column($userColumns, ['email']);
$passwordColumn = api_pick_column($userColumns, ['password']);
$roleColumn = api_pick_column($userColumns, ['role']);
$barangayColumn = api_pick_column($userColumns, ['barangay']);
$statusColumn = api_pick_column($userColumns, ['status']);
$contactColumn = api_pick_column($userColumns, ['contact_number', 'contact', 'phone']);
$ageColumn = api_pick_column($userColumns, ['age']);
$createdAtColumn = api_pick_column($userColumns, ['created_at']);
$updatedAtColumn = api_pick_column($userColumns, ['updated_at']);

$insertColumns = [];
$placeholders = [];
$types = '';
$values = [];

if ($nameColumn) {
    $insertColumns[] = $nameColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $name;
}

if ($usernameColumn) {
    $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', explode('@', $email)[0] ?? $name) ?? 'user');
    $baseUsername = trim($baseUsername, '.');
    if ($baseUsername === '') {
        $baseUsername = 'user';
    }

    $candidate = $baseUsername;
    $suffix = 0;
    while (api_user_by_credential($conn, $candidate)) {
        $suffix++;
        $candidate = $baseUsername . $suffix;
    }

    $insertColumns[] = $usernameColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $candidate;
}

if ($emailColumn) {
    $insertColumns[] = $emailColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $email;
}

if ($passwordColumn) {
    $insertColumns[] = $passwordColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = password_hash($password, PASSWORD_DEFAULT);
}

if ($roleColumn) {
    $insertColumns[] = $roleColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = 'resident';
}

if ($barangayColumn) {
    $insertColumns[] = $barangayColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $barangay;
}

if ($statusColumn) {
    $insertColumns[] = $statusColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = 'Active';
}

if ($contactColumn) {
    $insertColumns[] = $contactColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $contactNumber;
}

if ($ageColumn) {
    $insertColumns[] = $ageColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $age;
}

if ($createdAtColumn) {
    $insertColumns[] = $createdAtColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = date('Y-m-d H:i:s');
}

if ($updatedAtColumn) {
    $insertColumns[] = $updatedAtColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = date('Y-m-d H:i:s');
}

$sql = 'INSERT INTO users (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    api_error('Unable to prepare registration query.', 500);
}

$stmt->bind_param($types, ...$values);
if (!$stmt->execute()) {
    $stmt->close();
    api_error('Unable to create account right now.', 500);
}

$userId = (int)$stmt->insert_id;
$stmt->close();

api_success([
    'message' => 'Account created successfully. You can now sign in.',
    'data' => [
        'user_id' => $userId,
        'email' => $email,
        'name' => $name,
    ],
], 200);