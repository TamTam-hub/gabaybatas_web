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

    function api_ensure_user_profile_columns(mysqli $conn): void
    {
        $columns = api_user_columns($conn);
        if (!$columns) {
            return;
        }

        if (!in_array('age', $columns, true)) {
            $conn->query("ALTER TABLE users ADD COLUMN age VARCHAR(10) NULL AFTER phone");
        }
        if (!in_array('email_verified', $columns, true)) {
            $conn->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email");
        }
    }

    function api_case_columns_v1(mysqli $conn): array
    {
        return api_table_columns($conn, 'cases');
    }

    function api_case_evidence(mysqli $conn, int $caseId): array
    {
        $stmt = $conn->prepare(
            'SELECT id, evidence_type, original_filename, mime_type, file_size, stored_filename, created_at
             FROM api_case_evidence WHERE case_id = ? ORDER BY id ASC'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $caseId);
        $stmt->execute();
        $result = $stmt->get_result();
        $evidence = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $evidence[] = [
                    'id' => (int)$row['id'],
                    'type' => (string)$row['evidence_type'],
                    'name' => (string)$row['original_filename'],
                    'mime_type' => (string)$row['mime_type'],
                    'size' => (int)$row['file_size'],
                    'created_at' => api_iso_timestamp($row['created_at'] ?? null),
                    'url' => '/api/v1/case_evidence.php?id=' . (int)$row['id'],
                ];
            }
        }
        $stmt->close();
        return $evidence;
    }

    function api_user_select_sql(array $columns): string
    {
        $select = ['id AS user_id'];
        $nameColumn = api_pick_column($columns, ['name', 'full_name']);
        $select[] = $nameColumn ? $nameColumn . ' AS name' : (api_pick_column($columns, ['username']) ? 'username AS name' : "'' AS name");
        $select[] = api_pick_column($columns, ['username']) ? 'username AS username' : "'' AS username";
        $select[] = api_pick_column($columns, ['email']) ? 'email AS email' : "'' AS email";
        $select[] = api_pick_column($columns, ['email_verified']) ? 'email_verified AS email_verified' : '1 AS email_verified';
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

        $sql = 'SELECT ' . api_user_select_sql($columns) . ' FROM users WHERE id = ? AND archived_at IS NULL LIMIT 1';
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

        $sql = 'SELECT ' . api_user_select_sql($columns) . ' FROM users WHERE (' . implode(' OR ', $parts) . ') AND archived_at IS NULL LIMIT 1';
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
            'barangay' => 'Barangay Muñoz',
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
            'CREATE TABLE IF NOT EXISTS api_password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_password_reset_token (token_hash),
                KEY idx_password_reset_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS api_email_verification_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_email_verification_token (token_hash),
                KEY idx_email_verification_user (user_id)
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
            'CREATE TABLE IF NOT EXISTS api_case_evidence (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                case_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                evidence_type VARCHAR(20) NOT NULL,
                stored_filename VARCHAR(255) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_case_evidence_case (case_id),
                KEY idx_case_evidence_user (user_id)
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
            'CREATE TABLE IF NOT EXISTS api_ai_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                role VARCHAR(20) NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_ai_user_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS api_ai_conversations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(191) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_ai_conversation_user_updated (user_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS api_ai_conversation_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                role VARCHAR(20) NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_ai_conversation_created (conversation_id, created_at),
                KEY idx_ai_conversation_user (conversation_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS residency_verifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                full_name VARCHAR(191) NOT NULL,
                date_of_birth DATE NULL,
                complete_address TEXT NOT NULL,
                barangay VARCHAR(191) NOT NULL,
                government_id_type VARCHAR(80) NOT NULL,
                government_id_last4 VARCHAR(4) NULL,
                status VARCHAR(32) NOT NULL DEFAULT \'pending\',
                reviewer_note TEXT NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_residency_user_status (user_id, status),
                KEY idx_residency_barangay_status (barangay, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS residency_verification_documents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                verification_id BIGINT UNSIGNED NOT NULL,
                document_type VARCHAR(40) NOT NULL,
                stored_filename VARCHAR(255) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_verification_document_type (verification_id, document_type),
                KEY idx_document_verification (verification_id)
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
        $token = api_bearer_token();
        if ($token !== '') {
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

        $sessionUserId = $_SESSION['user_id'] ?? null;
        if ($sessionUserId !== null && $sessionUserId !== '') {
            return api_user_by_id($conn, (string)$sessionUserId);
        }

        return null;
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