<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header('Location: ../login.php'); exit(); }
require_once '../config/database.php';
require_once '../config/kp.php';
require_once '../config/archive.php';
gabaybatas_archive_ensure_schema($conn);

$caseColumns = [];
$caseColResult = $conn->query("SHOW COLUMNS FROM cases");
if ($caseColResult) {
    while ($col = $caseColResult->fetch_assoc()) {
        $caseColumns[] = $col['Field'];
    }
}

$hasCaseColumn = static function (string $column) use ($caseColumns): bool {
    return in_array($column, $caseColumns, true);
};

$rowIdColumn      = $hasCaseColumn('id') ? 'id' : ($hasCaseColumn('case_id') ? 'case_id' : ($caseColumns[0] ?? 'id'));
$displayIdColumn  = $hasCaseColumn('case_id') ? 'case_id' : ($hasCaseColumn('id') ? 'id' : ($caseColumns[0] ?? 'id'));
$displayIdExpression = $displayIdColumn;
if ($hasCaseColumn('case_id') && $hasCaseColumn('id')) {
    $displayIdExpression = "COALESCE(NULLIF(case_id, ''), CONCAT('C-', LPAD(id, 4, '0')))";
}
$residentColumn   = $hasCaseColumn('resident_name') ? 'resident_name' : ($hasCaseColumn('name') ? 'name' : null);
$typeColumn       = $hasCaseColumn('case_type') ? 'case_type' : ($hasCaseColumn('type') ? 'type' : null);
$statusColumn     = $hasCaseColumn('status') ? 'status' : null;
$kpStatusColumn   = $hasCaseColumn('kp_status') ? 'kp_status' : null;
$barangayColumn   = $hasCaseColumn('barangay') ? 'barangay' : null;
$dateColumn       = $hasCaseColumn('created_at') ? 'created_at' : ($hasCaseColumn('date_submitted') ? 'date_submitted' : ($hasCaseColumn('updated_at') ? 'updated_at' : null));

$activeCaseWhere = 'archived_at IS NULL';
$totalRes = $conn->query("SELECT COUNT(*) AS total FROM cases WHERE $activeCaseWhere");
$total = (int)($totalRes ? ($totalRes->fetch_assoc()['total'] ?? 0) : 0);

$pending = 0;
$progress = 0;
$resolved = 0;
if ($statusColumn) {
    $pendingRes = $conn->query("SELECT COUNT(*) AS total FROM cases WHERE $activeCaseWhere AND LOWER($statusColumn)='pending'");
    $progressRes = $conn->query("SELECT COUNT(*) AS total FROM cases WHERE $activeCaseWhere AND LOWER($statusColumn)='in progress'");
    $resolvedRes = $conn->query("SELECT COUNT(*) AS total FROM cases WHERE $activeCaseWhere AND LOWER($statusColumn)='resolved'");
    $pending = (int)($pendingRes ? ($pendingRes->fetch_assoc()['total'] ?? 0) : 0);
    $progress = (int)($progressRes ? ($progressRes->fetch_assoc()['total'] ?? 0) : 0);
    $resolved = (int)($resolvedRes ? ($resolvedRes->fetch_assoc()['total'] ?? 0) : 0);
}

$recentFields = [
    "$rowIdColumn AS row_id",
    "$displayIdExpression AS display_case_id",
    ($residentColumn ? "$residentColumn AS display_resident_name" : "'' AS display_resident_name"),
    ($typeColumn ? "$typeColumn AS case_type" : "'' AS case_type"),
    ($statusColumn ? "$statusColumn AS status" : "'' AS status"),
    ($kpStatusColumn ? "$kpStatusColumn AS kp_status" : "'' AS kp_status"),
    ($barangayColumn ? "$barangayColumn AS display_barangay" : "'' AS display_barangay"),
    ($dateColumn ? "$dateColumn AS display_date" : "NULL AS display_date"),
];
$recentOrderColumn = $dateColumn ?: $rowIdColumn;
$recentSql = 'SELECT ' . implode(', ', $recentFields) . " FROM cases WHERE $activeCaseWhere ORDER BY $recentOrderColumn DESC LIMIT 5";
$recent = $conn->query($recentSql);

