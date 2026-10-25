<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

require_once '../config/database.php';
$documentId = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT stored_filename, original_filename, mime_type FROM residency_verification_documents WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $documentId);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$document) {
    http_response_code(404);
    exit('Document not found');
}

$path = __DIR__ . '/../storage/residency_documents/' . basename((string)$document['stored_filename']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Document file not found');
}

header('Content-Type: ' . (string)$document['mime_type']);
header('Content-Disposition: inline; filename="' . addslashes((string)$document['original_filename']) . '"');
header('Content-Length: ' . (string)filesize($path));
readfile($path);