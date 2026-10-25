<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized. Token is invalid or expired.', 401);
}

$userId = (int)($user['user_id'] ?? 0);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $stmt = $conn->prepare(
        'SELECT id, full_name, date_of_birth, complete_address, barangay, government_id_type,
                government_id_last4, status, reviewer_note, reviewed_at, created_at, updated_at
         FROM residency_verifications
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT 1'
    );
    if (!$stmt) {
        api_error('Unable to prepare verification query.', 500);
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $verification = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$verification) {
        api_success([
            'message' => 'No residency verification has been submitted.',
            'data' => null,
        ]);
    }

    api_success([
        'message' => 'Residency verification retrieved.',
        'data' => [
            'verification_id' => (int)$verification['id'],
            'full_name' => (string)$verification['full_name'],
            'date_of_birth' => $verification['date_of_birth'],
            'complete_address' => (string)$verification['complete_address'],
            'barangay' => 'Barangay Muñoz',
            'government_id_type' => (string)$verification['government_id_type'],
            'government_id_last4' => (string)($verification['government_id_last4'] ?? ''),
            'status' => (string)$verification['status'],
            'reviewer_note' => (string)($verification['reviewer_note'] ?? ''),
            'reviewed_at' => api_iso_timestamp($verification['reviewed_at'] ?? null),
            'created_at' => api_iso_timestamp($verification['created_at'] ?? null),
            'updated_at' => api_iso_timestamp($verification['updated_at'] ?? null),
        ],
    ]);
}

if ($method !== 'POST') {
    api_error('Method not allowed.', 405);
}

$fullName = trim((string)($_POST['full_name'] ?? ''));
$dateOfBirth = trim((string)($_POST['date_of_birth'] ?? ''));
$completeAddress = trim((string)($_POST['complete_address'] ?? ''));
$barangay = trim((string)($_POST['barangay'] ?? ''));
$governmentIdType = trim((string)($_POST['government_id_type'] ?? ''));
$governmentIdLast4 = trim((string)($_POST['government_id_last4'] ?? ''));

if ($fullName === '' || $completeAddress === '' || $barangay === '' || $governmentIdType === '') {
    api_error('Full name, address, barangay, and ID type are required.', 422);
}

$barangay = api_require_barangay($barangay);

if ($dateOfBirth !== '' && !DateTime::createFromFormat('Y-m-d', $dateOfBirth)) {
    api_error('Date of birth must use YYYY-MM-DD format.', 422);
}

if ($governmentIdLast4 !== '' && !preg_match('/^\d{4}$/', $governmentIdLast4)) {
    api_error('The ID last four digits must contain exactly four numbers.', 422);
}

$allowedDocuments = [
    'id_document' => 'government_id',
    'proof_of_residence' => 'proof_of_residence',
];
$maxFileSize = 5 * 1024 * 1024;
$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];
$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$uploads = [];

foreach ($allowedDocuments as $field => $documentType) {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        api_error('Both a government ID and proof of residence are required.', 422);
    }

    $size = (int)($file['size'] ?? 0);
    $tmpName = (string)($file['tmp_name'] ?? '');
    $mimeType = $tmpName !== '' ? (string)$fileInfo->file($tmpName) : '';
    if ($size <= 0 || $size > $maxFileSize || !isset($allowedMimeTypes[$mimeType])) {
        api_error('Each document must be a JPG or PNG image up to 5 MB.', 422);
    }

    $uploads[$documentType] = [
        'tmp_name' => $tmpName,
        'mime_type' => $mimeType,
        'size' => $size,
        'extension' => $allowedMimeTypes[$mimeType],
        'original_name' => basename((string)($file['name'] ?? 'document')),
    ];
}

$storageDirectory = __DIR__ . '/../../storage/residency_documents';
if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0700, true) && !is_dir($storageDirectory)) {
    api_error('Unable to prepare secure document storage.', 500);
}

