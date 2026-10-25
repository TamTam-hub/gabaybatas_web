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

if ($method === 'GET') {
    $stmt = $conn->prepare('SELECT id, title, created_at, updated_at FROM api_ai_conversations WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 50');
    if (!$stmt) {
        api_error('Unable to load AI conversations.', 500);
    }
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $conversations = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $conversations[] = [
                'conversation_id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? 'New chat'),
                'created_at' => api_iso_timestamp($row['created_at'] ?? null),
                'updated_at' => api_iso_timestamp($row['updated_at'] ?? null),
            ];
        }
    }
    $stmt->close();

    if ($conversations === []) {
        $legacyStmt = $conn->prepare('SELECT role, content, created_at FROM api_ai_messages WHERE user_id = ? ORDER BY created_at ASC, id ASC');
        if ($legacyStmt) {
            $legacyStmt->bind_param('s', $userId);
            $legacyStmt->execute();
            $legacyResult = $legacyStmt->get_result();
            $legacyMessages = [];
            if ($legacyResult) {
                while ($row = $legacyResult->fetch_assoc()) {
                    $legacyMessages[] = $row;
                }
            }
            $legacyStmt->close();

            if ($legacyMessages !== []) {
                $title = 'Previous conversation';
                foreach ($legacyMessages as $legacyMessage) {
                    if (strtolower((string)($legacyMessage['role'] ?? '')) === 'user') {
                        $candidate = trim((string)($legacyMessage['content'] ?? ''));
                        if ($candidate !== '') {
                            $title = function_exists('mb_substr')
                                ? mb_substr($candidate, 0, 48)
                                : substr($candidate, 0, 48);
                            break;
                        }
                    }
                }
                $create = $conn->prepare('INSERT INTO api_ai_conversations (user_id, title, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
                if ($create) {
                    $create->bind_param('ss', $userId, $title);
                    if ($create->execute()) {
                        $conversationId = (int)$create->insert_id;
                        $create->close();
                        $copy = $conn->prepare('INSERT INTO api_ai_conversation_messages (conversation_id, user_id, role, content, created_at) VALUES (?, ?, ?, ?, ?)');
                        if ($copy) {
                            foreach ($legacyMessages as $legacyMessage) {
                                $role = (string)($legacyMessage['role'] ?? 'model');
                                $content = (string)($legacyMessage['content'] ?? '');
                                $createdAt = (string)($legacyMessage['created_at'] ?? date('Y-m-d H:i:s'));
                                $copy->bind_param('sssss', $conversationId, $userId, $role, $content, $createdAt);
                                $copy->execute();
                            }
                            $copy->close();
                        }
                        $conversations[] = [
                            'conversation_id' => $conversationId,
                            'title' => $title,
                            'created_at' => api_iso_timestamp(null),
                            'updated_at' => api_iso_timestamp(null),
                        ];
                    } else {
                        $create->close();
                    }
                }
            }
        }
    }
    api_success(['message' => 'AI conversations retrieved.', 'data' => ['conversations' => $conversations]]);
}

if ($method === 'POST' || $method === 'PATCH') {
    $payload = api_json_input();
    $title = trim((string)($payload['title'] ?? 'New chat'));
    if ($title === '') {
        $title = 'New chat';
    }
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 191) : substr($title, 0, 191);
    if ($method === 'PATCH') {
        $conversationId = trim((string)($_GET['conversation_id'] ?? $_GET['id'] ?? ''));
        if ($conversationId === '') {
            api_error('conversation_id is required.', 422);
        }
        $stmt = $conn->prepare('UPDATE api_ai_conversations SET title = ? WHERE id = ? AND user_id = ?');
        if (!$stmt) {
            api_error('Unable to rename AI conversation.', 500);
        }
        $stmt->bind_param('sss', $title, $conversationId, $userId);
        $stmt->execute();
        $updated = $stmt->errno === 0;
        $stmt->close();
        if (!$updated) {
            api_error('AI conversation not found.', 404);
        }
        api_success(['message' => 'AI conversation renamed.']);
    }
    $stmt = $conn->prepare('INSERT INTO api_ai_conversations (user_id, title, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
    if (!$stmt) {
        api_error('Unable to create AI conversation.', 500);
    }
    $stmt->bind_param('ss', $userId, $title);
    if (!$stmt->execute()) {
        $stmt->close();
        api_error('Unable to create AI conversation.', 500);
    }
    $conversationId = (int)$stmt->insert_id;
    $stmt->close();
    api_success([
        'message' => 'AI conversation created.',
        'data' => ['conversation_id' => $conversationId, 'title' => $title],
    ], 201);
}

if ($method === 'DELETE') {
    $conversationId = trim((string)($_GET['conversation_id'] ?? $_GET['id'] ?? ''));
    if ($conversationId === '') {
        api_error('conversation_id is required.', 422);
    }
    $stmt = $conn->prepare('DELETE FROM api_ai_conversations WHERE id = ? AND user_id = ?');
    if (!$stmt) {
        api_error('Unable to delete AI conversation.', 500);
    }
    $stmt->bind_param('ss', $conversationId, $userId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$deleted) {
        api_error('AI conversation not found.', 404);
    }
    $messageStmt = $conn->prepare('DELETE FROM api_ai_conversation_messages WHERE conversation_id = ? AND user_id = ?');
    if ($messageStmt) {
        $messageStmt->bind_param('ss', $conversationId, $userId);
        $messageStmt->execute();
        $messageStmt->close();
    }
    api_success(['message' => 'AI conversation deleted.']);
}

api_error('Method not allowed.', 405);
