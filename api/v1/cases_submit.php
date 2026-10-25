<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');
api_ensure_mobile_tables($conn);

$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

$verificationStmt = $conn->prepare(
    "SELECT id FROM residency_verifications
     WHERE user_id = ? AND status = 'approved'
     ORDER BY id DESC LIMIT 1"
);
if (!$verificationStmt) {
    api_error('Unable to check residency verification.', 500);
}

$verificationUserId = (string)($user['user_id'] ?? '');
$verificationStmt->bind_param('s', $verificationUserId);
$verificationStmt->execute();
$verificationResult = $verificationStmt->get_result();
$approvedVerification = $verificationResult ? $verificationResult->fetch_assoc() : null;
$verificationStmt->close();

if (!$approvedVerification) {
    api_error('Your residency must be verified before submitting a case.', 403);
}

$payload = api_input();

$name = trim((string)($payload['name'] ?? $payload['resident_name'] ?? ''));
$complainantAddress = trim((string)($payload['complainant_address'] ?? ''));
$complainantLocation = trim((string)($payload['complainant_location'] ?? $complainantAddress));
$complainantAge = trim((string)($payload['complainant_age'] ?? $payload['age'] ?? ''));
$complainedPerson = trim((string)($payload['complained_person'] ?? $payload['respondent_name'] ?? $payload['against_person'] ?? ''));
$respondentAddress = trim((string)($payload['respondent_address'] ?? ''));
$respondentLocation = trim((string)($payload['respondent_location'] ?? $respondentAddress));
$barangay = trim((string)($payload['barangay'] ?? ''));
$contact = trim((string)($payload['contact'] ?? ''));
$caseTypeRaw = trim((string)($payload['case_type'] ?? ''));
$description = trim((string)($payload['description'] ?? ''));

$caseType = api_normalize_case_type($caseTypeRaw);

if ($name === '' || $complainantAddress === '' || $complainantLocation === '' || $complainantAge === '' || $complainedPerson === '' || $respondentAddress === '' || $respondentLocation === '' || $barangay === '' || $contact === '' || $caseType === '' || $description === '') {
    api_error('Missing required fields. Required: complainant name, age, complete address, respondent name, respondent complete address, contact, case type, and complaint reason.', 422);
}

$description = sprintf(
    'Ako po hi %s, %s anyos, ngan naukoy ha %s. Ginrereklamo ko hi %s, nga naukoy ha %s, tungod %s. Karuyag ko unta ipatawag hiya ha barangay basi magkastorya kami hin maopay.',
    $name,
    $complainantAge,
    $complainantLocation,
    $complainedPerson,
    $respondentLocation,
    rtrim($description, " \t\n\r\0\x0B.")
);

$barangay = api_require_barangay($barangay);

$currentUserName = trim((string)($user['name'] ?? ''));
if ($currentUserName !== '' && strcasecmp($name, $currentUserName) !== 0) {
    api_error('Case name must match the logged-in resident profile.', 422);
}

$caseColumns = api_case_columns($conn);
if (empty($caseColumns)) {
    api_error('Cases table is not available.', 500);
}

$nameColumn = api_has_column($caseColumns, 'resident_name') ? 'resident_name' : (api_has_column($caseColumns, 'name') ? 'name' : null);
$complainedColumn = api_has_column($caseColumns, 'complained_person')
    ? 'complained_person'
    : (api_has_column($caseColumns, 'respondent_name')
        ? 'respondent_name'
        : (api_has_column($caseColumns, 'against_person') ? 'against_person' : null));
$userIdColumn = api_has_column($caseColumns, 'user_id') ? 'user_id' : null;
$typeColumn = api_has_column($caseColumns, 'case_type') ? 'case_type' : (api_has_column($caseColumns, 'type') ? 'type' : null);
$descColumn = api_has_column($caseColumns, 'description') ? 'description' : (api_has_column($caseColumns, 'details') ? 'details' : null);

if ($nameColumn === null || $typeColumn === null || $descColumn === null) {
    api_error('Cases table is missing required columns for resident/case/description.', 500);
}

