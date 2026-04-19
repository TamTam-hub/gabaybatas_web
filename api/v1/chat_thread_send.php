<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

api_require_method('POST');
$payload = api_input();
$threadId = trim((string)($payload['thread_id'] ?? $_GET['thread_id'] ?? ''));
$content = trim((string)($payload['content'] ?? ''));

if ($threadId === '') {
    api_error('thread_id is required.', 422);
}

if ($content === '') {
    api_error('Message content cannot be empty.', 400);
}

$threadStmt = $conn->prepare('SELECT id FROM api_chat_threads WHERE id = ? AND user_id = ? LIMIT 1');
if (!$threadStmt) {
    api_error('Unable to load chat thread.', 500);
}

$userId = (string)($user['user_id'] ?? '');
$threadStmt->bind_param('ss', $threadId, $userId);
$threadStmt->execute();
$threadRes = $threadStmt->get_result();
$thread = $threadRes ? $threadRes->fetch_assoc() : null;
$threadStmt->close();

if (!$thread) {
    api_error('Chat thread not found.', 404);
}

$senderName = trim((string)($user['name'] ?? ''));
if ($senderName === '') {
    $senderName = trim((string)($user['username'] ?? 'Resident'));
}

$senderRole = trim((string)($user['role'] ?? 'resident'));

$insert = $conn->prepare('INSERT INTO api_chat_messages (thread_id, sender_id, sender_name, sender_role, content, is_read, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())');
if (!$insert) {
    api_error('Unable to send message.', 500);
}

$insert->bind_param('sssss', $threadId, $userId, $senderName, $senderRole, $content);
if (!$insert->execute()) {
    $insert->close();
    api_error('Unable to send message.', 500);
}

$messageId = (int)$insert->insert_id;
$insert->close();

$update = $conn->prepare('UPDATE api_chat_threads SET last_message = ?, last_message_at = NOW(), updated_at = NOW() WHERE id = ? AND user_id = ? LIMIT 1');
if ($update) {
    $update->bind_param('sss', $content, $threadId, $userId);
    $update->execute();
    $update->close();
}

api_success([
    'message' => 'Message sent.',
    'data' => [
        'message_id' => $messageId,
        'thread_id' => (int)$threadId,
        'sender_id' => (int)$userId,
        'sender_name' => $senderName,
        'content' => $content,
        'created_at' => api_now_manila_iso(),
    ],
], 201);