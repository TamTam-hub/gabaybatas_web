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

$name = trim((string)($payload['name'] ?? $payload['resident_name'] ?? ''));
$complainedPerson = trim((string)($payload['complained_person'] ?? $payload['respondent_name'] ?? $payload['against_person'] ?? ''));
$barangay = trim((string)($payload['barangay'] ?? ''));
$contact = trim((string)($payload['contact'] ?? ''));
$caseTypeRaw = trim((string)($payload['case_type'] ?? ''));
$description = trim((string)($payload['description'] ?? ''));

$caseType = api_normalize_case_type($caseTypeRaw);

if ($name === '' || $complainedPerson === '' || $barangay === '' || $contact === '' || $caseType === '' || $description === '') {
    api_error('Missing required fields. Required: name, complained_person, barangay, contact, case_type, description.', 422);
}

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
