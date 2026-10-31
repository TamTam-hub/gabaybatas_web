<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header('Location: ../login.php'); exit(); }
require_once '../config/database.php';
require_once '../config/kp.php';
require_once '../config/archive.php';
require_once __DIR__ . '/../includes/flexible_report.php';
gabaybatas_archive_ensure_schema($conn);

// Cases are counted by KP status group (same groups as Flexible Reports);
// archived cases are never counted.
$groups = gabaybatas_flex_groups();
$groupOf = static function (string $status) use ($groups): string {
    foreach ($groups as $key => $group) {
        if (in_array($status, $group['statuses'], true)) {
            return $key;
        }
    }
    return 'review';
};

$counts = array_fill_keys(array_keys($groups), 0);
$total = 0;
$res = $conn->query("SELECT COALESCE(NULLIF(kp_status, ''), 'filed') AS st, COUNT(*) AS n FROM cases WHERE archived_at IS NULL GROUP BY st");
foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $r) {
    $counts[$groupOf($r['st'])] += (int)$r['n'];
    $total += (int)$r['n'];
}

// Cases filed in one month (picker: last 12 months), one bar per status group
$monthOptions = [];
for ($i = 0; $i < 12; $i++) {
    $m = new DateTimeImmutable("first day of -{$i} month");
    $monthOptions[$m->format('Y-m')] = $m->format('F Y');
}
$selectedMonth = (string)($_GET['month'] ?? '');
if (!isset($monthOptions[$selectedMonth])) {
    $selectedMonth = array_key_first($monthOptions);
}
$monthCounts = array_fill_keys(array_keys($groups), 0);
$stmt = $conn->prepare(
    "SELECT COALESCE(NULLIF(kp_status, ''), 'filed') AS st, COUNT(*) AS n
     FROM cases WHERE archived_at IS NULL AND DATE_FORMAT(date_submitted, '%Y-%m') = ? GROUP BY st"
);
$stmt->bind_param('s', $selectedMonth);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $monthCounts[$groupOf($r['st'])] += (int)$r['n'];
}
$stmt->close();
$monthTotal = array_sum($monthCounts);

// Cases by type
$types = [];
$res = $conn->query("SELECT case_type, COUNT(*) AS n FROM cases WHERE archived_at IS NULL AND case_type IS NOT NULL AND case_type <> '' GROUP BY case_type ORDER BY n DESC, case_type LIMIT 8");
foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $r) {
    $types[$r['case_type']] = (int)$r['n'];
}

