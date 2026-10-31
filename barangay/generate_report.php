<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['barangay', 'admin'], true)) {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/kp.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
require_once __DIR__ . '/../includes/flexible_report.php';
require_once __DIR__ . '/../vendor/autoload.php';
gabaybatas_block_barangay_delete_requests();

use Dompdf\Dompdf;
use Dompdf\Options;

// Same filters and rows as the Flexible Reports page (includes/flexible_report.php).
$isBarangayUser = ($_SESSION['role'] ?? '') === 'barangay';
$scopeBarangay = $isBarangayUser ? gabaybatas_get_user_barangay($conn) : '';
$params = gabaybatas_flex_params($_GET);
$report = gabaybatas_flex_report($conn, $scopeBarangay, $params);
$groups = gabaybatas_flex_groups();

$nowManila = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$generatedAt = $nowManila->format('F d, Y \a\t h:i A');
$reportTitle = 'Case Report';
$reportPeriod = $report['period_label'];
$filterSummaryText = $report['applied'] ? implode('; ', $report['applied']) : 'None (all non-archived cases)';
$filename = 'Barangay_Munoz_Case_Report_' . $nowManila->format('F_j_Y') . '.pdf';

// ── SEALS (same as KP Monitoring Form No. 1) ─────────────────────
// Embedded so Dompdf needs no file access.
$seal = static function (string $file): string {
    $path = __DIR__ . '/../images/' . $file;
    return is_file($path)
        ? '<img src="data:image/png;base64,' . base64_encode((string)file_get_contents($path)) . '" style="height:70px;">'
        : '';
};

// ── BUILD TABLE ROWS ──────────────────────────────────────────────
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$htmlRows = '';
$rowNum   = 1;
foreach ($report['rows'] as $row) {
    $dateTs = strtotime((string)($row['date_submitted'] ?? ''));
    $htmlRows .= '<tr' . ($rowNum % 2 === 0 ? ' class="alt"' : '') . '>'
        . '<td class="c">' . $rowNum . '</td>'
        . '<td class="c nowrap"><b>' . $e(gabaybatas_format_case_id($row['case_id'] ?? '', $row['id'] ?? '')) . '</b></td>'
        . '<td>' . $e($row['name'] ?? '') . '</td>'
        . '<td>' . $e($row['complained_person'] ?? '') . '</td>'
        . '<td>' . $e($row['case_type'] ?? '') . '</td>'
        . '<td>' . $e(kp_status_label((string)$row['kp_status'])) . '</td>'
        . '<td class="c nowrap">' . ($dateTs ? date('m/d/Y', $dateTs) : '') . '</td>'
        . '</tr>';
    $rowNum++;
}
if ($rowNum === 1) {
    $htmlRows = '<tr><td colspan="7" class="c empty">No case records found for the selected filters.</td></tr>';
}
$caseCount = $rowNum - 1;

// Prepared by the admin when downloaded from the admin pages, otherwise by the Barangay Secretary.
$preparedBy = $isBarangayUser
    ? (defined('KP_LUPON_SECRETARY') ? KP_LUPON_SECRETARY : '')
    : GABAYBATAS_ADMIN_NAME;
$preparedTitle = $isBarangayUser ? 'Barangay Secretary' : 'System Administrator';
$captain   = defined('KP_PUNONG_BARANGAY') ? KP_PUNONG_BARANGAY : '';
$upper     = static fn(string $v): string => mb_strtoupper($v, 'UTF-8');

// ── HTML ──────────────────────────────────────────────────────────
$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>' . $e($reportTitle) . ' - Barangay ' . $e(KP_BARANGAY_NAME) . '</title>
<style>
    @page { margin: 0; }
    body { margin: 0; padding: 36px 46px 62px; font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #111; }

    /* Letterhead, same as KP Monitoring Form No. 1 */
    .head { width: 470px; border-collapse: collapse; margin: 0 auto; }
    .head td { vertical-align: middle; padding: 0; }
    .head .seal { width: 92px; text-align: center; }
    .lgu { text-align: center; font-size: 10px; line-height: 1.4; }
    .lgu b { font-size: 12px; letter-spacing: .5px; }
    .lgu .office { font-size: 9px; font-weight: bold; letter-spacing: 1px; margin-top: 3px; color: #1a3a6b; }
    .rule { border-top: 2px solid #1a3a6b; border-bottom: 1px solid #c2560c; height: 2px; margin: 10px 0 12px; }

    h1 { text-align: center; font-size: 15px; letter-spacing: 1px; margin: 0; color: #0e2242; }
    .period { text-align: center; font-size: 9.5px; margin: 3px 0 12px; }

    .meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .meta td { border: 1px solid #9aa7b8; padding: 5px 7px; font-size: 8.5px; width: 25%; }
    .meta .lbl { display: block; font-size: 7px; font-weight: bold; letter-spacing: .8px; text-transform: uppercase; color: #5a6a7e; margin-bottom: 1px; }

    .section { font-size: 9.5px; font-weight: bold; letter-spacing: .8px; text-transform: uppercase; color: #0e2242;
               border-left: 4px solid #c2560c; padding-left: 7px; margin: 14px 0 6px; }

    .stats { width: 100%; border-collapse: collapse; }
    .stats td { border: 1px solid #9aa7b8; text-align: center; padding: 6px 4px; width: 20%; }
    .stats .num { font-size: 15px; font-weight: bold; color: #0e2242; }
    .stats .lbl { font-size: 7.5px; letter-spacing: .6px; text-transform: uppercase; color: #5a6a7e; }

    table.cases { width: 100%; border-collapse: collapse; }
    table.cases th { background: #1a3a6b; color: #fff; font-size: 7.5px; letter-spacing: .5px; text-transform: uppercase;
                     border: 1px solid #1a3a6b; padding: 5px 4px; }
    table.cases td { border: 1px solid #9aa7b8; padding: 4px 5px; font-size: 8.5px; vertical-align: top; }
    table.cases tr.alt td { background: #f4f6fa; }
    .c { text-align: center; }
    .nowrap { white-space: nowrap; }
    .empty { padding: 18px; font-style: italic; color: #5a6a7e; }

    .cert { margin-top: 14px; font-size: 8.5px; line-height: 1.5; text-align: justify; }
    .sign { width: 100%; border-collapse: collapse; margin-top: 26px; page-break-inside: avoid; }
    .sign td { width: 50%; vertical-align: top; font-size: 8.5px; padding: 0; }
    .sign .sigblock { width: 230px; margin: 0 auto; }
    .sign .sig-gap { height: 36px; }
    .sign .line { margin-top: 34px; width: 230px; border-top: 1px solid #111; text-align: center; padding-top: 3px; }

    .footer { position: fixed; left: 46px; right: 46px; bottom: 24px; border-top: 1px solid #9aa7b8; padding-top: 4px;
              font-size: 7.5px; color: #5a6a7e; }
</style>
</head>
<body>

<div class="footer">
    <table style="width:100%;border-collapse:collapse;"><tr>
        <td>Barangay ' . $e(KP_BARANGAY_NAME) . ', City of ' . $e(KP_MUNICIPALITY) . ', ' . $e(KP_PROVINCE) . ' &bull; Generated ' . $e($generatedAt) . ' through GabayBatas</td>
    </tr></table>
</div>

<table class="head"><tr>
    <td class="seal">' . $seal('seal_city_catbalogan.png') . '</td>
    <td class="lgu">
        Republic of the Philippines<br>
        PROVINCE OF ' . $e($upper(KP_PROVINCE)) . '<br>
        CITY OF ' . $e($upper(KP_MUNICIPALITY)) . '<br>
        <b>BARANGAY ' . $e($upper(KP_BARANGAY_NAME)) . '</b>
        <div class="office">OFFICE OF THE PUNONG BARANGAY</div>
    </td>
    <td class="seal">' . $seal('seal_barangay_munoz.png') . '</td>
</tr></table>
<div class="rule"></div>

<h1>' . $e($upper($reportTitle)) . '</h1>
<div class="period">Period covered: <u>&nbsp;' . $e($reportPeriod) . '&nbsp;</u></div>

<table class="meta"><tr>
    <td><span class="lbl">Prepared for</span>Barangay ' . $e(KP_BARANGAY_NAME) . '</td>
    <td><span class="lbl">Date generated</span>' . $e($generatedAt) . '</td>
    <td><span class="lbl">Records listed</span>' . $caseCount . ' ' . ($caseCount === 1 ? 'case' : 'cases') . '</td>
    <td><span class="lbl">Filters applied</span>' . $e($filterSummaryText) . '</td>
</tr></table>

<div class="section">Summary of Cases</div>
<table class="stats"><tr>
    <td><div class="num">' . $report['total'] . '</div><div class="lbl">Total cases</div></td>' . implode('', array_map(
        static fn($key, $g) => '<td><div class="num">' . $report['counts'][$key] . '</div><div class="lbl">' . $e($g['label']) . '</div></td>',
        array_keys($groups), $groups
    )) . '
</tr></table>

<div class="section">List of Cases</div>
<table class="cases">
    <thead><tr>
        <th style="width:4%;">No.</th>
        <th style="width:9%;">Case No.</th>
        <th style="width:20%;">Complainant</th>
        <th style="width:20%;">Respondent</th>
        <th style="width:16%;">Case Type</th>
        <th style="width:19%;">Status</th>
        <th style="width:12%;">Date Filed</th>
    </tr></thead>
    <tbody>' . $htmlRows . '</tbody>
</table>

<div class="cert">
    I hereby certify that the foregoing is a true and correct report of the cases recorded in the Barangay ' . $e(KP_BARANGAY_NAME) . '
    case records for the period stated above, as of the date of generation. Archived records are not included.
</div>

<table class="sign"><tr>
    <td><div class="sigblock">Prepared by:<div class="sig-gap"></div><div class="line"><b>' . ($preparedBy !== '' ? $e($preparedBy) : '&nbsp;') . '</b><br>' . $preparedTitle . '</div></div></td>
    <td><div class="sigblock">Approved by:<div class="sig-gap"></div><div class="line"><b>' . $e($captain) . '</b><br>Punong Barangay</div></div></td>
</tr></table>

</body>
</html>';

// ── RENDER ────────────────────────────────────────────────────────
$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isPhpEnabled', false);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');

// Long bond (8.5in × 13in); margins are set on the body.
$dompdf->setPaper([0, 0, 612, 936], 'portrait');

$dompdf->render();
// "Page X of Y" is stamped after rendering; CSS cannot count the total pages.
$canvas = $dompdf->getCanvas();
$canvas->page_text(
    $canvas->get_width() - 46 - 62, $canvas->get_height() - 22,
    'Page {PAGE_NUM} of {PAGE_COUNT}',
    $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.35, 0.42, 0.49]
);
require_once __DIR__ . '/../includes/download_signal.php';
gabaybatas_download_ready();
$dompdf->stream($filename, ['Attachment' => true]);
