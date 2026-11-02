<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');
api_ensure_mobile_tables($conn);

$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

$payload = api_input();

$caseIdRaw = trim((string)($_POST['case_id'] ?? $payload['case_id'] ?? ''));
if ($caseIdRaw === '') {
    api_error('Missing required field: case_id.', 422);
}

$additionalInfo = trim((string)($_POST['additional_info'] ?? $_POST['description'] ?? $payload['additional_info'] ?? $payload['description'] ?? ''));
// The complaint reason ("tungod ...") as corrected by the resident; the statement is rebuilt from it.
$reason = trim((string)($_POST['reason'] ?? $payload['reason'] ?? ''));
$complainedPerson = trim((string)($_POST['complained_person'] ?? $payload['complained_person'] ?? ''));

$removeRaw = $_POST['remove_evidence_ids'] ?? $payload['remove_evidence_ids'] ?? [];
if (!is_array($removeRaw)) {
    $removeRaw = explode(',', (string)$removeRaw);
}
$removeIds = array_values(array_unique(array_filter(array_map('intval', $removeRaw), fn($id) => $id > 0)));

$userId = (int)($user['user_id'] ?? 0);

// Find case owned by this resident
$stmt = $conn->prepare("SELECT * FROM cases WHERE (CAST(id AS CHAR) = ? OR LOWER(case_id) = LOWER(?)) AND user_id = ? LIMIT 1");
if (!$stmt) {
    api_error('Database error preparing case lookup.', 500);
}
$stmt->bind_param('ssi', $caseIdRaw, $caseIdRaw, $userId);
$stmt->execute();
$caseRes = $stmt->get_result();
$case = $caseRes ? $caseRes->fetch_assoc() : null;
$stmt->close();

if (!$case) {
    api_error('Case not found or does not belong to your account.', 404);
}

$caseId = (int)$case['id'];
$currentKpStatus = trim((string)($case['kp_status'] ?? 'filed'));
$currentStatus = trim((string)($case['status'] ?? 'Pending'));

// Disallow edits if case is in an irreversible final state
$nonEditableStatuses = ['settled', 'settlement_final', 'execution_completed', 'closed', 'dismissed', 'not_eligible'];
if (in_array($currentKpStatus, $nonEditableStatuses, true)) {
    api_error('This case is already in final disposition and cannot be modified.', 422);
}

