<?php
/**
 * KP Reports — Monthly Transmittal & Case Statistics
 * Phase 9: Reporting & Monthly Transmittal
 *
 * Features:
 * - Monthly Transmittal of Final Reports (KP Form #28)
 * - Auto-populate from settled/awarded/certified cases
 * - Case statistics by month/status
 * - Generate & download Form #28 DOCX
 */
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'barangay') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/kp.php';
require_once '../includes/kp_docx_engine.php';
require_once '../includes/kp_monitoring.php';
require_once __DIR__ . '/../includes/barangay_auth.php';
gabaybatas_block_barangay_delete_requests();
$currentBarangay = gabaybatas_get_user_barangay($conn);
$barangayFilter = $conn->real_escape_string($currentBarangay);

// ─── Selected month/year (default: current) ─────────────────────────────────
$selMonth = (int)($_GET['month'] ?? date('n'));
$selYear  = (int)($_GET['year']  ?? date('Y'));
if ($selMonth < 1 || $selMonth > 12) $selMonth = (int)date('n');
if ($selYear < 2020 || $selYear > 2030) $selYear = (int)date('Y');

$monthName = date('F', mktime(0, 0, 0, $selMonth, 1, $selYear));
$monthYearLabel = "{$monthName} {$selYear}";
$startDate = sprintf('%04d-%02d-01', $selYear, $selMonth);
$endDate   = date('Y-m-t', mktime(0, 0, 0, $selMonth, 1, $selYear));

// ─── Query resolved/settled/certified cases for the selected month ──────────

// Cases settled (amicable settlement final in this month)
$settledQuery = $conn->prepare(
    "SELECT c.id, c.case_id, c.name, c.complained_person, c.kp_status,
            s.settlement_type, s.settlement_date, s.terms
     FROM cases c
     LEFT JOIN kp_settlements s ON s.case_id = c.id
     WHERE c.archived_at IS NULL AND LOWER(c.barangay) = LOWER(?)
       AND c.kp_status IN ('mediation_settled','conciliation_settled','settlement_final','closed','execution_completed')
       AND (s.settlement_date BETWEEN ? AND ? OR c.date_submitted BETWEEN ? AND ?)
     ORDER BY c.id ASC"
);
$settledQuery->bind_param('sssss', $barangayFilter, $startDate, $endDate, $startDate, $endDate);
$settledQuery->execute();
$settledCases = $settledQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$settledQuery->close();

// Cases certified to court
$certQuery = $conn->prepare(
    "SELECT c.id, c.case_id, c.name, c.complained_person, c.kp_status, c.date_submitted
     FROM cases c
     WHERE c.archived_at IS NULL AND LOWER(c.barangay) = LOWER(?)
       AND c.kp_status IN ('certified_to_court','dismissed')
       AND c.date_submitted BETWEEN ? AND ?
     ORDER BY c.id ASC"
);
$certQuery->bind_param('sss', $barangayFilter, $startDate, $endDate);
$certQuery->execute();
$certifiedCases = $certQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$certQuery->close();

// Cases with arbitration awards
$arbQuery = $conn->prepare(
    "SELECT c.id, c.case_id, c.name, c.complained_person, c.kp_status
     FROM cases c
     WHERE c.archived_at IS NULL AND LOWER(c.barangay) = LOWER(?)
       AND c.kp_status IN ('arbitration_awarded')
       AND c.date_submitted BETWEEN ? AND ?
     ORDER BY c.id ASC"
);
$arbQuery->bind_param('sss', $barangayFilter, $startDate, $endDate);
$arbQuery->execute();
$arbCases = $arbQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$arbQuery->close();

// All cases filed this month
$filedQuery = $conn->prepare(
    "SELECT COUNT(*) as cnt FROM cases WHERE archived_at IS NULL AND LOWER(barangay) = LOWER(?) AND date_submitted BETWEEN ? AND ?"
);
$filedQuery->bind_param('sss', $barangayFilter, $startDate, $endDate);
$filedQuery->execute();
$totalFiled = (int)$filedQuery->get_result()->fetch_assoc()['cnt'];
$filedQuery->close();

