<?php
/**
 * Passwords saved as plain text by early versions are replaced with a hash
 * the first time the account logs in with them, so none stay readable in the
 * database. Call it only after the password was confirmed correct.
 */
if (!function_exists('gabaybatas_upgrade_password')) {
    function gabaybatas_upgrade_password(mysqli $conn, int $userId, string $password, string $storedPassword, string $column = 'password'): void
    {
        $isHash = password_get_info($storedPassword)['algo'] !== null;
        if ($userId <= 0 || ($isHash && !password_needs_rehash($storedPassword, PASSWORD_DEFAULT))) {
            return;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET `{$column}` = ? WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $hash, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
