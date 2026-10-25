<?php
/**
 * Load the resident app's Legal Information articles into the database.
 *
 * Usage (from the gabaybatas folder):
 *     php tools/seed_legal_information.php
 *
 * Replaces all rows of `legal_information` with db/legal_information_content.php
 * (run db/kp_migration_v5.sql first). Command line only.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require __DIR__ . '/../config/database.php';
$articles = require __DIR__ . '/../db/legal_information_content.php';

$conn->begin_transaction();
try {
    $conn->query('DELETE FROM legal_information');
    $stmt = $conn->prepare(
        'INSERT INTO legal_information (title, summary, content, category, offense_class, report_to, case_type, sort_order, reference)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($articles as $index => $a) {
        $order = ($index + 1) * 10;
        $stmt->bind_param(
            'sssssssis',
            $a['title'], $a['summary'], $a['content'], $a['category'],
            $a['offense_class'], $a['report_to'], $a['case_type'], $order, $a['reference']
        );
        $stmt->execute();
    }
    $stmt->close();
    $conn->commit();
    echo 'Loaded ' . count($articles) . " legal information articles.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
