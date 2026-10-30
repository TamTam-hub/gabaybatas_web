<?php
/**
 * Flexible Reports: the filters, status groups and case rows shared by the
 * page (barangay/reports.php) and its PDF (barangay/generate_report.php), so
 * the preview and the download always contain the same cases.
 *
 * Archived cases are never included. Barangay users only see their barangay.
 */

require_once __DIR__ . '/../config/kp.php';
require_once __DIR__ . '/../config/case_types.php';

if (!function_exists('gabaybatas_flex_groups')) {
    /**
     * KP statuses grouped for the summary cards and the status filter.
     *
     * @return array<string, array{label: string, sub: string, color: string, statuses: list<string>}>
     */
    function gabaybatas_flex_groups(): array
    {
        return [
            'review' => [
                'label'    => 'Under Review',
                'sub'      => 'filed, being reviewed',
                'color'    => '#f59e0b',
                'statuses' => ['filed', 'under_review', 'additional_info_required'],
            ],
            'active' => [
                'label'    => 'In KP Process',
                'sub'      => 'mediation, conciliation, arbitration',
                'color'    => '#6366f1',
                'statuses' => [
                    'accepted', 'mediation_scheduled', 'mediation_ongoing', 'mediation_noshow_complainant',
                    'pangkat_constitution', 'conciliation_scheduled', 'conciliation_ongoing',
                    'conciliation_noshow_complainant', 'conciliation_noshow_respondent',
                    'arbitration_agreed', 'arbitration_hearing',
                ],
            ],
            'settled' => [
                'label'    => 'Settled / Awarded',
                'sub'      => 'incl. compliance and execution',
                'color'    => '#10b981',
                'statuses' => [
                    'mediation_settled', 'conciliation_settled', 'arbitration_awarded', 'settlement_repudiation',
                    'settlement_final', 'compliance_monitoring', 'execution_pending', 'execution_completed',
                ],
            ],
            'ended' => [
                'label'    => 'Closed / Certified / Not Covered',
                'sub'      => 'court, dismissed, referred',
                'color'    => '#94a3b8',
                'statuses' => ['certified_to_court', 'dismissed', 'closed', 'not_eligible', 'referred'],
            ],
        ];
    }
}

if (!function_exists('gabaybatas_flex_params')) {
    /**
     * Normalised filters from a request (GET array).
     *
     * @return array{period: string, status: string, case_type: string, search: string, date_from: string, date_to: string}
     */
    function gabaybatas_flex_params(array $in): array
    {
        $period = (string)($in['period'] ?? 'all');
        if (!in_array($period, ['all', 'month', 'quarter', 'year', 'custom'], true)) {
            $period = 'all';
        }

        $status = trim((string)($in['status'] ?? ''));
        $groups = gabaybatas_flex_groups();
        $validStatus = (str_starts_with($status, 'group:') && isset($groups[substr($status, 6)]))
            || array_key_exists($status, kp_statuses());
        if (!$validStatus) {
            $status = '';
        }

        $date = static function ($value): string {
            $value = trim((string)$value);
            $dt = DateTime::createFromFormat('Y-m-d', $value);
            return $dt && $dt->format('Y-m-d') === $value ? $value : '';
        };
        $from = $period === 'custom' ? $date($in['date_from'] ?? '') : '';
        $to = $period === 'custom' ? $date($in['date_to'] ?? '') : '';
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'period'    => $period,
            'status'    => $status,
            'case_type' => gabaybatas_normalize_case_type((string)($in['case_type'] ?? '')) ?: trim((string)($in['case_type'] ?? '')),
            'search'    => mb_substr(trim((string)($in['search'] ?? '')), 0, 100),
            'date_from' => $from,
            'date_to'   => $to,
        ];
    }
}

