<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
api_ensure_user_profile_columns($conn);
$token = trim((string)($_GET['token'] ?? ''));

if ($token === '' || strlen($token) !== 64) {
    api_error('This verification link is invalid.', 422);
}

$tokenHash = hash('sha256', $token);
$stmt = $conn->prepare('SELECT id, user_id FROM api_email_verification_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
if (!$stmt) {
    api_error('Unable to validate the verification link.', 500);
}
$stmt->bind_param('s', $tokenHash);
$stmt->execute();
$result = $stmt->get_result();
$verification = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$verification) {
    api_error('This verification link is invalid or expired.', 422);
}

$userId = (string)$verification['user_id'];
$update = $conn->prepare('UPDATE users SET email_verified = 1 WHERE id = ? LIMIT 1');
$update->bind_param('s', $userId);
$update->execute();
$update->close();

$used = $conn->prepare('UPDATE api_email_verification_tokens SET used_at = NOW() WHERE id = ?');
$verificationId = (string)$verification['id'];
$used->bind_param('s', $verificationId);
$used->execute();
$used->close();

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>GabayBatas</title></head><body style="font-family:Arial,sans-serif;text-align:center;padding:48px;color:#12284b"><h1>Email verified</h1><p>Your Gmail address is verified. You can now sign in to GabayBatas.</p></body></html>';
