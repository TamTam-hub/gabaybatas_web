<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/_bootstrap.php';

api_require_method('GET');
api_ensure_mobile_tables($conn);

$evidenceId = (int)($_GET['id'] ?? 0);
if ($evidenceId <= 0) {
    api_error('Evidence file not found.', 404);
}

$user = api_current_user($conn);
$isStaff = in_array(strtolower((string)($user['role'] ?? '')), ['admin', 'barangay'], true);
$sessionRole = strtolower((string)($_SESSION['role'] ?? ''));
if (!$user && !in_array($sessionRole, ['admin', 'barangay'], true)) {
    api_error('Unauthorized.', 401);
}

$stmt = $conn->prepare(
    'SELECT e.stored_filename, e.original_filename, e.mime_type, e.case_id, c.barangay, c.user_id
     FROM api_case_evidence e
     LEFT JOIN cases c ON c.id = e.case_id
     WHERE e.id = ? LIMIT 1'
);
if (!$stmt) {
    api_error('Unable to prepare evidence query.', 500);
}
$stmt->bind_param('i', $evidenceId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    api_error('Evidence file not found.', 404);
}

if (!$isStaff && $user) {
    $ownerId = (string)($user['user_id'] ?? '');
    if ($ownerId === '' || $ownerId !== (string)($row['user_id'] ?? '')) {
        api_error('You are not allowed to access this evidence.', 403);
    }
}
if ($sessionRole === 'barangay') {
    $userBarangay = trim((string)($_SESSION['barangay'] ?? ''));
    $caseBarangay = trim((string)($row['barangay'] ?? ''));
    if ($userBarangay !== '' && $caseBarangay !== '') {
        $cleanUserBgy = strtolower(preg_replace('/[^a-z0-9]/i', '', str_ireplace('barangay', '', $userBarangay)));
        $cleanCaseBgy = strtolower(preg_replace('/[^a-z0-9]/i', '', str_ireplace('barangay', '', $caseBarangay)));
        if ($cleanUserBgy !== '' && $cleanCaseBgy !== '' && $cleanUserBgy !== $cleanCaseBgy) {
            api_error('You are not allowed to access evidence outside your barangay.', 403);
        }
    }
}

$path = __DIR__ . '/../../storage/case_evidence/' . basename((string)$row['stored_filename']);
if (!is_file($path)) {
    api_error('Evidence file is unavailable.', 404);
}

header('Content-Type: ' . (string)$row['mime_type']);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . addcslashes((string)$row['original_filename'], '"\\') . '"');
readfile($path);
exit();
