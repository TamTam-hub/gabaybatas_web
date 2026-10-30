<?php
// Replaced by the Case Report page (admin/cases_report.php); old links and bookmarks land there.
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$period = ['monthly' => 'month', 'quarterly' => 'quarter', 'yearly' => 'year'][(string)($_GET['type'] ?? '')] ?? '';
$query = array_filter([
    'period'    => ($_GET['date_from'] ?? '') !== '' || ($_GET['date_to'] ?? '') !== '' ? 'custom' : $period,
    'date_from' => (string)($_GET['date_from'] ?? ''),
    'date_to'   => (string)($_GET['date_to'] ?? ''),
    'case_type' => (string)($_GET['case_type'] ?? ''),
    'search'    => (string)($_GET['search'] ?? ''),
], static fn($v) => $v !== '');
header('Location: cases_report.php' . ($query ? '?' . http_build_query($query) : ''));
exit();
