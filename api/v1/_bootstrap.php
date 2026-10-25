<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/barangays.php';
require_once __DIR__ . '/../../config/case_types.php';
require_once __DIR__ . '/../../config/archive.php';
gabaybatas_archive_ensure_schema($conn);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

mysqli_report(MYSQLI_REPORT_OFF);

function api_now_manila_iso(): string
{
    $dt = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    return $dt->format(DateTimeInterface::ATOM);
}

function api_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
}

function api_error(string $message, int $statusCode = 400, array $extra = []): void
{
    api_response(array_merge([
        'success' => false,
        'message' => $message,
        'generated_at' => api_now_manila_iso(),
    ], $extra), $statusCode);
}

function api_require_barangay(string $barangay): string
{
    if (!gabaybatas_is_valid_barangay($barangay)) {
        api_error('Only Barangay Muñoz is supported.', 422);
    }

    return 'Barangay Muñoz';
}

function api_success(array $data = [], int $statusCode = 200): void
{
    api_response(array_merge([
        'success' => true,
        'generated_at' => api_now_manila_iso(),
    ], $data), $statusCode);
}

function api_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        api_error('Method not allowed.', 405);
    }
}

function api_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        api_error('Invalid JSON payload.', 422);
    }

    return is_array($decoded) ? $decoded : [];
}

function api_input(): array
{
    $json = api_json_input();
    if (!empty($json)) {
        return $json;
    }

    return $_POST;
}

function api_case_columns(mysqli $conn): array
{
    $columns = [];
    $res = $conn->query('SHOW COLUMNS FROM cases');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

function api_kb_columns(mysqli $conn): array
{
    $columns = [];
    $res = $conn->query('SHOW COLUMNS FROM knowledge_base');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

function api_has_column(array $columns, string $name): bool
{
    return in_array($name, $columns, true);
}

function api_case_status_allowed(string $status): bool
{
    return in_array($status, ['Pending', 'In Progress', 'Resolved'], true);
}

function api_normalize_case_type(string $input): string
{
    return gabaybatas_normalize_case_type($input);
}

function api_apply_referral_logic(string $caseType, string $barangay): array
{
    $normalized = strtolower($caseType);

    $minorKeywords = [
        'minor dispute', 'noise complaint', 'boundary dispute', 'public disturbance', 'domestic dispute',
        'family dispute', 'unpaid debt', 'oral defamation', 'trespassing', 'rental dispute', 'swindling', 'other dispute',
    ];
    $seriousKeywords = ['serious/legal', 'serious legal', 'rape', 'homicide', 'drug', 'physical injury', 'theft'];

    $isMinor = false;
    foreach ($minorKeywords as $keyword) {
        if (str_contains($normalized, $keyword)) {
            $isMinor = true;
            break;
        }
    }

    $isSerious = false;
    foreach ($seriousKeywords as $keyword) {
        if (str_contains($normalized, $keyword)) {
            $isSerious = true;
            break;
        }
    }

    if ($isSerious) {
        return [
            'priority' => 'High',
            'status' => 'Pending',
            'assigned_to' => 'ESCALATION_QUEUE',
            'route' => 'escalation',
            'note' => 'Marked as serious/legal for escalation.',
        ];
    }

    if ($isMinor) {
        return [
            'priority' => 'Medium',
            'status' => 'Pending',
            'assigned_to' => $barangay,
            'route' => 'barangay',
            'note' => 'Assigned to barangay for local handling.',
        ];
    }

    return [
        'priority' => 'Medium',
        'status' => 'Pending',
        'assigned_to' => $barangay,
        'route' => 'barangay',
        'note' => 'Assigned to barangay by default routing.',
    ];
}

function api_case_reference_from_id(int $id): string
{
    return 'C-' . str_pad((string)max(0, $id), 4, '0', STR_PAD_LEFT);
}

function api_generate_case_reference(mysqli $conn): string
{
    $suffix = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $candidate = 'C-' . $suffix;

    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM cases WHERE case_id = ?');
    if (!$stmt) {
        return $candidate;
    }

    $tries = 0;
    while ($tries < 3) {
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $res = $stmt->get_result();
        $count = (int)($res ? ($res->fetch_assoc()['c'] ?? 0) : 0);
        if ($count === 0) {
            $stmt->close();
            return $candidate;
        }
        $suffix = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $candidate = 'C-' . $suffix;
        $tries++;
    }

    $stmt->close();
    return $candidate;
}

require_once __DIR__ . '/_mobile_helpers.php';
