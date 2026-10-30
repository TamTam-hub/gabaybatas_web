<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/archive.php';
require_once '../config/case_types.php';
require_once '../includes/analytics_metrics.php';
gabaybatas_archive_ensure_schema($conn);

// All numbers come from includes/analytics_metrics.php (shared with the live refresh).
$months = (int)($_GET['months'] ?? 6) === 12 ? 12 : 6;
$m = gabaybatas_analytics_metrics($conn, $months);
$kpi = $m['kpi'];
$usage = $m['usage'];
$filedChange = $kpi['filedThisMonth'] - $kpi['filedLastMonth'];
$outcomeTotal = array_sum(array_column($m['outcomes'], 'value'));
?>
<?php include('../includes/header_admin.php'); ?>
<?php include('../includes/sidebar_admin.php'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left"><div class="topbar-title">Analytics</div></div>
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

    <div class="page-body an-body">
        <div class="an-meta">
            <div>
                <p class="an-sub">How Katarungang Pambarangay cases in Barangay Muñoz are moving. Archived records are not counted.</p>
                <div class="an-live"><span class="an-live-dot"></span> Live · updated <span id="anUpdated"><?= date('h:i A') ?></span></div>
            </div>
            <div class="an-date"><?= date('l, F j, Y') ?></div>
        </div>

        <!-- KPIs -->
        <div class="an-kpi-grid">
            <div class="an-kpi" style="--c:#2563eb;--cb:#eff6ff">
                <div class="an-kpi-icon"><i class="uil uil-file-plus-alt"></i></div>
                <div>
                    <div class="an-kpi-label">Filed this month</div>
                    <div class="an-kpi-val" data-kpi="filedThisMonth"><?= $kpi['filedThisMonth'] ?></div>
                    <div class="an-kpi-sub" data-kpi="filedChange"><?= $filedChange === 0 ? 'Same as last month' : ($filedChange > 0 ? '▲ ' . $filedChange : '▼ ' . abs($filedChange)) . ' vs last month (' . $kpi['filedLastMonth'] . ')' ?></div>
                </div>
            </div>
            <div class="an-kpi" style="--c:#059669;--cb:#ecfdf5">
                <div class="an-kpi-icon"><i class="uil uil-file-check-alt"></i></div>
                <div>
                    <div class="an-kpi-label">Settled this month</div>
                    <div class="an-kpi-val" data-kpi="settledThisMonth"><?= $kpi['settledThisMonth'] ?></div>
                    <div class="an-kpi-sub" data-kpi="settledSub"><?= $kpi['settledAllTime'] ?> settled or awarded in total</div>
                </div>
            </div>
            <div class="an-kpi" style="--c:#7c3aed;--cb:#f5f3ff">
                <div class="an-kpi-icon"><i class="uil uil-calendar-alt"></i></div>
                <div>
                    <div class="an-kpi-label">Hearings this week</div>
                    <div class="an-kpi-val" data-kpi="hearingsThisWeek"><?= $kpi['hearingsThisWeek'] ?></div>
                    <div class="an-kpi-sub" data-kpi="hearingSub"><?= $kpi['nextHearing'] ? 'Next: ' . htmlspecialchars($kpi['nextHearing']['case'] . ' · ' . $kpi['nextHearing']['stage'] . ', ' . $kpi['nextHearing']['when']) : 'None scheduled in the next 7 days' ?></div>
                </div>
            </div>
            <div class="an-kpi" style="--c:#d97706;--cb:#fffbeb">
                <div class="an-kpi-icon"><i class="uil uil-shield-check"></i></div>
                <div>
                    <div class="an-kpi-label">Verified residents</div>
                    <div class="an-kpi-val" data-kpi="verifiedResidents"><?= $usage['verifiedResidents'] ?><span> / <?= $usage['residents'] ?></span></div>
                    <div class="an-kpi-sub" data-kpi="verifySub"><?= $usage['pendingVerifications'] > 0 ? $usage['pendingVerifications'] . ' verification' . ($usage['pendingVerifications'] === 1 ? '' : 's') . ' waiting for review' : 'No verifications waiting' ?></div>
                </div>
            </div>
        </div>

        <!-- Trend + case types -->
        <div class="an-row an-row-main">
            <section class="an-card">
                <div class="an-card-head">
                    <div>
                        <div class="an-card-title">Cases Filed per Month</div>
                        <div class="an-card-sub">By current status group, last <?= $months ?> months</div>
                    </div>
                    <div class="an-switch" role="group" aria-label="Range">
                        <a href="?months=6" class="<?= $months === 6 ? 'active' : '' ?>">6 months</a>
                        <a href="?months=12" class="<?= $months === 12 ? 'active' : '' ?>">12 months</a>
                    </div>
                </div>
                <div class="an-legend">
                    <?php foreach ($m['trend']['series'] as $s): ?>
                    <span><i style="background:<?= $s['color'] ?>"></i><?= htmlspecialchars($s['label']) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="an-chart"><canvas id="trendChart"></canvas></div>
            </section>

            <section class="an-card">
                <div class="an-card-head">
                    <div>
                        <div class="an-card-title">Case Types</div>
                        <div class="an-card-sub">Share of all active cases</div>
                    </div>
                </div>
                <div class="an-donut"><canvas id="typeChart"></canvas></div>
                <div class="an-type-list" id="typeList">
                    <?php foreach ($m['types']['labels'] as $i => $label): ?>
                    <div><i style="background:<?= $m['types']['colors'][$i] ?>"></i><span><?= htmlspecialchars($label) ?></span><strong><?= $m['types']['values'][$i] ?></strong></div>
                    <?php endforeach; ?>
                    <?php if (!$m['types']['labels']): ?><p class="an-empty">No cases yet.</p><?php endif; ?>
                </div>
            </section>
        </div>

        <!-- Stage, outcomes, usage -->
        <div class="an-row an-row-three">
            <section class="an-card">
                <div class="an-card-head">
                    <div>
                        <div class="an-card-title">Where Active Cases Are</div>
                        <div class="an-card-sub">Cases still moving through KP, by stage</div>
                    </div>
                </div>
                <div class="an-chart an-chart-sm"><canvas id="stageChart"></canvas></div>
            </section>

            <section class="an-card">
                <div class="an-card-head">
                    <div>
                        <div class="an-card-title">Outcomes</div>
                        <div class="an-card-sub"><span data-kpi="outcomeTotal"><?= $outcomeTotal ?></span> settled or concluded case<?= $outcomeTotal === 1 ? '' : 's' ?></div>
                    </div>
                </div>
                <div class="an-outcomes" id="outcomeList">
                    <?php foreach ($m['outcomes'] as $o): $pct = $outcomeTotal > 0 ? round($o['value'] / $outcomeTotal * 100) : 0; ?>
                    <div class="an-outcome" data-outcome="<?= $o['key'] ?>">
                        <div class="an-outcome-row"><span><i style="background:<?= $o['color'] ?>"></i><?= htmlspecialchars($o['label']) ?></span><strong><?= $o['value'] ?></strong></div>
                        <div class="an-track"><div style="width:<?= $pct ?>%;background:<?= $o['color'] ?>"></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="an-card">
                <div class="an-card-head">
                    <div>
                        <div class="an-card-title">System Usage</div>
                        <div class="an-card-sub">Today and overall</div>
                    </div>
                </div>
                <div class="an-usage">
                    <?php foreach ([
                        ['residents', 'uil-users-alt', '#2563eb', 'Registered residents'],
                        ['verifiedResidents', 'uil-shield-check', '#059669', 'Verified residents'],
                        ['pendingVerifications', 'uil-hourglass', '#d97706', 'Verifications waiting'],
                        ['loginsToday', 'uil-signin', '#7c3aed', 'Logins today'],
                        ['caseActionsToday', 'uil-history', '#0d9488', 'Case actions today'],
                    ] as [$key, $icon, $color, $label]): ?>
                    <div class="an-usage-item" style="--c:<?= $color ?>">
                        <span class="an-usage-icon"><i class="uil <?= $icon ?>"></i></span>
                        <span class="an-usage-label"><?= $label ?></span>
                        <strong data-usage="<?= $key ?>"><?= (int)$usage[$key] ?></strong>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const data = <?= json_encode($m, JSON_HEX_TAG) ?>;
    const FF = "'Source Sans 3', sans-serif";
    const GC = 'rgba(15,23,42,.06)';
    Chart.defaults.font.family = FF;
    Chart.defaults.color = '#64748b';
    const tooltip = {backgroundColor: '#0f172a', padding: 10, cornerRadius: 8, titleFont: {weight: '700'}};
    const intTicks = {precision: 0};

    const trendChart = new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: data.trend.labels,
            datasets: data.trend.series.map(s => ({
                label: s.label, data: s.data, borderColor: s.color, backgroundColor: s.color + '22',
                pointBackgroundColor: s.color, pointRadius: 3.5, pointHoverRadius: 6, borderWidth: 2.5, cubicInterpolationMode: 'monotone', fill: false
            }))
        },
        options: {
            responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false},
            plugins: {legend: {display: false}, tooltip},
            scales: {x: {grid: {display: false}}, y: {beginAtZero: true, grid: {color: GC}, ticks: intTicks}}
        }
    });

    const typeChart = new Chart(document.getElementById('typeChart'), {
        type: 'doughnut',
        data: {labels: data.types.labels, datasets: [{data: data.types.values, backgroundColor: data.types.colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 6}]},
        options: {responsive: true, maintainAspectRatio: false, cutout: '66%', plugins: {legend: {display: false}, tooltip}}
    });

    const stageChart = new Chart(document.getElementById('stageChart'), {
        type: 'bar',
        data: {labels: data.pipeline.labels, datasets: [{label: 'Active cases', data: data.pipeline.values, backgroundColor: '#6366f1cc', hoverBackgroundColor: '#6366f1', borderRadius: 6, maxBarThickness: 18}]},
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: {legend: {display: false}, tooltip},
            scales: {x: {beginAtZero: true, grid: {color: GC}, ticks: intTicks}, y: {grid: {display: false}}}
        }
    });

    // ── Live refresh every 30 seconds (admin/analytics_data.php, same calculations) ──
    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const setHtml = (sel, html) => { const el = document.querySelector(sel); if (el) el.innerHTML = html; };
    function render(d) {
        const k = d.kpi, u = d.usage, change = k.filedThisMonth - k.filedLastMonth;
        setHtml('[data-kpi="filedThisMonth"]', k.filedThisMonth);
        setHtml('[data-kpi="filedChange"]', change === 0 ? 'Same as last month' : (change > 0 ? '▲ ' + change : '▼ ' + Math.abs(change)) + ' vs last month (' + k.filedLastMonth + ')');
        setHtml('[data-kpi="settledThisMonth"]', k.settledThisMonth);
        setHtml('[data-kpi="settledSub"]', k.settledAllTime + ' settled or awarded in total');
        setHtml('[data-kpi="hearingsThisWeek"]', k.hearingsThisWeek);
        setHtml('[data-kpi="hearingSub"]', k.nextHearing ? esc('Next: ' + k.nextHearing.case + ' · ' + k.nextHearing.stage + ', ' + k.nextHearing.when) : 'None scheduled in the next 7 days');
        setHtml('[data-kpi="verifiedResidents"]', u.verifiedResidents + '<span> / ' + u.residents + '</span>');
        setHtml('[data-kpi="verifySub"]', u.pendingVerifications > 0 ? u.pendingVerifications + ' verification' + (u.pendingVerifications === 1 ? '' : 's') + ' waiting for review' : 'No verifications waiting');
        Object.keys(u).forEach(key => setHtml('[data-usage="' + key + '"]', u[key]));

        trendChart.data.labels = d.trend.labels;
        d.trend.series.forEach((s, i) => { trendChart.data.datasets[i].data = s.data; });
        trendChart.update('none');
        typeChart.data.labels = d.types.labels;
        typeChart.data.datasets[0].data = d.types.values;
        typeChart.data.datasets[0].backgroundColor = d.types.colors;
        typeChart.update('none');
        stageChart.data.datasets[0].data = d.pipeline.values;
        stageChart.update('none');

        setHtml('#typeList', d.types.labels.length ? d.types.labels.map((l, i) =>
            '<div><i style="background:' + d.types.colors[i] + '"></i><span>' + esc(l) + '</span><strong>' + d.types.values[i] + '</strong></div>').join('') : '<p class="an-empty">No cases yet.</p>');
        const total = d.outcomes.reduce((a, o) => a + o.value, 0);
        setHtml('[data-kpi="outcomeTotal"]', total);
        d.outcomes.forEach(o => {
            const row = document.querySelector('[data-outcome="' + o.key + '"]');
            if (!row) return;
            row.querySelector('strong').textContent = o.value;
            row.querySelector('.an-track div').style.width = (total > 0 ? Math.round(o.value / total * 100) : 0) + '%';
        });
    }
    async function refresh() {
        try {
            const res = await fetch('analytics_data.php?months=' + data.months + '&t=' + Date.now(), {headers: {'Accept': 'application/json'}});
            if (!res.ok) return;
            const payload = await res.json();
            if (!payload.success) return;
            render(payload.data);
            const now = new Date();
            document.getElementById('anUpdated').textContent = now.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
        } catch (e) { /* keep showing the last numbers */ }
    }
    setInterval(refresh, 30000);
})();
</script>

