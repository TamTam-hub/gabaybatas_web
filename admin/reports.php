<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header('Location: ../login.php'); exit(); }
require_once '../config/database.php';

$statsFile = __DIR__ . '/report_download_stats.json';
$downloadStats = ['total' => 0, 'monthly' => 0, 'summary' => 0, 'quarterly' => 0];
if (is_file($statsFile)) {
    $storedStats = json_decode((string)file_get_contents($statsFile), true);
    if (is_array($storedStats)) {
        $downloadStats = array_merge($downloadStats, array_intersect_key($storedStats, $downloadStats));
    }
}

$caseColumns = [];
$colResult = $conn->query("SHOW COLUMNS FROM cases");
if ($colResult) { while ($col = $colResult->fetch_assoc()) $caseColumns[] = $col['Field']; }

$hasCaseColumn = static function (string $column) use ($caseColumns): bool { return in_array($column, $caseColumns, true); };

$dateColumn = $hasCaseColumn('created_at') ? 'created_at' : ($hasCaseColumn('date_submitted') ? 'date_submitted' : null);
$quarterStartMonth = ((int)ceil((int)date('n') / 3) - 1) * 3 + 1;
$quarterEndMonth = $quarterStartMonth + 2;

$totalReports = 3;
if ($dateColumn !== null) {
    $thisMonthRes = $conn->query("SELECT COUNT(*) AS total FROM cases WHERE MONTH($dateColumn) = MONTH(CURRENT_DATE()) AND YEAR($dateColumn) = YEAR(CURRENT_DATE())");
    $thisMonth = (int)(($thisMonthRes ? $thisMonthRes->fetch_assoc()['total'] : 0) ?? 0);
} else { $thisMonth = 0; }

$totalCases = (int)($conn->query("SELECT COUNT(*) AS total FROM cases")->fetch_assoc()['total'] ?? 0);
$quarterCases = 0;
if ($dateColumn !== null) {
    $quarterCasesRes = $conn->query("SELECT COUNT(*) AS total FROM cases WHERE MONTH($dateColumn) BETWEEN {$quarterStartMonth} AND {$quarterEndMonth} AND YEAR($dateColumn) = YEAR(CURRENT_DATE())");
    $quarterCases = (int)(($quarterCasesRes ? $quarterCasesRes->fetch_assoc()['total'] : 0) ?? 0);
}

$totalDownloads = (int)($downloadStats['total'] ?? 0);
$generatedDate  = date('Y-m-d');

$reportRows = [
    ['title'=>'Monthly Case Report','desc'=>'All cases filed in the current month','type'=>'Monthly','period'=>date('F Y'),'generated'=>$generatedDate,'downloads'=>(string)($downloadStats['monthly'] ?? 0),'size'=>max(120, (int)round($thisMonth * 1.9 + 120)) . ' KB','icon'=>'uil-calendar-alt','accent'=>'blue','link'=>'report_download.php?type=monthly'],
    ['title'=>'Case Summary Report','desc'=>'High-level overview of all cases and statuses','type'=>'Summary','period'=>'All Periods - '.date('Y'),'generated'=>$generatedDate,'downloads'=>(string)($downloadStats['summary'] ?? 0),'size'=>max(140, (int)round($totalCases * 1.2 + 140)) . ' KB','icon'=>'uil-file-graph','accent'=>'green','link'=>'report_download.php?type=summary'],
    ['title'=>'Quarterly Case Report','desc'=>'Quarter-wide analysis and case performance snapshot','type'=>'Quarterly','period'=>'Quarter '.(int)ceil((int)date('n')/3).', '.date('Y'),'generated'=>$generatedDate,'downloads'=>(string)($downloadStats['quarterly'] ?? 0),'size'=>max(160, (int)round($quarterCases * 1.7 + 160)) . ' KB','icon'=>'uil-chart-growth','accent'=>'indigo','link'=>'report_download.php?type=quarterly'],
];
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
<div class="topbar">
    <div class="topbar-left"><div class="topbar-title">Reports</div></div>
    <div class="topbar-right">
        <div class="notif-btn"><i class="uil uil-bell"></i></div>
        <div class="topbar-sep"></div>
        <div class="user-pill">
            <div class="user-avatar"><i class="uil uil-user"></i></div>
            <div class="user-text">
                <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></strong>
                <span>Administrator</span>
            </div>
        </div>
    </div>
