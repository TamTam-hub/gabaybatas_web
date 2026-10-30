<?php
/** Header bell feed for administrators (JSON). See includes/activity_feed.php. */
session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/activity_feed.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'items' => []]);
    exit();
}

echo json_encode([
    'success' => true,
    'items'   => gabaybatas_activity_feed($conn, 'admin', ''),
], JSON_UNESCAPED_UNICODE);
