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
$complainedPerson = trim((string)($_POST['complained_person'] ?? $payload['complained_person'] ?? ''));

if ($additionalInfo === '' && $complainedPerson === '' && empty($_FILES)) {
    api_error('Provide additional information or attach replacement evidence.', 422);
}

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

$conn->begin_transaction();

$updateParts = [];
$updateTypes = '';
$updateValues = [];

// 1. Update Description / Additional Information
if ($additionalInfo !== '') {
    $existingDesc = trim((string)($case['description'] ?? ''));
    $updateStamp = date('M d, Y h:i A');
    $combinedDesc = $existingDesc !== ''
        ? $existingDesc . "\n\n[Resident Update - {$updateStamp}]:\n" . $additionalInfo
        : $additionalInfo;

    $updateParts[] = "description = ?";
    $updateTypes .= 's';
    $updateValues[] = $combinedDesc;
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

// 4. Handle optional evidence replacement uploads
$storageDir = __DIR__ . '/../../storage/case_evidence';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0777, true);
}

$uploadedImages = [];
if (!empty($_FILES['evidence_images'])) {
    $f = $_FILES['evidence_images'];
    if (is_array($f['name'])) {
        for ($i = 0; $i < count($f['name']); $i++) {
            if (isset($f['error'][$i]) && $f['error'][$i] === UPLOAD_ERR_OK && ($f['size'][$i] ?? 0) > 0) {
                $uploadedImages[] = [
                    'tmp_name' => $f['tmp_name'][$i],
                    'name' => $f['name'][$i],
                    'type' => $f['type'][$i] ?? 'image/jpeg',
                    'size' => $f['size'][$i]
                ];
            }
        }
    } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($f['size'] ?? 0) > 0) {
        $uploadedImages[] = [
            'tmp_name' => $f['tmp_name'],
            'name' => $f['name'],
            'type' => $f['type'] ?? 'image/jpeg',
            'size' => $f['size']
        ];
    }
}

foreach ($_FILES as $key => $fileData) {
    if ($key === 'evidence_images' || $key === 'evidence_video') continue;
    if (strpos($key, 'evidence_image') !== false) {
        if (is_array($fileData['name'])) {
            for ($i = 0; $i < count($fileData['name']); $i++) {
                if (isset($fileData['error'][$i]) && $fileData['error'][$i] === UPLOAD_ERR_OK && ($fileData['size'][$i] ?? 0) > 0) {
                    $uploadedImages[] = [
                        'tmp_name' => $fileData['tmp_name'][$i],
                        'name' => $fileData['name'][$i],
                        'type' => $fileData['type'][$i] ?? 'image/jpeg',
                        'size' => $fileData['size'][$i]
                    ];
                }
            }
        } elseif (($fileData['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($fileData['size'] ?? 0) > 0) {
            $uploadedImages[] = [
                'tmp_name' => $fileData['tmp_name'],
                'name' => $fileData['name'],
                'type' => $fileData['type'] ?? 'image/jpeg',
                'size' => $fileData['size']
            ];
        }
    }
}

$evidenceCount = 0;
$newEvidenceIds = [];
foreach ($uploadedImages as $img) {
    $origName = basename((string)$img['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true)) {
        $ext = 'jpg';
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $targetPath = $storageDir . '/' . $storedName;

    if (move_uploaded_file($img['tmp_name'], $targetPath)) {
        $mime = $img['type'];
        if (empty($mime) || $mime === 'application/octet-stream') {
            $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
        }
        $evStmt = $conn->prepare(
            'INSERT INTO api_case_evidence (case_id, user_id, evidence_type, stored_filename, original_filename, mime_type, file_size, created_at)
             VALUES (?, ?, "image", ?, ?, ?, ?, NOW())'
        );
        if ($evStmt) {
            $size = (int)$img['size'];
            $evStmt->bind_param('iisssi', $caseId, $userId, $storedName, $origName, $mime, $size);
            if ($evStmt->execute()) {
                $newEvidenceIds[] = (int)$evStmt->insert_id;
                $evidenceCount++;
            }
            $evStmt->close();
        }
    }
}

if (!empty($_FILES['evidence_video']) && ($_FILES['evidence_video']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($_FILES['evidence_video']['size'] ?? 0) > 0) {
    $v = $_FILES['evidence_video'];
    $origName = basename((string)$v['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', '3gp'], true)) {
        $ext = 'mp4';
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $targetPath = $storageDir . '/' . $storedName;

    if (move_uploaded_file($v['tmp_name'], $targetPath)) {
        $mime = $v['type'];
        if (empty($mime) || $mime === 'application/octet-stream') {
            $mime = 'video/' . ($ext === 'mov' ? 'quicktime' : ($ext === '3gp' ? '3gpp' : $ext));
        }
        $evStmt = $conn->prepare(
            'INSERT INTO api_case_evidence (case_id, user_id, evidence_type, stored_filename, original_filename, mime_type, file_size, created_at)
             VALUES (?, ?, "video", ?, ?, ?, ?, NOW())'
        );
        if ($evStmt) {
            $size = (int)$v['size'];
            $evStmt->bind_param('iisssi', $caseId, $userId, $storedName, $origName, $mime, $size);
            if ($evStmt->execute()) {
                $newEvidenceIds[] = (int)$evStmt->insert_id;
                $evidenceCount++;
            }
            $evStmt->close();
        }
    }
}

// Replace prior evidence only after at least one new file was stored.
if ($newEvidenceIds) {
    $oldEvidence = [];
    $oldEvidenceDeleted = false;
    $oldStmt = $conn->prepare('SELECT id, stored_filename FROM api_case_evidence WHERE case_id = ? AND id NOT IN (' . implode(',', array_fill(0, count($newEvidenceIds), '?')) . ')');
    if ($oldStmt) {
        $oldTypes = 'i' . str_repeat('i', count($newEvidenceIds));
        $oldValues = array_merge([$caseId], $newEvidenceIds);
        $oldStmt->bind_param($oldTypes, ...$oldValues);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();
        while ($oldResult && ($oldRow = $oldResult->fetch_assoc())) {
            $oldEvidence[] = $oldRow;
        }
        $oldStmt->close();
    }

    $deleteStmt = $conn->prepare('DELETE FROM api_case_evidence WHERE case_id = ? AND id NOT IN (' . implode(',', array_fill(0, count($newEvidenceIds), '?')) . ')');
    if ($deleteStmt) {
        $deleteTypes = 'i' . str_repeat('i', count($newEvidenceIds));
        $deleteValues = array_merge([$caseId], $newEvidenceIds);
        $deleteStmt->bind_param($deleteTypes, ...$deleteValues);
        $oldEvidenceDeleted = $deleteStmt->execute();
        $deleteStmt->close();
    }

    if ($oldEvidenceDeleted) {
        foreach ($oldEvidence as $oldRow) {
            $oldPath = $storageDir . DIRECTORY_SEPARATOR . basename((string)$oldRow['stored_filename']);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
    }
}

// 5. Log Timeline Event
$timelineTitle = $transitionedToReview
    ? 'Resident submitted additional info & documents'
    : 'Resident updated case details';

$timelineDetails = '';
if ($additionalInfo !== '') {
    $timelineDetails = 'Details provided: ' . mb_substr($additionalInfo, 0, 160);
}
if ($evidenceCount > 0) {
    $timelineDetails .= ($timelineDetails !== '' ? ' | ' : '') . "Uploaded {$evidenceCount} new evidence item(s).";
}
if ($timelineDetails === '') {
    $timelineDetails = 'Resident submitted updates to the case file.';
}

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
