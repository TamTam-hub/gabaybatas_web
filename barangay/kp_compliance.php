<?php
/**
 * KP Compliance Action Handler
 * Monitors compliance with a final settlement or award.
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

$stmt = $conn->prepare("SELECT id, kp_status FROM cases WHERE id = ? AND archived_at IS NULL LIMIT 1");
$stmt->bind_param('i', $caseId);
$stmt->execute();
$case = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$case) {
    header('Location: cases.php');
    exit();
}

$tab = 'compliance';

switch ($action) {
    case 'start_monitoring':
        if (kp_transition_case($conn, $caseId, 'compliance_monitoring')) {
            kp_log_event($conn, $caseId, 'compliance', 'compliance_monitoring', 'status_change',
                'Compliance monitoring started', 'Settlement/award is final and executory.', $userId);
        }
        break;

    case 'mark_complied':
        if (kp_transition_case($conn, $caseId, 'closed', 'settled_complied')) {
            kp_log_event($conn, $caseId, 'compliance', 'closed', 'compliance_update',
                'Full compliance confirmed — case closed', $notes, $userId);
            $tab = 'disposition';
        }
        break;

    case 'partial_compliance':
        if ($case['kp_status'] === 'compliance_monitoring') {
            kp_log_event($conn, $caseId, 'compliance', 'compliance_monitoring', 'compliance_update',
                'Partial compliance noted', $notes, $userId);
        }
        break;

    case 'non_compliance':
        // Execution starts only upon a party's Motion for Execution (KP Form 25).
        if ($case['kp_status'] === 'compliance_monitoring') {
            kp_log_event($conn, $caseId, 'compliance', 'compliance_monitoring', 'compliance_update',
                'Non-compliance noted', $notes, $userId);
            $tab = 'execution';
        }
        break;

    default:
        break;
}

header("Location: case_process.php?id={$caseId}#{$tab}");
exit();