// Recent cases
$recent = $conn->query(
    "SELECT id, case_id, name, complained_person, case_type, COALESCE(NULLIF(kp_status, ''), 'filed') AS kp_status, barangay, date_submitted
     FROM cases WHERE archived_at IS NULL ORDER BY date_submitted DESC, id DESC LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);

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
        <div class="notif-btn" title="Notifications"><i class="uil uil-bell"></i></div>
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
        <div class="db-greeting-main">
            <span class="db-seals">
                <span class="db-seal"><img src="../images/seal_city_catbalogan.png" alt=""></span>
                <span class="db-seal"><img src="../images/seal_barangay_munoz.png" alt=""></span>
            </span>
            <div>
                <h1 class="db-title">Good <?= (date('H')<12?'Morning':(date('H')<17?'Afternoon':'Evening')) ?>, <?= htmlspecialchars($firstName !== '' ? $firstName : 'Admin') ?></h1>
                <p class="db-sub">Katarungang Pambarangay cases of Barangay <?= htmlspecialchars(KP_BARANGAY_NAME) ?> — <?= date('l, F j, Y') ?></p>
            </div>
        </div>
        <a href="all_cases.php" class="db-cta"><i class="uil uil-folder-open"></i> Manage Cases <i class="uil uil-arrow-right"></i></a>
    </div>

    <!-- Case summary (same cards as the barangay Home page and Flexible Reports) -->
    <div class="fade-up" style="--d:.06s">
        <?php gabaybatas_summary_cards($total, $counts, 'All barangays, all periods'); ?>
    </div>

    <!-- Charts -->
    <div class="db-charts fade-up" style="--d:.14s">
        <div class="db-card db-chart-main">
            <div class="db-card-head">
                <div class="db-month-head">
                    <div>
                        <div class="db-card-title">Cases Filed in <?= htmlspecialchars($monthOptions[$selectedMonth]) ?></div>
                        <div class="db-card-sub"><?= $monthTotal ?> case<?= $monthTotal === 1 ? '' : 's' ?> filed this month, by current status</div>
                    </div>
                    <form method="GET" class="db-month-form">
                        <label for="monthPicker" class="db-card-sub">Month</label>
                        <select name="month" id="monthPicker" onchange="this.form.submit()">
                            <?php foreach ($monthOptions as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $value === $selectedMonth ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <div class="db-chart-panel"><canvas id="monthChart"></canvas></div>
        </div>
        <div class="db-card db-chart-side">
            <div class="db-card-head">
                <div>
                    <div class="db-card-title">Cases by Type</div>
                    <div class="db-card-sub">Most common complaints</div>
                </div>
            </div>
            <div class="db-chart-panel"><canvas id="typeChart"></canvas></div>
        </div>
    </div>

    <!-- Recent Cases -->
    <div class="db-card fade-up" style="--d:.18s">
        <div class="db-card-head">
            <div>
                <div class="db-card-title">Recent Cases</div>
                <div class="db-card-sub">Latest 5 complaints filed</div>
            </div>
            <a href="all_cases.php" class="db-view-all">View all <i class="uil uil-arrow-right"></i></a>
        </div>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead><tr><th>Case No.</th><th>Complainant</th><th>Respondent</th><th>Type</th><th>Status</th><th>Date Filed</th><th>Action</th></tr></thead>
                <tbody>
                <?php if ($recent): ?>
                    <?php foreach ($recent as $row):
                        $color = kp_status_color($row['kp_status']);
                        $resident = trim((string)$row['name']);
                        $ts = strtotime((string)$row['date_submitted']);
                    ?>
                    <tr>
                        <td><span class="db-case-id"><?= htmlspecialchars($row['case_id'] ?: ('C-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT))) ?></span></td>
                        <td>
                            <div class="db-name-cell">
                                <div class="db-avatar-sm"><?= htmlspecialchars(mb_strtoupper(mb_substr($resident !== '' ? $resident : '?', 0, 1))) ?></div>
                                <?= htmlspecialchars($resident !== '' ? $resident : '—') ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars((string)($row['complained_person'] ?: '—')) ?></td>
                        <td><?= htmlspecialchars((string)($row['case_type'] ?: '—')) ?></td>
                        <td><span class="db-badge" style="background:<?= $color ?>18;color:<?= $color ?>;border-color:<?= $color ?>40;"><?= htmlspecialchars(kp_status_label($row['kp_status'])) ?></span></td>
                        <td class="db-date"><?php if ($ts): ?><strong><?= date('M d, Y', $ts) ?></strong><small><?= date('h:i A', $ts) ?></small><?php else: ?>—<?php endif; ?></td>
                        <td><a href="view_case.php?id=<?= (int)$row['id'] ?>" class="db-action-btn"><i class="uil uil-eye"></i> View</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="db-date">No cases yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div><!-- /page-body -->
</div><!-- /main -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const FF = "'Source Sans 3',sans-serif", GC = 'rgba(0,0,0,.04)';
const tooltip = {backgroundColor:'#fff',titleColor:'#111',bodyColor:'#555',borderColor:'#e5e7eb',borderWidth:1,padding:12,cornerRadius:10,titleFont:{family:FF,size:12},bodyFont:{family:FF,size:12}};
const axis = {ticks:{font:{family:FF,size:11},color:'#94a3b8'},border:{display:false}};

const fo=new IntersectionObserver(e=>e.forEach(en=>{if(en.isIntersecting){en.target.classList.add('visible');fo.unobserve(en.target);}}),{threshold:.1});
document.querySelectorAll('.fade-up').forEach(el=>fo.observe(el));

// Cases filed in the selected month: one bar per status group
new Chart(document.getElementById('monthChart'), {
    type: 'bar',
    data: {
        // Long labels go on two lines ("Settled" / "Awarded") so they sit flat.
        labels: <?= json_encode(array_values(array_map(static fn($g) => explode(' / ', $g['label'], 2), $groups))) ?>,
        datasets: [{
            label: 'Cases',
            data: <?= json_encode(array_values($monthCounts)) ?>,
            backgroundColor: <?= json_encode(array_values(array_map(static fn($g) => $g['color'], $groups))) ?>,
            borderRadius: 8,
            maxBarThickness: 64
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, animation: {duration: 1200, easing: 'easeOutQuart'},
        plugins: {legend: {display: false}, tooltip},
        scales: {
            x: {...axis, grid: {display: false}, ticks: {...axis.ticks, maxRotation: 0}},
            y: {...axis, grid: {color: GC}, beginAtZero: true, ticks: {...axis.ticks, precision: 0}}
        }
    }
});

// Cases by type, one colour per type
const typeColors = <?= json_encode(array_map('gabaybatas_case_type_color', array_keys($types))) ?>;
new Chart(document.getElementById('typeChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($types ? array_keys($types) : ['No cases yet']) ?>,
        datasets: [{label: 'Cases', data: <?= json_encode($types ? array_values($types) : [0]) ?>,
            backgroundColor: typeColors, borderRadius: 6, maxBarThickness: 22}]
    },
    options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: {duration: 1200, easing: 'easeOutQuart'},
        plugins: {legend: {display: false}, tooltip},
        scales: {
            x: {...axis, grid: {color: GC}, beginAtZero: true, ticks: {...axis.ticks, precision: 0}},
            y: {...axis, grid: {display: false}}
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
.db-kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:14px;}
.db-kpi{background:#fff;border:1px solid #f1f5f9;border-radius:16px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.04);transition:box-shadow .2s,transform .2s;}
.db-kpi:hover{box-shadow:0 8px 24px rgba(0,0,0,.08);transform:translateY(-2px);}
.db-kpi-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.db-kpi-icon{width:40px;height:40px;border-radius:11px;background:var(--ab);color:var(--ac);display:flex;align-items:center;justify-content:center;font-size:18px;}
.db-kpi-trend{font-size:11px;font-weight:600;border-radius:100px;padding:3px 9px;}
.db-kpi-trend.neutral{background:#f1f5f9;color:#64748b;}
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
.db-legend{display:flex;gap:12px;font-size:11.5px;color:var(--text-sub);flex-wrap:wrap;align-items:center;}
.db-chart-main .db-card-head{display:block;}
.db-month-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;}
.db-month-form{display:flex;align-items:center;gap:8px;}
.db-month-form select{border:1px solid #dbe3ef;border-radius:8px;padding:7px 10px;font-size:12.5px;font-family:inherit;color:#1f2937;background:#fff;}
/* Summary cards follow the admin font */
.gb-sum-row{--gb-sum-font:'Source Sans 3',sans-serif;--gb-sum-weight:800;}
.gb-sum-value{letter-spacing:-1px;}
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
.db-badge{display:inline-block;font-size:11px;font-weight:600;padding:3px 10px;border-radius:100px;white-space:nowrap;}
.db-action-btn{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:600;color:#3b82f6;text-decoration:none;padding:5px 12px;border-radius:8px;border:1px solid #dbeafe;background:#eff6ff;transition:background .15s;}
.db-action-btn:hover{background:#dbeafe;}

/* ── UI refresh (admin theme) ── */
.gb-admin-ui .db-body{padding:26px 32px 44px;}
.gb-admin-ui .db-greeting{position:relative;overflow:hidden;gap:18px;padding:22px 26px;border-radius:16px;background:radial-gradient(circle at 0% 0%,rgba(255,255,255,.10),transparent 45%),linear-gradient(135deg,#1a3a6b 0%,#0e2242 70%,#0b1a33 100%);box-shadow:0 14px 34px rgba(14,34,66,.22);margin-bottom:18px;}
.gb-admin-ui .db-greeting::after{content:'';position:absolute;right:-60px;top:-80px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(201,162,39,.18),transparent 65%);pointer-events:none;}
.db-greeting-main{display:flex;align-items:center;gap:16px;position:relative;z-index:1;}
.db-seals{display:flex;gap:6px;flex-shrink:0;}
.db-seal{width:52px;height:52px;border-radius:50%;background:#fff;padding:3px;box-shadow:0 0 0 3px rgba(255,255,255,.16),0 8px 18px rgba(0,0,0,.25);}
.db-seal img{width:100%;height:100%;object-fit:contain;border-radius:50%;}
.gb-admin-ui .db-title{font-family:'Source Serif 4',serif;font-size:22px;color:#fff;}
.gb-admin-ui .db-sub{font-size:13.5px;color:rgba(255,255,255,.78);}
.gb-admin-ui .db-cta{position:relative;z-index:1;background:#fff;color:#0e2242;padding:11px 18px;font-size:14px;font-weight:700;white-space:nowrap;box-shadow:0 8px 18px rgba(0,0,0,.18);}
.gb-admin-ui .db-cta:hover{background:#eef3fb;}
.gb-admin-ui .gb-sum-card{border-radius:14px;border-right-color:#e1e7f0;border-bottom-color:#e1e7f0;border-left-color:#e1e7f0;box-shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px rgba(15,23,42,.045);}
.gb-admin-ui .gb-sum-label{color:#4f5f75;}
.gb-admin-ui .gb-sum-sub{color:#6f7f96;}
.gb-admin-ui .db-card{border-radius:14px;border-color:#e1e7f0;box-shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px rgba(15,23,42,.045);}
.gb-admin-ui .db-card-title{font-size:15px;color:#14213a;}
.gb-admin-ui .db-card-sub{font-size:12.5px;color:#6f7f96;}
.gb-admin-ui .db-month-form select{min-height:38px;border:1.5px solid #d6deea;border-radius:10px;font-size:13px;}
.gb-admin-ui .db-chart-panel{border-color:#edf1f7;background:#fbfcfe;}
.gb-admin-ui .db-view-all{color:#1a3a6b;background:#eef3fb;padding:8px 13px;border-radius:10px;font-size:13px;font-weight:700;}
.gb-admin-ui .db-view-all:hover{background:#dfe8f7;}
.gb-admin-ui .db-table th{text-align:left;background:#f5f7fb;color:#5b6b82;font-size:11.5px;font-weight:700;padding:13px 16px;}
.gb-admin-ui .db-table td{text-align:left;padding:15px 16px;font-size:14px;border-bottom-color:#edf1f7;}
.gb-admin-ui .db-table tbody tr:hover td{background:#f6f9ff;}
.gb-admin-ui .db-name-cell{justify-content:flex-start;}
.gb-admin-ui .db-avatar-sm{background:#eef3fb;color:#1a3a6b;}
.gb-admin-ui .db-case-id{font-size:12.5px;font-weight:600;white-space:nowrap;}
.gb-admin-ui .db-badge{display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:4px 11px;border:1px solid transparent;}
.gb-admin-ui .db-badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;}
.gb-admin-ui .db-date strong{display:block;font-size:13.5px;font-weight:600;color:#14213a;white-space:nowrap;}
.gb-admin-ui .db-date small{display:block;font-size:12.5px;color:#6f7f96;}
.gb-admin-ui .db-action-btn{color:#1a3a6b;background:#fff;border:1.5px solid #c9d6ea;border-radius:10px;padding:7px 13px;font-size:13px;}
.gb-admin-ui .db-action-btn:hover{background:#f6f9ff;border-color:#1a3a6b;}
@media(max-width:760px){.gb-admin-ui .db-greeting{flex-direction:column;align-items:flex-start;}}
@media(max-width:1200px){.db-kpi-grid{grid-template-columns:repeat(3,1fr);}}
@media(max-width:1100px){.db-charts{grid-template-columns:1fr;}}
@media(max-width:640px){.db-kpi-grid{grid-template-columns:1fr 1fr;}.db-body{padding:16px;}}
</style>
