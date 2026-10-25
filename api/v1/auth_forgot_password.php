<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

api_ensure_mobile_tables($conn);
$payload = api_input();
$email = strtolower(trim((string)($payload['email'] ?? '')));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_error('Enter a valid email address.', 422);
}

$user = api_user_by_credential($conn, $email);
$genericMessage = 'If an account uses that email, a password reset link has been sent.';

if (!$user ||
    strtolower((string)($user['role'] ?? '')) === 'resident' &&
    (int)($user['email_verified'] ?? 0) !== 1) {
    api_success(['message' => $genericMessage]);
}

$configPath = __DIR__ . '/../../config/mail.local.php';
if (!is_file($configPath)) {
    api_error('Password recovery is not configured on the server.', 503);
}

$mailConfig = require $configPath;
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$userId = (string)($user['user_id'] ?? '');

$conn->query("DELETE FROM api_password_reset_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL");
$stmt = $conn->prepare('INSERT INTO api_password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())');
if (!$stmt) {
    api_error('Unable to create a password reset request.', 500);
}
$stmt->bind_param('ss', $userId, $tokenHash);
$stmt->execute();
$stmt->close();

$resetUrl = rtrim((string)$mailConfig['reset_url'], '/') . '?token=' . urlencode($rawToken);
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
    $mailer->addAddress($email, (string)($user['name'] ?? 'GabayBatas user'));
    $mailer->isHTML(true);
    $mailer->Subject = 'Reset your GabayBatas password';
    $mailer->Body = '<p>We received a request to reset your GabayBatas password.</p><p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Reset your password</a></p><p>This link expires in one hour.</p>';
    $mailer->AltBody = "Reset your password: {$resetUrl}\nThis link expires in one hour.";
    $mailer->send();
} catch (MailException) {
    api_error('Unable to send the password reset email right now.', 502);
}

api_success(['message' => $genericMessage]);
