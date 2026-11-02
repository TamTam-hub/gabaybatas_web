<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
    exit;
}

// Same database settings as the rest of the site (the live server sets them in config/database.local.php).
require_once __DIR__ . '/../config/database.php';
$dbHost = $host;
$dbName = $database;
$dbUser = $user;
$dbPass = $password;

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $sql = "SELECT id, title, summary, content, category, offense_class, report_to, case_type, sort_order, file_path, `reference`, created_at
            FROM legal_information ORDER BY sort_order ASC, id ASC";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    // Contract: return a JSON list.
    echo json_encode($rows);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch legal information.',
        'error' => $e->getMessage(),
    ]);
}
