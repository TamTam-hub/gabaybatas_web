<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_method('GET');

$caseIdInput = trim((string)($_GET['case_id'] ?? $_GET['tracking_code'] ?? ''));
if ($caseIdInput === '') {
    api_error('Please provide case_id.', 422);
}

$caseColumns = api_case_columns($conn);
if (empty($caseColumns)) {
    api_error('Cases table is not available.', 500);
}

$nameColumn = api_has_column($caseColumns, 'resident_name') ? 'resident_name' : (api_has_column($caseColumns, 'name') ? 'name' : null);
$typeColumn = api_has_column($caseColumns, 'case_type') ? 'case_type' : (api_has_column($caseColumns, 'type') ? 'type' : null);
$descColumn = api_has_column($caseColumns, 'description') ? 'description' : (api_has_column($caseColumns, 'details') ? 'details' : null);
$dateColumn = api_has_column($caseColumns, 'created_at') ? 'created_at' : (api_has_column($caseColumns, 'date_submitted') ? 'date_submitted' : null);

$select = [
    'id',
    api_has_column($caseColumns, 'case_id') ? 'case_id' : "'' AS case_id",
    $nameColumn ? $nameColumn . ' AS resident_name' : "'' AS resident_name",
    api_has_column($caseColumns, 'barangay') ? 'barangay' : "'' AS barangay",
    api_has_column($caseColumns, 'contact') ? 'contact' : "'' AS contact",
    $typeColumn ? $typeColumn . ' AS case_type' : "'' AS case_type",
    $descColumn ? $descColumn . ' AS description' : "'' AS description",
    api_has_column($caseColumns, 'priority') ? 'priority' : "'' AS priority",
    api_has_column($caseColumns, 'status') ? 'status' : "'' AS status",
    api_has_column($caseColumns, 'assigned_to') ? 'assigned_to' : "'' AS assigned_to",
    $dateColumn ? ($dateColumn . ' AS created_at') : "NULL AS created_at",
];

if (api_has_column($caseColumns, 'case_id')) {
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM cases WHERE case_id = ? OR CAST(id AS CHAR) = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        api_error('Unable to prepare tracking query.', 500);
    }
    $stmt->bind_param('ss', $caseIdInput, $caseIdInput);
} else {
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM cases WHERE CAST(id AS CHAR) = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        api_error('Unable to prepare tracking query.', 500);
    }
    $stmt->bind_param('s', $caseIdInput);
}

$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    api_error('Case not found.', 404);
}

$status = trim((string)($row['status'] ?? ''));
if ($status === '' || !api_case_status_allowed($status)) {
    $status = 'Pending';
}

api_success([
    'message' => 'Case found.',
    'case' => [
        'id' => (int)($row['id'] ?? 0),
        'case_id' => (string)($row['case_id'] ?? ''),
        'resident_name' => (string)($row['resident_name'] ?? ''),
        'barangay' => (string)($row['barangay'] ?? ''),
        'contact' => (string)($row['contact'] ?? ''),
        'case_type' => (string)($row['case_type'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'priority' => (string)($row['priority'] ?? ''),
        'status' => $status,
        'assigned_to' => (string)($row['assigned_to'] ?? ''),
        'created_at' => $row['created_at'] ? (string)$row['created_at'] : null,
    ],
]);