if (!function_exists('gabaybatas_flex_report')) {
    /**
     * Cases, totals and labels for the given filters.
     *
     * @param string   $barangay  '' for all barangays (admin)
     * @param int|null $limit     rows to return (null = all)
     * @return array{rows: list<array>, total: int, counts: array<string, int>, applied: list<string>, period_label: string}
     */
    function gabaybatas_flex_report(mysqli $conn, string $barangay, array $p, ?int $limit = null): array
    {
        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable('now', $tz);
        $where = ['c.archived_at IS NULL'];
        $types = '';
        $args = [];
        $applied = [];

        if ($barangay !== '') {
            $where[] = 'LOWER(c.barangay) = LOWER(?)';
            $types .= 's';
            $args[] = $barangay;
        }

        // Period
        $range = null;
        $periodLabel = 'All periods';
        if ($p['period'] === 'month') {
            $range = [$now->format('Y-m-01'), $now->format('Y-m-t')];
            $periodLabel = $now->format('F Y');
        } elseif ($p['period'] === 'quarter') {
            $q = (int)ceil((int)$now->format('n') / 3);
            $start = $now->setDate((int)$now->format('Y'), ($q - 1) * 3 + 1, 1);
            $end = $start->modify('+2 months');
            $range = [$start->format('Y-m-d'), $end->format('Y-m-t')];
            $periodLabel = 'Quarter ' . $q . ' of ' . $now->format('Y') . ' (' . $start->format('M') . '–' . $end->format('M') . ')';
        } elseif ($p['period'] === 'year') {
            $range = [$now->format('Y-01-01'), $now->format('Y-12-31')];
            $periodLabel = 'Year ' . $now->format('Y');
        } elseif ($p['period'] === 'custom' && ($p['date_from'] !== '' || $p['date_to'] !== '')) {
            $range = [$p['date_from'] ?: '1970-01-01', $p['date_to'] ?: '9999-12-31'];
            $fmt = static fn(string $d): string => date('M j, Y', strtotime($d));
            $periodLabel = $p['date_from'] !== '' && $p['date_to'] !== ''
                ? $fmt($p['date_from']) . ' – ' . $fmt($p['date_to'])
                : ($p['date_from'] !== '' ? 'From ' . $fmt($p['date_from']) : 'Until ' . $fmt($p['date_to']));
        }
        if ($range) {
            $where[] = 'DATE(c.date_submitted) BETWEEN ? AND ?';
            $types .= 'ss';
            array_push($args, $range[0], $range[1]);
            $applied[] = $periodLabel;
        }

        // Status: a group or one KP status. Old cases without a KP status count as Filed.
        if ($p['status'] !== '') {
            $groups = gabaybatas_flex_groups();
            $isGroup = str_starts_with($p['status'], 'group:');
            $list = $isGroup ? $groups[substr($p['status'], 6)]['statuses'] : [$p['status']];
            $cond = 'c.kp_status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
            if (in_array('filed', $list, true)) {
                $cond = "({$cond} OR c.kp_status IS NULL OR c.kp_status = '')";
            }
            $where[] = $cond;
            $types .= str_repeat('s', count($list));
            array_push($args, ...$list);
            $applied[] = 'Status: ' . ($isGroup ? $groups[substr($p['status'], 6)]['label'] : kp_status_label($p['status']));
        }

        if ($p['case_type'] !== '') {
            $where[] = 'c.case_type = ?';
            $types .= 's';
            $args[] = $p['case_type'];
            $applied[] = 'Type: ' . $p['case_type'];
        }

        if ($p['search'] !== '') {
            $like = '%' . $p['search'] . '%';
            $digits = preg_replace('/^\s*C\s*-\s*/i', '', $p['search']);
            $cond = '(c.case_id LIKE ? OR c.name LIKE ? OR c.complained_person LIKE ? OR c.case_type LIKE ?';
            $types .= 'ssss';
            array_push($args, $like, $like, $like, $like);
            if (ctype_digit($digits)) {
                $cond .= ' OR c.id = ?';
                $types .= 'i';
                $args[] = (int)$digits;
            }
            $where[] = $cond . ')';
            $applied[] = 'Search: “' . $p['search'] . '”';
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $run = static function (string $sql) use ($conn, $types, $args): array {
            $stmt = $conn->prepare($sql);
            if ($types !== '') {
                $stmt->bind_param($types, ...$args);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        };

        $counts = array_fill_keys(array_keys(gabaybatas_flex_groups()), 0);
        $total = 0;
        foreach ($run("SELECT COALESCE(NULLIF(c.kp_status, ''), 'filed') AS st, COUNT(*) AS n FROM cases c{$whereSql} GROUP BY st") as $r) {
            $total += (int)$r['n'];
            foreach (gabaybatas_flex_groups() as $key => $group) {
                if (in_array($r['st'], $group['statuses'], true)) {
                    $counts[$key] += (int)$r['n'];
                }
            }
        }

        $rows = $run(
            "SELECT c.id, c.case_id, c.name, c.complained_person, c.case_type,
                    COALESCE(NULLIF(c.kp_status, ''), 'filed') AS kp_status, c.date_submitted
             FROM cases c{$whereSql}
             ORDER BY c.date_submitted DESC, c.id DESC" . ($limit !== null ? ' LIMIT ' . (int)$limit : '')
        );

        return [
            'rows'         => $rows,
            'total'        => $total,
            'counts'       => $counts,
            'applied'      => $applied,
            'period_label' => $periodLabel,
        ];
    }
}

if (!function_exists('gabaybatas_flex_case_types')) {
    /** The official case types, plus older types still used by non-archived cases. */
    function gabaybatas_flex_case_types(mysqli $conn, string $barangay): array
    {
        $types = gabaybatas_case_types();
        $sql = "SELECT DISTINCT case_type FROM cases WHERE archived_at IS NULL AND case_type IS NOT NULL AND case_type <> ''"
            . ($barangay !== '' ? ' AND LOWER(barangay) = LOWER(?)' : '');
        $stmt = $conn->prepare($sql);
        if ($barangay !== '') {
            $stmt->bind_param('s', $barangay);
        }
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            if (!in_array($r['case_type'], $types, true)) {
                $types[] = $r['case_type'];
            }
        }
        $stmt->close();
        return $types;
    }
}

if (!function_exists('gabaybatas_case_group_counts')) {
    /**
     * Non-archived cases per status group.
     *
     * @param string $barangay '' for all barangays (admin)
     * @return array{total: int, counts: array<string, int>}
     */
    function gabaybatas_case_group_counts(mysqli $conn, string $barangay): array
    {
        $groups = gabaybatas_flex_groups();
        $counts = array_fill_keys(array_keys($groups), 0);
        $total = 0;
        $stmt = $conn->prepare(
            "SELECT COALESCE(NULLIF(kp_status, ''), 'filed') AS st, COUNT(*) AS n FROM cases
             WHERE archived_at IS NULL" . ($barangay !== '' ? ' AND LOWER(barangay) = LOWER(?)' : '') . ' GROUP BY st'
        );
        if ($barangay !== '') {
            $stmt->bind_param('s', $barangay);
        }
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $total += (int)$r['n'];
            foreach ($groups as $key => $group) {
                if (in_array($r['st'], $group['statuses'], true)) {
                    $counts[$key] += (int)$r['n'];
                    continue 2;
                }
            }
            $counts['review'] += (int)$r['n'];
        }
        $stmt->close();
        return ['total' => $total, 'counts' => $counts];
    }
}