$months = [];
$pendingData = [];
$progressData = [];
$resolvedData = [];
for ($i = 5; $i >= 0; $i--) {
    $monthDate = new DateTimeImmutable("first day of -$i month");
    $months[] = $monthDate->format('M');

    if ($dateColumn && $statusColumn) {
        $monthExpr = "MONTH($dateColumn) = '{$monthDate->format('m')}' AND YEAR($dateColumn) = '{$monthDate->format('Y')}'";
        $pendingRes = $conn->query("SELECT COUNT(*) AS t FROM cases WHERE $activeCaseWhere AND $monthExpr AND LOWER($statusColumn)='pending'");
        $progressRes = $conn->query("SELECT COUNT(*) AS t FROM cases WHERE $activeCaseWhere AND $monthExpr AND LOWER($statusColumn)='in progress'");
        $resolvedRes = $conn->query("SELECT COUNT(*) AS t FROM cases WHERE $activeCaseWhere AND $monthExpr AND LOWER($statusColumn)='resolved'");
        $pendingData[] = (int)($pendingRes ? ($pendingRes->fetch_assoc()['t'] ?? 0) : 0);
        $progressData[] = (int)($progressRes ? ($progressRes->fetch_assoc()['t'] ?? 0) : 0);
        $resolvedData[] = (int)($resolvedRes ? ($resolvedRes->fetch_assoc()['t'] ?? 0) : 0);
    } else {
        $pendingData[] = 0;
        $progressData[] = 0;
        $resolvedData[] = 0;
    }
}

$types = ['No Data'];
$typeData = [0];
if ($typeColumn) {
    $typeResult = $conn->query("SELECT $typeColumn AS type_name, COUNT(*) AS total FROM cases WHERE $activeCaseWhere AND $typeColumn IS NOT NULL AND $typeColumn <> '' GROUP BY $typeColumn ORDER BY total DESC LIMIT 5");
    if ($typeResult && $typeResult->num_rows > 0) {
        $types = [];
        $typeData = [];
        while ($row = $typeResult->fetch_assoc()) {
            $types[] = (string)($row['type_name'] ?? 'Unspecified');
            $typeData[] = (int)($row['total'] ?? 0);
        }
    }
}

$firstName = trim((string)explode(' ', (string)($_SESSION['name'] ?? 'Admin'))[0]);
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
<div class="topbar">
    <div class="topbar-left">
        <div class="topbar-title">Home Dashboard</div>
        <span class="topbar-badge">Overview</span>
    </div>
    <div class="topbar-right">
        <div class="notif-btn" title="Notifications"><i class="uil uil-bell"></i><span class="notif-dot"></span></div>
        <div class="topbar-sep"></div>
        <div class="user-pill">
            <div class="user-avatar"><i class="uil uil-user"></i></div>
            <div class="user-text">
                <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Administrator') ?></strong>
                <span>System Administrator</span>
            </div>
        </div>
    </div>
</div>

