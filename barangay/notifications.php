<?php
session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access.',
        'items' => [],
    ]);
    exit();
}

$caseColumns = [];
$colResult = $conn->query("SHOW COLUMNS FROM cases");
if ($colResult) {
    while ($col = $colResult->fetch_assoc()) {
        $caseColumns[] = $col['Field'];
    }
}

$hasColumn = static function (string $column) use ($caseColumns): bool {
    return in_array($column, $caseColumns, true);
};

$idColumn = $hasColumn('id') ? 'id' : null;
$caseIdColumn = $hasColumn('case_id') ? 'case_id' : null;
$residentColumn = $hasColumn('resident_name') ? 'resident_name' : ($hasColumn('name') ? 'name' : null);
$statusColumn = $hasColumn('status') ? 'status' : null;
$barangayColumn = $hasColumn('barangay') ? 'barangay' : null;
$typeColumn = $hasColumn('case_type') ? 'case_type' : ($hasColumn('type') ? 'type' : null);
$dateColumn = $hasColumn('updated_at') ? 'updated_at' : ($hasColumn('created_at') ? 'created_at' : ($hasColumn('date_submitted') ? 'date_submitted' : null));

if ($idColumn === null || $residentColumn === null || $dateColumn === null) {
    echo json_encode([
        'success' => true,
        'message' => 'Notification source columns are incomplete.',
        'items' => [],
    ]);
    exit();
}

$currentBarangay = gabaybatas_get_user_barangay($conn);
$whereParts = ['1=1'];
if ($barangayColumn !== null && $currentBarangay !== '') {
    $safeBarangay = $conn->real_escape_string($currentBarangay);
    $whereParts[] = "LOWER($barangayColumn) = LOWER('$safeBarangay')";
}

$selectFields = [
    "$idColumn AS row_id",
    $caseIdColumn ? "$caseIdColumn AS case_ref" : "'' AS case_ref",
    "$residentColumn AS resident_name",
    $statusColumn ? "$statusColumn AS status" : "'' AS status",
    $typeColumn ? "$typeColumn AS case_type" : "'' AS case_type",
    "$dateColumn AS event_at",
];

$sql = 'SELECT ' . implode(', ', $selectFields) . ' FROM cases WHERE ' . implode(' AND ', $whereParts) . " ORDER BY $dateColumn DESC LIMIT 8";
$res = $conn->query($sql);

$items = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rowId = (int)($row['row_id'] ?? 0);
        $caseRef = trim((string)($row['case_ref'] ?? ''));
        $resident = trim((string)($row['resident_name'] ?? 'Resident'));
        $status = trim((string)($row['status'] ?? 'Pending'));
        $caseType = trim((string)($row['case_type'] ?? 'Case'));
        $eventAtRaw = (string)($row['event_at'] ?? '');

        $eventTs = strtotime($eventAtRaw);
        $eventDisplay = $eventTs !== false ? date('M d, Y h:i A', $eventTs) : $eventAtRaw;

        $title = gabaybatas_format_case_id($caseRef, (string)$rowId);
        $message = $resident . ' - ' . $caseType;

        if ($status !== '') {
            $message .= ' (' . $status . ')';
        }

        $items[] = [
            'id' => $rowId,
            'title' => $title,
            'message' => $message,
            'status' => $status,
            'time' => $eventDisplay,
            'url' => 'view_case.php?id=' . urlencode((string)$rowId),
        ];
    }
}

echo json_encode([
    'success' => true,
    'count' => count($items),
    'items' => $items,
]);