if (!function_exists('gabaybatas_summary_cards')) {
    /**
     * The case summary row (Total + the four status groups) used on the admin
     * and barangay Home pages and on Flexible Reports, so all three look and
     * count alike.
     */
    function gabaybatas_summary_cards(int $total, array $counts, string $totalSub = 'All periods'): void
    {
        $groups = gabaybatas_flex_groups();
        static $styled = false;
        if (!$styled) {
            $styled = true;
            echo '<style>
                .gb-sum-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px; }
                .gb-sum-card { background: #fff; border: 1px solid #e2e8f0; border-top: 3px solid var(--gb-sum, #0e2242); border-radius: 12px;
                               padding: 18px 20px; box-shadow: 0 1px 4px rgba(15, 23, 42, .04); }
                .gb-sum-label { font-size: 11px; text-transform: uppercase; letter-spacing: .7px; color: #64748b; font-weight: 700; line-height: 1.4; }
                .gb-sum-value { font-family: var(--gb-sum-font, "Source Serif 4", Georgia, serif); font-size: 30px; font-weight: var(--gb-sum-weight, 700); color: #0f172a; line-height: 1.15; margin: 6px 0 4px; }
                .gb-sum-sub { font-size: 11.5px; color: #94a3b8; }
                @media (max-width: 1200px) { .gb-sum-row { grid-template-columns: repeat(3, 1fr); } }
                @media (max-width: 760px) { .gb-sum-row { grid-template-columns: 1fr 1fr; } }
            </style>';
        }
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        echo '<div class="gb-sum-row">';
        echo '<div class="gb-sum-card" style="--gb-sum:#0e2242;"><div class="gb-sum-label">Total Cases</div>'
            . '<div class="gb-sum-value" data-gb-sum="total">' . $total . '</div><div class="gb-sum-sub">' . $e($totalSub) . '</div></div>';
        foreach ($groups as $key => $group) {
            echo '<div class="gb-sum-card" style="--gb-sum:' . $e($group['color']) . ';"><div class="gb-sum-label">' . $e($group['label']) . '</div>'
                . '<div class="gb-sum-value" data-gb-sum="' . $e($key) . '">' . (int)($counts[$key] ?? 0) . '</div><div class="gb-sum-sub">' . $e($group['sub']) . '</div></div>';
        }
        echo '</div>';
    }
}