<div class="page-body db-body">

    <!-- Greeting -->
    <div class="db-greeting fade-up" style="--d:0s">
        <div>
            <h1 class="db-title">Good <?= (date('H')<12?'Morning':(date('H')<17?'Afternoon':'Evening')) ?>, <?= htmlspecialchars($firstName !== '' ? $firstName : 'Admin') ?></h1>
            <p class="db-sub">Here's what's happening in your system today — <?= date('l, F j, Y') ?></p>
        </div>
        <a href="all_cases.php" class="db-cta"><i class="uil uil-arrow-right"></i> Manage Cases</a>
    </div>

    <!-- KPI Cards -->
    <div class="db-kpi-grid fade-up" style="--d:.06s">
        <div class="db-kpi" style="--ac:#3b82f6;--ab:#eff6ff">
            <div class="db-kpi-top">
                <div class="db-kpi-icon"><i class="uil uil-folder-open"></i></div>
                <span class="db-kpi-trend neutral">All time</span>
            </div>
            <div class="db-kpi-num" data-count="<?= (int)$total ?>"><?= (int)$total ?></div>
            <div class="db-kpi-label">Total Cases</div>
            <div class="db-kpi-bar"><div class="db-kpi-fill" style="width:100%"></div></div>
        </div>
        <div class="db-kpi" style="--ac:#f59e0b;--ab:#fffbeb">
            <div class="db-kpi-top">
                <div class="db-kpi-icon"><i class="uil uil-clock-three"></i></div>
                <span class="db-kpi-trend warn">Awaiting</span>
            </div>
            <div class="db-kpi-num" data-count="<?= (int)$pending ?>"><?= (int)$pending ?></div>
            <div class="db-kpi-label">Pending Cases</div>
            <div class="db-kpi-bar"><div class="db-kpi-fill" data-w="<?= $total>0?round(($pending/$total)*100):0 ?>"></div></div>
        </div>
        <div class="db-kpi" style="--ac:#6366f1;--ab:#eef2ff">
            <div class="db-kpi-top">
                <div class="db-kpi-icon"><i class="uil uil-chart-line"></i></div>
                <span class="db-kpi-trend info">Active</span>
            </div>
            <div class="db-kpi-num" data-count="<?= (int)$progress ?>"><?= (int)$progress ?></div>
            <div class="db-kpi-label">In Progress</div>
            <div class="db-kpi-bar"><div class="db-kpi-fill" data-w="<?= $total>0?round(($progress/$total)*100):0 ?>"></div></div>
        </div>
        <div class="db-kpi" style="--ac:#10b981;--ab:#ecfdf5">
            <div class="db-kpi-top">
                <div class="db-kpi-icon"><i class="uil uil-check-circle"></i></div>
                <span class="db-kpi-trend good">Closed</span>
            </div>
            <div class="db-kpi-num" data-count="<?= (int)$resolved ?>"><?= (int)$resolved ?></div>
            <div class="db-kpi-label">Resolved Cases</div>
            <div class="db-kpi-bar"><div class="db-kpi-fill" data-w="<?= $total>0?round(($resolved/$total)*100):0 ?>"></div></div>
        </div>
    </div>

    <!-- Charts -->
    <div class="db-charts fade-up" style="--d:.12s">
        <div class="db-card db-chart-main">
            <div class="db-card-head">
                <div>
                    <div class="db-card-title">Case Trends</div>
                    <div class="db-card-sub">Last 6 months by status</div>
                </div>
                <div class="db-legend">
                    <span><span class="db-dot" style="background:#f59e0b"></span>Pending</span>
                    <span><span class="db-dot" style="background:#6366f1"></span>In Progress</span>
                    <span><span class="db-dot" style="background:#10b981"></span>Resolved</span>
                </div>
            </div>
            <div class="db-chart-panel"><canvas id="lineChart"></canvas></div>
        </div>
        <div class="db-card db-chart-side">
            <div class="db-card-head">
                <div>
                    <div class="db-card-title">Cases by Type</div>
                    <div class="db-card-sub">Case categories</div>
                </div>
            </div>
            <div class="db-chart-panel"><canvas id="barChart"></canvas></div>
        </div>
    </div>

    <!-- Recent Cases -->
    <div class="db-card fade-up" style="--d:.18s">
        <div class="db-card-head">
            <div>
                <div class="db-card-title">Recent Cases</div>
                <div class="db-card-sub">Latest 5 entries for Barangay Muñoz</div>
            </div>
            <a href="all_cases.php" class="db-view-all">View all <i class="uil uil-arrow-right"></i></a>
        </div>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead><tr><th>Case ID</th><th>Resident</th><th>Type</th><th>Status</th><th>Barangay</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                <?php if ($recent && $recent->num_rows > 0): ?>
                    <?php while ($row = $recent->fetch_assoc()):
                        $legacyStatus = trim((string)($row['status'] ?? ''));
                        $kpStatusValue = trim((string)($row['kp_status'] ?? ''));
                        $s = $kpStatusValue !== '' ? kp_status_label($kpStatusValue) : $legacyStatus;
                        $statusColor = $kpStatusValue !== '' ? kp_status_color($kpStatusValue) : ($legacyStatus === 'Resolved' ? '#15803d' : ($legacyStatus === 'Pending' ? '#92400e' : '#4338ca'));
                        $sLower = strtolower($legacyStatus);
                        $sc = $sLower === 'pending' ? 'pending' : ($sLower === 'resolved' ? 'resolved' : 'progress');
                        $displayDateRaw = (string)($row['display_date'] ?? '');
                        $displayTs = strtotime($displayDateRaw);
                        $displayDate = $displayTs !== false ? date('M d, Y h:i A', $displayTs) : ($displayDateRaw !== '' ? $displayDateRaw : '-');
                        $residentName = trim((string)($row['display_resident_name'] ?? '-'));
                    ?>
                    <tr>
                        <?php
                        $caseIdValue = trim((string)($row['display_case_id'] ?? ''));
                        if ($caseIdValue === '') {
                            $caseIdValue = 'C-' . str_pad((string)($row['row_id'] ?? ''), 4, '0', STR_PAD_LEFT);
                        }
                        ?>
                        <td><span class="db-case-id"><?= htmlspecialchars($caseIdValue !== '' ? $caseIdValue : '-') ?></span></td>
                        <td>
                            <div class="db-name-cell">
                                <div class="db-avatar-sm"><?= strtoupper(substr($residentName !== '' ? $residentName : '?', 0, 1)) ?></div>
                                <?= htmlspecialchars($residentName !== '' ? $residentName : '-') ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars((string)($row['case_type'] ?? '-')) ?></td>
                        <td><span class="db-badge <?= $sc ?>" style="color: <?= htmlspecialchars($statusColor) ?>;"><?= htmlspecialchars($s !== '' ? $s : '-') ?></span></td>
                        <td><?= htmlspecialchars((string)($row['display_barangay'] ?? '-')) ?></td>
                        <td class="db-date"><?= htmlspecialchars($displayDate) ?></td>
                        <td><a href="view_case.php?id=<?= urlencode((string)($row['row_id'] ?? '')) ?>" class="db-action-btn"><i class="uil uil-eye"></i> View</a></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="db-date">No cases found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /page-body -->
