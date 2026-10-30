<?php
/**
 * Recent activity for the header bell (barangay/notifications.php and
 * admin/recent_activity.php). Built from the KP case timeline, plus action
 * items for the admin (residency verifications and new resident accounts).
 * Archived cases are left out; barangay staff only see their barangay.
 */

require_once __DIR__ . '/../config/kp.php';

if (!function_exists('gabaybatas_activity_style')) {
    /** Icon and colour for a timeline event type. @return array{0: string, 1: string} */
    function gabaybatas_activity_style(string $eventType): array
    {
        $map = [
            'case_submitted'      => ['uil-file-plus-alt', '#2563eb'],
            'resident_update'     => ['uil-comment-alt-message', '#0d9488'],
            'review_decision'     => ['uil-clipboard-notes', '#d97706'],
            'case_accepted'       => ['uil-check-circle', '#16a34a'],
            'nature_set'          => ['uil-tag-alt', '#64748b'],
            'details_corrected'   => ['uil-edit', '#ea580c'],
            'status_changed'      => ['uil-exchange', '#475569'],
            'hearing_scheduled'   => ['uil-calendar-alt', '#4f46e5'],
            'hearing_outcome'     => ['uil-comments-alt', '#4f46e5'],
            'settlement_recorded' => ['uil-file-check-alt', '#16a34a'],
            'settlement_final'    => ['uil-file-check-alt', '#15803d'],
            'repudiation'         => ['uil-file-block-alt', '#dc2626'],
        ];
        if (isset($map[$eventType])) {
            return $map[$eventType];
        }
        foreach ([
            'hearing' => ['uil-calendar-alt', '#4f46e5'], 'pangkat' => ['uil-users-alt', '#4f46e5'],
            'arbitra' => ['uil-balance-scale', '#7c3aed'], 'award' => ['uil-award', '#7c3aed'],
            'settle' => ['uil-file-check-alt', '#16a34a'], 'repudi' => ['uil-file-block-alt', '#dc2626'],
            'certif' => ['uil-file-upload-alt', '#475569'], 'dismiss' => ['uil-ban', '#64748b'],
            'execut' => ['uil-bolt', '#b45309'], 'complian' => ['uil-shield-check', '#0284c7'],
            'transmit' => ['uil-envelope-upload', '#475569'], 'review' => ['uil-clipboard-notes', '#d97706'],
        ] as $needle => $style) {
            if (str_contains($eventType, $needle)) {
                return $style;
            }
        }
        return ['uil-bell', '#64748b'];
    }
}

if (!function_exists('gabaybatas_activity_feed')) {
    /**
     * @param string $role     'barangay' or 'admin'
     * @param string $barangay barangay of the signed-in staff ('' = all)
     * @return list<array{key: string, icon: string, color: string, title: string, meta: string, timestamp: int, url: string}>
     */
    function gabaybatas_activity_feed(mysqli $conn, string $role, string $barangay, int $limit = 15): array
    {
        $items = [];
        $caseUrl = $role === 'admin' ? 'view_case.php?id=' : 'case_process.php?id=';

        // Case timeline (document generation is left out: it is frequent and not news).
        $sql = "SELECT t.id, t.event_type, t.title, t.event_at, t.created_at, c.id AS case_row, c.case_id, c.name, c.complained_person
                FROM kp_timeline_events t
                JOIN cases c ON c.id = t.case_id
                WHERE c.archived_at IS NULL
                  AND t.event_type NOT IN ('document_generated', 'test_event')"
            . ($barangay !== '' ? ' AND LOWER(c.barangay) = LOWER(?)' : '')
            . ' ORDER BY COALESCE(t.event_at, t.created_at) DESC, t.id DESC LIMIT ' . (int)$limit;
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($barangay !== '') {
                $stmt->bind_param('s', $barangay);
            }
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                [$icon, $color] = gabaybatas_activity_style((string)$r['event_type']);
                $code = (string)($r['case_id'] ?: ('C-' . str_pad((string)$r['case_row'], 4, '0', STR_PAD_LEFT)));
                $parties = trim((string)$r['name']) . ($r['complained_person'] ? ' vs. ' . $r['complained_person'] : '');
                $items[] = [
                    'key'       => 'event-' . $r['id'],
                    'icon'      => $icon,
                    'color'     => $color,
                    'title'     => (string)$r['title'],
                    'meta'      => $code . ' · ' . $parties,
                    'timestamp' => (int)strtotime((string)($r['event_at'] ?: $r['created_at'])),
                    'url'       => $caseUrl . (int)$r['case_row'],
                ];
            }
            $stmt->close();
        }

        if ($role === 'admin') {
            // Residency verifications waiting for a decision
            $res = $conn->query(
                "SELECT id, full_name, barangay, created_at FROM residency_verifications
                 WHERE status = 'pending' ORDER BY created_at DESC LIMIT 10"
            );
            foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $r) {
                $items[] = [
                    'key'       => 'verify-' . $r['id'],
                    'icon'      => 'uil-user-exclamation',
                    'color'     => '#d97706',
                    'title'     => 'Residency verification waiting: ' . $r['full_name'],
                    'meta'      => 'Action needed · ' . ($r['barangay'] ?: 'Resident'),
                    'timestamp' => (int)strtotime((string)$r['created_at']),
                    'url'       => 'residency_verifications.php',
                ];
            }

            // New resident accounts (last 30 days)
            $res = $conn->query(
                "SELECT id, name, created_at FROM users
                 WHERE role = 'resident' AND archived_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 ORDER BY created_at DESC LIMIT 5"
            );
            foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $r) {
                $items[] = [
                    'key'       => 'user-' . $r['id'],
                    'icon'      => 'uil-user-plus',
                    'color'     => '#0d9488',
                    'title'     => 'New resident account: ' . $r['name'],
                    'meta'      => 'Registered in the app',
                    'timestamp' => (int)strtotime((string)$r['created_at']),
                    'url'       => 'edit_user.php?id=' . (int)$r['id'],
                ];
            }
        }

        usort($items, static fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        return array_slice($items, 0, $limit);
    }
}
