<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

$userId = (string)($user['user_id'] ?? '');
$limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$seedStmt = $conn->prepare('SELECT COUNT(*) AS total FROM api_chat_threads WHERE user_id = ?');
if (!$seedStmt) {
    api_error('Unable to load chat threads.', 500);
}

$seedStmt->bind_param('s', $userId);
$seedStmt->execute();
$seedRes = $seedStmt->get_result();
$seedRow = $seedRes ? $seedRes->fetch_assoc() : [];
$seedStmt->close();

if ((int)($seedRow['total'] ?? 0) === 0) {
    $caseColumns = api_case_columns_v1($conn);
    if ($caseColumns) {
        [$scopeClauses, $scopeTypes, $scopeValues] = api_scope_case_query($conn, $user);
        $scopeWhere = $scopeClauses ? ('WHERE ' . implode(' OR ', $scopeClauses)) : '';
        $caseIdColumn = api_pick_column($caseColumns, ['case_id', 'id']);
        $referenceColumn = api_pick_column($caseColumns, ['case_id']);
        $caseNameColumn = api_pick_column($caseColumns, ['resident_name', 'name']);

        $sql = 'SELECT ' . ($caseIdColumn ? $caseIdColumn . ' AS case_row_id' : 'id AS case_row_id') . ', ' . ($referenceColumn ? $referenceColumn . ' AS reference_number' : "'' AS reference_number") . ', ' . ($caseNameColumn ? $caseNameColumn . ' AS resident_name' : "'' AS resident_name") . ' FROM cases ' . $scopeWhere . ' ORDER BY ' . ($caseIdColumn ?: 'id') . ' DESC LIMIT 20';
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($scopeTypes !== '') {
                $stmt->bind_param($scopeTypes, ...$scopeValues);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $threadCaseId = (string)($row['case_row_id'] ?? '');
                    $referenceNumber = trim((string)($row['reference_number'] ?? ''));
                    $participantName = trim((string)($row['resident_name'] ?? 'Resident'));
                    if ($participantName === '') {
                        $participantName = 'Barangay Officer';
                    }

                    $insert = $conn->prepare('INSERT INTO api_chat_threads (user_id, case_id, case_reference, participant_name, last_message, last_message_at, unread_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), 0, NOW(), NOW())');
                    if ($insert) {
                        $lastMessage = 'Thread created for case tracking.';
                        $insert->bind_param('sssss', $userId, $threadCaseId, $referenceNumber, $participantName, $lastMessage);
                        $insert->execute();
                        $insert->close();
                    }
                }
            }
            $stmt->close();
        }
    }
}

$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM api_chat_threads WHERE user_id = ?');
if (!$countStmt) {
    api_error('Unable to count chat threads.', 500);
}

$countStmt->bind_param('s', $userId);
$countStmt->execute();
$countRes = $countStmt->get_result();
$countRow = $countRes ? $countRes->fetch_assoc() : [];
$countStmt->close();

$sql = 'SELECT t.id, t.case_id, t.case_reference, t.participant_name, t.last_message, t.last_message_at, t.unread_count FROM api_chat_threads t WHERE t.user_id = ? ORDER BY t.updated_at DESC LIMIT ? OFFSET ?';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    api_error('Unable to list chat threads.', 500);
}

$stmt->bind_param('sii', $userId, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

$threads = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $threads[] = [
            'thread_id' => (int)($row['id'] ?? 0),
            'case_id' => (string)($row['case_id'] ?? ''),
            'case_reference' => (string)($row['case_reference'] ?? ''),
            'participant_name' => (string)($row['participant_name'] ?? ''),
            'last_message' => (string)($row['last_message'] ?? ''),
            'last_message_at' => api_iso_timestamp($row['last_message_at'] ?? null),
            'unread_count' => (int)($row['unread_count'] ?? 0),
        ];
    }
}

$stmt->close();

api_success([
    'message' => 'Chat threads retrieved.',
    'data' => [
        'total' => (int)($countRow['total'] ?? 0),
        'threads' => $threads,
        'limit' => $limit,
        'offset' => $offset,
    ],
], 200);