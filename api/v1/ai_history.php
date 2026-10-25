<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
api_require_method('GET');

$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

$userId = (string)($user['user_id'] ?? '');
$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$stmt = $conn->prepare('SELECT id, role, content, created_at FROM api_ai_messages WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?');
if (!$stmt) {
    api_error('Unable to load AI conversation.', 500);
}

$stmt->bind_param('si', $userId, $limit);
$stmt->execute();
$result = $stmt->get_result();
$messages = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            'message_id' => (int)($row['id'] ?? 0),
            'role' => (string)($row['role'] ?? ''),
            'content' => (string)($row['content'] ?? ''),
            'created_at' => api_iso_timestamp($row['created_at'] ?? null),
        ];
    }
}
$stmt->close();

api_success([
    'message' => 'AI conversation retrieved.',
    'data' => ['messages' => array_reverse($messages)],
]);
