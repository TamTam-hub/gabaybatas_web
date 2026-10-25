<?php
/**
 * KP Hearing Action Handler
 * Schedules hearings and records outcomes for mediation, Pangkat constitution,
 * conciliation, arbitration and failure-to-appear explanation hearings.
 */
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/kp.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
gabaybatas_block_barangay_delete_requests();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cases.php');
    exit();
}

$caseId     = (int)($_POST['case_id'] ?? 0);
$action     = trim($_POST['action'] ?? '');
$stage      = trim($_POST['stage'] ?? 'mediation');
$userId     = (int)($_SESSION['user_id'] ?? 0);

if ($caseId <= 0) {
    header('Location: cases.php');
    exit();
}

// Verify case exists
$stmt = $conn->prepare("SELECT * FROM cases WHERE id = ? AND archived_at IS NULL LIMIT 1");
$stmt->bind_param('i', $caseId);
$stmt->execute();
$case = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$case) {
    header('Location: cases.php');
    exit();
}

$kpStatus = (string)($case['kp_status'] ?? 'filed');
$today    = date('Y-m-d');

$fail = static function (string $code, string $tab) use ($caseId): void {
    header("Location: case_process.php?id={$caseId}&kp_error={$code}#{$tab}");
    exit();
};

$insertHearing = static function (string $hStage, string $purposeType, ?int $relatedId, string $date, string $time, string $location, string $purpose) use ($conn, $caseId, $userId): int {
    $stmt = $conn->prepare(
        "INSERT INTO kp_hearings (case_id, stage, purpose_type, related_hearing_id, hearing_date, hearing_time, location, purpose, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('ississssi', $caseId, $hStage, $purposeType, $relatedId, $date, $time, $location, $purpose, $userId);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
};

// ═══ ACTION: Schedule Hearing ═══
if ($action === 'schedule') {
    $hearingDate = trim($_POST['hearing_date'] ?? '');
    $hearingTime = trim($_POST['hearing_time'] ?? '');
    $location    = trim($_POST['location'] ?? '');
    $purpose     = trim($_POST['purpose'] ?? '');
    $purposeType = trim($_POST['purpose_type'] ?? 'regular');

    if ($hearingDate === '' || !in_array($stage, ['mediation', 'conciliation', 'arbitration'], true)) {
        $fail('missing_date', $stage);
    }

    $newStatus = null;
    $tab = $stage;

    if ($stage === 'mediation') {
        if (!in_array($kpStatus, ['accepted', 'mediation_scheduled', 'mediation_ongoing'], true)) {
            $fail('invalid_stage', $tab);
        }
        $purposeType = 'regular';
        $newStatus = 'mediation_scheduled';
    } elseif ($stage === 'conciliation' && $purposeType === 'pangkat_constitution') {
        // KP Form 10: meeting of the parties before the PB to constitute the Pangkat.
        $tab = 'pangkat';
        if ($kpStatus !== 'pangkat_constitution') {
            $fail('invalid_stage', $tab);
        }
        if ($purpose === '') {
            $purpose = 'Meeting for the constitution of the Pangkat ng Tagapagkasundo';
        }
    } elseif ($stage === 'conciliation') {
        $purposeType = 'regular';
        $hasPangkat = (int)$conn->query("SELECT COUNT(*) AS n FROM kp_pangkat WHERE case_id = {$caseId}")->fetch_assoc()['n'] > 0;
        if (!$hasPangkat || !in_array($kpStatus, ['pangkat_constitution', 'conciliation_scheduled', 'conciliation_ongoing'], true)) {
            $fail('pangkat_required', $tab);
        }
        $newStatus = 'conciliation_scheduled';
    } else { // arbitration
        $purposeType = 'regular';
        if (!in_array($kpStatus, ['arbitration_agreed', 'arbitration_hearing'], true)) {
            $fail('invalid_stage', $tab);
        }
        // The parties may repudiate the agreement within 5 days; hear only after it lapses.
        $agr = $conn->query("SELECT repudiation_deadline FROM kp_arbitration_agreements WHERE case_id = {$caseId} AND repudiated = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
        if (!$agr) {
            $fail('agreement_required', $tab);
        }
        if ($today <= $agr['repudiation_deadline'] || $hearingDate <= $agr['repudiation_deadline']) {
            $fail('agreement_window_open', $tab);
        }
        $newStatus = 'arbitration_hearing';
    }

    if ($newStatus !== null && $newStatus !== $kpStatus && !kp_transition_case($conn, $caseId, $newStatus)) {
        $fail('invalid_transition', $tab);
    }

    $insertHearing($stage, $purposeType, null, $hearingDate, $hearingTime, $location, $purpose);

    $timeDisplay = $hearingTime ? date('h:i A', strtotime($hearingTime)) : 'TBD';
    $title = $purposeType === 'pangkat_constitution'
        ? 'Pangkat constitution meeting scheduled'
        : ucfirst($stage) . ' hearing scheduled';
    kp_log_event(
        $conn, $caseId, $stage, $newStatus ?? $kpStatus, 'hearing_scheduled', $title,
        "Date: " . date('M d, Y', strtotime($hearingDate)) . " at {$timeDisplay}\nLocation: {$location}",
        $userId
    );

    header("Location: case_process.php?id={$caseId}&scheduled=1#{$tab}");
    exit();
}

// ═══ ACTION: Schedule Failure-to-Appear Explanation Hearing (KP Forms 18/19) ═══
if ($action === 'schedule_explanation') {
    $hearingDate = trim($_POST['hearing_date'] ?? '');
    $hearingTime = trim($_POST['hearing_time'] ?? '');
    $location    = trim($_POST['location'] ?? '');

    $map = [
        'mediation_noshow_complainant'    => ['mediation',    'explanation_complainant'],
        'conciliation_noshow_complainant' => ['conciliation', 'explanation_complainant'],
        'conciliation_noshow_respondent'  => ['conciliation', 'explanation_respondent'],
    ];
    if (!isset($map[$kpStatus])) {
        $fail('invalid_stage', 'overview');
    }
    [$hStage, $purposeType] = $map[$kpStatus];
    if ($hearingDate === '') {
        $fail('missing_date', $hStage);
    }

    // The missed hearing is the latest regular hearing of this stage marked absent.
    $missed = $conn->query("SELECT id FROM kp_hearings WHERE case_id = {$caseId} AND stage = '{$hStage}' AND purpose_type = 'regular' ORDER BY hearing_date DESC, id DESC LIMIT 1")->fetch_assoc();
    $missedId = $missed ? (int)$missed['id'] : null;

    $party = $purposeType === 'explanation_complainant' ? 'complainant' : 'respondent';
    $insertHearing($hStage, $purposeType, $missedId, $hearingDate, $hearingTime, $location, "Hearing for the {$party} to explain failure to appear");

    kp_log_event(
        $conn, $caseId, $hStage, $kpStatus, 'hearing_scheduled',
        'Failure-to-appear hearing scheduled (' . ucfirst($party) . ')',
        "Date: " . date('M d, Y', strtotime($hearingDate)) . ($hearingTime ? ' at ' . date('h:i A', strtotime($hearingTime)) : '') . "\nLocation: {$location}",
        $userId
    );

    header("Location: case_process.php?id={$caseId}&scheduled=1#{$hStage}");
    exit();
}

// ═══ ACTION: Record Outcome ═══
if ($action === 'record_outcome') {
    $hearingId = (int)($_POST['hearing_id'] ?? 0);
    $outcomeKey = trim($_POST['outcome'] ?? '');

    $hStmt = $conn->prepare("SELECT * FROM kp_hearings WHERE id = ? AND case_id = ? LIMIT 1");
    $hStmt->bind_param('ii', $hearingId, $caseId);
    $hStmt->execute();
    $hearing = $hStmt->get_result()->fetch_assoc();
    $hStmt->close();

    if (!$hearing || trim((string)($hearing['outcome'] ?? '')) !== '') {
        $fail('invalid_hearing', $stage);
    }

    $hStage      = (string)$hearing['stage'];
    $purposeType = (string)($hearing['purpose_type'] ?? 'regular');

    // outcome key => [label, new status, disposition, absent party]
    $rules = [];
    if ($purposeType === 'regular' && $hStage === 'mediation') {
        $rules = [
            'adjourned'          => ['Adjourned', 'mediation_ongoing', null, null],
            'settled'            => ['Settled', 'mediation_settled', null, null],
            'failed'             => ['Mediation failed — proceed to Pangkat', 'pangkat_constitution', null, null],
            'complainant_absent' => ['Complainant absent', 'mediation_noshow_complainant', null, 'complainant'],
            // Respondent no-show before the PB: mediation fails; sanctions apply before the Pangkat.
            'respondent_absent'  => ['Respondent absent — proceed to Pangkat', 'pangkat_constitution', null, 'respondent'],
        ];
    } elseif ($purposeType === 'regular' && $hStage === 'conciliation') {
        $rules = [
            'adjourned'          => ['Adjourned', 'conciliation_ongoing', null, null],
            'settled'            => ['Settled', 'conciliation_settled', null, null],
            'failed'             => ['Conciliation failed — certified to file action', 'certified_to_court', 'cfa_conciliation_failed', null],
            'complainant_absent' => ['Complainant absent', 'conciliation_noshow_complainant', null, 'complainant'],
            'respondent_absent'  => ['Respondent absent', 'conciliation_noshow_respondent', null, 'respondent'],
        ];
    } elseif ($purposeType === 'regular' && $hStage === 'arbitration') {
        $rules = [
            'adjourned' => ['Adjourned', 'arbitration_hearing', null, null],
            'concluded' => ['Submitted for award', 'arbitration_hearing', null, null],
        ];
    } elseif ($purposeType === 'pangkat_constitution') {
        $rules = [
            'held' => ['Meeting held', null, null, null],
        ];
    } elseif ($purposeType === 'explanation_complainant') {
        $rules = [
            'justified'   => ['Absence justified — proceedings resume', $hStage . '_ongoing', null, null],
            'unjustified' => ['Absence unjustified — complaint dismissed, action barred', 'dismissed', 'barred_complainant_absent', null],
        ];
    } elseif ($purposeType === 'explanation_respondent') {
        $rules = [
            'justified'   => ['Absence justified — proceedings resume', 'conciliation_ongoing', null, null],
            'unjustified' => ['Absence unjustified — certified to file action, counterclaim barred', 'certified_to_court', 'cfa_respondent_absent', null],
        ];
    }

    if (!isset($rules[$outcomeKey])) {
        $fail('invalid_outcome', $hStage);
    }
    [$label, $newStatus, $disposition, $absentParty] = $rules[$outcomeKey];

    if ($newStatus !== null && $newStatus !== $kpStatus && !kp_transition_case($conn, $caseId, $newStatus, $disposition)) {
        $fail('invalid_transition', $hStage);
    }

    $stmt = $conn->prepare("UPDATE kp_hearings SET outcome = ? WHERE id = ? AND case_id = ? LIMIT 1");
    $stmt->bind_param('sii', $label, $hearingId, $caseId);
    $stmt->execute();
    $stmt->close();

    if ($absentParty !== null) {
        $partyName = $absentParty === 'complainant' ? (string)$case['name'] : (string)$case['complained_person'];
        $att = $conn->prepare("INSERT INTO kp_attendance (hearing_id, party_name, party_role, attendance_status, created_by) VALUES (?, ?, ?, 'absent', ?)");
        if ($att) {
            $att->bind_param('issi', $hearingId, $partyName, $absentParty, $userId);
            $att->execute();
            $att->close();
        }
    }

    kp_log_event(
        $conn, $caseId, $hStage, $newStatus ?? $kpStatus, 'hearing_outcome',
        ucfirst($hStage) . ' hearing outcome recorded',
        "Outcome: {$label}" . ($disposition ? "\nDisposition: " . kp_disposition_label($disposition) : ''),
        $userId
    );

    $tab = $purposeType === 'pangkat_constitution' ? 'pangkat' : ($disposition ? 'disposition' : $hStage);
    header("Location: case_process.php?id={$caseId}&outcome=1#{$tab}");
    exit();
}

// ═══ ACTION: Record Service of Summons (Officer's Return, back of KP Form 9) ═══
if ($action === 'record_service') {
    $hearingId  = (int)($_POST['hearing_id'] ?? 0);
    $method     = trim($_POST['service_method'] ?? '');
    $servedDate = trim($_POST['served_date'] ?? '');
    $receivedBy = trim($_POST['received_by'] ?? '');

    $hStmt = $conn->prepare("SELECT id FROM kp_hearings WHERE id = ? AND case_id = ? AND stage = 'mediation' AND purpose_type = 'regular' LIMIT 1");
    $hStmt->bind_param('ii', $hearingId, $caseId);
    $hStmt->execute();
    $validHearing = (bool)$hStmt->get_result()->fetch_assoc();
    $hStmt->close();

    if (!$validHearing
        || !in_array($method, ['personal', 'refused', 'left_at_dwelling', 'left_at_office'], true)
        || $servedDate === '' || $servedDate > $today
        || (in_array($method, ['left_at_dwelling', 'left_at_office'], true) && $receivedBy === '')) {
        $fail('invalid_input', 'mediation');
    }

    $partyName = (string)$case['complained_person'];
    $stmt = $conn->prepare(
        "INSERT INTO kp_service_attempts (case_id, hearing_id, party_name, party_role, service_method, served_date, received_by, created_by)
         VALUES (?, ?, ?, 'respondent', ?, ?, ?, ?)"
    );
    $stmt->bind_param('iissssi', $caseId, $hearingId, $partyName, $method, $servedDate, $receivedBy, $userId);
    $stmt->execute();
    $stmt->close();

    $methodLabels = [
        'personal'         => 'handed in person',
        'refused'          => 'tendered in person but refused',
        'left_at_dwelling' => 'left at dwelling',
        'left_at_office'   => 'left at office/place of business',
    ];
    kp_log_event($conn, $caseId, 'mediation', $kpStatus, 'summons_served',
        'Service of summons recorded',
        'Served ' . date('M d, Y', strtotime($servedDate)) . ' — ' . $methodLabels[$method] . ($receivedBy !== '' ? " (received by {$receivedBy})" : ''),
        $userId);

    header("Location: case_process.php?id={$caseId}#mediation");
    exit();
}

// ═══ ACTION: Extend Conciliation Period (once, +15 days, meritorious cases) ═══
if ($action === 'extend_conciliation') {
    $reason = trim($_POST['reason'] ?? '');
    if (!in_array($kpStatus, ['conciliation_scheduled', 'conciliation_ongoing'], true)
        || (int)($case['conciliation_extended'] ?? 0) === 1
        || empty($case['conciliation_deadline'])
        || $reason === '') {
        $fail('extension_not_allowed', 'conciliation');
    }

    $newDeadline = date('Y-m-d', strtotime($case['conciliation_deadline'] . ' +15 days'));
    $upd = $conn->prepare("UPDATE cases SET conciliation_deadline = ?, conciliation_extended = 1 WHERE id = ? LIMIT 1");
    $upd->bind_param('si', $newDeadline, $caseId);
    $upd->execute();
    $upd->close();

    kp_log_event($conn, $caseId, 'conciliation', $kpStatus, 'conciliation_extended',
        'Conciliation period extended by 15 days',
        "New end of period: " . date('M d, Y', strtotime($newDeadline)) . "\nReason: {$reason}",
        $userId);

    header("Location: case_process.php?id={$caseId}#conciliation");
    exit();
}

header("Location: case_process.php?id={$caseId}#{$stage}");
exit();