// Cases still pending
$pendQuery = $conn->prepare(
    "SELECT COUNT(*) as cnt FROM cases WHERE archived_at IS NULL AND LOWER(barangay) = LOWER(?) AND status = 'Pending' AND date_submitted BETWEEN ? AND ?"
);
$pendQuery->bind_param('sss', $barangayFilter, $startDate, $endDate);
$pendQuery->execute();
$totalPending = (int)$pendQuery->get_result()->fetch_assoc()['cnt'];
$pendQuery->close();

// KP status breakdown for the month
$breakdownQuery = $conn->prepare(
    "SELECT kp_status, COUNT(*) as cnt FROM cases
     WHERE archived_at IS NULL AND LOWER(barangay) = LOWER(?) AND date_submitted BETWEEN ? AND ? AND kp_status IS NOT NULL
     GROUP BY kp_status ORDER BY cnt DESC"
);
$breakdownQuery->bind_param('sss', $barangayFilter, $startDate, $endDate);
$breakdownQuery->execute();
$statusBreakdown = $breakdownQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$breakdownQuery->close();

$monitoring = kp_monitoring_rows($conn, $currentBarangay, $selYear, $selMonth);

$totalSettled = count($settledCases);
$totalCertified = count($certifiedCases);
$totalArbitrated = count($arbCases);

// ─── Monthly Report Due Date Calculation ────────────────────────────────────
// Per KP rules, monthly transmittal is due by the 5th of the following month
$currentMonth = (int)date('n');
$currentYear  = (int)date('Y');
$dueDate = date('Y-m-d', mktime(0, 0, 0, $currentMonth + 1, 5, $currentYear));
$daysUntilDue = (int)((strtotime($dueDate) - time()) / 86400);
if ($daysUntilDue < 0) $daysUntilDue = 0;

// Check if current month's report has been generated
$reportCheck = $conn->prepare(
    "SELECT id, generated_at FROM kp_documents
     WHERE form_number = 28 AND generated_at BETWEEN ? AND ?
     ORDER BY id DESC LIMIT 1"
);
$curMonthStart = date('Y-m-01');
$curMonthEnd   = date('Y-m-t');
$reportCheck->bind_param('ss', $curMonthStart, $curMonthEnd);
$reportCheck->execute();
$existingReport = $reportCheck->get_result()->fetch_assoc();
$reportCheck->close();

// ─── Handle Form #28 Generation ─────────────────────────────────────────────
$genSuccess = false;
$genError   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_form28') {
    // Build the case list text for the template
    $caseLines = [];
    $lineNo = 1;

    foreach ($settledCases as $sc) {
        $caseLines[] = "{$lineNo}. {$sc['case_id']} — {$sc['name']} vs. {$sc['complained_person']} — Settled (Amicable)";
        $lineNo++;
    }
    foreach ($arbCases as $ac) {
        $caseLines[] = "{$lineNo}. {$ac['case_id']} — {$ac['name']} vs. {$ac['complained_person']} — Arbitration Award";
        $lineNo++;
    }
    foreach ($certifiedCases as $cc) {
        $status = $cc['kp_status'] === 'dismissed' ? 'Dismissed' : 'Certified to Court';
        $caseLines[] = "{$lineNo}. {$cc['case_id']} — {$cc['name']} vs. {$cc['complained_person']} — {$status}";
        $lineNo++;
    }

    if (empty($caseLines)) {
        $caseListText = 'No cases resolved for this period.';
    } else {
        $caseListText = implode("\n", $caseLines);
    }

    // Build a dummy case array for the template engine
    $dummyCase = [
        'id' => 0,
        'case_id' => 'REPORT',
        'name' => '',
        'complained_person' => '',
        'date_submitted' => $startDate,
    ];

    $extra = [
        'report_month_year'  => $monthYearLabel,
        'monthly_case_list'  => $caseListText,
        'total_settled'      => (string)($totalSettled + $totalArbitrated),
        'total_certified'    => (string)$totalCertified,
        'cert_signer_name'   => $_SESSION['name'] ?? 'Lupon Secretary',
    ];

    // Generate into a reports subdirectory
    $reportDir = KP_DOCUMENT_DIR . 'reports/';
    if (!is_dir($reportDir)) mkdir($reportDir, 0755, true);

    $formInfo = kp_form_info(28);
    $templatePath = KP_TEMPLATE_DIR . ($formInfo['template'] ?? '');

    if (file_exists($templatePath)) {
        try {
            $template = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
            $data = kp_build_template_data($dummyCase, $extra);
            foreach ($data as $key => $value) {
                $template->setValue($key, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
            }

            $outputName = "KP_Form_28_Transmittal_{$selYear}_{$selMonth}.docx";
            $outputPath = $reportDir . $outputName;
            $template->saveAs($outputPath);

            // Save document record
            kp_save_document_record($conn, 0, 28, $outputPath, '', (int)($_SESSION['user_id'] ?? 0));
            $genSuccess = true;
        } catch (Exception $e) {
            $genError = 'Error generating Form #28: ' . $e->getMessage();
        }
    } else {
        $genError = 'Form #28 template not found.';
    }
}

