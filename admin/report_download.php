<?php
// Replaced by the formal case report PDF (barangay/generate_report.php); old links keep working.
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$period = ['monthly' => 'month', 'quarterly' => 'quarter', 'yearly' => 'year'][(string)($_GET['type'] ?? '')] ?? 'all';
header('Location: ../barangay/generate_report.php?period=' . $period);
exit();