$referral = api_apply_referral_logic($caseType, $barangay);

$duplicateParts = [];
$duplicateTypes = '';
$duplicateValues = [];

if ($userIdColumn !== null) {
    $duplicateParts[] = $userIdColumn . ' = ?';
    $duplicateTypes .= 's';
    $duplicateValues[] = (string)($user['user_id'] ?? '');
}

$duplicateParts[] = $nameColumn . ' = ?';
$duplicateTypes .= 's';
$duplicateValues[] = $name;

if ($complainedColumn !== null) {
    $duplicateParts[] = $complainedColumn . ' = ?';
    $duplicateTypes .= 's';
    $duplicateValues[] = $complainedPerson;
}

foreach ([
    'complainant_address' => $complainantAddress,
    'respondent_address' => $respondentAddress,
] as $column => $value) {
    if (api_has_column($caseColumns, $column)) {
        $duplicateParts[] = $column . ' = ?';
        $duplicateTypes .= 's';
        $duplicateValues[] = $value;
    }
}

foreach ([
    'complainant_age' => $complainantAge,
    'age' => $complainantAge,
] as $column => $value) {
    if (api_has_column($caseColumns, $column)) {
        $duplicateParts[] = $column . ' = ?';
        $duplicateTypes .= 's';
        $duplicateValues[] = $value;
        break;
    }
}

if (api_has_column($caseColumns, 'barangay')) {
    $duplicateParts[] = 'barangay = ?';
    $duplicateTypes .= 's';
    $duplicateValues[] = $barangay;
}

if (api_has_column($caseColumns, 'contact')) {
    $duplicateParts[] = 'contact = ?';
    $duplicateTypes .= 's';
    $duplicateValues[] = $contact;
}

$duplicateParts[] = $typeColumn . ' = ?';
$duplicateTypes .= 's';
$duplicateValues[] = $caseType;

$duplicateParts[] = $descColumn . ' = ?';
$duplicateTypes .= 's';
$duplicateValues[] = $description;

$duplicateSql = 'SELECT id FROM cases WHERE ' . implode(' AND ', $duplicateParts) . ' LIMIT 1';
$duplicateStmt = $conn->prepare($duplicateSql);
if (!$duplicateStmt) {
    api_error('Unable to prepare duplicate case check.', 500);
}

$duplicateStmt->bind_param($duplicateTypes, ...$duplicateValues);
$duplicateStmt->execute();
$duplicateResult = $duplicateStmt->get_result();
$duplicateRow = $duplicateResult ? $duplicateResult->fetch_assoc() : null;
$duplicateStmt->close();

if ($duplicateRow) {
    api_error('Duplicate case submission. Please check your previous entry.', 409);
}

$submissionHash = hash(
    'sha256',
    implode('|', [
        (string)($user['user_id'] ?? ''),
        mb_strtolower(trim($name)),
        mb_strtolower(trim($complainedPerson)),
        mb_strtolower(trim($complainantAddress)),
        mb_strtolower(trim($respondentAddress)),
        mb_strtolower(trim($complainantAge)),
        mb_strtolower(trim($barangay)),
        mb_strtolower(trim($contact)),
        mb_strtolower(trim($caseType)),
        mb_strtolower(trim($description)),
    ])
);

$insertColumns = [];
$placeholders = [];
$types = '';
$values = [];

$insertColumns[] = $nameColumn;
$placeholders[] = '?';
$types .= 's';
$values[] = $name;

if ($complainedColumn !== null) {
    $insertColumns[] = $complainedColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $complainedPerson;
}

foreach ([
    'complainant_address' => $complainantAddress,
    'respondent_address' => $respondentAddress,
] as $column => $value) {
    if (api_has_column($caseColumns, $column)) {
        $insertColumns[] = $column;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $value;
    }

}

foreach ([
    'complainant_age' => $complainantAge,
    'age' => $complainantAge,
] as $column => $value) {
    if (api_has_column($caseColumns, $column)) {
        $insertColumns[] = $column;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $value;
        break;
    }
}