</div><!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const FF="'Source Sans 3',sans-serif", GC='rgba(0,0,0,.04)';
const chartMotion = {
    animation: { duration: 1600, easing: 'easeOutQuart' },
    interaction: { mode: 'index', intersect: false }
};

// Animate KPI numbers
function easeOut(t){return 1-Math.pow(2,-10*t);}
document.querySelectorAll('.db-kpi-num[data-count]').forEach(el=>{
    const target=parseInt(el.dataset.count);
    const dur=1500,start=performance.now();
    const step=now=>{
        const p=Math.min((now-start)/dur,1);
        el.textContent=Math.round(easeOut(p)*target);
        if(p<1)requestAnimationFrame(step);
    };
    setTimeout(()=>requestAnimationFrame(step),200);
});

// Animate progress bars
document.querySelectorAll('.db-kpi-fill[data-w]').forEach(b=>{
    setTimeout(()=>{ b.style.width=b.dataset.w+'%'; },400);
});

// Fade up
const fo=new IntersectionObserver(e=>e.forEach(en=>{if(en.isIntersecting){en.target.classList.add('visible');fo.unobserve(en.target);}}),{threshold:.1});
document.querySelectorAll('.fade-up').forEach(el=>fo.observe(el));

// Line Chart
new Chart(document.getElementById('lineChart'),{
    type:'line',
    data:{
        labels:<?= json_encode($months) ?>,
        datasets:[
            {label:'Pending',data:<?= json_encode($pendingData) ?>,borderColor:'#f59e0b',
             backgroundColor:ctx=>{const g=ctx.chart.ctx.createLinearGradient(0,0,0,230);g.addColorStop(0,'rgba(245,158,11,.15)');g.addColorStop(1,'rgba(245,158,11,0)');return g;},
             tension:.42,fill:true,pointBackgroundColor:'#f59e0b',pointBorderColor:'#fff',pointBorderWidth:2,pointRadius:5,pointHoverRadius:7,borderWidth:2.5},
            {label:'In Progress',data:<?= json_encode($progressData) ?>,borderColor:'#6366f1',
             backgroundColor:ctx=>{const g=ctx.chart.ctx.createLinearGradient(0,0,0,230);g.addColorStop(0,'rgba(99,102,241,.12)');g.addColorStop(1,'rgba(99,102,241,0)');return g;},
             tension:.42,fill:true,pointBackgroundColor:'#6366f1',pointBorderColor:'#fff',pointBorderWidth:2,pointRadius:5,pointHoverRadius:7,borderWidth:2.5},
            {label:'Resolved',data:<?= json_encode($resolvedData) ?>,borderColor:'#10b981',
             backgroundColor:ctx=>{const g=ctx.chart.ctx.createLinearGradient(0,0,0,230);g.addColorStop(0,'rgba(16,185,129,.12)');g.addColorStop(1,'rgba(16,185,129,0)');return g;},
             tension:.42,fill:true,pointBackgroundColor:'#10b981',pointBorderColor:'#fff',pointBorderWidth:2,pointRadius:5,pointHoverRadius:7,borderWidth:2.5}
        ]
    },
    options: {
        ...chartMotion,
        responsive: true,
        maintainAspectRatio: false,
        plugins:{legend:{display:false},tooltip:{mode:'index',intersect:false,backgroundColor:'#fff',titleColor:'#111',bodyColor:'#555',borderColor:'#e5e7eb',borderWidth:1,padding:12,cornerRadius:10,titleFont:{family:FF,weight:'600',size:12},bodyFont:{family:FF,size:12}}},
        scales:{
            x:{grid:{color:GC},ticks:{font:{family:FF,size:11},color:'#94a3b8'},border:{display:false}},
            y:{grid:{color:GC},ticks:{font:{family:FF,size:11},color:'#94a3b8'},border:{display:false},beginAtZero:true}
        }
    }
});

