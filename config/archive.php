<?php

if (!function_exists('gabaybatas_archive_ensure_schema')) {
    function gabaybatas_archive_ensure_schema(mysqli $conn): void
    {
        $tableDefinitions = [
            'cases' => [
                'archived_at' => 'DATETIME NULL',
                'archived_by' => 'INT NULL',
                'archive_reason' => 'VARCHAR(500) NULL',
                'legal_hold' => 'TINYINT(1) NOT NULL DEFAULT 0',
            ],
            'users' => [
                'archived_at' => 'DATETIME NULL',
                'archived_by' => 'INT NULL',
                'archive_reason' => 'VARCHAR(500) NULL',
                'legal_hold' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'archive_previous_status' => "VARCHAR(20) NULL",
                'last_login' => 'DATETIME NULL',
            ],
        ];

        foreach ($tableDefinitions as $table => $columns) {
            $existing = [];
            $result = $conn->query("SHOW COLUMNS FROM `$table`");
            if (!$result) {
                continue;
            }
            while ($column = $result->fetch_assoc()) {
                $existing[] = (string)$column['Field'];
            }

            foreach ($columns as $name => $definition) {
                if (!in_array($name, $existing, true)) {
                    $conn->query("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
                }
            }
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                actor_user_id INT NULL,
                action VARCHAR(80) NOT NULL,
                entity_type VARCHAR(40) NOT NULL,
                entity_id VARCHAR(80) NOT NULL,
                reason VARCHAR(500) NULL,
                before_data LONGTEXT NULL,
                after_data LONGTEXT NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_audit_entity (entity_type, entity_id),
                INDEX idx_audit_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('gabaybatas_archive_audit')) {
    function gabaybatas_archive_audit(
        mysqli $conn,
        string $action,
        string $entityType,
        string $entityId,
        string $reason = '',
        ?array $before = null,
        ?array $after = null
    ): bool {
        $actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE);
        $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare(
            'INSERT INTO audit_logs
                (actor_user_id, action, entity_type, entity_id, reason, before_data, after_data, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            'isssssss',
            $actorId,
            $action,
            $entityType,
            $entityId,
            $reason,
            $beforeJson,
            $afterJson,
            $ipAddress
        );
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }
}