// Check for existing report for selected month
$reportFile = KP_DOCUMENT_DIR . "reports/KP_Form_28_Transmittal_{$selYear}_{$selMonth}.docx";
$hasReport = file_exists($reportFile);
?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">
    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-title">KP Reports & Monthly Transmittal</div>
        <div class="topbar-right">
            <div class="user-pill">
                <div class="user-avatar"><i class="uil uil-user"></i></div>
                <div class="user-text">
                    <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Barangay Official') ?></strong>
                    <span>Barangay Administrator</span>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">

        <!-- Month Selector -->
        <div class="kp-report-selector card-box">
            <form method="GET" class="kp-report-month-form">
                <label for="kp-month">Report Period:</label>
                <select name="month" id="kp-month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $selMonth ? 'selected' : '' ?>>
                        <?= date('F', mktime(0,0,0,$m,1)) ?>
                    </option>
                    <?php endfor; ?>
                </select>
                <select name="year" id="kp-year">
                    <?php for ($y = 2024; $y <= (int)date('Y') + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y === $selYear ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="btn-kp-primary"><i class="uil uil-search"></i> View Report</button>
                <button type="button" onclick="window.print()" class="btn-kp-primary" style="background:#475569;"><i class="uil uil-print"></i> Print Report</button>
            </form>
        </div>

        <!-- Official Print Header (Visible only on print) -->
        <div class="kp-print-header" style="display:none;">
            <div style="text-align:center;margin-bottom:20px;font-family:serif;">
                <div style="font-size:12pt;letter-spacing:1px;">Republic of the Philippines</div>
                <div style="font-size:11pt;">Province of Samar • City of Catbalogan</div>
                <div style="font-size:13pt;font-weight:bold;margin-top:2px;">BARANGAY MUÑOZ</div>
                <div style="font-size:11pt;font-weight:bold;margin-top:4px;">OFFICE OF THE LUPONG TAGAPAMAYAPA</div>
                <hr style="margin:10px 0;border:none;border-top:2px solid #000;">
                <div style="font-size:14pt;font-weight:bold;margin-top:8px;">MONTHLY TRANSMITTAL OF FINAL REPORTS</div>
                <div style="font-size:12pt;margin-top:2px;">Period: <?= htmlspecialchars($monthYearLabel) ?></div>
            </div>
        </div>

        <?php if ($genSuccess): ?>
        <div class="kp-info-box kp-info-success" style="margin-bottom:16px;">
            <i class="uil uil-check-circle"></i>
            <div><strong>Form #28 generated successfully!</strong>
                <a href="kp_download.php?type=report&file=<?= urlencode(basename($reportFile)) ?>"
                   class="btn-kp-sm" style="margin-left:12px;">
                    <i class="uil uil-download-alt"></i> Download DOCX
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($genError): ?>
        <div class="kp-info-box kp-info-danger" style="margin-bottom:16px;">
            <i class="uil uil-exclamation-triangle"></i>
            <div><strong>Error:</strong> <?= htmlspecialchars($genError) ?></div>
        </div>
        <?php endif; ?>

        <!-- Monthly Transmittal Due Date Widget -->
        <div class="kp-report-due-widget card-box" style="margin-bottom:20px;">
            <div class="kp-report-due-inner">
                <div class="kp-report-due-icon <?= $daysUntilDue <= 5 ? 'kp-due-urgent' : 'kp-due-normal' ?>">
                    <i class="uil uil-calendar-alt"></i>
                </div>
                <div class="kp-report-due-text">
                    <strong>Monthly Transmittal Report (KP Form #28)</strong>
                    <p>
                        <?php if ($daysUntilDue <= 0): ?>
                            <span class="kp-due-overdue">⚠ Report for <?= date('F Y') ?> is <strong>OVERDUE</strong>. Due date was <?= date('F j, Y', strtotime($dueDate)) ?>.</span>
                        <?php elseif ($daysUntilDue <= 5): ?>
                            <span class="kp-due-warning">Due in <strong><?= $daysUntilDue ?> day<?= $daysUntilDue > 1 ? 's' : '' ?></strong> (<?= date('F j, Y', strtotime($dueDate)) ?>)</span>
                        <?php else: ?>
                            Due in <strong><?= $daysUntilDue ?> days</strong> (<?= date('F j, Y', strtotime($dueDate)) ?>)
                        <?php endif; ?>
                    </p>
                </div>
                <div class="kp-report-due-actions">
                    <?php if ($existingReport): ?>
                        <span class="kp-badge-success"><i class="uil uil-check"></i> Generated</span>
                    <?php else: ?>
                        <span class="kp-badge-warning"><i class="uil uil-clock"></i> Not Yet Generated</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stats Cards for Selected Month -->
        <h3 class="kp-report-heading"><?= htmlspecialchars($monthYearLabel) ?> — Case Summary</h3>
        <div class="stat-cards-row" style="margin-bottom:20px;">
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Cases Filed</div>
                    <div class="stat-value"><?= $totalFiled ?></div>
                    <div class="stat-sub">In <?= $monthName ?></div>
                </div>
                <div class="stat-icon stat-blue"><i class="uil uil-file-plus-alt"></i></div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Settled / Awarded</div>
                    <div class="stat-value"><?= $totalSettled + $totalArbitrated ?></div>
                    <div class="stat-sub">Amicable + Arbitration</div>
                </div>
                <div class="stat-icon stat-green"><i class="uil uil-handshake"></i></div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Certified to Court</div>
                    <div class="stat-value"><?= $totalCertified ?></div>
                    <div class="stat-sub">Filed / Dismissed</div>
                </div>
                <div class="stat-icon stat-orange"><i class="uil uil-user-check"></i></div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Still Pending</div>
                    <div class="stat-value"><?= $totalPending ?></div>
                    <div class="stat-sub">Ongoing process</div>
                </div>
                <div class="stat-icon" style="background:rgba(139,92,246,.12);color:#7c3aed;"><i class="uil uil-clock-three"></i></div>
            </div>
        </div>

        <!-- KP Status Breakdown -->
        <?php if (!empty($statusBreakdown)): ?>
        <div class="card-box" style="margin-bottom:20px;">
            <div class="section-heading">KP Status Breakdown</div>
            <div class="section-heading-sub">Cases filed in <?= htmlspecialchars($monthYearLabel) ?> by KP status</div>
            <div class="kp-status-breakdown">
                <?php foreach ($statusBreakdown as $sb): ?>
                <div class="kp-sb-item">
                    <span class="kp-sb-dot" style="background:<?= kp_status_color($sb['kp_status']) ?>"></span>
                    <span class="kp-sb-label"><?= htmlspecialchars(kp_status_label($sb['kp_status'])) ?></span>
                    <span class="kp-sb-count"><?= (int)$sb['cnt'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Settled Cases List -->
        <div class="card-box" style="margin-bottom:20px;">
            <div class="section-heading">Settled / Awarded Cases</div>
            <div class="section-heading-sub">Cases resolved through amicable settlement or arbitration award in <?= htmlspecialchars($monthYearLabel) ?></div>

            <?php if (empty($settledCases) && empty($arbCases)): ?>
                <div class="kp-empty-state"><i class="uil uil-folder-open"></i><p>No settled or awarded cases for this period.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table-dashboard">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Case ID</th>
                            <th>Complainant</th>
                            <th>Respondent</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n = 1; foreach ($settledCases as $sc): ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><strong><?= htmlspecialchars($sc['case_id'] ?? '') ?></strong></td>
                            <td><?= htmlspecialchars($sc['name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($sc['complained_person'] ?? '') ?></td>
                            <td><span class="kp-badge-success">Settled</span></td>
                            <td><?= htmlspecialchars(kp_status_label($sc['kp_status'] ?? '')) ?></td>
                            <td><a href="case_process.php?id=<?= (int)$sc['id'] ?>" class="table-action"><i class="uil uil-eye"></i> View</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php foreach ($arbCases as $ac): ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><strong><?= htmlspecialchars($ac['case_id'] ?? '') ?></strong></td>
                            <td><?= htmlspecialchars($ac['name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($ac['complained_person'] ?? '') ?></td>
                            <td><span class="kp-badge-info">Arbitration Award</span></td>
                            <td><?= htmlspecialchars(kp_status_label($ac['kp_status'] ?? '')) ?></td>
                            <td><a href="case_process.php?id=<?= (int)$ac['id'] ?>" class="table-action"><i class="uil uil-eye"></i> View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Certified / Dismissed Cases -->
        <div class="card-box" style="margin-bottom:20px;">
            <div class="section-heading">Certified to Court / Dismissed</div>
            <div class="section-heading-sub">Cases certified for court filing or dismissed in <?= htmlspecialchars($monthYearLabel) ?></div>

            <?php if (empty($certifiedCases)): ?>
                <div class="kp-empty-state"><i class="uil uil-folder-open"></i><p>No certified or dismissed cases for this period.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table-dashboard">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Case ID</th>
                            <th>Complainant</th>
                            <th>Respondent</th>
                            <th>Disposition</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n2 = 1; foreach ($certifiedCases as $cc): ?>
                        <tr>
                            <td><?= $n2++ ?></td>
                            <td><strong><?= htmlspecialchars($cc['case_id'] ?? '') ?></strong></td>
                            <td><?= htmlspecialchars($cc['name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($cc['complained_person'] ?? '') ?></td>
                            <td><?= htmlspecialchars(kp_status_label($cc['kp_status'] ?? '')) ?></td>
                            <td><a href="case_process.php?id=<?= (int)$cc['id'] ?>" class="table-action"><i class="uil uil-eye"></i> View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- KP Monitoring Form No. 1 (monthly report to DILG) -->
        <div class="card-box kp-generate-form28">
            <div class="section-heading">KP Monitoring Form No. 1 — Monthly Report</div>
            <div class="section-heading-sub">
                KP cases filed in <?= htmlspecialchars($monthYearLabel) ?>, their nature and the action taken, in the official DILG format.
                Prepared by the Barangay Secretary and attested by the Punong Barangay. Complaints not covered by KP are not included.
            </div>

            <div class="kp-form28-summary">
                <div class="kp-f28-item">
                    <i class="uil uil-file-alt kp-f28-icon-purple"></i>
                    <span>KP cases filed: <strong><?= count($monitoring['rows']) ?></strong></span>
                </div>
                <div class="kp-f28-item">
                    <i class="uil uil-handshake kp-f28-icon-green"></i>
                    <span>Settled / awarded: <strong><?= $monitoring['totals']['mediation'] + $monitoring['totals']['conciliation'] + $monitoring['totals']['arbitration'] ?></strong></span>
                </div>
                <div class="kp-f28-item">
                    <i class="uil uil-file-upload-alt kp-f28-icon-orange"></i>
                    <span>Certified / repudiated / dismissed: <strong><?= $monitoring['totals']['certified'] + $monitoring['totals']['repudiation'] + $monitoring['totals']['dismissed'] ?></strong></span>
                </div>
                <div class="kp-f28-item">
                    <i class="uil uil-clock kp-f28-icon-purple"></i>
                    <span>Pending: <strong><?= $monitoring['totals']['pending'] ?></strong></span>
                </div>
            </div>

            <div class="kp-form28-actions">
                <a href="kp_monitoring_report.php?month=<?= $selMonth ?>&amp;year=<?= $selYear ?>" class="btn-kp-primary btn-kp-lg">
                    <i class="uil uil-file-download-alt"></i> Download Monitoring Form (<?= htmlspecialchars($monthYearLabel) ?>)
                </a>
            </div>
        </div>

        <!-- Generate Form #28 -->
        <div class="card-box kp-generate-form28">
            <div class="section-heading">Generate KP Form #28 — Monthly Transmittal</div>
            <div class="section-heading-sub">
                Generate the official Monthly Transmittal of Final Reports for <?= htmlspecialchars($monthYearLabel) ?>.
                This will auto-populate all settled, awarded, and certified cases.
            </div>

            <div class="kp-form28-summary">
                <div class="kp-f28-item">
                    <i class="uil uil-handshake kp-f28-icon-green"></i>
                    <span>Settled: <strong><?= $totalSettled ?></strong></span>
                </div>
                <div class="kp-f28-item">
                    <i class="uil uil-user-check kp-f28-icon-purple"></i>
                    <span>Arbitration Awards: <strong><?= $totalArbitrated ?></strong></span>
                </div>
                <div class="kp-f28-item">
                    <i class="uil uil-file-upload-alt kp-f28-icon-orange"></i>
                    <span>Certified to Court: <strong><?= $totalCertified ?></strong></span>
                </div>
            </div>

            <div class="kp-form28-actions">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="generate_form28">
                    <input type="hidden" name="month" value="<?= $selMonth ?>">
                    <input type="hidden" name="year" value="<?= $selYear ?>">
                    <button type="submit" class="btn-kp-primary btn-kp-lg">
                        <i class="uil uil-file-download-alt"></i>
                        <?= $hasReport ? 'Re-generate' : 'Generate' ?> Form #28 (<?= $monthYearLabel ?>)
                    </button>
                </form>

                <?php if ($hasReport): ?>
                <a href="kp_download.php?type=report&file=<?= urlencode(basename($reportFile)) ?>"
                   class="btn-kp-success btn-kp-lg">
                    <i class="uil uil-download-alt"></i> Download Existing Report
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Official Printable Signatures Block (Visible on print) -->
        <div class="kp-print-signatures" style="display:none;margin-top:40px;font-family:serif;">
            <div style="display:flex;justify-content:space-between;margin-top:50px;">
                <div style="text-align:center;width:45%;">
                    <div style="font-size:11pt;color:#333;margin-bottom:40px;">Prepared by:</div>
                    <div style="border-bottom:1px solid #000;font-weight:bold;font-size:12pt;"><?= htmlspecialchars(strtoupper($_SESSION['name'] ?? 'BARANGAY SECRETARY')) ?></div>
                    <div style="font-size:11pt;margin-top:4px;">Barangay Secretary</div>
                </div>
                <div style="text-align:center;width:45%;">
                    <div style="font-size:11pt;color:#333;margin-bottom:40px;">Attested by:</div>
                    <div style="border-bottom:1px solid #000;font-weight:bold;font-size:12pt;">HON. ULYSIS B. MACABARE</div>
                    <div style="font-size:11pt;margin-top:4px;">Punong Barangay / Lupon Chairman</div>
                </div>
            </div>
        </div>

    </div><!-- /page-body -->
</div><!-- /main -->

<style>
/* ─── KP Reports Styles ─── */
.kp-report-selector { padding: 16px 20px; margin-bottom: 20px; }
.kp-report-month-form {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
}
.kp-report-month-form label {
    font-weight: 600; color: #334155; font-size: 14px;
}
.kp-report-month-form select {
    padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px;
    font-size: 14px; background: #fff; color: #1e293b;
}
.kp-report-heading {
    font-size: 18px; font-weight: 700; color: #1e293b; margin-bottom: 12px;
}

/* Due Date Widget */
.kp-report-due-inner {
    display: flex; align-items: center; gap: 16px; padding: 8px 0; flex-wrap: wrap;
}
.kp-report-due-icon {
    width: 48px; height: 48px; border-radius: 12px; display: flex;
    align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0;
}
.kp-due-normal { background: rgba(59,130,246,.12); color: #2563eb; }
.kp-due-urgent { background: rgba(239,68,68,.12); color: #dc2626; }
.kp-report-due-text { flex: 1; }
.kp-report-due-text strong { font-size: 15px; color: #1e293b; display: block; margin-bottom: 2px; }
.kp-report-due-text p { font-size: 13px; color: #64748b; margin: 0; }
.kp-due-overdue { color: #dc2626; font-weight: 600; }
.kp-due-warning { color: #d97706; font-weight: 600; }
.kp-report-due-actions { flex-shrink: 0; }

.kp-badge-success {
    display: inline-flex; align-items: center; gap: 4px; padding: 5px 12px;
    border-radius: 20px; background: #dcfce7; color: #16a34a; font-size: 12px; font-weight: 600;
}
.kp-badge-warning {
    display: inline-flex; align-items: center; gap: 4px; padding: 5px 12px;
    border-radius: 20px; background: #fef3c7; color: #d97706; font-size: 12px; font-weight: 600;
}
.kp-badge-info {
    display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px;
    border-radius: 20px; background: #ede9fe; color: #7c3aed; font-size: 12px; font-weight: 600;
}

/* Status Breakdown */
.kp-status-breakdown {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; padding: 12px 0;
}
.kp-sb-item {
    display: flex; align-items: center; gap: 8px; padding: 8px 12px;
    background: #f8fafc; border-radius: 8px; font-size: 13px;
}
.kp-sb-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.kp-sb-label { flex: 1; color: #334155; }
.kp-sb-count { font-weight: 700; color: #1e293b; font-size: 15px; }

/* Empty State */
.kp-empty-state {
    text-align: center; padding: 32px 20px; color: #94a3b8;
}
.kp-empty-state i { font-size: 40px; margin-bottom: 8px; display: block; }
.kp-empty-state p { margin: 0; font-size: 14px; }

/* Form 28 Generate Section */
.kp-generate-form28 { border: 2px dashed #e2e8f0; }
.kp-form28-summary {
    display: flex; gap: 24px; padding: 16px 0; flex-wrap: wrap;
}
.kp-f28-item {
    display: flex; align-items: center; gap: 8px; font-size: 14px; color: #334155;
}
.kp-f28-icon-green { color: #16a34a; font-size: 20px; }
.kp-f28-icon-purple { color: #7c3aed; font-size: 20px; }
.kp-f28-icon-orange { color: #ea580c; font-size: 20px; }
.kp-form28-actions {
    display: flex; gap: 12px; margin-top: 12px; flex-wrap: wrap;
}

/* Buttons */
.btn-kp-primary {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px;
    background: #2563eb; color: #fff; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer; transition: background .2s;
}
.btn-kp-primary:hover { background: #1d4ed8; }
.btn-kp-success {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px;
    background: #16a34a; color: #fff; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; transition: background .2s;
}
.btn-kp-success:hover { background: #15803d; }
.btn-kp-lg { padding: 10px 24px; font-size: 14px; }
.btn-kp-sm {
    display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px;
    background: #16a34a; color: #fff; border: none; border-radius: 6px;
    font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none;
}

/* ── Print Media Styles ── */
@media print {
    body { background: #fff !important; color: #000 !important; font-size: 11pt !important; }
    .sidebar, .topbar, .kp-report-selector, .kp-report-due-widget, .kp-generate-form28, .kp-info-box, .btn-kp-sm, footer, .main-footer { display: none !important; }
    .main { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
    .page-body { padding: 0 !important; }
    .kp-print-header, .kp-print-signatures { display: block !important; }
    .card-box { border: 1px solid #94a3b8 !important; box-shadow: none !important; margin-bottom: 20px !important; break-inside: avoid; }
    .table-dashboard { width: 100% !important; border-collapse: collapse !important; }
    .table-dashboard th, .table-dashboard td { border: 1px solid #cbd5e1 !important; padding: 6px 8px !important; color: #000 !important; font-size: 10pt !important; }
    .kp-badge-success, .kp-badge-warning, .badge-status { border: 1px solid #94a3b8 !important; background: none !important; color: #000 !important; }
}
</style>

