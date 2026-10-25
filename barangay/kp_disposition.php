<?php
/**
 * KP Disposition Handler (retired)
 *
 * Certifications and dismissals are no longer chosen freely: each disposition
 * is recorded by the workflow step that legally produces it (failed
 * conciliation, unjustified absence, repudiation, compliance or execution).
 * Requests to this endpoint are redirected back to the case.
 */
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

$caseId = (int)($_POST['case_id'] ?? $_GET['case_id'] ?? 0);
header($caseId > 0 ? "Location: case_process.php?id={$caseId}#disposition" : 'Location: cases.php');
exit();
