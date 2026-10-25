<?php
/**
 * KP Settlement Action Handler
 * Records amicable settlements (KP Form 16), sworn repudiations within the
 * 10-day period (KP Form 17 → KP Form 20), and confirms finality once the
 * repudiation/nullification period has lapsed.
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

$caseId = (int)($_POST['case_id'] ?? 0);
$action = trim($_POST['action'] ?? 'record');
$userId = (int)($_SESSION['user_id'] ?? 0);

$stmt = $conn->prepare("SELECT id, kp_status, name, complained_person FROM cases WHERE id = ? AND archived_at IS NULL LIMIT 1");
$stmt->bind_param('i', $caseId);
$stmt->execute();
$case = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$case) {
    header('Location: cases.php');
    exit();
}

$kpStatus = (string)$case['kp_status'];
$today    = date('Y-m-d');

$fail = static function (string $code) use ($caseId): void {
    header("Location: case_process.php?id={$caseId}&kp_error={$code}#settlement");
    exit();
};

$latestSettlement = static function () use ($conn, $caseId): ?array {
    $row = $conn->query("SELECT * FROM kp_settlements WHERE case_id = {$caseId} AND repudiated = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    return $row ?: null;
};

switch ($action) {
    // ── KP Form 16: amicable settlement reached in mediation or conciliation ──
    case 'record':
        $terms          = trim($_POST['terms'] ?? '');
        $settlementDate = trim($_POST['settlement_date'] ?? '');
        $stageMap = ['mediation_settled' => 'mediation', 'conciliation_settled' => 'conciliation'];

        if (!isset($stageMap[$kpStatus])) {
            $fail('invalid_stage');
        }
        if ($terms === '' || $settlementDate === '' || $settlementDate > $today) {
            $fail('invalid_input');
        }
        if (!kp_transition_case($conn, $caseId, 'settlement_repudiation')) {
            $fail('invalid_transition');
        }

        $stage    = $stageMap[$kpStatus];
        $deadline = date('Y-m-d', strtotime($settlementDate . ' +10 days'));
        $stmt = $conn->prepare(
            "INSERT INTO kp_settlements (case_id, settlement_type, stage, terms, settlement_date, repudiation_deadline, created_by)
             VALUES (?, 'amicable', ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issssi', $caseId, $stage, $terms, $settlementDate, $deadline, $userId);
        $stmt->execute();
        $stmt->close();

        kp_log_event($conn, $caseId, 'settlement', 'settlement_repudiation', 'settlement_recorded',
            'Amicable Settlement recorded (' . $stage . ')',
            "Terms: {$terms}\nRepudiation period ends: " . date('M d, Y', strtotime($deadline)),
            $userId);
        break;

    // ── KP Form 17: sworn repudiation within 10 days → KP Form 20 ──
    case 'repudiate':
        $settlement = $latestSettlement();
        $partyRole  = trim($_POST['party_role'] ?? '');
        $ground     = trim($_POST['ground'] ?? '');
        $details    = trim($_POST['details'] ?? '');
        $swornDate  = trim($_POST['sworn_date'] ?? '');

        if ($kpStatus !== 'settlement_repudiation' || !$settlement || $settlement['settlement_type'] !== 'amicable') {
            $fail('invalid_stage');
        }
        if (!in_array($partyRole, ['complainant', 'respondent'], true) || !in_array($ground, ['fraud', 'violence', 'intimidation'], true) || $swornDate === '' || $swornDate > $today) {
            $fail('invalid_input');
        }
        if ($swornDate < $settlement['settlement_date'] || $swornDate > $settlement['repudiation_deadline']) {
            $fail('repudiation_period_lapsed');
        }
        if (!kp_transition_case($conn, $caseId, 'certified_to_court', 'cfa_settlement_repudiated')) {
            $fail('invalid_transition');
        }

        $partyName    = $partyRole === 'complainant' ? (string)$case['name'] : (string)$case['complained_person'];
        $settlementId = (int)$settlement['id'];
        $stmt = $conn->prepare(
            "INSERT INTO kp_repudiations (case_id, target_type, target_id, party_name, party_role, ground, details, sworn_date, created_by)
             VALUES (?, 'settlement', ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iisssssi', $caseId, $settlementId, $partyName, $partyRole, $ground, $details, $swornDate, $userId);
        $stmt->execute();
        $stmt->close();

        $upd = $conn->prepare("UPDATE kp_settlements SET repudiated = 1, repudiation_ground = ?, repudiation_details = ?, repudiation_date = ? WHERE id = ? LIMIT 1");
        $upd->bind_param('sssi', $ground, $details, $swornDate, $settlementId);
        $upd->execute();
        $upd->close();

        kp_log_event($conn, $caseId, 'disposition', 'certified_to_court', 'repudiation',
            'Amicable Settlement repudiated — certified to file action',
            "By: {$partyName} ({$partyRole})\nGround: " . ucfirst($ground) . ($details !== '' ? "\nDetails: {$details}" : ''),
            $userId);
        header("Location: case_process.php?id={$caseId}&outcome=1#disposition");
        exit();

    // ── Finality: settlement (10 days, no repudiation) or award (10 days) ──
    case 'confirm_final':
        $settlement = $latestSettlement();
        if (!in_array($kpStatus, ['settlement_repudiation', 'arbitration_awarded'], true) || !$settlement) {
            $fail('invalid_stage');
        }
        if ($today <= $settlement['repudiation_deadline']) {
            $fail('finality_period_open');
        }
        if (!kp_transition_case($conn, $caseId, 'settlement_final')) {
            $fail('invalid_transition');
        }

        $now = date('Y-m-d H:i:s');
        $settlementId = (int)$settlement['id'];
        $upd = $conn->prepare("UPDATE kp_settlements SET final_at = ? WHERE id = ? LIMIT 1");
        $upd->bind_param('si', $now, $settlementId);
        $upd->execute();
        $upd->close();

        $label = $settlement['settlement_type'] === 'arbitration_award' ? 'Arbitration Award' : 'Amicable Settlement';
        kp_log_event($conn, $caseId, 'settlement', 'settlement_final', 'settlement_final',
            "{$label} is final and executory",
            'Period ended ' . date('M d, Y', strtotime($settlement['repudiation_deadline'])) . ' without repudiation.',
            $userId);
        break;

    // ── Transmittal to the city/municipal court (LGC Sec. 419: within 5 days) ──
    case 'record_transmittal':
        $transmittedOn = trim($_POST['transmitted_on'] ?? '');
        $settlementId  = (int)($_POST['settlement_id'] ?? 0);
        $row = $conn->query("SELECT * FROM kp_settlements WHERE id = {$settlementId} AND case_id = {$caseId} AND repudiated = 0 LIMIT 1")->fetch_assoc();
        if (!$row || !empty($row['transmitted_at'])) {
            $fail('invalid_stage');
        }
        $isAward = $row['settlement_type'] === 'arbitration_award';
        // A settlement is transmitted once its repudiation period ends; an award once rendered.
        $earliest = $isAward ? $row['settlement_date'] : date('Y-m-d', strtotime($row['repudiation_deadline'] . ' +1 day'));
        if ($transmittedOn === '' || $transmittedOn > $today || $transmittedOn < $earliest) {
            $fail('invalid_input');
        }

        $upd = $conn->prepare("UPDATE kp_settlements SET transmitted_at = ? WHERE id = ? LIMIT 1");
        $upd->bind_param('si', $transmittedOn, $settlementId);
        $upd->execute();
        $upd->close();

        kp_log_event($conn, $caseId, 'settlement', $kpStatus, 'transmitted_to_court',
            ($isAward ? 'Arbitration Award' : 'Amicable Settlement') . ' transmitted to the city court',
            'Transmitted on ' . date('M d, Y', strtotime($transmittedOn)),
            $userId);
        break;

    default:
        break;
}

header("Location: case_process.php?id={$caseId}#settlement");
exit();