// Collect uploaded photos (evidence_images[] or any evidence_image* key).
$uploadedImages = [];
foreach ($_FILES as $key => $fileData) {
    if ($key === 'evidence_video' || strpos($key, 'evidence_image') === false) {
        continue;
    }
    $names = (array)$fileData['name'];
    foreach (array_keys($names) as $i) {
        $error = is_array($fileData['error']) ? ($fileData['error'][$i] ?? UPLOAD_ERR_NO_FILE) : $fileData['error'];
        $size = (int)(is_array($fileData['size']) ? ($fileData['size'][$i] ?? 0) : $fileData['size']);
        if ($error !== UPLOAD_ERR_OK || $size <= 0) {
            continue;
        }
        $uploadedImages[] = [
            'tmp_name' => is_array($fileData['tmp_name']) ? $fileData['tmp_name'][$i] : $fileData['tmp_name'],
            'name' => $names[$i],
            'type' => (is_array($fileData['type']) ? ($fileData['type'][$i] ?? '') : $fileData['type']) ?: 'image/jpeg',
            'size' => $size,
        ];
    }
}
$video = (!empty($_FILES['evidence_video']) && ($_FILES['evidence_video']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($_FILES['evidence_video']['size'] ?? 0) > 0)
    ? $_FILES['evidence_video'] : null;

// Evidence the resident chose to remove (only files of this case), and what stays.
$existingEvidence = api_case_evidence($conn, $caseId);
$removeIds = array_values(array_intersect($removeIds, array_map(fn($ev) => (int)$ev['id'], $existingEvidence)));
$keptImages = 0;
$keptVideoIds = [];
foreach ($existingEvidence as $ev) {
    if (in_array((int)$ev['id'], $removeIds, true)) {
        continue;
    }
    if ($ev['type'] === 'video') {
        $keptVideoIds[] = (int)$ev['id'];
    } else {
        $keptImages++;
    }
}
if ($keptImages + count($uploadedImages) > 3) {
    api_error('A case can have at most 3 photos. Remove a photo first before adding another.', 422);
}
// A new video replaces the one already attached.
if ($video) {
    $removeIds = array_merge($removeIds, $keptVideoIds);
}

if ($additionalInfo === '' && $reason === '' && $complainedPerson === '' && !$uploadedImages && !$video && !$removeIds) {
    api_error('Provide additional information or update your evidence.', 422);
}

$conn->begin_transaction();

$updateParts = [];
$updateTypes = '';
$updateValues = [];
$updateLines = [];

// 1. Update the complaint statement
$existingDesc = trim((string)($case['description'] ?? ''));
$statement = $reason !== '' ? gabaybatas_parse_complaint_statement($existingDesc) : null;
if ($statement) {
    $reasonBefore = rtrim($statement['reason'], " \t\n\r\0\x0B.");
    $reasonAfter = rtrim($reason, " \t\n\r\0\x0B.");
    if ($reasonAfter !== $reasonBefore) {
        $rebuilt = gabaybatas_complaint_statement(
            $statement['name'],
            $statement['age'],
            $statement['area'],
            $statement['respondent'],
            $statement['respondent_area'],
            $reasonAfter
        );
        $existingDesc = $statement['extra'] !== '' ? $rebuilt . "\n\n" . $statement['extra'] : $rebuilt;
        $updateLines[] = "New reason: tungod {$reasonAfter}";
    }
} else {
    // Older free-text complaints cannot be rebuilt; the new details are added below them.
    $additionalInfo = trim($additionalInfo . ($reason !== '' && $additionalInfo !== '' ? "\n" : '') . $reason);
}
if ($additionalInfo !== '') {
    $updateStamp = date('M d, Y h:i A');
    $existingDesc = $existingDesc !== ''
        ? $existingDesc . "\n\n[Resident Update - {$updateStamp}]:\n" . $additionalInfo
        : $additionalInfo;
    $updateLines[] = "Details provided: {$additionalInfo}";
}
if ($existingDesc !== trim((string)($case['description'] ?? ''))) {
    $updateParts[] = "description = ?";
    $updateTypes .= 's';
    $updateValues[] = $existingDesc;
}

// 2. Update Complained Person if provided
if ($complainedPerson !== '') {
    $updateParts[] = "complained_person = ?";
    $updateTypes .= 's';
    $updateValues[] = $complainedPerson;
}

// 3. If status was additional_info_required, advance to under_review
$transitionedToReview = false;
if ($currentKpStatus === 'additional_info_required') {
    $updateParts[] = "kp_status = ?";
    $updateTypes .= 's';
    $updateValues[] = 'under_review';

    $updateParts[] = "status = ?";
    $updateTypes .= 's';
    $updateValues[] = 'Pending';

    $transitionedToReview = true;
}

if (!empty($updateParts)) {
    $updateSql = "UPDATE cases SET " . implode(', ', $updateParts) . " WHERE id = ? LIMIT 1";
    $updateTypes .= 'i';
    $updateValues[] = $caseId;

    $upStmt = $conn->prepare($updateSql);
    if (!$upStmt) {
        $conn->rollback();
        api_error('Database error preparing case update.', 500);
    }
    $upStmt->bind_param($updateTypes, ...$updateValues);
    if (!$upStmt->execute()) {
        $upStmt->close();
        $conn->rollback();
        api_error('Failed to update case record.', 500);
    }
    $upStmt->close();
}

// 4. Store new evidence and remove what the resident took out
$storageDir = __DIR__ . '/../../storage/case_evidence';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0777, true);
}

