<?php
/**
 * KP Execution Action Handler
 * Motion for Execution (KP Form 25) within 6 months of the settlement,
 * hearing on the motion (KP Form 26) within 5 days of filing, and Notice of
 * Execution (KP Form 27) after 5 days without voluntary compliance.
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
$action = trim($_POST['action'] ?? '');
$notes  = trim($_POST['notes'] ?? '');
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
$now      = date('Y-m-d H:i:s');

$fail = static function (string $code) use ($caseId): void {
    header("Location: case_process.php?id={$caseId}&kp_error={$code}#execution");
    exit();
};

$finalSettlement = $conn->query(
    "SELECT * FROM kp_settlements WHERE case_id = {$caseId} AND repudiated = 0 AND final_at IS NOT NULL ORDER BY id DESC LIMIT 1"
)->fetch_assoc();
$executionLimit = $finalSettlement
    ? date('Y-m-d', strtotime($finalSettlement['settlement_date'] . ' +6 months'))
    : null;

$execution = $conn->query("SELECT * FROM kp_executions WHERE case_id = {$caseId} ORDER BY id DESC LIMIT 1")->fetch_assoc();

switch ($action) {
    // ── KP Form 25 ──
    case 'file_motion':
        $partyRole  = trim($_POST['moving_party_role'] ?? '');
        $motionDate = trim($_POST['motion_date'] ?? '');

        if ($kpStatus !== 'compliance_monitoring' || !$finalSettlement) {
            $fail('invalid_stage');
        }
        if (!in_array($partyRole, ['complainant', 'respondent'], true) || $motionDate === '' || $motionDate > $today) {
            $fail('invalid_input');
        }
        if ($motionDate > $executionLimit) {
            $fail('execution_period_lapsed');
        }
        if (!kp_transition_case($conn, $caseId, 'execution_pending')) {
            $fail('invalid_transition');
        }

        $partyName    = $partyRole === 'complainant' ? (string)$case['name'] : (string)$case['complained_person'];
        $settlementId = (int)$finalSettlement['id'];
        $motionNotes  = "Moving party: {$partyName} ({$partyRole})" . ($notes !== '' ? "\n{$notes}" : '');
        $stmt = $conn->prepare(
            "INSERT INTO kp_executions (case_id, settlement_id, motion_date, notes, status, created_by, created_at)
             VALUES (?, ?, ?, ?, 'motion_filed', ?, ?)"
        );
        $stmt->bind_param('iissis', $caseId, $settlementId, $motionDate, $motionNotes, $userId, $now);
        $stmt->execute();
        $stmt->close();

        kp_log_event($conn, $caseId, 'execution', 'execution_pending', 'execution_filed',
            'Motion for Execution filed', $motionNotes, $userId);
        break;

    // ── 6-month period lapsed without execution: enforce by motion in the MTC ──
    case 'close_lapsed':
        if ($kpStatus !== 'compliance_monitoring' || !$finalSettlement || $today <= $executionLimit) {
            $fail('invalid_stage');
        }
        if (kp_transition_case($conn, $caseId, 'closed', 'execution_lapsed_mtc')) {
            kp_log_event($conn, $caseId, 'disposition', 'closed', 'disposition',
                'Case closed — 6-month execution period lapsed',
                'The settlement/award may be enforced by motion in the Municipal Trial Court.' . ($notes !== '' ? "\n{$notes}" : ''),
                $userId);
            header("Location: case_process.php?id={$caseId}#disposition");
            exit();
        }
        break;

    // ── KP Form 26: hearing not later than 5 days from filing of the motion ──
    case 'schedule_hearing':
        $hearingDate = trim($_POST['hearing_date'] ?? '');
        $hearingTime = trim($_POST['hearing_time'] ?? '');
        $location    = trim($_POST['location'] ?? '');

        if ($kpStatus !== 'execution_pending' || !$execution || !empty($execution['hearing_date'])) {
            $fail('invalid_stage');
        }
        $latest = date('Y-m-d', strtotime($execution['motion_date'] . ' +5 days'));
        if ($hearingDate === '' || $hearingDate < $execution['motion_date'] || $hearingDate > $latest) {
            $fail('execution_hearing_date');
        }

        $stmt = $conn->prepare(
            "INSERT INTO kp_hearings (case_id, stage, purpose_type, hearing_date, hearing_time, location, purpose, created_by)
             VALUES (?, 'execution', 'execution', ?, ?, ?, 'Hearing on the Motion for Execution', ?)"
        );
        $stmt->bind_param('isssi', $caseId, $hearingDate, $hearingTime, $location, $userId);
        $stmt->execute();
        $stmt->close();

        $complianceDeadline = date('Y-m-d', strtotime($hearingDate . ' +5 days'));
        $executionId = (int)$execution['id'];
        $upd = $conn->prepare("UPDATE kp_executions SET hearing_date = ?, compliance_deadline = ?, status = 'hearing_set' WHERE id = ? LIMIT 1");
        $upd->bind_param('ssi', $hearingDate, $complianceDeadline, $executionId);
        $upd->execute();
        $upd->close();

        kp_log_event($conn, $caseId, 'execution', 'execution_pending', 'hearing_scheduled',
            'Hearing on Motion for Execution scheduled',
            'Date: ' . date('M d, Y', strtotime($hearingDate)) . ($hearingTime ? ' at ' . date('h:i A', strtotime($hearingTime)) : '')
            . "\nVoluntary compliance period ends: " . date('M d, Y', strtotime($complianceDeadline)),
            $userId);
        break;

    // ── Obliged party complied voluntarily ──
    case 'complied':
        if ($kpStatus !== 'execution_pending' || !$execution) {
            $fail('invalid_stage');
        }
        if (kp_transition_case($conn, $caseId, 'execution_completed')) {
            $executionId = (int)$execution['id'];
            $conn->query("UPDATE kp_executions SET voluntary_compliance = 1, status = 'complied' WHERE id = {$executionId} LIMIT 1");
            kp_log_event($conn, $caseId, 'execution', 'execution_completed', 'execution_completed',
                'Obliged party complied voluntarily', $notes, $userId);
        }
        break;

    // ── KP Form 27: after 5 days from the hearing without compliance ──
    case 'issue_notice':
        $amount = trim($_POST['amount'] ?? '');
        if ($kpStatus !== 'execution_pending' || !$execution || empty($execution['compliance_deadline'])) {
            $fail('invalid_stage');
        }
        if ($today <= $execution['compliance_deadline']) {
            $fail('compliance_period_open');
        }
        $amountValue = $amount === '' ? null : (float)str_replace(',', '', $amount);
        $executionId = (int)$execution['id'];
        $upd = $conn->prepare("UPDATE kp_executions SET execution_issued_at = ?, amount = ?, status = 'notice_issued' WHERE id = ? LIMIT 1");
        $upd->bind_param('sdi', $now, $amountValue, $executionId);
        $upd->execute();
        $upd->close();

        kp_log_event($conn, $caseId, 'execution', 'execution_pending', 'execution_notice',
            'Notice of Execution issued',
            ($amountValue !== null ? 'Amount: PHP ' . number_format($amountValue, 2) . "\n" : '') . $notes,
            $userId);
        break;

    case 'mark_executed':
        if ($kpStatus !== 'execution_pending' || !$execution || empty($execution['execution_issued_at'])) {
            $fail('invalid_stage');
        }
        if (kp_transition_case($conn, $caseId, 'execution_completed')) {
            $executionId = (int)$execution['id'];
            $conn->query("UPDATE kp_executions SET status = 'executed' WHERE id = {$executionId} LIMIT 1");
            kp_log_event($conn, $caseId, 'execution', 'execution_completed', 'execution_completed',
                'Execution completed — settlement/award enforced', $notes, $userId);
        }
        break;

    // ── Close the case after execution ──
    case 'close_case':
        if ($kpStatus !== 'execution_completed' || !$execution) {
            $fail('invalid_stage');
        }
        $disposition = (int)$execution['voluntary_compliance'] === 1 ? 'settled_complied' : 'executed';
        if (kp_transition_case($conn, $caseId, 'closed', $disposition)) {
            kp_log_event($conn, $caseId, 'disposition', 'closed', 'disposition',
                'Case closed — ' . kp_disposition_label($disposition), $notes, $userId);
            header("Location: case_process.php?id={$caseId}#disposition");
            exit();
        }
        break;

    default:
        break;
}

header("Location: case_process.php?id={$caseId}#execution");
exit();
