<?php
/**
 * KP Monitoring Form No. 1 — Cases Filed, Actions Taken and Problems
 * Encountered on KP Implementation (monthly report to the DILG / city).
 *
 * Lists the KP cases filed in a month with their nature (criminal, civil,
 * others) and the action taken as of the report date. Complaints found not
 * covered by KP are not KP cases and are left out.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/kp.php';

if (!function_exists('kp_monitoring_actions')) {
    /** @return array<string, string> action key => column heading */
    function kp_monitoring_actions(): array
    {
        return [
            'mediation'    => 'THRU MEDIATION',
            'conciliation' => 'THRU CONCILIATION',
            'arbitration'  => 'THRU ARBITRATION',
            'repudiation'  => 'THRU REPUDIATION',
            'dismissed'    => 'DISMISSED',
            'pending'      => 'PENDING',
            'certified'    => 'CERTIFIED CASE',
        ];
    }
}

if (!function_exists('kp_monitoring_action')) {
    /**
     * Action taken on a case as of today, in the monitoring form's terms.
     *
     * @param array $settlements kp_settlements rows of the case, newest first
     */
    function kp_monitoring_action(array $case, array $settlements): string
    {
        $status      = (string)($case['kp_status'] ?? '');
        $disposition = (string)($case['kp_disposition'] ?? '');

        if (in_array($disposition, ['cfa_settlement_repudiated', 'cfa_arbitration_repudiated'], true)) {
            return 'repudiation';
        }
        if ($status === 'certified_to_court') {
            return 'certified';
        }
        if ($status === 'dismissed') {
            return 'dismissed';
        }

        foreach ($settlements as $settlement) {
            if ((int)$settlement['repudiated'] === 1) {
                continue;
            }
            if ($settlement['settlement_type'] === 'arbitration_award') {
                return 'arbitration';
            }
            return ($settlement['stage'] ?? '') === 'conciliation' ? 'conciliation' : 'mediation';
        }
        if ($status === 'mediation_settled') {
            return 'mediation';
        }
        if ($status === 'conciliation_settled') {
            return 'conciliation';
        }
        return 'pending';
    }
}

if (!function_exists('kp_monitoring_rows')) {
    /**
     * KP cases filed in the given month, with nature and action taken.
     *
     * @return array{rows: array<int, array>, totals: array<string, int>}
     */
    function kp_monitoring_rows(mysqli $conn, string $barangay, int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end   = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));
        $statuses = kp_post_acceptance_statuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        $stmt = $conn->prepare(
            "SELECT id, case_id, name, complained_person, case_type, kp_nature, kp_status, kp_disposition, date_submitted
             FROM cases
             WHERE archived_at IS NULL AND LOWER(barangay) = LOWER(?)
               AND date_submitted BETWEEN ? AND ?
               AND kp_status IN ({$placeholders})
             ORDER BY date_submitted ASC, id ASC"
        );
        $types = 'sss' . str_repeat('s', count($statuses));
        $stmt->bind_param($types, $barangay, $start, $end, ...$statuses);
        $stmt->execute();
        $cases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $totals = array_fill_keys(array_merge(array_keys(kp_case_natures()), array_keys(kp_monitoring_actions())), 0);
        $rows = [];
        foreach ($cases as $i => $case) {
            $settlements = [];
            $sStmt = $conn->prepare("SELECT settlement_type, stage, repudiated FROM kp_settlements WHERE case_id = ? ORDER BY id DESC");
            $caseRowId = (int)$case['id'];
            $sStmt->bind_param('i', $caseRowId);
            $sStmt->execute();
            $settlements = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $sStmt->close();

            $nature = kp_case_nature($case);
            $action = kp_monitoring_action($case, $settlements);
            $totals[$nature]++;
            $totals[$action]++;
            $rows[] = [
                'no'         => $i + 1,
                'date_filed' => date('m/d/Y', strtotime((string)$case['date_submitted'])),
                'case_no'    => (string)$case['case_id'],
                'title'      => trim((string)$case['name']) . ' vs. ' . trim((string)$case['complained_person']),
                'nature'     => $nature,
                'action'     => $action,
            ];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }
}

