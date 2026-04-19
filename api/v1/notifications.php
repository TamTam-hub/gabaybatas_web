<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'PATCH') {
    api_error('Method not allowed.', 405);
}

if ($method === 'PATCH') {
    $payload = api_input();
    $notificationId = trim((string)($payload['notification_id'] ?? $_GET['notification_id'] ?? ''));
    if ($notificationId === '') {
        api_error('notification_id is required.', 422);
    }

    $stmt = $conn->prepare('UPDATE api_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) {
        api_error('Unable to update notification.', 500);
    }

    $userId = (string)($user['user_id'] ?? '');
    $stmt->bind_param('ss', $notificationId, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        api_error('Notification not found.', 404);
    }

    api_success([
        'message' => 'Notification marked as read.',
    ], 200);
}

$limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$unreadOnly = api_truthy_value($_GET['unread_only'] ?? false);

$userId = (string)($user['user_id'] ?? '');
$countStmt = $conn->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count FROM api_notifications WHERE user_id = ?');
if (!$countStmt) {
    api_error('Unable to count notifications.', 500);
}

$countStmt->bind_param('s', $userId);
$countStmt->execute();
$countRes = $countStmt->get_result();
$countRow = $countRes ? $countRes->fetch_assoc() : [];
$countStmt->close();

$where = 'user_id = ?';
$types = 's';
$values = [$userId];
if ($unreadOnly) {
    $where .= ' AND is_read = 0';
}

$stmt = $conn->prepare('SELECT id, title, message, type, related_case_id, related_chat_id, is_read, created_at FROM api_notifications WHERE ' . $where . ' ORDER BY created_at DESC LIMIT ? OFFSET ?');
if (!$stmt) {
    api_error('Unable to list notifications.', 500);
}

$types .= 'ii';
$values[] = $limit;
$values[] = $offset;
$stmt->bind_param($types, ...$values);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $notifications[] = [
            'notification_id' => (int)($row['id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'related_case_id' => (string)($row['related_case_id'] ?? ''),
            'related_chat_id' => (int)($row['related_chat_id'] ?? 0),
            'read' => (int)($row['is_read'] ?? 0) === 1,
            'created_at' => api_iso_timestamp($row['created_at'] ?? null),
        ];
    }
}

$stmt->close();

api_success([
    'message' => 'Notifications retrieved.',
    'data' => [
        'total' => (int)($countRow['total'] ?? 0),
        'unread_count' => (int)($countRow['unread_count'] ?? 0),
        'notifications' => $notifications,
        'limit' => $limit,
        'offset' => $offset,
    ],
], 200);