$newFiles = [];
foreach ($uploadedImages as $img) {
    $newFiles[] = ['file' => $img, 'type' => 'image', 'exts' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], 'default' => 'jpg'];
}
if ($video) {
    $newFiles[] = ['file' => $video, 'type' => 'video', 'exts' => ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp'], 'default' => 'mp4'];
}

$addedImages = 0;
$addedVideo = false;
foreach ($newFiles as $new) {
    $file = $new['file'];
    $origName = basename((string)$file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $new['exts'], true)) {
        $ext = $new['default'];
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $storageDir . '/' . $storedName)) {
        continue;
    }
    $mime = (string)$file['type'];
    if ($mime === '' || $mime === 'application/octet-stream') {
        $mime = $new['type'] . '/' . (['jpg' => 'jpeg', 'mov' => 'quicktime', '3gp' => '3gpp'][$ext] ?? $ext);
    }
    $evStmt = $conn->prepare(
        'INSERT INTO api_case_evidence (case_id, user_id, evidence_type, stored_filename, original_filename, mime_type, file_size, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    if ($evStmt) {
        $size = (int)$file['size'];
        $evStmt->bind_param('iissssi', $caseId, $userId, $new['type'], $storedName, $origName, $mime, $size);
        if ($evStmt->execute()) {
            if ($new['type'] === 'video') {
                $addedVideo = true;
            } else {
                $addedImages++;
            }
        }
        $evStmt->close();
    }
}

$removedFiles = [];
if ($removeIds) {
    $placeholders = implode(',', array_fill(0, count($removeIds), '?'));
    $types = 'i' . str_repeat('i', count($removeIds));
    $oldStmt = $conn->prepare("SELECT stored_filename FROM api_case_evidence WHERE case_id = ? AND id IN ({$placeholders})");
    if ($oldStmt) {
        $oldStmt->bind_param($types, $caseId, ...$removeIds);
        $oldStmt->execute();
        $removedFiles = array_column($oldStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'stored_filename');
        $oldStmt->close();
    }
    $deleteStmt = $conn->prepare("DELETE FROM api_case_evidence WHERE case_id = ? AND id IN ({$placeholders})");
    if ($deleteStmt) {
        $deleteStmt->bind_param($types, $caseId, ...$removeIds);
        if (!$deleteStmt->execute()) {
            $removedFiles = [];
        }
        $deleteStmt->close();
    }
}

if ($addedImages > 0) {
    $updateLines[] = "Added {$addedImages} photo" . ($addedImages === 1 ? '' : 's') . '.';
}
if ($addedVideo) {
    $updateLines[] = 'Added a video.';
}
if ($removedFiles) {
    $removedCount = count($removedFiles);
    $updateLines[] = "Removed {$removedCount} earlier file" . ($removedCount === 1 ? '' : 's') . '.';
}
$evidenceCount = $addedImages + ($addedVideo ? 1 : 0);

// 5. Log Timeline Event
$timelineTitle = $transitionedToReview
    ? 'Resident submitted additional info & documents'
    : 'Resident updated case details';

$timelineDetails = $updateLines ? implode("\n", $updateLines) : 'Resident submitted updates to the case file.';

$newKpStatus = $transitionedToReview ? 'under_review' : $currentKpStatus;

$tlStmt = $conn->prepare(
    "INSERT INTO kp_timeline_events (case_id, stage, status, event_type, title, details, event_at, created_at)
     VALUES (?, 'intake', ?, 'resident_update', ?, ?, NOW(), NOW())"
);
if ($tlStmt) {
    $tlStmt->bind_param('isss', $caseId, $newKpStatus, $timelineTitle, $timelineDetails);
    $tlStmt->execute();
    $tlStmt->close();
}

$conn->commit();

foreach ($removedFiles as $storedName) {
    $oldPath = $storageDir . DIRECTORY_SEPARATOR . basename((string)$storedName);
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

api_success([
    'message' => 'Additional information and details submitted successfully. Your case is now under review.',
    'case' => [
        'id' => $caseId,
        'case_id' => $case['case_id'] ?? $caseIdRaw,
        'kp_status' => $newKpStatus,
        'status' => 'Pending',
        'new_evidence_count' => $evidenceCount,
    ],
], 200);