if (!function_exists('kp_monitoring_html')) {
    /** HTML of KP Monitoring Form No. 1 (A4 landscape) for Dompdf. */
    function kp_monitoring_html(array $report, int $year, int $month): string
    {
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $natures = kp_case_natures();
        $actions = kp_monitoring_actions();
        $monthLabel = strtoupper(date('F', mktime(0, 0, 0, $month, 1, $year)));

        $body = '';
        foreach ($report['rows'] as $row) {
            $body .= '<tr><td class="c">' . $row['no'] . '</td><td class="c">' . $e($row['date_filed']) . '</td><td class="c">' . $e($row['case_no']) . '</td><td>' . $e($row['title']) . '</td>';
            foreach (array_keys($natures) as $key) {
                $body .= '<td class="c">' . ($row['nature'] === $key ? '1' : '') . '</td>';
            }
            $body .= '<td class="c">1</td>';
            foreach (array_keys($actions) as $key) {
                $body .= '<td class="c">' . ($row['action'] === $key ? '1' : '') . '</td>';
            }
            $body .= '<td class="c">1</td></tr>';
        }
        // Keep the printed form's minimum number of lines.
        $columns = 4 + count($natures) + 1 + count($actions) + 1;
        for ($i = count($report['rows']); $i < 15; $i++) {
            $body .= '<tr>' . str_repeat('<td>&nbsp;</td>', $columns) . '</tr>';
        }

        $count = count($report['rows']);
        $totalRow = '<tr class="total"><td colspan="4" class="c">TOTAL</td>';
        foreach (array_keys($natures) as $key) {
            $totalRow .= '<td class="c">' . $report['totals'][$key] . '</td>';
        }
        $totalRow .= '<td class="c">' . $count . '</td>';
        foreach (array_keys($actions) as $key) {
            $totalRow .= '<td class="c">' . $report['totals'][$key] . '</td>';
        }
        $totalRow .= '<td class="c">' . $count . '</td></tr>';

        $natureHeads = '';
        foreach ($natures as $label) {
            $natureHeads .= '<th>' . $e(strtoupper($label)) . '</th>';
        }
        $actionHeads = '';
        foreach ($actions as $label) {
            $actionHeads .= '<th>' . $e($label) . '</th>';
        }

        $secretary = KP_LUPON_SECRETARY !== '' ? $e(KP_LUPON_SECRETARY) : '&nbsp;';

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            @page { size: A4 landscape; margin: 28px 34px; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; color: #000; }
            .formno { font-size: 7.5px; }
            .lgu { font-weight: bold; font-size: 10px; line-height: 1.35; }
            h1 { text-align: center; font-size: 15px; margin: 6px 0 2px; }
            .sub { text-align: center; font-weight: bold; font-size: 9px; }
            .month { text-align: center; font-size: 9px; margin-top: 2px; }
            .date { text-align: right; font-size: 8.5px; margin: 4px 0; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #000; padding: 2px 3px; height: 14px; }
            th { font-size: 7.5px; text-align: center; vertical-align: middle; }
            td.c { text-align: center; }
            tr.total td { font-weight: bold; }
            .sign { width: 100%; margin-top: 18px; }
            .sign td { border: none; width: 50%; vertical-align: top; font-size: 8.5px; }
            .line { margin-top: 24px; width: 230px; border-top: 1px solid #000; text-align: center; padding-top: 2px; }
        </style></head><body>
            <div class="formno">KP Monitoring Form No. 1</div>
            <div class="lgu">PROVINCE OF ' . $e(mb_strtoupper(KP_PROVINCE, 'UTF-8')) . '<br>CITY OF ' . $e(mb_strtoupper(KP_MUNICIPALITY, 'UTF-8')) . '<br>BARANGAY ' . $e(mb_strtoupper(KP_BARANGAY_NAME, 'UTF-8')) . '</div>
            <h1>LUPON TAGAPAMAYAPA</h1>
            <div class="sub">CASES FILED, ACTIONS TAKEN AND PROBLEMS ENCOUNTERED ON KP IMPLEMENTATION</div>
            <div class="month">For the month <u>&nbsp;' . $e($monthLabel) . '&nbsp;</u>, CY <u>&nbsp;' . $year . '&nbsp;</u></div>
            <div class="date">Date: <u>&nbsp;' . $e(date('F j, Y')) . '&nbsp;</u></div>
            <table>
                <thead>
                    <tr>
                        <th rowspan="2" style="width:3%">NO.</th>
                        <th rowspan="2" style="width:6%">DATE FILED</th>
                        <th rowspan="2" style="width:6%">CASE NO.</th>
                        <th rowspan="2" style="width:19%">COMPLAINANT VS. RESPONDENT, ET AL.</th>
                        <th colspan="4">NATURE OF CASES</th>
                        <th colspan="8">ACTIONS TAKEN</th>
                    </tr>
                    <tr>' . $natureHeads . '<th>TOTAL</th>' . $actionHeads . '<th>TOTAL</th></tr>
                </thead>
                <tbody>' . $body . $totalRow . '</tbody>
            </table>
            <table class="sign"><tr>
                <td>Prepared by:<div class="line"><b>' . $secretary . '</b><br>Barangay Secretary</div></td>
                <td>Attested by:<div class="line"><b>' . $e(KP_PUNONG_BARANGAY) . '</b><br>Punong Barangay<br>Chairman, Lupon Tagapamayapa</div></td>
            </tr></table>
        </body></html>';
    }
}
