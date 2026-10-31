<?php
/**
 * Tells the page that started a download that the file is ready, so its
 * "Preparing your document…" screen (includes/lottie.php) can close.
 * The page sends a random dl_token; this sets it back as a short-lived cookie
 * just before the file is sent.
 */

if (!function_exists('gabaybatas_download_ready')) {
    function gabaybatas_download_ready(): void
    {
        $token = (string)($_REQUEST['dl_token'] ?? '');
        if (preg_match('/^[A-Za-z0-9]{8,32}$/', $token) && !headers_sent()) {
            setcookie('gb_dl', $token, ['expires' => time() + 120, 'path' => '/', 'samesite' => 'Lax']);
        }
    }
}