if (api_has_column($caseColumns, 'barangay')) {
    $insertColumns[] = 'barangay';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $barangay;
}

if (api_has_column($caseColumns, 'contact')) {
    $insertColumns[] = 'contact';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $contact;
}

$insertColumns[] = $typeColumn;
$placeholders[] = '?';
$types .= 's';
$values[] = $caseType;

$insertColumns[] = $descColumn;
$placeholders[] = '?';
$types .= 's';
$values[] = $description;

if (api_has_column($caseColumns, 'priority')) {
    $insertColumns[] = 'priority';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $referral['priority'];
}

if (api_has_column($caseColumns, 'status')) {
    $insertColumns[] = 'status';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $referral['status'];
}

if (api_has_column($caseColumns, 'assigned_to')) {
    $insertColumns[] = 'assigned_to';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $referral['assigned_to'];
}

if (api_has_column($caseColumns, 'submission_hash')) {
    $insertColumns[] = 'submission_hash';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = $submissionHash;
}

if ($userIdColumn !== null) {
    $insertColumns[] = $userIdColumn;
    $placeholders[] = '?';
    $types .= 's';
    $values[] = (string)($user['user_id'] ?? '');
}

if (api_has_column($caseColumns, 'kp_status')) {
    $insertColumns[] = 'kp_status';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = 'filed';
}

if (api_has_column($caseColumns, 'created_at')) {
    $insertColumns[] = 'created_at';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = date('Y-m-d H:i:s');
} elseif (api_has_column($caseColumns, 'date_submitted')) {
    $insertColumns[] = 'date_submitted';
    $placeholders[] = '?';
    $types .= 's';
    $values[] = date('Y-m-d H:i:s');
}

$sql = 'INSERT INTO cases (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';

$conn->begin_transaction();

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->rollback();
    api_error('Unable to prepare case submission query.', 500);
}

$stmt->bind_param($types, ...$values);
$ok = $stmt->execute();

if (!$ok) {
    $stmt->close();
    $conn->rollback();
    api_error('Unable to submit case right now.', 500);
}

$newId = (int)$stmt->insert_id;
$stmt->close();

$caseReference = api_case_reference_from_id($newId);

if (api_has_column($caseColumns, 'case_id')) {
    $updateStmt = $conn->prepare('UPDATE cases SET case_id = ? WHERE id = ? LIMIT 1');
    if (!$updateStmt) {
        $conn->rollback();
        api_error('Unable to finalize case reference.', 500);
    }

    $idString = (string)$newId;
    $updateStmt->bind_param('ss', $caseReference, $idString);
    if (!$updateStmt->execute()) {
        $updateStmt->close();
        $conn->rollback();
        api_error('Unable to finalize case reference.', 500);
    }

    $updateStmt->close();
}

// --- Handle Evidence Uploads ---
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

$uId = (int)($user['user_id'] ?? 0);

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
            $evStmt->bind_param('iisssi', $newId, $uId, $storedName, $origName, $mime, $size);
            $evStmt->execute();
            $evStmt->close();
        }
    }
}

// 2. Process evidence_video
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
            $evStmt->bind_param('iisssi', $newId, $uId, $storedName, $origName, $mime, $size);
            $evStmt->execute();
            $evStmt->close();
        }
    }
}

// Log initial KP timeline event
$tlStmt = $conn->prepare("INSERT INTO kp_timeline_events (case_id, stage, status, event_type, title, event_at, created_at) VALUES (?, 'intake', 'filed', 'case_submitted', 'Complaint submitted online by resident', NOW(), NOW())");
if ($tlStmt) {
    $tlStmt->bind_param('i', $newId);
    $tlStmt->execute();
    $tlStmt->close();
}

$conn->commit();

api_success([
    'message' => 'Case submitted successfully.',
    'case' => [
        'id' => $newId,
        'case_id' => $caseReference,
        'user_id' => (int)($user['user_id'] ?? 0),
        'complained_person' => $complainedPerson,
        'status' => $referral['status'],
        'priority' => $referral['priority'],
        'route' => $referral['route'],
        'referral_note' => $referral['note'],
    ],
], 201);
