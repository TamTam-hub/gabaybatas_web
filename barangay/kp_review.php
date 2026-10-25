<?php
/**
 * KP Review Action Handler
 * Processes intake review decisions for KP cases.
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

$caseId   = (int)($_POST['case_id'] ?? 0);
$decision = trim($_POST['decision'] ?? '');
$notes    = trim($_POST['notes'] ?? '');
$missing  = trim($_POST['missing_items'] ?? '');
$userId   = (int)($_SESSION['user_id'] ?? 0);
$coverageReason = trim($_POST['not_covered_reason'] ?? '');
$referredTo     = trim($_POST['referral_destination'] ?? '');
$nature         = trim($_POST['kp_nature'] ?? '');

// ── Set the nature of case (criminal / civil / others) for monitoring ──
if (($_POST['action'] ?? '') === 'set_nature') {
    if ($caseId > 0 && array_key_exists($nature, kp_case_natures())) {
        $upd = $conn->prepare("UPDATE cases SET kp_nature = ? WHERE id = ? AND archived_at IS NULL LIMIT 1");
        $upd->bind_param('si', $nature, $caseId);
        $upd->execute();
        $changed = $upd->affected_rows > 0;
        $upd->close();
        if ($changed) {
            $statusRow = $conn->query("SELECT kp_status FROM cases WHERE id = {$caseId}")->fetch_assoc();
            $currentStatus = (string)($statusRow['kp_status'] ?? 'filed');
            kp_log_event($conn, $caseId, kp_status_stage($currentStatus), $currentStatus, 'nature_set',
                'Nature of case set to ' . kp_case_natures()[$nature], '', $userId);
        }
    }
    header("Location: case_process.php?id={$caseId}");
    exit();
}

// Validate
$validDecisions = ['under_review', 'accepted', 'additional_info_required', 'referred', 'not_eligible'];
if ($caseId <= 0 || !in_array($decision, $validDecisions, true)) {
    header('Location: cases.php');
    exit();
}

// Verify case exists
$stmt = $conn->prepare("SELECT id, kp_status, name, complained_person FROM cases WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $caseId);
$stmt->execute();
$case = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$case) {
    header('Location: cases.php');
    exit();
}

// Only allow review from valid statuses
$allowedFrom = ['filed', 'under_review', 'additional_info_required', null, ''];
if (!in_array($case['kp_status'], $allowedFrom, true)) {
    header("Location: case_process.php?id={$caseId}#review");
    exit();
}

// A complaint excluded from KP must state the legal ground.
if ($decision === 'not_eligible') {
    $reasons = kp_not_covered_reasons();
    if (!isset($reasons[$coverageReason]) || ($coverageReason === 'other' && $notes === '')) {
        header("Location: case_process.php?id={$caseId}&kp_error=coverage_reason#review");
        exit();
    }
}

// ── Save the review record ──
$now = date('Y-m-d H:i:s');
$stmt = $conn->prepare(
    "INSERT INTO kp_case_reviews (case_id, decision, notes, missing_items, completed_at, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param('issssis', $caseId, $decision, $notes, $missing, $now, $userId, $now);
$stmt->execute();
$stmt->close();

// ── Update case KP status ──
if (!kp_transition_case($conn, $caseId, $decision, $decision === 'not_eligible' ? 'not_covered' : null)) {
    header("Location: case_process.php?id={$caseId}&kp_error=invalid_transition#review");
    exit();
}

// ── Set mediation deadline (and nature of case) if accepted ──
if ($decision === 'accepted') {
    if (array_key_exists($nature, kp_case_natures())) {
        $nat = $conn->prepare("UPDATE cases SET kp_nature = ? WHERE id = ? LIMIT 1");
        $nat->bind_param('si', $nature, $caseId);
        $nat->execute();
        $nat->close();
    }
    $deadline = date('Y-m-d', strtotime('+15 days'));
    $upd = $conn->prepare("UPDATE cases SET mediation_deadline = ? WHERE id = ? LIMIT 1");
    $upd->bind_param('si', $deadline, $caseId);
    $upd->execute();
    $upd->close();
}

// ── Record why the complaint is not covered and where it was referred ──
if ($decision === 'not_eligible') {
    $ref = $conn->prepare("INSERT INTO kp_referrals (case_id, reason, details, destination, decided_by) VALUES (?, ?, ?, ?, ?)");
    if ($ref) {
        $ref->bind_param('isssi', $caseId, $coverageReason, $notes, $referredTo, $userId);
        $ref->execute();
        $ref->close();
    }
}

// ── Log timeline event ──
$eventTitle = 'Case review: ' . kp_status_label($decision);
$eventDetails = '';
if ($decision === 'not_eligible') {
    $eventDetails = 'Ground: ' . kp_not_covered_reasons()[$coverageReason]
        . ($referredTo !== '' ? "\nReferred to: {$referredTo}" : '');
}
if ($notes !== '') {
    $eventDetails .= ($eventDetails ? "\n" : '') . "Notes: {$notes}";
}
if ($missing !== '') {
    $eventDetails .= ($eventDetails ? "\n" : '') . "Required: {$missing}";
}

kp_log_event(
    $conn,
    $caseId,
    'intake',
    $decision,
    'review_decision',
    $eventTitle,
    $eventDetails,
    $userId
);

// Redirect back to case process
header("Location: case_process.php?id={$caseId}&reviewed=1#review");
exit();
