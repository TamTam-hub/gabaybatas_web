<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../config/kp.php';

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
$rowIdColumn = api_pick_column($caseColumns, ['id']);

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
    ($rowIdColumn ? $rowIdColumn : 'id') . ' AS case_row_id',
    $referenceColumn ? $referenceColumn . ' AS reference_number' : (($rowIdColumn ? $rowIdColumn : 'id') . ' AS reference_number'),
    $residentColumn ? $residentColumn . ' AS resident_name' : "'' AS resident_name",
    $complainedColumn ? $complainedColumn . ' AS complained_person' : "'' AS complained_person",
    $barangayColumn ? $barangayColumn . ' AS barangay' : "'' AS barangay",
    $contactColumn ? $contactColumn . ' AS contact' : "'' AS contact",
    $typeColumn ? $typeColumn . ' AS case_type' : "'' AS case_type",
    $descriptionColumn ? $descriptionColumn . ' AS description' : "'' AS description",
    $statusColumn ? $statusColumn . ' AS status' : "'' AS status",
    api_has_column($caseColumns, 'kp_status') ? 'kp_status' : "'' AS kp_status",
    api_has_column($caseColumns, 'kp_disposition') ? 'kp_disposition' : "'' AS kp_disposition",
    $createdColumn ? $createdColumn . ' AS created_at' : 'NULL AS created_at',
    $updatedColumn ? $updatedColumn . ' AS updated_at' : 'NULL AS updated_at',
];

$orderColumn = $updatedColumn ?: $createdColumn ?: ($rowIdColumn ?: 'id');
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
$kpStages = function_exists('kp_stages') ? kp_stages() : [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $cRowId = (int)($row['case_row_id'] ?? 0);
        $rawKpStatus = trim((string)($row['kp_status'] ?? 'filed')) ?: 'filed';
        $kpInfo = function_exists('kp_status_info') ? kp_status_info($rawKpStatus) : [];
        $kpStage = $kpInfo['stage'] ?? 'intake';
        $kpStageLabel = $kpStages[$kpStage]['label'] ?? ucfirst($kpStage);
        $kpStatusLabel = $kpInfo['label'] ?? ucfirst(str_replace('_', ' ', $rawKpStatus));
        $residentUpdates = [];
        $descriptionText = (string)($row['description'] ?? '');
        if (preg_match_all('/\[Resident Update - [^\]]+\]:\s*(.*?)(?=\R\R\[Resident Update - |\z)/s', $descriptionText, $matches)) {
            $residentUpdates = array_values(array_filter(array_map('trim', $matches[1])));
        }

        // Latest review details
        $review = null;
        if ($cRowId > 0) {
            $revStmt = $conn->prepare("SELECT decision, notes, missing_items, completed_at, created_at FROM kp_case_reviews WHERE case_id = ? ORDER BY id DESC LIMIT 1");
            if ($revStmt) {
                $revStmt->bind_param('i', $cRowId);
                $revStmt->execute();
                $review = $revStmt->get_result()->fetch_assoc();
                $revStmt->close();
            }
        }

        // Timeline events
        $timeline = [];
        if ($cRowId > 0) {
            $tlStmt = $conn->prepare("SELECT stage, status, event_type, title, details, event_at, created_at FROM kp_timeline_events WHERE case_id = ? AND event_type <> 'details_corrected' ORDER BY id DESC LIMIT 10");
            if ($tlStmt) {
                $tlStmt->bind_param('i', $cRowId);
                $tlStmt->execute();
                $tlRes = $tlStmt->get_result();
                if ($tlRes) {
                    while ($tlRow = $tlRes->fetch_assoc()) {
                        $timeline[] = [
                            'stage' => (string)($tlRow['stage'] ?? ''),
                            'status' => (string)($tlRow['status'] ?? ''),
                            'event_type' => (string)($tlRow['event_type'] ?? ''),
                            'title' => (string)($tlRow['title'] ?? ''),
                            'details' => (string)($tlRow['details'] ?? ''),
                            'event_at' => api_iso_timestamp($tlRow['event_at'] ?? $tlRow['created_at'] ?? null),
                        ];
                    }
                }
                $tlStmt->close();
            }
        }

        // Next scheduled hearing
        $nextHearing = null;
        if ($cRowId > 0) {
            $hStmt = $conn->prepare("SELECT stage, hearing_date, hearing_time, location FROM kp_hearings WHERE case_id = ? AND hearing_date >= CURDATE() AND (outcome IS NULL OR outcome = '') ORDER BY hearing_date ASC LIMIT 1");
            if ($hStmt) {
                $hStmt->bind_param('i', $cRowId);
                $hStmt->execute();
                $hRes = $hStmt->get_result();
                if ($hRes && ($hRow = $hRes->fetch_assoc())) {
                    $nextHearing = [
                        'stage' => (string)($hRow['stage'] ?? ''),
                        'date' => (string)$hRow['hearing_date'],
                        'time' => (string)($hRow['hearing_time'] ?? ''),
                        'location' => (string)($hRow['location'] ?? ''),
                    ];
                }
                $hStmt->close();
            }
        }

        // Evidence files
        $evidence = $cRowId > 0 ? api_case_evidence($conn, $cRowId) : [];

        $cases[] = [
            'case_row_id' => $cRowId,
            'case_id' => (string)($row['reference_number'] ?? ''),
            'reference_number' => (string)($row['reference_number'] ?? ''),
            'resident_name' => (string)($row['resident_name'] ?? ''),
            'complained_person' => (string)($row['complained_person'] ?? ''),
            'barangay' => (string)($row['barangay'] ?? ''),
            'contact' => trim((string)($row['contact'] ?? '')) !== '' ? (string)$row['contact'] : $fallbackContact,
            'case_type' => (string)($row['case_type'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'resident_updates' => $residentUpdates,
            'status' => (string)($row['status'] ?? ''),
            'kp_status' => $rawKpStatus,
            'kp_status_label' => $kpStatusLabel,
            'kp_stage' => $kpStage,
            'kp_stage_label' => $kpStageLabel,
            'kp_disposition' => (string)($row['kp_disposition'] ?? ''),
            'kp_disposition_label' => function_exists('kp_case_outcome_label') ? kp_case_outcome_label($conn, $cRowId, (string)($row['kp_disposition'] ?? '')) : '',
            'review_decision' => (string)($review['decision'] ?? ''),
            'review_notes' => (string)($review['notes'] ?? ''),
            'missing_items' => (string)($review['missing_items'] ?? ''),
            'timeline' => $timeline,
            'next_hearing' => $nextHearing,
            'evidence' => $evidence,
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