</div>

<div class="page-body rp-body">

    <!-- Hero Banner -->
    <div class="rp-hero fade-up" style="--d:0s">
        <div class="rp-hero-glow"></div>
        <div class="rp-hero-content">
            <div class="rp-hero-badge"><i class="uil uil-chart-bar"></i> Reports Center</div>
            <h1 class="rp-hero-title">Reports Workspace</h1>
            <p class="rp-hero-sub">Generate polished legal reports with one click and keep your barangay records presentation-ready.</p>
        </div>
        <a href="report_builder.php" class="rp-hero-btn">
            <i class="uil uil-plus"></i> Generate New Report
        </a>
    </div>

    <!-- KPI Cards -->
    <div class="rp-kpi-grid fade-up" style="--d:.08s">
        <div class="rp-kpi" style="--ac:#3b82f6;--ab:#eff6ff;--at:#1d4ed8">
            <div class="rp-kpi-top">
                <div class="rp-kpi-icon"><i class="uil uil-file"></i></div>
                <span class="rp-kpi-tag">Portal</span>
            </div>
            <div class="rp-kpi-num"><?= $totalReports ?></div>
            <div class="rp-kpi-label">Total Reports</div>
            <div class="rp-kpi-desc">Available in this portal</div>
        </div>
        <div class="rp-kpi" style="--ac:#10b981;--ab:#ecfdf5;--at:#065f46">
            <div class="rp-kpi-top">
                <div class="rp-kpi-icon"><i class="uil uil-calendar-alt"></i></div>
                <span class="rp-kpi-tag"><?= date('M Y') ?></span>
            </div>
            <div class="rp-kpi-num"><?= $thisMonth ?></div>
            <div class="rp-kpi-label">Cases This Month</div>
            <div class="rp-kpi-desc">Source records included</div>
        </div>
        <div class="rp-kpi" style="--ac:#6366f1;--ab:#eef2ff;--at:#3730a3">
            <div class="rp-kpi-top">
                <div class="rp-kpi-icon"><i class="uil uil-download-alt"></i></div>
                <span class="rp-kpi-tag">All time</span>
            </div>
            <div class="rp-kpi-num"><?= $totalDownloads ?></div>
            <div class="rp-kpi-label">Downloads</div>
            <div class="rp-kpi-desc">Tracking ready</div>
        </div>
    </div>

    <!-- Reports Table Card -->
    <div class="rp-card fade-up" style="--d:.16s">
        <div class="rp-card-head">
            <div>
                <div class="rp-card-title">Generated Reports</div>
                <div class="rp-card-sub">System-generated templates from existing case records</div>
            </div>
            <span class="rp-count-badge"><?= count($reportRows) ?> reports</span>
        </div>

        <!-- Report rows (cards style) -->
        <div class="rp-report-list">
            <?php foreach ($reportRows as $i => $row):
                $iconColors = ['blue'=>['bg'=>'#dbeafe','color'=>'#1d4ed8'],'green'=>['bg'=>'#d1fae5','color'=>'#059669'],'indigo'=>['bg'=>'#e0e7ff','color'=>'#4f46e5']];
                $ic = $iconColors[$row['accent']] ?? $iconColors['blue'];
                $typeColors = ['Monthly'=>['bg'=>'#eff6ff','color'=>'#1d4ed8'],'Summary'=>['bg'=>'#ecfdf5','color'=>'#065f46'],'Quarterly'=>['bg'=>'#eef2ff','color'=>'#3730a3']];
                $tc = $typeColors[$row['type']] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
            ?>
            <div class="rp-row fade-up" style="--d:<?= .2+($i*.06) ?>s">
                <div class="rp-row-left">
                    <div class="rp-row-icon" style="background:<?= $ic['bg'] ?>;color:<?= $ic['color'] ?>">
                        <i class="uil <?= htmlspecialchars($row['icon']) ?>"></i>
                    </div>
                    <div class="rp-row-info">
                        <div class="rp-row-title"><?= htmlspecialchars($row['title']) ?></div>
                        <div class="rp-row-desc"><?= htmlspecialchars($row['desc']) ?></div>
                    </div>
                </div>
                <div class="rp-row-meta">
                    <div class="rp-meta-item">
                        <span class="rp-meta-label">Type</span>
                        <span class="rp-type-pill" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>"><?= htmlspecialchars($row['type']) ?></span>
                    </div>
                    <div class="rp-meta-item">
                        <span class="rp-meta-label">Period</span>
                        <span class="rp-meta-val"><?= htmlspecialchars($row['period']) ?></span>
                    </div>
                    <div class="rp-meta-item">
                        <span class="rp-meta-label">Generated</span>
                        <span class="rp-meta-val"><?= htmlspecialchars($row['generated']) ?></span>
                    </div>
                    <div class="rp-meta-item">
                        <span class="rp-meta-label">Size</span>
                        <span class="rp-meta-val"><?= htmlspecialchars($row['size']) ?></span>
                    </div>
                </div>
                <a href="<?= htmlspecialchars($row['link']) ?>" class="rp-download-btn" target="_blank">
                    <i class="uil uil-import"></i> Download
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>
</div>