$now = date('Y-m-d H:i:s');
$conn->begin_transaction();
$storedFiles = [];
$filesToDelete = [];
try {
    $latest = $conn->prepare(
        'SELECT id, status
         FROM residency_verifications
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE'
    );
    if (!$latest) {
        throw new RuntimeException('Unable to prepare verification lookup.');
    }
    $latest->bind_param('i', $userId);
    if (!$latest->execute()) {
        throw new RuntimeException('Unable to look up the latest verification.');
    }
    $latestRow = $latest->get_result()->fetch_assoc();
    $latest->close();

    $verificationId = 0;
    if ($latestRow && (string)$latestRow['status'] === 'pending') {
        $conn->rollback();
        api_error('Your residency verification is already pending review.', 409);
    }
    if ($latestRow && (string)$latestRow['status'] === 'approved') {
        $conn->rollback();
        api_error('Your residency verification has already been approved.', 409);
    }
    if ($latestRow && (string)$latestRow['status'] === 'resubmit') {
        $verificationId = (int)$latestRow['id'];
        $oldDocuments = $conn->query(
            'SELECT stored_filename FROM residency_verification_documents WHERE verification_id = '
            . $verificationId
        );
        if ($oldDocuments) {
            while ($oldDocument = $oldDocuments->fetch_assoc()) {
                $filesToDelete[] = $storageDirectory . '/' . basename((string)$oldDocument['stored_filename']);
            }
        }
        $update = $conn->prepare(
            'UPDATE residency_verifications
             SET full_name = ?, date_of_birth = NULLIF(?, \'\'), complete_address = ?,
                 barangay = ?, government_id_type = ?, government_id_last4 = NULLIF(?, \'\'),
                 status = \'pending\', reviewer_note = NULL, reviewed_by = NULL, reviewed_at = NULL,
                 updated_at = ?
             WHERE id = ? LIMIT 1'
        );
        if (!$update) {
            throw new RuntimeException('Unable to prepare verification resubmission.');
        }
        $update->bind_param(
            'sssssssi',
            $fullName,
            $dateOfBirth,
            $completeAddress,
            $barangay,
            $governmentIdType,
            $governmentIdLast4,
            $now,
            $verificationId
        );
        if (!$update->execute()) {
            throw new RuntimeException('Unable to update verification resubmission.');
        }
        $update->close();
        $deleteDocuments = $conn->prepare('DELETE FROM residency_verification_documents WHERE verification_id = ?');
        if (!$deleteDocuments) {
            throw new RuntimeException('Unable to prepare old document cleanup.');
        }
        $deleteDocuments->bind_param('i', $verificationId);
        if (!$deleteDocuments->execute()) {
            throw new RuntimeException('Unable to replace old verification documents.');
        }
        $deleteDocuments->close();
    } else {
        $insert = $conn->prepare(
            'INSERT INTO residency_verifications
             (user_id, full_name, date_of_birth, complete_address, barangay, government_id_type,
              government_id_last4, status, created_at, updated_at)
             VALUES (?, ?, NULLIF(?, \'\'), ?, ?, ?, NULLIF(?, \'\'), \'pending\', ?, ?)'
        );
        if (!$insert) {
            throw new RuntimeException('Unable to prepare verification submission.');
        }
        $insert->bind_param(
            'issssssss',
            $userId,
            $fullName,
            $dateOfBirth,
            $completeAddress,
            $barangay,
            $governmentIdType,
            $governmentIdLast4,
            $now,
            $now
        );
        if (!$insert->execute()) {
            throw new RuntimeException('Unable to save verification submission.');
        }
        $verificationId = (int)$conn->insert_id;
        $insert->close();
    }

    $documentInsert = $conn->prepare(
        'INSERT INTO residency_verification_documents
         (verification_id, document_type, stored_filename, original_filename, mime_type, file_size, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$documentInsert) {
        throw new RuntimeException('Unable to prepare document record.');
    }

    foreach ($uploads as $documentType => $upload) {
        $storedFilename = bin2hex(random_bytes(20)) . '.' . $upload['extension'];
        $destination = $storageDirectory . '/' . $storedFilename;
        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to store uploaded document.');
        }
        $storedFiles[] = $destination;
        $documentInsert->bind_param(
            'issssis',
            $verificationId,
            $documentType,
            $storedFilename,
            $upload['original_name'],
            $upload['mime_type'],
            $upload['size'],
            $now
        );
        if (!$documentInsert->execute()) {
            throw new RuntimeException('Unable to save uploaded document.');
        }
    }
    $documentInsert->close();
    $conn->commit();
    foreach ($filesToDelete as $oldFile) {
        if (is_file($oldFile)) {
            unlink($oldFile);
        }
    }
} catch (Throwable $error) {
    $conn->rollback();
    foreach ($storedFiles ?? [] as $storedFile) {
        @unlink($storedFile);
    }
    api_error('Unable to submit residency verification right now.', 500);
}

api_success([
    'message' => 'Residency verification submitted for review.',
    'data' => [
        'verification_id' => $verificationId,
        'status' => 'pending',
    ],
], 201);