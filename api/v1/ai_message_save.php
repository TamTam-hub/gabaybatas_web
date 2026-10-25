<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
api_require_method('POST');

$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

$payload = api_json_input();
$userMessage = trim((string)($payload['user_message'] ?? ''));
$assistantMessage = trim((string)($payload['assistant_message'] ?? ''));
if ($userMessage === '' || $assistantMessage === '') {
    api_error('Both conversation messages are required.', 422);
}

$userId = (string)($user['user_id'] ?? '');
$stmt = $conn->prepare('INSERT INTO api_ai_messages (user_id, role, content, created_at) VALUES (?, ?, ?, NOW()), (?, ?, ?, NOW())');
if (!$stmt) {
    api_error('Unable to save AI conversation.', 500);
}

$userRole = 'user';
$modelRole = 'model';
$stmt->bind_param('ssssss', $userId, $userRole, $userMessage, $userId, $modelRole, $assistantMessage);
if (!$stmt->execute()) {
    $stmt->close();
    api_error('Unable to save AI conversation.', 500);
}
$stmt->close();

api_success(['message' => 'AI conversation saved.']);