<script>
const fo=new IntersectionObserver(e=>e.forEach(en=>{if(en.isIntersecting){en.target.classList.add('visible');fo.unobserve(en.target);}}),{threshold:.08});
document.querySelectorAll('.fade-up').forEach(el=>fo.observe(el));
</script>

<style>
.rp-body{padding:24px 28px 40px;}
.fade-up{opacity:0;transform:translateY(18px);transition:opacity .5s ease var(--d,0s),transform .5s ease var(--d,0s);}
.fade-up.visible{opacity:1;transform:none;}

/* Hero */
.rp-hero{position:relative;overflow:hidden;border-radius:18px;padding:36px 32px;margin-bottom:16px;
    background:linear-gradient(125deg,#0c1a2e 0%,#132340 55%,#0c1a2e 100%);
    border:1px solid rgba(255,255,255,.08);
    display:flex;align-items:center;justify-content:space-between;gap:24px;}
.rp-hero-glow{position:absolute;top:-60px;left:-60px;width:360px;height:360px;border-radius:50%;
    background:radial-gradient(circle,rgba(59,130,246,.18) 0%,transparent 70%);pointer-events:none;}
.rp-hero-content{position:relative;z-index:1;}
.rp-hero-badge{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;
    letter-spacing:.8px;text-transform:uppercase;color:rgba(148,198,255,.8);
    background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.25);
    border-radius:100px;padding:4px 12px;margin-bottom:12px;}