// Bar Chart
new Chart(document.getElementById('barChart'),{
    type:'bar',
    data:{
        labels:<?= json_encode($types) ?>,
        datasets:[{
            label:'Cases',
            data:<?= json_encode($typeData) ?>,
            backgroundColor:['rgba(59,130,246,.85)','rgba(99,102,241,.85)','rgba(245,158,11,.85)','rgba(16,185,129,.85)','rgba(239,68,68,.85)'],
            borderRadius:{topLeft:7,topRight:7},borderSkipped:false,
            hoverBackgroundColor:['#2563eb','#4f46e5','#d97706','#059669','#dc2626']
        }]
    },
    options: {
        ...chartMotion,
        responsive: true,
        maintainAspectRatio: false,
        plugins:{legend:{display:false},tooltip:{backgroundColor:'#fff',titleColor:'#111',bodyColor:'#555',borderColor:'#e5e7eb',borderWidth:1,padding:12,cornerRadius:10,titleFont:{family:FF,size:12},bodyFont:{family:FF,size:12},callbacks:{label:ctx=>` ${ctx.parsed.y} cases`}}},
        scales:{
            x:{grid:{display:false},ticks:{font:{family:FF,size:11},color:'#94a3b8'},border:{display:false}},
            y:{grid:{color:GC},ticks:{font:{family:FF,size:11},color:'#94a3b8'},border:{display:false},beginAtZero:true}
        }
    }
});
</script>

<style>
.db-body{padding:24px 28px 40px;}
.fade-up{opacity:0;transform:translateY(20px);transition:opacity .5s ease var(--d,0s),transform .5s ease var(--d,0s);}
.fade-up.visible{opacity:1;transform:none;}

