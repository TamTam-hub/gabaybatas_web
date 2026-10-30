<?php
/**
 * Every number on the admin Analytics page, computed in one place so the page
 * and its live refresh (admin/analytics_data.php) always agree.
 * Archived cases and users are never counted.
 */

require_once __DIR__ . '/flexible_report.php';
require_once __DIR__ . '/barangay_auth.php';

if (!function_exists('gabaybatas_analytics_outcomes')) {
    /** Outcome categories for concluded cases, in display order. */
    function gabaybatas_analytics_outcomes(): array
    {
        return [
            'mediation'    => ['label' => 'Settled in mediation',          'color' => '#10b981', 'settled' => true],
            'conciliation' => ['label' => 'Settled in conciliation',       'color' => '#059669', 'settled' => true],
            'arbitration'  => ['label' => 'Arbitration award',             'color' => '#0d9488', 'settled' => true],
            'settled'      => ['label' => 'Settled (stage not recorded)',  'color' => '#34d399', 'settled' => true],
            'certified'    => ['label' => 'Certified to file in court',    'color' => '#64748b', 'settled' => false],
            'dismissed'    => ['label' => 'Dismissed / action barred',     'color' => '#94a3b8', 'settled' => false],
            'not_covered'  => ['label' => 'Not covered by KP',             'color' => '#cbd5e1', 'settled' => null],
            'closed'       => ['label' => 'Closed (no outcome recorded)',  'color' => '#e2e8f0', 'settled' => null],
        ];
    }
}

