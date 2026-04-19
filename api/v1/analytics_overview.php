<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_method('GET');

$caseColumns = api_case_columns($conn);
if (empty($caseColumns)) {
    api_error('Cases table is not available.', 500);
}

$statusColumn = api_has_column($caseColumns, 'status') ? 'status' : null;
$typeColumn = api_has_column($caseColumns, 'case_type') ? 'case_type' : (api_has_column($caseColumns, 'type') ? 'type' : null);
$barangayColumn = api_has_column($caseColumns, 'barangay') ? 'barangay' : null;
$dateColumn = api_has_column($caseColumns, 'created_at') ? 'created_at' : (api_has_column($caseColumns, 'date_submitted') ? 'date_submitted' : null);

$totalCases = 0;
$pending = 0;
$inProgress = 0;
$resolved = 0;

$totalRes = $conn->query('SELECT COUNT(*) AS c FROM cases');
if ($totalRes) {
    $totalCases = (int)($totalRes->fetch_assoc()['c'] ?? 0);
}

if ($statusColumn !== null) {
    $pendingRes = $conn->query("SELECT COUNT(*) AS c FROM cases WHERE LOWER($statusColumn) = 'pending'");
    $progressRes = $conn->query("SELECT COUNT(*) AS c FROM cases WHERE LOWER($statusColumn) = 'in progress'");
    $resolvedRes = $conn->query("SELECT COUNT(*) AS c FROM cases WHERE LOWER($statusColumn) = 'resolved'");

    $pending = (int)(($pendingRes ? $pendingRes->fetch_assoc()['c'] : 0) ?? 0);
    $inProgress = (int)(($progressRes ? $progressRes->fetch_assoc()['c'] : 0) ?? 0);
    $resolved = (int)(($resolvedRes ? $resolvedRes->fetch_assoc()['c'] : 0) ?? 0);
}

$byType = [];
if ($typeColumn !== null) {
    $typeRes = $conn->query("SELECT $typeColumn AS label, COUNT(*) AS value FROM cases WHERE $typeColumn IS NOT NULL AND $typeColumn <> '' GROUP BY $typeColumn ORDER BY value DESC");
    if ($typeRes) {
        while ($row = $typeRes->fetch_assoc()) {
            $byType[] = [
                'label' => (string)($row['label'] ?? ''),
                'value' => (int)($row['value'] ?? 0),
            ];
        }
    }
}

$byBarangay = [];
if ($barangayColumn !== null) {
    $barangayRes = $conn->query("SELECT $barangayColumn AS label, COUNT(*) AS value FROM cases WHERE $barangayColumn IS NOT NULL AND TRIM($barangayColumn) <> '' GROUP BY $barangayColumn ORDER BY value DESC");
    if ($barangayRes) {
        while ($row = $barangayRes->fetch_assoc()) {
            $byBarangay[] = [
                'label' => (string)($row['label'] ?? ''),
                'value' => (int)($row['value'] ?? 0),
            ];
        }
    }
}

$byMonth = [];
if ($dateColumn !== null) {
    $monthlyRes = $conn->query("SELECT DATE_FORMAT($dateColumn, '%Y-%m') AS month_key, COUNT(*) AS value FROM cases WHERE $dateColumn IS NOT NULL GROUP BY month_key ORDER BY month_key ASC");
    if ($monthlyRes) {
        while ($row = $monthlyRes->fetch_assoc()) {
            $byMonth[] = [
                'month' => (string)($row['month_key'] ?? ''),
                'value' => (int)($row['value'] ?? 0),
            ];
        }
    }
}

api_success([
    'message' => 'Analytics overview fetched.',
    'summary' => [
        'total_cases' => $totalCases,
        'pending' => $pending,
        'in_progress' => $inProgress,
        'resolved' => $resolved,
    ],
    'cases_by_type' => $byType,
    'cases_by_barangay' => $byBarangay,
    'cases_per_month' => $byMonth,
]);
