<?php
/**
 * KP Arbitration Action Handler
 * Agreement for arbitration (KP Form 14), its repudiation within 5 days
 * (KP Form 17 → KP Form 20), and the arbitration award (KP Form 15).
 *
 * The arbitrator follows the stage: the Punong Barangay (Lupon Chairman) when
 * the parties agree during mediation, the Pangkat during conciliation. The award
 * is made after the agreement's 5-day repudiation period and within 10 days
 * thereafter (LGC Sec. 413(b)).
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
    header("Location: case_process.php?id={$caseId}&kp_error={$code}#arbitration");
    exit();
};

$latestAgreement = static function () use ($conn, $caseId): ?array {
    $row = $conn->query("SELECT * FROM kp_arbitration_agreements WHERE case_id = {$caseId} ORDER BY id DESC LIMIT 1")->fetch_assoc();
    return $row ?: null;
};

switch ($action) {
    // ── KP Form 14: parties agree in writing to arbitration (any stage) ──
    case 'agree':
        $agreementDate = trim($_POST['agreement_date'] ?? '');
        $fromStages = [
            'mediation_scheduled' => 'mediation', 'mediation_ongoing' => 'mediation',
            'pangkat_constitution' => 'conciliation', 'conciliation_scheduled' => 'conciliation', 'conciliation_ongoing' => 'conciliation',
        ];
        if (!isset($fromStages[$kpStatus])) {
            $fail('invalid_stage');
        }
        if ($agreementDate === '' || $agreementDate > $today) {
            $fail('invalid_input');
        }
        $arbitrator = $fromStages[$kpStatus] === 'mediation' ? 'punong_barangay' : 'pangkat';
        if ($arbitrator === 'pangkat') {
            $hasPangkat = (int)$conn->query("SELECT COUNT(*) AS n FROM kp_pangkat WHERE case_id = {$caseId}")->fetch_assoc()['n'] > 0;
            if (!$hasPangkat) {
                $fail('pangkat_required');
            }
        }
        if (!kp_transition_case($conn, $caseId, 'arbitration_agreed')) {
            $fail('invalid_transition');
        }

        $deadline  = date('Y-m-d', strtotime($agreementDate . ' +5 days'));
        $fromStage = $fromStages[$kpStatus];
        $stmt = $conn->prepare(
            "INSERT INTO kp_arbitration_agreements (case_id, arbitrator, from_stage, agreement_date, repudiation_deadline, created_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issssi', $caseId, $arbitrator, $fromStage, $agreementDate, $deadline, $userId);
        $stmt->execute();
        $stmt->close();

        kp_log_event($conn, $caseId, 'arbitration', 'arbitration_agreed', 'arbitration_agreed',
            'Agreement for Arbitration entered into',
            'Arbitrator: ' . ($arbitrator === 'pangkat' ? 'Pangkat ng Tagapagkasundo' : 'Punong Barangay')
            . "\nRepudiation period ends: " . date('M d, Y', strtotime($deadline)),
            $userId);
        break;

    // ── KP Form 17 on the agreement (within 5 days) → adapted KP Form 20 ──
    case 'repudiate_agreement':
        $agreement = $latestAgreement();
        $partyRole = trim($_POST['party_role'] ?? '');
        $ground    = trim($_POST['ground'] ?? '');
        $details   = trim($_POST['details'] ?? '');
        $swornDate = trim($_POST['sworn_date'] ?? '');

        if ($kpStatus !== 'arbitration_agreed' || !$agreement || (int)$agreement['repudiated'] === 1) {
            $fail('invalid_stage');
        }
        if (!in_array($partyRole, ['complainant', 'respondent'], true) || !in_array($ground, ['fraud', 'violence', 'intimidation'], true) || $swornDate === '' || $swornDate > $today) {
            $fail('invalid_input');
        }
        if ($swornDate < $agreement['agreement_date'] || $swornDate > $agreement['repudiation_deadline']) {
            $fail('repudiation_period_lapsed');
        }
        if (!kp_transition_case($conn, $caseId, 'certified_to_court', 'cfa_arbitration_repudiated')) {
            $fail('invalid_transition');
        }

        $partyName = $partyRole === 'complainant' ? (string)$case['name'] : (string)$case['complained_person'];
        $agreementId = (int)$agreement['id'];
        $stmt = $conn->prepare(
            "INSERT INTO kp_repudiations (case_id, target_type, target_id, party_name, party_role, ground, details, sworn_date, created_by)
             VALUES (?, 'arbitration_agreement', ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iisssssi', $caseId, $agreementId, $partyName, $partyRole, $ground, $details, $swornDate, $userId);
        $stmt->execute();
        $stmt->close();
        $conn->query("UPDATE kp_arbitration_agreements SET repudiated = 1 WHERE id = {$agreementId} LIMIT 1");

        kp_log_event($conn, $caseId, 'disposition', 'certified_to_court', 'repudiation',
            'Agreement for Arbitration repudiated — certified to file action',
            "By: {$partyName} ({$partyRole})\nGround: " . ucfirst($ground) . ($details !== '' ? "\nDetails: {$details}" : ''),
            $userId);
        header("Location: case_process.php?id={$caseId}&outcome=1#disposition");
        exit();

    // ── KP Form 15: award after the agreement's repudiation period ──
    case 'award':
        $awardText = trim($_POST['award_text'] ?? '');
        $awardDate = trim($_POST['award_date'] ?? '');
        $agreement = $latestAgreement();

        if ($kpStatus !== 'arbitration_hearing' || !$agreement) {
            $fail('invalid_stage');
        }
        if ($awardText === '' || $awardDate === '' || $awardDate > $today) {
            $fail('invalid_input');
        }

        $last = $conn->query(
            "SELECT MAX(hearing_date) AS d FROM kp_hearings
             WHERE case_id = {$caseId} AND stage = 'arbitration' AND purpose_type = 'regular' AND outcome = 'Submitted for award'"
        )->fetch_assoc();
        $lastHearing = $last['d'] ?? null;
        if (!$lastHearing) {
            $fail('award_hearing_required');
        }
        if ($awardDate <= $agreement['repudiation_deadline'] || $awardDate < $lastHearing) {
            $fail('award_too_early');
        }
        if (!kp_transition_case($conn, $caseId, 'arbitration_awarded')) {
            $fail('invalid_transition');
        }

        // 10 days for a petition to nullify the award in court (KP Form 17 does not apply).
        $deadline   = date('Y-m-d', strtotime($awardDate . ' +10 days'));
        $arbitrator = (string)$agreement['arbitrator'];
        $stmt = $conn->prepare(
            "INSERT INTO kp_settlements (case_id, settlement_type, stage, arbitrator, terms, settlement_date, repudiation_deadline, created_by)
             VALUES (?, 'arbitration_award', 'arbitration', ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issssi', $caseId, $arbitrator, $awardText, $awardDate, $deadline, $userId);
        $stmt->execute();
        $stmt->close();

        $late = $awardDate > date('Y-m-d', strtotime($agreement['repudiation_deadline'] . ' +10 days'));
        kp_log_event($conn, $caseId, 'arbitration', 'arbitration_awarded', 'award_rendered',
            'Arbitration Award rendered',
            "Award date: " . date('M d, Y', strtotime($awardDate))
            . "\nFinal and executory after: " . date('M d, Y', strtotime($deadline))
            . ($late ? "\nNote: rendered more than 10 days after the agreement's repudiation period ended." : ''),
            $userId);
        break;

    default:
        break;
}

header("Location: case_process.php?id={$caseId}#arbitration");
exit();