.db-greeting{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;}
.db-title{font-size:20px;font-weight:700;color:var(--text-main);}
.db-sub{font-size:12.5px;color:var(--text-sub);margin-top:3px;}
.db-cta{display:flex;align-items:center;gap:6px;background:#3b82f6;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;transition:background .2s;}
.db-cta:hover{background:#2563eb;}

/* KPI */
.db-kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:14px;}
.db-kpi{background:#fff;border:1px solid #f1f5f9;border-radius:16px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.04);transition:box-shadow .2s,transform .2s;}
.db-kpi:hover{box-shadow:0 8px 24px rgba(0,0,0,.08);transform:translateY(-2px);}
.db-kpi-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.db-kpi-icon{width:40px;height:40px;border-radius:11px;background:var(--ab);color:var(--ac);display:flex;align-items:center;justify-content:center;font-size:18px;}
.db-kpi-trend{font-size:11px;font-weight:600;border-radius:100px;padding:3px 9px;}
.db-kpi-trend.neutral{background:#f1f5f9;color:#64748b;}
.db-kpi-trend.warn{background:#fffbeb;color:#92400e;}
.db-kpi-trend.info{background:#eef2ff;color:#4338ca;}
.db-kpi-trend.good{background:#dcfce7;color:#15803d;}
.db-kpi-num{font-size:30px;font-weight:800;color:var(--text-main);line-height:1;letter-spacing:-1px;}
.db-kpi-label{font-size:12px;color:var(--text-sub);margin:4px 0 12px;}
.db-kpi-bar{height:4px;background:#f1f5f9;border-radius:99px;overflow:hidden;}
.db-kpi-fill{height:100%;background:var(--ac);border-radius:99px;width:0;transition:width 1.2s cubic-bezier(.22,1,.36,1);}

/* Charts */
.db-charts{display:grid;grid-template-columns:1.55fr 1fr;gap:14px;margin-bottom:14px;}
.db-card{background:#fff;border:1px solid #f1f5f9;border-radius:16px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.db-card-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;gap:12px;}
.db-card-title{font-size:14px;font-weight:700;color:var(--text-main);}
.db-card-sub{font-size:12px;color:var(--text-sub);margin-top:2px;}
.db-chart-panel{position:relative;height:260px;border-radius:14px;background:linear-gradient(180deg, rgba(255,255,255,.72), rgba(248,250,252,.96));border:1px solid #eef2f7;overflow:hidden;padding:10px;}
.db-chart-panel canvas{width:100% !important;height:100% !important;display:block;}
.db-legend{display:flex;gap:14px;font-size:12px;color:var(--text-sub);flex-wrap:wrap;align-items:center;}
.db-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px;}
.db-view-all{font-size:12.5px;font-weight:600;color:#3b82f6;text-decoration:none;display:flex;align-items:center;gap:4px;white-space:nowrap;}

/* Table */
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:13px;}
.db-table thead tr{border-bottom:1px solid #e5e7eb;}
.db-table th{padding:12px 16px;text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:var(--text-sub);font-weight:600;white-space:nowrap;}
.db-table td{padding:14px 16px;color:var(--text-main);border-bottom:1px solid #f3f4f6;text-align:center;}
.db-table tbody tr:last-child td{border-bottom:none;}
.db-table tbody tr:hover td{background:#fafbff;}
.db-case-id{font-family:monospace;font-size:12px;background:#f1f5f9;color:#475569;padding:3px 8px;border-radius:6px;}
.db-name-cell{display:flex;align-items:center;justify-content:center;gap:8px;}
.db-avatar-sm{width:30px;height:30px;border-radius:50%;background:#e0e7ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;}
.db-date{font-size:12px;color:var(--text-sub);}
.db-badge{display:inline-block;font-size:11px;font-weight:600;padding:3px 10px;border-radius:100px;}
.db-badge.pending{background:#fffbeb;color:#92400e;}
.db-badge.progress{background:#eef2ff;color:#4338ca;}
.db-badge.resolved{background:#dcfce7;color:#15803d;}
.db-action-btn{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:600;color:#3b82f6;text-decoration:none;padding:5px 12px;border-radius:8px;border:1px solid #dbeafe;background:#eff6ff;transition:all .2s;}
.db-action-btn:hover{background:#dbeafe;}

@media(max-width:1100px){.db-kpi-grid{grid-template-columns:repeat(2,1fr);}.db-charts{grid-template-columns:1fr;}}
@media(max-width:640px){.db-kpi-grid{grid-template-columns:1fr 1fr;}.db-body{padding:16px;}}
</style>