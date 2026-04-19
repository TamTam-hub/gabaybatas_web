<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_ensure_mobile_tables($conn);
$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

$caseColumns = api_case_columns_v1($conn);
if (!$caseColumns) {
    api_error('Cases table is not available.', 500);
}

$statusColumn = api_pick_column($caseColumns, ['status']);
$typeColumn = api_pick_column($caseColumns, ['case_type', 'type']);
$createdColumn = api_pick_column($caseColumns, ['created_at', 'date_submitted', 'submitted_at']);
$updatedColumn = api_pick_column($caseColumns, ['updated_at']);
$idColumn = api_pick_column($caseColumns, ['case_id', 'id']);

$statusFilter = trim((string)($_GET['status'] ?? ''));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

[$scopeClauses, $scopeTypes, $scopeValues] = api_scope_case_query($conn, $user);

$whereParts = [];
$types = '';
$values = [];

if ($scopeClauses) {
    $whereParts[] = '(' . implode(' OR ', $scopeClauses) . ')';
    $types .= $scopeTypes;
    $values = array_merge($values, $scopeValues);
}

if ($statusFilter !== '' && $statusColumn) {
    $whereParts[] = $statusColumn . ' = ?';
    $types .= 's';
    $values[] = $statusFilter;
}

$whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM cases ' . $whereSql);
if (!$countStmt) {
    api_error('Unable to prepare case count query.', 500);
}

if ($types !== '') {
    $countStmt->bind_param($types, ...$values);
}

$countStmt->execute();
$countRes = $countStmt->get_result();
$total = (int)(($countRes ? $countRes->fetch_assoc()['total'] : 0) ?? 0);
$countStmt->close();

$referenceColumn = api_pick_column($caseColumns, ['case_id']);
$residentColumn = api_pick_column($caseColumns, ['resident_name', 'name']);
$complainedColumn = api_pick_column($caseColumns, ['complained_person', 'respondent_name', 'against_person']);
$contactColumn = api_pick_column($caseColumns, ['contact', 'contact_number', 'phone']);
$barangayColumn = api_pick_column($caseColumns, ['barangay']);
$descriptionColumn = api_pick_column($caseColumns, ['description', 'details', 'remarks', 'notes']);
$fallbackContact = trim((string)($user['contact_number'] ?? $user['phone'] ?? ''));

$selectParts = [
    ($idColumn ? $idColumn : 'id') . ' AS case_row_id',
    $referenceColumn ? $referenceColumn . ' AS reference_number' : (($idColumn ? $idColumn : 'id') . ' AS reference_number'),
    $residentColumn ? $residentColumn . ' AS resident_name' : "'' AS resident_name",
    $complainedColumn ? $complainedColumn . ' AS complained_person' : "'' AS complained_person",
    $barangayColumn ? $barangayColumn . ' AS barangay' : "'' AS barangay",
    $contactColumn ? $contactColumn . ' AS contact' : "'' AS contact",
    $typeColumn ? $typeColumn . ' AS case_type' : "'' AS case_type",
    $descriptionColumn ? $descriptionColumn . ' AS description' : "'' AS description",
    $statusColumn ? $statusColumn . ' AS status' : "'' AS status",
    $createdColumn ? $createdColumn . ' AS created_at' : 'NULL AS created_at',
    $updatedColumn ? $updatedColumn . ' AS updated_at' : 'NULL AS updated_at',
];

$orderColumn = $updatedColumn ?: $createdColumn ?: ($idColumn ?: 'id');
$sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM cases ' . $whereSql . ' ORDER BY ' . $orderColumn . ' DESC LIMIT ? OFFSET ?';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    api_error('Unable to prepare case list query.', 500);
}

$queryTypes = $types . 'ii';
$queryValues = array_merge($values, [$limit, $offset]);
$stmt->bind_param($queryTypes, ...$queryValues);
$stmt->execute();
$result = $stmt->get_result();

$cases = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $cases[] = [
            'case_row_id' => (int)($row['case_row_id'] ?? 0),
            'case_id' => (string)($row['reference_number'] ?? ''),
            'reference_number' => (string)($row['reference_number'] ?? ''),
            'resident_name' => (string)($row['resident_name'] ?? ''),
            'complained_person' => (string)($row['complained_person'] ?? ''),
            'barangay' => (string)($row['barangay'] ?? ''),
            'contact' => trim((string)($row['contact'] ?? '')) !== '' ? (string)$row['contact'] : $fallbackContact,
            'case_type' => (string)($row['case_type'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'created_at' => api_iso_timestamp($row['created_at'] ?? null),
            'updated_at' => api_iso_timestamp($row['updated_at'] ?? null),
        ];
    }
}

$stmt->close();

api_success([
    'message' => 'Cases retrieved.',
    'data' => [
        'total' => $total,
        'cases' => $cases,
        'limit' => $limit,
        'offset' => $offset,
    ],
], 200);