<?php

declare(strict_types=1);

if (!function_exists('api_table_columns')) {
    function api_table_columns(mysqli $conn, string $table): array
    {
        $columns = [];
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if ($safeTable === '') {
            return $columns;
        }

        $result = $conn->query('SHOW COLUMNS FROM `' . $safeTable . '`');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $columns[] = $field;
                }
            }
        }

        return $columns;
    }

    function api_pick_column(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    function api_truthy_value(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    function api_iso_timestamp(mixed $value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }

        return gmdate(DateTimeInterface::ATOM, $ts);
    }

    function api_user_columns(mysqli $conn): array
    {
        return api_table_columns($conn, 'users');
    }

    function api_case_columns_v1(mysqli $conn): array
    {
        return api_table_columns($conn, 'cases');
    }

    function api_user_select_sql(array $columns): string
    {
        $select = ['id AS user_id'];
        $select[] = api_pick_column($columns, ['name']) ? 'name AS name' : (api_pick_column($columns, ['username']) ? 'username AS name' : "'' AS name");
        $select[] = api_pick_column($columns, ['username']) ? 'username AS username' : "'' AS username";
        $select[] = api_pick_column($columns, ['email']) ? 'email AS email' : "'' AS email";
        $contactColumn = api_pick_column($columns, ['contact_number', 'contact', 'phone']);
        $select[] = $contactColumn ? $contactColumn . ' AS contact_number' : "'' AS contact_number";
        $select[] = api_pick_column($columns, ['age']) ? 'age AS age' : "'' AS age";
        $select[] = api_pick_column($columns, ['barangay']) ? 'barangay AS barangay' : "'' AS barangay";
        $select[] = api_pick_column($columns, ['role']) ? 'role AS role' : "'' AS role";
        $select[] = api_pick_column($columns, ['status']) ? 'status AS status' : "'' AS status";
        $select[] = api_pick_column($columns, ['created_at']) ? 'created_at AS created_at' : 'NULL AS created_at';
        $select[] = api_pick_column($columns, ['updated_at']) ? 'updated_at AS updated_at' : 'NULL AS updated_at';

        return implode(', ', $select);
    }

    function api_user_by_id(mysqli $conn, int|string $userId): ?array
    {
        $columns = api_user_columns($conn);
        if (!$columns) {
            return null;
        }

        $sql = 'SELECT ' . api_user_select_sql($columns) . ' FROM users WHERE id = ? LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $userIdStr = (string)$userId;
        $stmt->bind_param('s', $userIdStr);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    function api_user_by_credential(mysqli $conn, string $credential): ?array
    {
        $columns = api_user_columns($conn);
        if (!$columns) {
            return null;
        }

        $credential = trim($credential);
        if ($credential === '') {
            return null;
        }

        $lookupColumns = [];
        foreach (['email', 'username'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $lookupColumns[] = $candidate;
            }
        }

        if (!$lookupColumns) {
            return null;
        }

        $parts = [];
        $types = '';
        $values = [];
        foreach ($lookupColumns as $lookupColumn) {
            $parts[] = $lookupColumn . ' = ?';
            $types .= 's';
            $values[] = $credential;
        }

        $sql = 'SELECT ' . api_user_select_sql($columns) . ' FROM users WHERE ' . implode(' OR ', $parts) . ' LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    function api_user_public_payload(array $row): array
    {
        return [
            'user_id' => (int)($row['user_id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'contact_number' => (string)($row['contact_number'] ?? ''),
            'age' => (string)($row['age'] ?? ''),
            'barangay' => (string)($row['barangay'] ?? ''),
            'role' => (string)($row['role'] ?? 'resident'),
            'status' => (string)($row['status'] ?? ''),
            'created_at' => api_iso_timestamp($row['created_at'] ?? null),
            'updated_at' => api_iso_timestamp($row['updated_at'] ?? null),
        ];
    }

    function api_ensure_mobile_tables(mysqli $conn): void
    {
        $queries = [
            'CREATE TABLE IF NOT EXISTS api_auth_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                token_prefix VARCHAR(24) NOT NULL,
                expires_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_token_hash (token_hash),
                KEY idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS api_notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(191) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(80) NOT NULL,
                related_case_id VARCHAR(80) NULL,
                related_chat_id BIGINT UNSIGNED NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                read_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_user_read (user_id, is_read),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS api_chat_threads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                case_id VARCHAR(80) NULL,
                case_reference VARCHAR(120) NULL,
                participant_name VARCHAR(191) NOT NULL,
                last_message TEXT NULL,
                last_message_at DATETIME NULL,
                unread_count INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_user_updated (user_id, updated_at),
                KEY idx_case_id (case_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS api_chat_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                thread_id BIGINT UNSIGNED NOT NULL,
                sender_id BIGINT UNSIGNED NOT NULL,
                sender_name VARCHAR(191) NOT NULL,
                sender_role VARCHAR(80) NOT NULL,
                content TEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_thread_created (thread_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];

        foreach ($queries as $sql) {
            $conn->query($sql);
        }
    }

    function api_generate_token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    function api_bearer_token(): string
    {
        $header = '';
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach (['Authorization', 'authorization'] as $key) {
                if (!empty($headers[$key])) {
                    $header = (string)$headers[$key];
                    break;
                }
            }
        }

        if ($header === '') {
            $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }

        if ($header !== '' && preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return trim((string)$matches[1]);
        }

        return '';
    }

    function api_issue_token(mysqli $conn, int|string $userId): ?array
    {
        api_ensure_mobile_tables($conn);

        $token = api_generate_token();
        $hash = hash('sha256', $token);
        $prefix = substr($token, 0, 12);
        $createdAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $stmt = $conn->prepare('INSERT INTO api_auth_tokens (user_id, token_hash, token_prefix, expires_at, created_at) VALUES (?, ?, ?, ?, ?)');
        if (!$stmt) {
            return null;
        }

        $userIdStr = (string)$userId;
        $stmt->bind_param('sssss', $userIdStr, $hash, $prefix, $expiresAt, $createdAt);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $stmt->close();

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => api_iso_timestamp($expiresAt),
        ];
    }

    function api_current_user(mysqli $conn): ?array
    {
        $sessionUserId = $_SESSION['user_id'] ?? null;
        if ($sessionUserId !== null && $sessionUserId !== '') {
            $sessionUser = api_user_by_id($conn, (string)$sessionUserId);
            if ($sessionUser) {
                return $sessionUser;
            }
        }

        $token = api_bearer_token();
        if ($token === '') {
            return null;
        }

        api_ensure_mobile_tables($conn);

        $tokenHash = hash('sha256', $token);
        $stmt = $conn->prepare('SELECT user_id FROM api_auth_tokens WHERE token_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return null;
        }

        $user = api_user_by_id($conn, (string)($row['user_id'] ?? ''));
        if (!$user) {
            return null;
        }

        $touch = $conn->prepare('UPDATE api_auth_tokens SET last_used_at = NOW() WHERE token_hash = ?');
        if ($touch) {
            $touch->bind_param('s', $tokenHash);
            $touch->execute();
            $touch->close();
        }

        return $user;
    }

    function api_scope_case_query(mysqli $conn, array $user): array
    {
        $caseColumns = api_case_columns_v1($conn);
        if (!$caseColumns) {
            return [[], '', []];
        }

        $clauses = [];
        $types = '';
        $values = [];

        if (in_array('user_id', $caseColumns, true) && !empty($user['user_id'])) {
            $clauses[] = 'user_id = ?';
            $types .= 's';
            $values[] = (string)$user['user_id'];

            return [$clauses, $types, $values];
        }

        $userName = trim((string)($user['name'] ?? ''));
        $userUsername = trim((string)($user['username'] ?? ''));
        $userEmail = trim((string)($user['email'] ?? ''));
        $userContact = trim((string)($user['contact_number'] ?? ''));
        $userBarangay = trim((string)($user['barangay'] ?? ''));

        $caseNameColumn = api_pick_column($caseColumns, ['resident_name', 'name']);
        $caseContactColumn = api_pick_column($caseColumns, ['contact', 'contact_number', 'phone']);
        $caseBarangayColumn = api_pick_column($caseColumns, ['barangay']);
        $caseEmailColumn = api_pick_column($caseColumns, ['email']);

        if ($caseNameColumn && $userName !== '') {
            $clauses[] = $caseNameColumn . ' = ?';
            $types .= 's';
            $values[] = $userName;
        }

        if ($caseNameColumn && $userUsername !== '') {
            $clauses[] = $caseNameColumn . ' = ?';
            $types .= 's';
            $values[] = $userUsername;
        }

        if ($caseEmailColumn && $userEmail !== '') {
            $clauses[] = $caseEmailColumn . ' = ?';
            $types .= 's';
            $values[] = $userEmail;
        }

        if ($caseContactColumn && $userContact !== '') {
            $clauses[] = $caseContactColumn . ' = ?';
            $types .= 's';
            $values[] = $userContact;
        }

        if ($caseBarangayColumn && $userBarangay !== '') {
            $clauses[] = 'LOWER(' . $caseBarangayColumn . ') = LOWER(?)';
            $types .= 's';
            $values[] = $userBarangay;
        }

        return [$clauses, $types, $values];
    }
}