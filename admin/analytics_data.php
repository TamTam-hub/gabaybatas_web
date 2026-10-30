<?php
// Live refresh for the Analytics page: the same numbers, from includes/analytics_metrics.php.
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

require_once '../config/database.php';
require_once '../config/case_types.php';
require_once '../includes/analytics_metrics.php';

$months = (int)($_GET['months'] ?? 6) === 12 ? 12 : 6;
echo json_encode([
    'success' => true,
    'generated_at' => date('c'),
    'data' => gabaybatas_analytics_metrics($conn, $months),
]);
