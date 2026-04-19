<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_activity_first_seen']) || !is_array($_SESSION['admin_activity_first_seen'])) {
    $_SESSION['admin_activity_first_seen'] = [];
}

function table_columns(mysqli $conn, string $table): array {
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM {$table}");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}

function has_col(array $cols, string $name): bool {
    return in_array($name, $cols, true);
}

function pick_col(array $cols, array $candidates, string $fallback = ''): string {
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) {
            return $c;
        }
    }
    return $fallback;
}

function human_time(?int $timestamp): string {
    if (!$timestamp) {
        return 'Recently';
    }

    $diff = time() - $timestamp;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day(s) ago';
    return date('M d, Y', $timestamp);
}

function stable_activity_timestamp(string $key, ?int $dbTimestamp, int $rank): int {
    $store = &$_SESSION['admin_activity_first_seen'];

    if ($dbTimestamp !== null && $dbTimestamp > 0) {
        $store[$key] = $dbTimestamp;
        return $dbTimestamp;
    }

    if (isset($store[$key]) && is_numeric($store[$key])) {
        return (int)$store[$key];
    }

    $fallback = time() - max(1, $rank) * 60;
    $store[$key] = $fallback;
    return $fallback;
}

$activities = [];

$caseCols = table_columns($conn, 'cases');
if ($caseCols) {
    $caseIdCol = pick_col($caseCols, ['id', 'case_id'], $caseCols[0]);
    $caseLabelCol = pick_col($caseCols, ['case_type', 'title', 'complaint_type', 'description'], $caseIdCol);
    $caseStatusCol = pick_col($caseCols, ['status'], '');
    $caseDateCol = pick_col($caseCols, ['updated_at', 'created_at', 'date_reported', 'createdon'], '');

    $select = [
        "{$caseIdCol} AS row_id",
        "{$caseLabelCol} AS row_label",
        $caseStatusCol !== '' ? "{$caseStatusCol} AS row_status" : "'' AS row_status",
        $caseDateCol !== '' ? "{$caseDateCol} AS row_date" : 'NULL AS row_date',
    ];

    $query = 'SELECT ' . implode(', ', $select) . ' FROM cases ORDER BY ' . ($caseDateCol !== '' ? $caseDateCol : $caseIdCol) . ' DESC LIMIT 6';
    $res = $conn->query($query);
    if ($res) {
        $rank = 0;
        while ($row = $res->fetch_assoc()) {
            $rank++;
            $label = trim((string)($row['row_label'] ?? 'Case update'));
            $status = trim((string)($row['row_status'] ?? ''));
            $rowId = (string)($row['row_id'] ?? '');
            $ts = null;
            if (!empty($row['row_date'])) {
                $parsed = strtotime((string)$row['row_date']);
                if ($parsed !== false) {
                    $ts = $parsed;
                }
            }
            $activityKey = 'case:' . $rowId . ':' . strtolower($status);
            $ts = stable_activity_timestamp($activityKey, $ts, $rank);

            $activities[] = [
                'icon' => 'uil-folder-open',
                'title' => $status !== ''
                    ? ('Case #' . $rowId . ' is now ' . $status)
                    : ('Case #' . $rowId . ' activity recorded'),
                'meta' => $label,
                'time' => human_time($ts),
                'timestamp' => $ts,
                'key' => $activityKey,
                'url' => 'view_case.php?id=' . rawurlencode($rowId),
            ];
        }
    }
}

$userCols = table_columns($conn, 'users');
if ($userCols) {
    $userIdCol = pick_col($userCols, ['id'], $userCols[0]);
    $userNameCol = pick_col($userCols, ['name', 'username'], $userIdCol);
    $userRoleCol = pick_col($userCols, ['role'], '');
    $userStatusCol = pick_col($userCols, ['status'], '');
    $userDateCol = pick_col($userCols, ['updated_at', 'created_at'], '');

    $select = [
        "{$userIdCol} AS row_id",
        "{$userNameCol} AS row_name",
        $userRoleCol !== '' ? "{$userRoleCol} AS row_role" : "'' AS row_role",
        $userStatusCol !== '' ? "{$userStatusCol} AS row_status" : "'' AS row_status",
        $userDateCol !== '' ? "{$userDateCol} AS row_date" : 'NULL AS row_date',
    ];

    $query = 'SELECT ' . implode(', ', $select) . ' FROM users ORDER BY ' . ($userDateCol !== '' ? $userDateCol : $userIdCol) . ' DESC LIMIT 6';
    $res = $conn->query($query);
    if ($res) {
        $rank = 0;
        while ($row = $res->fetch_assoc()) {
            $rank++;
            $name = trim((string)($row['row_name'] ?? 'User'));
            $role = trim((string)($row['row_role'] ?? ''));
            $status = trim((string)($row['row_status'] ?? ''));
            $rowId = (string)($row['row_id'] ?? '');
            $ts = null;
            if (!empty($row['row_date'])) {
                $parsed = strtotime((string)$row['row_date']);
                if ($parsed !== false) {
                    $ts = $parsed;
                }
            }
            $activityKey = 'user:' . $rowId . ':' . strtolower($status);
            $ts = stable_activity_timestamp($activityKey, $ts, $rank);

            $details = [];
            if ($role !== '') $details[] = $role;
            if ($status !== '') $details[] = $status;

            $activities[] = [
                'icon' => 'uil-user-check',
                'title' => 'User updated: ' . $name,
                'meta' => $details ? implode(' | ', $details) : 'Account activity',
                'time' => human_time($ts),
                'timestamp' => $ts,
                'key' => $activityKey,
                'url' => 'edit_user.php?id=' . rawurlencode($rowId),
            ];
        }
    }
}

usort($activities, static function (array $a, array $b): int {
    return (int)$b['timestamp'] <=> (int)$a['timestamp'];
});

$activities = array_slice($activities, 0, 10);
$cleaned = [];
foreach ($activities as $a) {
    $cleaned[] = [
        'icon' => (string)$a['icon'],
        'title' => (string)$a['title'],
        'meta' => (string)$a['meta'],
        'time' => (string)$a['time'],
        'timestamp' => (int)($a['timestamp'] ?? 0),
        'key' => (string)($a['key'] ?? ''),
        'url' => (string)$a['url'],
    ];
}

echo json_encode([
    'ok' => true,
    'count' => count($cleaned),
    'activities' => $cleaned,
    'generated_at' => date('c'),
]);
