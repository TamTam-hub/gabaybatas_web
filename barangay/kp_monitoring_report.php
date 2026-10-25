<?php
/**
 * Download KP Monitoring Form No. 1 (monthly report) as a PDF.
 * GET: month (1–12), year. Defaults to the current month.
 */
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['barangay', 'admin'], true)) {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/kp.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/kp_monitoring.php';
require_once __DIR__ . '/../vendor/autoload.php';
gabaybatas_block_barangay_delete_requests();

use Dompdf\Dompdf;
use Dompdf\Options;

$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}
if ($year < 2020 || $year > 2100) {
    $year = (int)date('Y');
}

$barangay = gabaybatas_get_user_barangay($conn);
$report   = kp_monitoring_rows($conn, $barangay, $year, $month);

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml(kp_monitoring_html($report, $year, $month), 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = sprintf('KP_Monitoring_Form_1_%04d_%02d.pdf', $year, $month);
$dompdf->stream($filename, ['Attachment' => true]);
exit();
