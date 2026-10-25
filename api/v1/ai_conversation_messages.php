<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

$userId = (string)($user['user_id'] ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$conversationId = trim((string)($_GET['conversation_id'] ?? $_GET['id'] ?? ''));
if ($conversationId === '') {
    $payload = api_json_input();
    $conversationId = trim((string)($payload['conversation_id'] ?? ''));
} else {
    $payload = [];
}
if ($conversationId === '') {
    api_error('conversation_id is required.', 422);
}

$ownerStmt = $conn->prepare('SELECT id FROM api_ai_conversations WHERE id = ? AND user_id = ? LIMIT 1');
if (!$ownerStmt) {
    api_error('Unable to verify AI conversation.', 500);
}
$ownerStmt->bind_param('ss', $conversationId, $userId);
$ownerStmt->execute();
$ownerResult = $ownerStmt->get_result();
$owned = $ownerResult && $ownerResult->fetch_assoc();
$ownerStmt->close();
if (!$owned) {
    api_error('AI conversation not found.', 404);
}

if ($method === 'GET') {
    $stmt = $conn->prepare('SELECT id, role, content, created_at FROM api_ai_conversation_messages WHERE conversation_id = ? AND user_id = ? ORDER BY created_at ASC, id ASC');
    if (!$stmt) {
        api_error('Unable to load AI conversation messages.', 500);
    }
    $stmt->bind_param('ss', $conversationId, $userId);
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
    api_success(['message' => 'AI conversation messages retrieved.', 'data' => ['messages' => $messages]]);
}

if ($method === 'POST') {
    if (!$payload) {
        $payload = api_json_input();
    }
    $userMessage = trim((string)($payload['user_message'] ?? ''));
    $assistantMessage = trim((string)($payload['assistant_message'] ?? ''));
    if ($userMessage === '' || $assistantMessage === '') {
        api_error('Both conversation messages are required.', 422);
    }
    $stmt = $conn->prepare('INSERT INTO api_ai_conversation_messages (conversation_id, user_id, role, content, created_at) VALUES (?, ?, ?, ?, NOW()), (?, ?, ?, ?, NOW())');
    if (!$stmt) {
        api_error('Unable to save AI conversation.', 500);
    }
    $userRole = 'user';
    $modelRole = 'model';
    $stmt->bind_param('ssssssss', $conversationId, $userId, $userRole, $userMessage, $conversationId, $userId, $modelRole, $assistantMessage);
    if (!$stmt->execute()) {
        $stmt->close();
        api_error('Unable to save AI conversation.', 500);
    }
    $stmt->close();
    $update = $conn->prepare('UPDATE api_ai_conversations SET updated_at = NOW() WHERE id = ? AND user_id = ?');
    if ($update) {
        $update->bind_param('ss', $conversationId, $userId);
        $update->execute();
        $update->close();
    }
    api_success(['message' => 'AI conversation saved.']);
}

api_error('Method not allowed.', 405);