if (!function_exists('gabaybatas_analytics_metrics')) {
    /**
     * @param int $months length of the monthly trend (6 or 12)
     */
    function gabaybatas_analytics_metrics(mysqli $conn, int $months = 6): array
    {
        $groups = gabaybatas_flex_groups();
        $statuses = kp_statuses();
        $groupOf = static function (string $status) use ($groups): string {
            foreach ($groups as $key => $g) {
                if (in_array($status, $g['statuses'], true)) return $key;
            }
            return 'review';
        };
        $one = static fn(string $sql): int => (int)($conn->query($sql)->fetch_row()[0] ?? 0);

        // ── Cases (one pass; the barangay has a small caseload) ──
        $cases = $conn->query(
            "SELECT c.id, COALESCE(NULLIF(c.kp_status, ''), 'filed') AS st, c.kp_disposition, c.case_type, c.date_submitted,
                    (SELECT CONCAT(s.settlement_type, '|', COALESCE(s.stage, '')) FROM kp_settlements s
                     WHERE s.case_id = c.id AND s.repudiated = 0 ORDER BY s.id DESC LIMIT 1) AS settlement
             FROM cases c WHERE c.archived_at IS NULL"
        )->fetch_all(MYSQLI_ASSOC);

        $counts = array_fill_keys(array_keys($groups), 0);
        $outcomeDefs = gabaybatas_analytics_outcomes();
        $outcomes = array_fill_keys(array_keys($outcomeDefs), 0);
        $stageDefs = kp_stages();
        $pipelineStages = ['intake', 'mediation', 'conciliation', 'arbitration', 'settlement', 'compliance', 'execution'];
        $pipeline = array_fill_keys($pipelineStages, 0);
        $types = [];

        // Month buckets for the trend, oldest first.
        $monthKeys = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = new DateTimeImmutable("first day of -{$i} month");
            $monthKeys[$m->format('Y-m')] = $m->format('M Y');
        }
        $trend = [];
        foreach ($groups as $key => $g) {
            $trend[$key] = array_fill_keys(array_keys($monthKeys), 0);
        }

        foreach ($cases as $c) {
            $st = (string)$c['st'];
            $group = $groupOf($st);
            $counts[$group]++;

            $type = trim((string)$c['case_type']) ?: 'Unspecified';
            $types[$type] = ($types[$type] ?? 0) + 1;

            $month = substr((string)$c['date_submitted'], 0, 7);
            if (isset($trend[$group][$month])) {
                $trend[$group][$month]++;
            }

            // Where the case is now (cases still moving through KP).
            $stage = $statuses[$st]['stage'] ?? 'intake';
            if ($group !== 'ended' && $st !== 'execution_completed' && isset($pipeline[$stage])) {
                $pipeline[$stage]++;
            }

            // Outcome of concluded or settled cases.
            [$sType, $sStage] = array_pad(explode('|', (string)$c['settlement'], 2), 2, '');
            $settledBy = static function () use ($st, $sType, $sStage): string {
                if ($sType === 'arbitration_award' || $st === 'arbitration_awarded') return 'arbitration';
                if ($sStage === 'mediation' || $st === 'mediation_settled') return 'mediation';
                if ($sStage === 'conciliation' || $st === 'conciliation_settled') return 'conciliation';
                return 'settled';
            };
            $disp = (string)$c['kp_disposition'];
            $outcome = null;
            if ($group === 'settled') {
                $outcome = $settledBy();
            } elseif (in_array($st, ['not_eligible', 'referred'], true) || $disp === 'not_covered') {
                $outcome = 'not_covered';
            } elseif ($st === 'certified_to_court' || str_starts_with($disp, 'cfa_')) {
                $outcome = 'certified';
            } elseif ($st === 'dismissed' || $disp === 'barred_complainant_absent') {
                $outcome = 'dismissed';
            } elseif ($st === 'closed') {
                $outcome = in_array($disp, ['settled_complied', 'executed', 'execution_lapsed_mtc'], true) ? $settledBy() : 'closed';
            }
            if ($outcome !== null) {
                $outcomes[$outcome]++;
            }
        }
        arsort($types);

        // Settlements and awards (not repudiated) recorded this month and overall.
        $settledSql = "FROM kp_settlements s JOIN cases c ON c.id = s.case_id
                       WHERE c.archived_at IS NULL AND s.repudiated = 0 AND s.settlement_date IS NOT NULL";
        $settledThisMonth = $one("SELECT COUNT(DISTINCT s.case_id) $settledSql AND DATE_FORMAT(s.settlement_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
        $settledAllTime = $one("SELECT COUNT(DISTINCT s.case_id) $settledSql");

        // Hearings not yet held, dated today through the next 6 days.
        $hearingSql = "FROM kp_hearings h JOIN cases c ON c.id = h.case_id
                       WHERE c.archived_at IS NULL AND (h.outcome IS NULL OR h.outcome = '')
                         AND h.hearing_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 6 DAY";
        $hearingsThisWeek = $one("SELECT COUNT(*) $hearingSql");
        $next = $conn->query("SELECT c.id, c.case_id, h.stage, h.hearing_date, h.hearing_time $hearingSql ORDER BY h.hearing_date, h.hearing_time LIMIT 1")->fetch_assoc();
        $nextHearing = $next ? [
            'case'  => gabaybatas_format_case_id($next['case_id'] ?? '', $next['id']),
            'stage' => ucfirst((string)$next['stage']),
            'when'  => date('D, M j', strtotime((string)$next['hearing_date'])) . ($next['hearing_time'] ? ', ' . date('g:i A', strtotime((string)$next['hearing_time'])) : ''),
        ] : null;

        $filedThisMonth = $one("SELECT COUNT(*) FROM cases WHERE archived_at IS NULL AND DATE_FORMAT(date_submitted, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
        $filedLastMonth = $one("SELECT COUNT(*) FROM cases WHERE archived_at IS NULL AND DATE_FORMAT(date_submitted, '%Y-%m') = DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m')");

        return [
            'months'    => $months,
            'total'     => count($cases),
            'counts'    => $counts,
            'kpi' => [
                'filedThisMonth'  => $filedThisMonth,
                'filedLastMonth'  => $filedLastMonth,
                'settledThisMonth' => $settledThisMonth,
                'settledAllTime'   => $settledAllTime,
                'hearingsThisWeek' => $hearingsThisWeek,
                'nextHearing'      => $nextHearing,
            ],
            'trend' => [
                'labels' => array_values($monthKeys),
                'series' => array_map(static fn($g, $key) => [
                    'key' => $key, 'label' => $g['label'], 'color' => $g['color'], 'data' => array_values($trend[$key]),
                ], $groups, array_keys($groups)),
            ],
            'types' => [
                'labels' => array_keys($types),
                'values' => array_values($types),
                'colors' => array_map('gabaybatas_case_type_color', array_map('strval', array_keys($types))),
            ],
            'pipeline' => [
                // "Intake" also holds cases accepted but not yet scheduled for mediation.
                'labels' => array_map(static fn($s) => $s === 'intake' ? 'Intake / Accepted' : ($stageDefs[$s]['label'] ?? ucfirst($s)), $pipelineStages),
                'values' => array_values($pipeline),
            ],
            'outcomes' => array_map(static fn($def, $key) => [
                'key' => $key, 'label' => $def['label'], 'color' => $def['color'], 'value' => $outcomes[$key],
            ], $outcomeDefs, array_keys($outcomeDefs)),
            'usage' => [
                'residents'            => $one("SELECT COUNT(*) FROM users WHERE archived_at IS NULL AND role = 'resident'"),
                'verifiedResidents'    => $one("SELECT COUNT(DISTINCT v.user_id) FROM residency_verifications v JOIN users u ON u.id = v.user_id WHERE v.status = 'approved' AND u.archived_at IS NULL"),
                'pendingVerifications' => $one("SELECT COUNT(*) FROM residency_verifications WHERE status = 'pending'"),
                'loginsToday'          => $one("SELECT COUNT(*) FROM users WHERE archived_at IS NULL AND DATE(last_login) = CURDATE()"),
                'caseActionsToday'     => $one("SELECT COUNT(*) FROM kp_timeline_events e JOIN cases c ON c.id = e.case_id WHERE c.archived_at IS NULL AND DATE(e.event_at) = CURDATE()"),
            ],
        ];
    }
}
