<?php
/**
 * KP Pangkat Constitution Handler
 * Records the 3-member Pangkat ng Tagapagkasundo chosen by the parties (or by
 * drawing of lots) and the Chairman and Secretary elected from among them.
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

$caseId    = (int)($_POST['case_id'] ?? 0);
$chairman  = (int)($_POST['chairman'] ?? 0);
$secretary = (int)($_POST['secretary'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

$validChosenBy = ['both_parties', 'complainant', 'respondent', 'drawing_lots'];
$members = [];
for ($i = 1; $i <= 3; $i++) {
    $name = trim($_POST["member_{$i}_name"] ?? '');
    $chosenBy = trim($_POST["member_{$i}_chosen_by"] ?? '');
    if ($name === '' || !in_array($chosenBy, $validChosenBy, true)) {
        header("Location: case_process.php?id={$caseId}&kp_error=pangkat_incomplete#pangkat");
        exit();
    }
    $members[$i] = ['name' => $name, 'chosen_by' => $chosenBy];
}

if ($chairman < 1 || $chairman > 3 || $secretary < 1 || $secretary > 3 || $chairman === $secretary) {
    header("Location: case_process.php?id={$caseId}&kp_error=pangkat_officers#pangkat");
    exit();
}

// Verify case exists and is awaiting Pangkat constitution
$stmt = $conn->prepare("SELECT id, kp_status FROM cases WHERE id = ? AND archived_at IS NULL LIMIT 1");
$stmt->bind_param('i', $caseId);
$stmt->execute();
$case = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$case) {
    header('Location: cases.php');
    exit();
}

$existing = (int)$conn->query("SELECT COUNT(*) AS n FROM kp_pangkat WHERE case_id = {$caseId}")->fetch_assoc()['n'];
if ($case['kp_status'] !== 'pangkat_constitution' || $existing > 0) {
    header("Location: case_process.php?id={$caseId}&kp_error=invalid_stage#pangkat");
    exit();
}

foreach ($members as $i => $m) {
    $role = $i === $chairman ? 'chairman' : ($i === $secretary ? 'secretary' : 'member');
    $stmt = $conn->prepare("INSERT INTO kp_pangkat (case_id, member_name, chosen_by, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isss', $caseId, $m['name'], $m['chosen_by'], $role);
    $stmt->execute();
    $stmt->close();
}

// Conciliation period: 15 days from the constitution of the Pangkat
$deadline = date('Y-m-d', strtotime('+15 days'));
$upd = $conn->prepare("UPDATE cases SET conciliation_deadline = ? WHERE id = ? LIMIT 1");
$upd->bind_param('si', $deadline, $caseId);
$upd->execute();
$upd->close();

$memberNames = implode(', ', array_map(
    static fn(int $i, array $m): string => $m['name'] . ($i === $chairman ? ' (Chairman)' : ($i === $secretary ? ' (Secretary)' : '')),
    array_keys($members),
    $members
));
kp_log_event(
    $conn, $caseId, 'conciliation', 'pangkat_constitution', 'pangkat_constituted',
    'Pangkat ng Tagapagkasundo constituted',
    "Members: {$memberNames}\nConciliation period ends: " . date('M d, Y', strtotime($deadline)),
    $userId
);

header("Location: case_process.php?id={$caseId}&pangkat=1#pangkat");
exit();
