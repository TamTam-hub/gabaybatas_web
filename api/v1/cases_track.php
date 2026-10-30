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
    api_has_column($caseColumns, 'kp_status') ? 'kp_status' : "'' AS kp_status",
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

// ── Safe KP details for resident tracking ──
$rawKpStatus = trim((string)($row['kp_status'] ?? ''));
if ($rawKpStatus === '') {
    $rawKpStatus = 'filed';
}

require_once __DIR__ . '/../../config/kp.php';
$kpInfo = kp_status_info($rawKpStatus);
$kpStage = $kpInfo['stage'] ?? 'intake';
$kpStages = kp_stages();
$kpStageLabel = $kpStages[$kpStage]['label'] ?? ucfirst($kpStage);
$kpStatusLabel = $kpInfo['label'] ?? ucfirst(str_replace('_', ' ', $rawKpStatus));

// Look up next upcoming hearing if scheduled
$nextHearing = null;
$caseRowId = (int)($row['id'] ?? 0);
if ($caseRowId > 0) {
    $hStmt = $conn->prepare("SELECT stage AS hearing_type, hearing_date, hearing_time, location AS venue FROM kp_hearings WHERE case_id = ? AND hearing_date >= CURDATE() AND (outcome IS NULL OR outcome = '') ORDER BY hearing_date ASC, hearing_time ASC LIMIT 1");
    if ($hStmt) {
        $hStmt->bind_param('i', $caseRowId);
        $hStmt->execute();
        $hRes = $hStmt->get_result();
        if ($hRes && ($hRow = $hRes->fetch_assoc())) {
            $nextHearing = [
                'type' => ucfirst((string)($hRow['hearing_type'] ?? 'Mediation')),
                'date' => (string)$hRow['hearing_date'],
                'date_formatted' => date('F j, Y', strtotime($hRow['hearing_date'])),
                'time' => $hRow['hearing_time'] ? date('h:i A', strtotime($hRow['hearing_time'])) : 'TBD',
                'venue' => (string)($hRow['venue'] ?? 'Barangay Muñoz Hall'),
            ];
        }
        $hStmt->close();
    }
}

// Public safe milestones
$milestones = [];
if ($caseRowId > 0) {
    $mStmt = $conn->prepare("SELECT event_type, title AS summary, created_at FROM kp_timeline_events WHERE case_id = ? AND event_type <> 'details_corrected' ORDER BY id ASC LIMIT 10");
    if ($mStmt) {
        $mStmt->bind_param('i', $caseRowId);
        $mStmt->execute();
        $mRes = $mStmt->get_result();
        if ($mRes) {
            while ($mRow = $mRes->fetch_assoc()) {
                $milestones[] = [
                    'event' => (string)$mRow['event_type'],
                    'summary' => (string)$mRow['summary'],
                    'date' => date('M d, Y', strtotime($mRow['created_at'])),
                ];
            }
        }
        $mStmt->close();
    }
}

$latestReview = null;
if ($caseRowId > 0) {
    $reviewStmt = $conn->prepare(
        'SELECT decision, notes, missing_items, completed_at, created_at
         FROM kp_case_reviews WHERE case_id = ? ORDER BY id DESC LIMIT 1'
    );
    if ($reviewStmt) {
        $reviewStmt->bind_param('i', $caseRowId);
        $reviewStmt->execute();
        $reviewResult = $reviewStmt->get_result();
        $latestReview = $reviewResult ? $reviewResult->fetch_assoc() : null;
        $reviewStmt->close();
    }
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
        'kp' => [
            'stage' => $kpStage,
            'stage_label' => $kpStageLabel,
            'status' => $rawKpStatus,
            'status_label' => $kpStatusLabel,
            'color' => $kpInfo['color'] ?? '#6b7280',
            'next_hearing' => $nextHearing,
            'milestones' => $milestones,
            'review' => [
                'decision' => (string)($latestReview['decision'] ?? ''),
                'notes' => (string)($latestReview['notes'] ?? ''),
                'missing_items' => (string)($latestReview['missing_items'] ?? ''),
                'completed_at' => !empty($latestReview['completed_at'])
                    ? (string)$latestReview['completed_at']
                    : null,
            ],
        ],
    ],
]);
