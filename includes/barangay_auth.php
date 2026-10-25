<?php

if (!function_exists('gabaybatas_require_role')) {
    function gabaybatas_table_has_column(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return false;
        }

        $key = strtolower($safeTable . '.' . $safeColumn);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $sql = "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'";
        $result = $conn->query($sql);
        $exists = $result && $result->num_rows > 0;
        $cache[$key] = $exists;

        return $exists;
    }

    function gabaybatas_require_role(array $allowedRoles, string $loginPath = '../login.php'): void
    {
        if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles, true)) {
            header('Location: ' . $loginPath);
            exit();
        }
    }

    function gabaybatas_block_barangay_delete_requests(): void
    {
        if (($_SESSION['role'] ?? '') !== 'barangay') {
            return;
        }

        $action = strtolower(trim((string)($_REQUEST['action'] ?? $_REQUEST['intent'] ?? '')));
        $hasDeleteFlag = isset($_GET['delete'])
            || isset($_POST['delete'])
            || isset($_REQUEST['delete_id']);

        if ($hasDeleteFlag || in_array($action, ['delete', 'remove'], true)) {
            http_response_code(403);
            exit('Delete action is not allowed for barangay officials.');
        }
    }

    function gabaybatas_get_user_barangay(mysqli $conn): string
    {
        require_once __DIR__ . '/../config/barangays.php';
        $_SESSION['barangay'] = 'Barangay Muñoz';
        return 'Barangay Muñoz';
    }

    function gabaybatas_barangay_scope_clause(mysqli $conn, string $column = 'barangay', string $tableAlias = ''): string
    {
        if (!isset($_SESSION['role'])) {
            return '1=0';
        }

        if ($_SESSION['role'] === 'admin') {
            return '1=1';
        }

        $barangay = gabaybatas_get_user_barangay($conn);
        if ($barangay === '') {
            return '1=0';
        }

        $qualifiedColumn = $tableAlias !== '' ? $tableAlias . '.' . $column : $column;
        $escapedBarangay = $conn->real_escape_string($barangay);

        return "LOWER($qualifiedColumn) = LOWER('$escapedBarangay')";
    }

    function gabaybatas_barangay_matches(array $row, string $barangay): bool
    {
        $rowBarangay = trim((string)($row['barangay'] ?? $row['case_barangay'] ?? $row['display_barangay'] ?? ''));

        if ($barangay === '') {
            return false;
        }

        return strtolower($rowBarangay) === strtolower($barangay);
    }

    function gabaybatas_format_case_id($value, $fallback = ''): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            $raw = trim((string)$fallback);
        }

        if ($raw === '') {
            return 'N/A';
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits !== '') {
            $digits = substr($digits, -4);
            return 'C-' . str_pad($digits, 4, '0', STR_PAD_LEFT);
        }

        $normalized = preg_replace('/^\s*C\s*-\s*/i', '', $raw);
        return 'C-' . strtoupper($normalized);
    }
}