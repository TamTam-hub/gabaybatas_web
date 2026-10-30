<?php
/** Header bell feed for barangay staff (JSON). See includes/activity_feed.php. */
session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/activity_feed.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    http_response_code(401);
    echo json_encode(['success' => false, 'items' => []]);
    exit();
}

echo json_encode([
    'success' => true,
    'items'   => gabaybatas_activity_feed($conn, 'barangay', gabaybatas_get_user_barangay($conn)),
], JSON_UNESCAPED_UNICODE);