.rp-hero-title{font-size:26px;font-weight:800;color:#f1f5f9;letter-spacing:-.4px;margin-bottom:6px;}
.rp-hero-sub{font-size:13px;color:rgba(226,232,240,.65);max-width:460px;line-height:1.6;}
.rp-hero-btn{position:relative;z-index:1;display:inline-flex;align-items:center;gap:7px;
    padding:11px 22px;border-radius:11px;flex-shrink:0;
    background:linear-gradient(135deg,#3b82f6,#2563eb);
    color:#fff;font-size:13px;font-weight:700;text-decoration:none;
    box-shadow:0 8px 24px rgba(37,99,235,.35);transition:all .2s;}
.rp-hero-btn:hover{box-shadow:0 12px 32px rgba(37,99,235,.45);transform:translateY(-1px);}

/* KPI */
.rp-kpi-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px;}
.rp-kpi{background:#fff;border:1px solid #f1f5f9;border-radius:16px;padding:20px 22px;
    box-shadow:0 1px 4px rgba(0,0,0,.04);transition:box-shadow .2s,transform .2s;}
.rp-kpi:hover{box-shadow:0 8px 24px rgba(0,0,0,.08);transform:translateY(-2px);}
.rp-kpi-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.rp-kpi-icon{width:42px;height:42px;border-radius:12px;background:var(--ab);color:var(--ac);
    display:flex;align-items:center;justify-content:center;font-size:18px;}
.rp-kpi-tag{font-size:11px;font-weight:600;padding:3px 9px;border-radius:100px;
    background:var(--ab);color:var(--at);}
.rp-kpi-num{font-size:32px;font-weight:800;color:var(--text-main);letter-spacing:-1px;line-height:1;}
.rp-kpi-label{font-size:13px;font-weight:600;color:var(--text-main);margin:4px 0 2px;}
.rp-kpi-desc{font-size:12px;color:var(--text-sub);}

/* Card */
.rp-card{background:#fff;border:1px solid #f1f5f9;border-radius:16px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.rp-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:20px;}
.rp-card-title{font-size:15px;font-weight:700;color:var(--text-main);}
.rp-card-sub{font-size:12px;color:var(--text-sub);margin-top:2px;}
.rp-count-badge{font-size:11.5px;font-weight:700;padding:4px 12px;border-radius:100px;
    background:#ccfbf1;color:#0f766e;border:1px solid #99f6e4;white-space:nowrap;}

/* Report rows */
.rp-report-list{display:flex;flex-direction:column;gap:12px;}
.rp-row{display:flex;align-items:center;gap:16px;padding:18px 20px;
    border:1px solid #f1f5f9;border-radius:14px;background:#fafbff;
    transition:all .25s;}
.rp-row:hover{border-color:#dbeafe;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,.06);transform:translateY(-1px);}
.rp-row-left{display:flex;align-items:center;gap:14px;flex:1;min-width:0;}
.rp-row-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.rp-row-title{font-size:14px;font-weight:700;color:var(--text-main);margin-bottom:3px;}
.rp-row-desc{font-size:12px;color:var(--text-sub);}
.rp-row-meta{display:flex;align-items:center;gap:24px;flex-shrink:0;}
.rp-meta-item{display:flex;flex-direction:column;gap:4px;align-items:flex-start;}
.rp-meta-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-sub);font-weight:600;}
.rp-meta-val{font-size:12.5px;color:var(--text-main);font-weight:500;}
.rp-type-pill{display:inline-block;font-size:11px;font-weight:700;padding:3px 10px;border-radius:100px;}
.rp-download-btn{display:inline-flex;align-items:center;gap:6px;flex-shrink:0;
    padding:9px 18px;border-radius:10px;font-size:12.5px;font-weight:700;
    background:#1d4ed8;color:#fff;text-decoration:none;
    transition:all .2s;box-shadow:0 2px 8px rgba(29,78,216,.2);}
.rp-download-btn:hover{background:#1e40af;box-shadow:0 4px 16px rgba(29,78,216,.3);transform:translateY(-1px);}

@media(max-width:1100px){
    .rp-kpi-grid{grid-template-columns:1fr 1fr;}
    .rp-row{flex-direction:column;align-items:flex-start;}
    .rp-row-meta{flex-wrap:wrap;gap:14px;}
    .rp-download-btn{width:100%;}
}
@media(max-width:640px){
    .rp-kpi-grid{grid-template-columns:1fr;}
    .rp-hero{flex-direction:column;align-items:flex-start;}
    .rp-body{padding:16px;}
}
</style>