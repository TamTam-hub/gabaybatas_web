<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

api_require_method('POST');
api_ensure_mobile_tables($conn);
api_ensure_user_profile_columns($conn);

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

$barangay = api_require_barangay($barangay);

if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !str_ends_with(strtolower($email), '@gmail.com')) {
    api_error('Please use a valid Gmail address ending in @gmail.com.', 422);
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

$nameColumn = api_pick_column($userColumns, ['name', 'full_name']);
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

$verifiedColumn = api_pick_column($userColumns, ['email_verified']);
if ($verifiedColumn) {
    $insertColumns[] = $verifiedColumn;
    $placeholders[] = '?';
    $types .= 'i';
    $values[] = 0;
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

$mailConfigPath = __DIR__ . '/../../config/mail.local.php';
if (!is_file($mailConfigPath)) {
    api_error('Gmail verification is not configured on the server.', 503);
}
$mailConfig = require $mailConfigPath;
$requiredMailKeys = [
    'host',
    'port',
    'username',
    'password',
    'from_email',
    'from_name',
    'verify_url',
];
foreach ($requiredMailKeys as $key) {
    if (!isset($mailConfig[$key]) || trim((string)$mailConfig[$key]) === '') {
        api_error('Gmail verification is not configured on the server.', 503);
    }
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

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiresAt = date('Y-m-d H:i:s', time() + 86400);
$tokenStmt = $conn->prepare('INSERT INTO api_email_verification_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW())');
$userIdString = (string)$userId;
$tokenStmt->bind_param('sss', $userIdString, $tokenHash, $expiresAt);
$tokenStmt->execute();
$tokenStmt->close();

$verifyUrl = rtrim((string)$mailConfig['verify_url'], '/') . '?token=' . urlencode($rawToken);
$mailer = new PHPMailer(true);
try {
    $mailer->isSMTP();
    $mailer->Host = (string)$mailConfig['host'];
    $mailer->Port = (int)$mailConfig['port'];
    $mailer->SMTPAuth = true;
    $mailer->Username = (string)$mailConfig['username'];
    $mailer->Password = (string)$mailConfig['password'];
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->setFrom((string)$mailConfig['from_email'], (string)$mailConfig['from_name']);
    $mailer->addAddress($email, $name);
    $mailer->isHTML(true);
    $mailer->Subject = 'Verify your GabayBatas Gmail address';
    $mailer->Body = '<p>Welcome to GabayBatas.</p><p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '">Verify your Gmail address</a></p><p>This link expires in 24 hours.</p>';
    $mailer->AltBody = "Verify your Gmail address: {$verifyUrl}\nThis link expires in 24 hours.";
    $mailer->send();
} catch (MailException) {
    api_error('Unable to send the verification email. Please try again later.', 502);
}

api_success([
    'message' => 'Account created. Check your Gmail inbox to verify it before signing in.',
    'data' => [
        'user_id' => $userId,
        'email' => $email,
        'name' => $name,
    ],
], 200);