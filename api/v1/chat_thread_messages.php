<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

$threadId = trim((string)($_GET['thread_id'] ?? $_GET['id'] ?? ''));
if ($threadId === '') {
    api_error('thread_id is required.', 422);
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

$limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM api_chat_messages WHERE thread_id = ?');
if (!$countStmt) {
    api_error('Unable to count messages.', 500);
}

$countStmt->bind_param('s', $threadId);
$countStmt->execute();
$countRes = $countStmt->get_result();
$countRow = $countRes ? $countRes->fetch_assoc() : [];
$countStmt->close();

$stmt = $conn->prepare('SELECT id, sender_id, sender_name, sender_role, content, created_at FROM api_chat_messages WHERE thread_id = ? ORDER BY created_at ASC LIMIT ? OFFSET ?');
if (!$stmt) {
    api_error('Unable to load messages.', 500);
}

$stmt->bind_param('sii', $threadId, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            'message_id' => (int)($row['id'] ?? 0),
            'sender_id' => (int)($row['sender_id'] ?? 0),
            'sender_name' => (string)($row['sender_name'] ?? ''),
            'sender_role' => (string)($row['sender_role'] ?? ''),
            'content' => (string)($row['content'] ?? ''),
            'created_at' => api_iso_timestamp($row['created_at'] ?? null),
        ];
    }
}

$stmt->close();

api_success([
    'message' => 'Messages retrieved.',
    'data' => [
        'thread_id' => (int)$threadId,
        'total' => (int)($countRow['total'] ?? 0),
        'messages' => $messages,
    ],
], 200);