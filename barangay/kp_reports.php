<?php
/**
 * KP Reports — monthly case summary and the monthly report.
 *
 * The monthly report is KP Monitoring Form No. 1 (includes/kp_monitoring.php),
 * prepared within the first 5 days of the following month.
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

// KP status breakdown for the month
$breakdownQuery = $conn->prepare(
    "SELECT kp_status, COUNT(*) as cnt FROM cases
     WHERE archived_at IS NULL AND LOWER(barangay) = LOWER(?) AND DATE(date_submitted) BETWEEN ? AND ? AND kp_status IS NOT NULL
     GROUP BY kp_status ORDER BY cnt DESC"
);
$breakdownQuery->bind_param('sss', $barangayFilter, $startDate, $endDate);
$breakdownQuery->execute();
$statusBreakdown = $breakdownQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$breakdownQuery->close();

$monitoring = kp_monitoring_rows($conn, $currentBarangay, $selYear, $selMonth);

// Summary cards use the Monitoring Form's own figures, so the page and the report agree.
$totalFiled = count($monitoring['rows']);
$totalSettled = $monitoring['totals']['mediation'] + $monitoring['totals']['conciliation'] + $monitoring['totals']['arbitration'];
$totalCertified = $monitoring['totals']['certified'] + $monitoring['totals']['repudiation'] + $monitoring['totals']['dismissed'];
$totalPending = $monitoring['totals']['pending'];
$breakdownTotal = array_sum(array_map(static fn($r) => (int)$r['cnt'], $statusBreakdown));
$stages = kp_stages();

// ─── Monthly Report Due Date Calculation ────────────────────────────────────
// The monthly report (KP Monitoring Form No. 1) is due within the first 5 days of the following month
$currentMonth = (int)date('n');
$currentYear  = (int)date('Y');
$dueDate = date('Y-m-d', mktime(0, 0, 0, $currentMonth + 1, 5, $currentYear));
// Whole calendar days, so the due date itself counts as "due today".
$daysUntilDue = (int)(new DateTime(date('Y-m-d')))->diff(new DateTime($dueDate))->format('%r%a');

?>
<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar_barangay.php'); ?>

<div class="main">
    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-title">Monthly Report</div>
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
            </form>
        </div>

        <!-- Stats Cards for Selected Month -->
        <h3 class="kp-report-heading"><?= htmlspecialchars($monthYearLabel) ?> — Case Summary</h3>
        <div class="stat-cards-row" style="margin-bottom:20px;">
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">KP Cases Filed</div>
                    <div class="stat-value"><?= $totalFiled ?></div>
                    <div class="stat-sub">In <?= $monthName ?></div>
                </div>
                <div class="stat-icon stat-blue"><i class="uil uil-file-plus-alt"></i></div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Settled / Awarded</div>
                    <div class="stat-value"><?= $totalSettled ?></div>
                    <div class="stat-sub">Amicable + Arbitration</div>
                </div>
                <div class="stat-icon stat-green"><i class="uil uil-file-check-alt"></i></div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Certified / Dismissed</div>
                    <div class="stat-value"><?= $totalCertified ?></div>
                    <div class="stat-sub">Incl. repudiated</div>
                </div>
                <div class="stat-icon stat-orange"><i class="uil uil-user-check"></i></div>
            </div>
            <div class="card-box stat-card">
                <div>
                    <div class="stat-label">Pending</div>
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
            <div class="section-heading-sub">All complaints filed in <?= htmlspecialchars($monthYearLabel) ?>, including those still under review, by their current status</div>
            <div class="kp-sb-list">
                <?php foreach ($statusBreakdown as $sb):
                    $sbStatus = (string)$sb['kp_status'];
                    $sbColor = kp_status_color($sbStatus);
                    $sbCount = (int)$sb['cnt'];
                    $sbShare = $breakdownTotal > 0 ? round($sbCount / $breakdownTotal * 100) : 0;
                    $sbStage = $stages[kp_status_stage($sbStatus)] ?? null;
                ?>
                <div class="kp-sb-row">
                    <div class="kp-sb-icon" style="background:<?= $sbColor ?>1a;color:<?= $sbColor ?>;">
                        <i class="uil <?= htmlspecialchars($sbStage['icon'] ?? 'uil-circle') ?>"></i>
                    </div>
                    <div class="kp-sb-main">
                        <div class="kp-sb-top">
                            <span class="kp-sb-label" title="<?= htmlspecialchars(kp_status_label($sbStatus)) ?>"><?= htmlspecialchars(kp_status_label($sbStatus)) ?></span>
                            <span class="kp-sb-stage"><?= htmlspecialchars($sbStage['label'] ?? '') ?></span>
                        </div>
                        <div class="kp-sb-bar"><span style="width:<?= max($sbShare, 3) ?>%;background:<?= $sbColor ?>;"></span></div>
                    </div>
                    <div class="kp-sb-num">
                        <strong><?= $sbCount ?></strong>
                        <span><?= $sbShare ?>%</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Monthly report: KP Monitoring Form No. 1 -->
        <div class="card-box kp-generate-form28" id="monitoring">
            <div class="section-heading">Monthly Report &mdash; KP Monitoring Form No. 1</div>
            <div class="section-heading-sub">
                KP cases filed in <?= htmlspecialchars($monthYearLabel) ?>, their nature and the action taken, in the official DILG format.
                Prepared by the Barangay Secretary and attested by the Punong Barangay. Complaints not covered by KP are not included.
            </div>
            <div class="kp-form28-due">
                <i class="uil uil-calendar-alt"></i>
                The report for <strong><?= date('F Y') ?></strong> is prepared within the first 5 days of next month:
                <?php if ($daysUntilDue < 0): ?>
                    <span class="kp-due-overdue">overdue since <?= date('F j, Y', strtotime($dueDate)) ?></span>
                <?php elseif ($daysUntilDue === 0): ?>
                    <span class="kp-due-warning">due <strong>today</strong> (<?= date('M j', strtotime($dueDate)) ?>)</span>
                <?php elseif ($daysUntilDue <= 5): ?>
                    <span class="kp-due-warning">due in <strong><?= $daysUntilDue ?> day<?= $daysUntilDue > 1 ? 's' : '' ?></strong> (<?= date('M j', strtotime($dueDate)) ?>)</span>
                <?php else: ?>
                    due in <strong><?= $daysUntilDue ?> days</strong> (<?= date('M j', strtotime($dueDate)) ?>)
                <?php endif; ?>
            </div>

            <div class="kp-form28-summary">
                <div class="kp-f28-item">
                    <i class="uil uil-file-alt kp-f28-icon-purple"></i>
                    <span>KP cases filed: <strong><?= count($monitoring['rows']) ?></strong></span>
                </div>
                <div class="kp-f28-item">
                    <i class="uil uil-file-check-alt kp-f28-icon-green"></i>
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
                    <i class="uil uil-file-download-alt"></i> Download Monthly Report (<?= htmlspecialchars($monthYearLabel) ?>)
                </a>
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
.kp-sb-list { display: flex; gap: 10px; padding-top: 12px; overflow-x: auto; }
.kp-sb-row { flex: 1 1 0; min-width: 190px; display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: #f8fafc; border: 1px solid #eef2f7; border-radius: 12px; }
.kp-sb-icon { width: 32px; height: 32px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.kp-sb-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.kp-sb-top { display: flex; flex-direction: column; }
.kp-sb-label { font-size: 12.5px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kp-sb-stage { font-size: 10px; font-weight: 600; letter-spacing: .4px; text-transform: uppercase; color: #94a3b8; }
.kp-sb-bar { height: 5px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
.kp-sb-bar span { display: block; height: 100%; border-radius: 999px; }
.kp-sb-num { display: flex; flex-direction: column; align-items: flex-end; min-width: 34px; }
.kp-sb-num strong { font-size: 17px; color: #1e293b; line-height: 1.1; }
.kp-sb-num span { font-size: 11px; color: #64748b; }

/* Empty State */
.kp-empty-state {
    text-align: center; padding: 32px 20px; color: #94a3b8;
}
.kp-empty-state i { font-size: 40px; margin-bottom: 8px; display: block; }
.kp-empty-state p { margin: 0; font-size: 14px; }

/* Form 28 Generate Section */
.kp-generate-form28 { border: 2px dashed #e2e8f0; scroll-margin-top: 80px; margin-bottom: 18px; }
.kp-generate-form28 a.btn-kp-primary, .kp-generate-form28 a.btn-kp-success { text-decoration: none; }
.kp-form28-due { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12.5px; color: #475569; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 8px 12px; margin: 12px 0 4px; }
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