<style>
.an-body{padding:24px 28px 44px;}
.an-meta{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px;}
.an-sub{margin:0;font-size:13.5px;color:#475569;}
.an-live{display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:11.5px;color:#64748b;}
.an-live-dot{width:7px;height:7px;border-radius:50%;background:#10b981;box-shadow:0 0 0 3px #10b98133;}
.an-date{font-size:12.5px;color:#64748b;background:#fff;border:1px solid #e2e8f0;border-radius:999px;padding:6px 12px;white-space:nowrap;}

.an-kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px;}
.an-kpi{display:flex;align-items:flex-start;gap:14px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(15,23,42,.04);}
.an-kpi-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:var(--cb);color:var(--c);font-size:21px;flex-shrink:0;}
.an-kpi-label{font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:#64748b;font-weight:700;}
.an-kpi-val{font-size:28px;font-weight:800;color:#0f172a;line-height:1.15;margin:4px 0 3px;letter-spacing:-.5px;}
.an-kpi-val span{font-size:15px;font-weight:700;color:#94a3b8;letter-spacing:0;}
.an-kpi-sub{font-size:12px;color:#64748b;line-height:1.4;}

.an-row{display:grid;gap:16px;margin-bottom:16px;}
.an-row-main{grid-template-columns:2fr 1fr;}
.an-row-three{grid-template-columns:1.2fr 1fr 1fr;}
.an-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;box-shadow:0 1px 4px rgba(15,23,42,.04);min-width:0;}
.an-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;}
.an-card-title{font-size:15px;font-weight:700;color:#0f172a;}
.an-card-sub{font-size:12px;color:#64748b;margin-top:2px;}
.an-switch{display:inline-flex;background:#f1f5f9;border-radius:9px;padding:3px;}
.an-switch a{padding:6px 11px;border-radius:7px;font-size:12px;font-weight:600;color:#64748b;text-decoration:none;}
.an-switch a.active{background:#fff;color:#1d4ed8;box-shadow:0 1px 3px rgba(15,23,42,.12);}
.an-legend{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:10px;font-size:12px;color:#475569;}
.an-legend span{display:inline-flex;align-items:center;gap:6px;}
.an-legend i,.an-type-list i,.an-outcome-row i{display:inline-block;width:9px;height:9px;border-radius:50%;flex-shrink:0;}
.an-chart{position:relative;height:290px;}
.an-chart-sm{height:250px;}
.an-donut{position:relative;height:190px;margin-bottom:12px;}
.an-type-list{display:flex;flex-direction:column;gap:7px;max-height:150px;overflow:auto;}
.an-type-list div{display:flex;align-items:center;gap:8px;font-size:12.5px;color:#334155;}
.an-type-list span{flex:1;}
.an-type-list strong{color:#0f172a;}
.an-empty{font-size:12.5px;color:#94a3b8;text-align:center;margin:8px 0;}

.an-outcomes{display:flex;flex-direction:column;gap:11px;}
.an-outcome-row{display:flex;justify-content:space-between;align-items:center;font-size:12.5px;color:#334155;margin-bottom:5px;}
.an-outcome-row span{display:inline-flex;align-items:center;gap:7px;}
.an-outcome-row strong{color:#0f172a;}
.an-track{height:6px;border-radius:999px;background:#f1f5f9;overflow:hidden;}
.an-track div{height:100%;border-radius:999px;transition:width .6s ease;}

.an-usage{display:flex;flex-direction:column;gap:6px;}
.an-usage-item{display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:10px;transition:background .15s;}
.an-usage-item:hover{background:#f8fafc;}
.an-usage-icon{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;background:color-mix(in srgb,var(--c) 12%,#fff);color:var(--c);font-size:16px;}
.an-usage-label{flex:1;font-size:13px;color:#334155;}
.an-usage-item strong{font-size:16px;font-weight:800;color:#0f172a;}

@media(max-width:1200px){.an-kpi-grid{grid-template-columns:repeat(2,1fr);}.an-row-main,.an-row-three{grid-template-columns:1fr;}}
@media(max-width:640px){.an-body{padding:16px;}.an-meta{flex-direction:column;}.an-kpi-grid{grid-template-columns:1fr;}}
</style>
</body>
</html>
