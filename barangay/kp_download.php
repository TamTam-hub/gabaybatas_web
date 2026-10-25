<?php
/**
 * KP Document Download Handler
 * Securely serves generated DOCX files for download.
 */
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/barangays.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/kp_docx_engine.php';
gabaybatas_block_barangay_delete_requests();

function kp_download_path_inside(string $path, string $directory): bool
{
    $realPath = realpath($path);
    $realDirectory = realpath($directory);
    if ($realPath === false || $realDirectory === false || !is_file($realPath)) {
        return false;
    }

    $normalizedPath = strtolower(str_replace('/', '\\', $realPath));
    $normalizedDirectory = rtrim(strtolower(str_replace('/', '\\', $realDirectory)), '\\') . '\\';

    return strncmp($normalizedPath, $normalizedDirectory, strlen($normalizedDirectory)) === 0;
}

// ── Report file download (from kp_reports.php) ──
$dlType = trim($_GET['type'] ?? '');
$dlFile = trim($_GET['file'] ?? '');
if ($dlType === 'report' && $dlFile !== '') {
    $dlFile = basename($dlFile);
    $reportDirectory = __DIR__ . '/../storage/kp_documents/reports/';
    $reportPath = $reportDirectory . $dlFile;
    if (
        strtolower((string)pathinfo($dlFile, PATHINFO_EXTENSION)) === 'docx'
        && kp_download_path_inside($reportPath, $reportDirectory)
    ) {
        kp_serve_docx_download($reportPath, $dlFile);
    }
    http_response_code(404);
    echo 'Report file not found.';
    exit;
}

$docId = (int)($_GET['doc_id'] ?? 0);

if ($docId <= 0) {
    http_response_code(400);
    echo 'Invalid document ID.';
    exit;
}

// Load document record
$stmt = $conn->prepare(
    "SELECT d.*, c.case_id AS case_code, c.barangay AS case_barangay
     FROM kp_documents d
     JOIN cases c ON d.case_id = c.id
     WHERE d.id = ? AND LOWER(TRIM(c.barangay)) = LOWER(?)
     LIMIT 1"
);
$barangay = GABAYBATAS_BARANGAYS[0];
$stmt->bind_param('is', $docId, $barangay);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc || !$doc['file_path']) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
}

$documentDirectory = __DIR__ . '/../storage/kp_documents/';
if (
    strtolower((string)pathinfo((string)$doc['file_path'], PATHINFO_EXTENSION)) !== 'docx'
    || !kp_download_path_inside((string)$doc['file_path'], $documentDirectory)
) {
    http_response_code(404);
    echo 'Document file not found.';
    exit;
}

// Build download filename
$formLabel = kp_form_file_label((int)$doc['form_number']);
$caseCode  = $doc['case_code'] ?? ('C-' . str_pad((string)$doc['case_id'], 4, '0', STR_PAD_LEFT));
$versionLabel = (int)($doc['version_number'] ?? 1);
$filename  = "KP_Form_{$formLabel}_{$caseCode}_v{$versionLabel}.docx";

// Update downloaded_at timestamp
$now = date('Y-m-d H:i:s');
$upd = $conn->prepare("UPDATE kp_documents SET downloaded_at = ? WHERE id = ? LIMIT 1");
$upd->bind_param('si', $now, $docId);
$upd->execute();
$upd->close();

// Serve the file
kp_serve_docx_download($doc['file_path'], $filename);
