<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GabayBatas — Admin Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,wght@0,500;0,700;1,500&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unicons.iconscout.com/release/v4.0.8/css/line.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            /* Admin-specific palette — teal authority, dark navy sidebar */
            --sidebar-bg:     #0f172a;
            --sidebar-soft:   #18243c;
            --sidebar-border: rgba(255,255,255,.07);
            --accent:         #0d9488;   /* teal-600 — distinct from barangay blue */
            --accent-light:   #ccfbf1;
            --accent-glow:    rgba(13,148,136,.18);
            --page-bg:        #edf3fa;
            --white:          #ffffff;
            --border:         #d9e2ef;
            --text-main:      #111827;
            --text-sub:       #6b7280;
            --sidebar-w:      252px;

            /* KPI icon badge colours */
            --kpi-blue:   #1d4ed8;
            --kpi-orange: #d97706;
            --kpi-indigo: #4f46e5;
            --kpi-green:  #059669;
        }

        body {
            background: var(--page-bg);
            color: var(--text-main);
            font-family: 'Source Sans 3', sans-serif;
            font-size: 14px;
            line-height: 1.6;
            overflow-x: hidden;
            background-image:
                radial-gradient(circle at 12% 12%, rgba(13,148,136,.08), transparent 35%),
                radial-gradient(circle at 88% 90%, rgba(79,70,229,.07), transparent 36%);
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            background:
                radial-gradient(circle at 20% 18%, rgba(255,255,255,.62), transparent 20%),
                radial-gradient(circle at 85% 12%, rgba(13,148,136,.04), transparent 14%),
                radial-gradient(circle at 60% 82%, rgba(79,70,229,.04), transparent 16%);
            opacity: .85;
        }

        /* ── SIDEBAR ─────────────────────────────── */
        .sidebar {
            width: var(--sidebar-w);
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            background: linear-gradient(180deg, #0f172a 0%, #121d33 52%, #0f172a 100%);
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--sidebar-border);
            z-index: 100;
        }

        .sidebar-brand {
            padding: 22px 18px 16px;
            border-bottom: 1px solid var(--sidebar-border);
        }

        .brand-logo-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 4px;
        }

        .brand-icon {
            width: 34px; height: 34px;
            background: linear-gradient(145deg, #1a3a6b 0%, #122d54 100%);
            border: 1px solid rgba(255,255,255,.16);
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 17px; color: #fff;
            flex-shrink: 0;
            box-shadow: 0 6px 14px rgba(26,58,107,.28);
        }

        .brand-title {
            font-family: 'Source Serif 4', serif;
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -.3px;
        }

        .brand-sub {
            font-size: 10.5px;
            color: rgba(255,255,255,.45);
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-left: 44px;
        }

        /* nav section label */
        .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            color: rgba(255,255,255,.3);
            padding: 12px 14px 4px;
            font-weight: 600;
        }

        .sidebar nav { padding: 10px 10px; flex: 1; overflow-y: auto; }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255,255,255,.65);
            text-decoration: none;
            padding: 10px 12px;
            border-radius: 8px;
            margin-bottom: 2px;
            font-size: 13.5px;
            font-weight: 500;
            transition: background .15s, color .15s, transform .15s;
        }

        .sidebar a i { font-size: 17px; flex-shrink: 0; }
        .sidebar a:hover { background: rgba(255,255,255,.08); color: #fff; transform: translateX(2px); }

        .sidebar a.active {
            background: linear-gradient(90deg, rgba(13,148,136,.22), rgba(13,148,136,.08));
            color: #5eead4;
            border: 1px solid rgba(94,234,212,.15);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.02);
        }

        .sidebar a.active i { color: #5eead4; }

        .sidebar-footer {
            border-top: 1px solid var(--sidebar-border);
            padding: 10px;
        }

        .sidebar-footer a {
            display: flex; align-items: center; gap: 10px;
            color: rgba(255,255,255,.45);
            font-size: 13px; padding: 9px 12px;
            border-radius: 8px; text-decoration: none;
            transition: background .15s, color .15s;
        }
        .sidebar-footer a:hover { background: rgba(239,68,68,.12); color: #f87171; }

        /* ── MAIN LAYOUT ─────────────────────────── */
        .main {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 1;
        }

        /* ── TOPBAR ──────────────────────────────── */
        .topbar {
            height: 66px;
            background:
                radial-gradient(120% 160% at -8% -60%, rgba(249, 115, 22, .09) 0%, rgba(249, 115, 22, 0) 48%),
                radial-gradient(120% 160% at 110% -70%, rgba(37, 99, 235, .11) 0%, rgba(37, 99, 235, 0) 50%),
                rgba(255,255,255,.93);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 0 rgba(15, 23, 42, .04), 0 8px 18px rgba(15, 23, 42, .05);
            padding: 0 26px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 90;
            backdrop-filter: blur(10px);
        }

        .topbar-left { display: flex; align-items: center; gap: 14px; }

        .topbar-title {
            font-family: 'Source Serif 4', serif;
            font-size: 24px;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: .3px;
            line-height: 1;
        }

        .topbar-badge {
            font-size: 11px;
            font-weight: 700;
            color: #9a3412;
            background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%);
            border: 1px solid rgba(234, 88, 12, .22);
            padding: 4px 10px;
            border-radius: 999px;
            letter-spacing: .4px;
            text-transform: uppercase;
        }

        .topbar-right { display: flex; align-items: center; gap: 10px; position: relative; }

        .notif-btn {
            width: 36px; height: 36px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: var(--white);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            color: var(--text-sub);
            font-size: 17px;
            transition: background .15s, color .15s;
            position: relative;
        }
        .notif-btn:hover { background: var(--page-bg); color: var(--text-main); }
        .notif-dot {
            position: absolute;
            top: 6px; right: 7px;
            width: 7px; height: 7px;
            background: #ef4444;
            border-radius: 50%;
            border: 1.5px solid #fff;
        }

        .notif-btn.active {
            background: var(--accent-light);
            color: #0f766e;
            border-color: #99f6e4;
        }

        .notif-btn.active .notif-dot,
        .notif-btn.cleared .notif-dot { display: none; }

        .admin-toast-host {
            position: fixed;
            top: 84px;
            right: 22px;
            z-index: 180;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .admin-toast {
            min-width: 290px;
            max-width: 360px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 12px 26px rgba(15,23,42,.14);
            padding: 10px 12px;
            display: flex;
            gap: 10px;
            opacity: 0;
            transform: translateY(-8px);
            transition: opacity .18s ease, transform .18s ease;
            pointer-events: auto;
        }

        .admin-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .admin-toast-icon {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .admin-toast-body {
            min-width: 0;
            flex: 1;
        }

        .admin-toast-title {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.25;
            margin-bottom: 2px;
        }

        .admin-toast-message {
            font-size: 12px;
            color: var(--text-sub);
            line-height: 1.35;
        }

        .admin-toast-close {
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: 14px;
            cursor: pointer;
            align-self: flex-start;
            line-height: 1;
            padding: 2px;
        }

        .admin-toast.success .admin-toast-icon { background: #dcfce7; color: #15803d; }
        .admin-toast.info .admin-toast-icon { background: #dbeafe; color: #1d4ed8; }
        .admin-toast.error .admin-toast-icon { background: #fee2e2; color: #b91c1c; }

        .admin-confirm-overlay {
            position: fixed;
            inset: 0;
            z-index: 190;
            background: rgba(2, 6, 23, .45);
            backdrop-filter: blur(2px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            transition: opacity .18s ease;
        }

        .admin-confirm-overlay.show { opacity: 1; }

        .admin-confirm {
            width: 100%;
            max-width: 430px;
            background: #fff;
            border: 1px solid #dbe3ef;
            border-radius: 14px;
            box-shadow: 0 24px 48px rgba(15, 23, 42, .28);
            overflow: hidden;
            transform: translateY(8px) scale(.98);
            transition: transform .2s ease;
        }

        .admin-confirm-overlay.show .admin-confirm {
            transform: translateY(0) scale(1);
        }

        .admin-confirm-head {
            padding: 14px 16px 10px;
            border-bottom: 1px solid #edf2f7;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .admin-confirm-icon {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            background: #fee2e2;
            color: #b91c1c;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 16px;
            margin-top: 1px;
        }

        .admin-confirm-title {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            line-height: 1.3;
            margin-bottom: 3px;
        }

        .admin-confirm-message {
            font-size: 12.5px;
            color: #6b7280;
            line-height: 1.4;
        }

        .admin-confirm-actions {
            padding: 12px 16px 14px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .admin-confirm-btn {
            border: 1px solid #dbe3ef;
            background: #fff;
            color: #475569;
            border-radius: 9px;
            padding: 8px 14px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
        }

        .admin-confirm-btn.primary {
            background: #dc2626;
            border-color: #dc2626;
            color: #fff;
        }

        .admin-confirm-btn.primary:hover {
            background: #b91c1c;
            border-color: #b91c1c;
        }

        .topbar-sep { width: 1px; height: 28px; background: var(--border); }

        .user-pill { display: flex; align-items: center; gap: 10px; }
        .user-avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent) 0%, #0f766e 100%);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
        }
        .user-text strong { display: block; font-size: 13px; line-height: 1.3; }
        .user-text span { font-size: 11px; color: var(--text-sub); }

        /* ── PAGE BODY ───────────────────────────── */
        .page-body { padding: 22px 26px 40px; }

        /* ── CARDS ───────────────────────────────── */
        .card-box {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(15,23,42,.05), 0 10px 28px rgba(15,23,42,.04);
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
            animation: panelRise .55s ease both;
            will-change: transform, box-shadow;
        }

        .card-box:hover {
            transform: translateY(-3px);
            border-color: rgba(13,148,136,.18);
            box-shadow: 0 4px 10px rgba(15,23,42,.06), 0 16px 34px rgba(15,23,42,.08);
        }

        .card-box:nth-child(2n) { animation-delay: .03s; }
        .card-box:nth-child(3n) { animation-delay: .06s; }

        /* ── KPI CARDS ───────────────────────────── */
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 16px; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-bottom: 16px; }

        .kpi-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: relative;
            overflow: hidden;
        }

        .kpi-card::after {
            content: '';
            position: absolute;
            width: 92px;
            height: 92px;
            right: -32px;
            top: -30px;
            border-radius: 50%;
            background: linear-gradient(180deg, rgba(13,148,136,.09), rgba(13,148,136,.02));
            pointer-events: none;
        }

        .kpi-left {}
        .kpi-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .9px;
            color: var(--text-sub);
            font-weight: 600;
            margin-bottom: 6px;
        }
        .kpi-value {
            font-family: 'Source Serif 4', serif;
            font-size: 36px;
            font-weight: 700;
            line-height: 1;
            color: var(--text-main);
        }
        .kpi-sub {
            font-size: 11.5px;
            color: var(--text-sub);
            margin-top: 4px;
        }

        .kpi-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            box-shadow: 0 6px 14px rgba(17,24,39,.09);
        }
        .kpi-blue   { background: #dbeafe; color: var(--kpi-blue); }
        .kpi-orange { background: #fef3c7; color: var(--kpi-orange); }
        .kpi-indigo { background: #e0e7ff; color: var(--kpi-indigo); }
        .kpi-green  { background: #d1fae5; color: var(--kpi-green); }

        /* ── SECTION HEADING ─────────────────────── */
        .section-heading {
            font-size: 15.5px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 2px;
        }
        .section-sub {
            font-size: 12px;
            color: var(--text-sub);
            margin-bottom: 14px;
        }

        /* chart container keeps consistent height */
        .chart-wrap { position: relative; }

        /* ── TABLE ───────────────────────────────── */
        .table-wrap { overflow-x: auto; }

        .table-dashboard {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .table-dashboard thead th {
            background: linear-gradient(180deg, #f8fafc 0%, #f2f7fb 100%);
            color: var(--text-sub);
            border-bottom: 1px solid var(--border);
            padding: 10px 12px;
            text-align: left;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .7px;
            white-space: nowrap;
        }
        .table-dashboard tbody td {
            padding: 11px 12px;
            border-bottom: 1px solid #f0f4f8;
            color: var(--text-main);
            vertical-align: middle;
        }
        .table-dashboard tbody tr:last-child td { border-bottom: none; }
        .table-dashboard tbody tr:hover td { background: #f5fbfb; }

        .case-id { font-weight: 700; color: var(--accent); font-size: 13px; }

        .badge-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
            white-space: nowrap;
        }
        .pending  { background: #fef3c7; color: #92400e; }
        .progress { background: #dbeafe; color: #1e40af; }
        .resolved { background: #d1fae5; color: #065f46; }

        .table-action {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--accent);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            padding: 5px 12px;
            border-radius: 7px;
            border: 1px solid var(--accent-light);
            background: var(--accent-light);
            transition: background .15s, border-color .15s;
            white-space: nowrap;
        }
        .table-action:hover {
            background: #99f6e4;
            border-color: #99f6e4;
        }

        .chart-wrap canvas {
            animation: chartReveal .8s ease both;
        }

        .grid-4, .grid-2, .grid-3 {
            position: relative;
            z-index: 1;
        }

        @keyframes panelRise {
            from { opacity: 0; transform: translateY(10px) scale(.99); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes chartReveal {
            from { opacity: 0; transform: translateY(10px); filter: blur(1px); }
            to   { opacity: 1; transform: translateY(0); filter: blur(0); }
        }

        /* ── RESPONSIVE ──────────────────────────── */
        @media (max-width: 1100px) {
            .grid-4 { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .grid-4, .grid-2 { grid-template-columns: 1fr; }
            .main { margin-left: 0; }
            .sidebar { transform: translateX(-100%); }
        }
    </style>
    <?php $gbBellEndpoint = 'recent_activity.php'; include __DIR__ . '/activity_bell.php'; ?>
    <?php require_once __DIR__ . '/lottie.php'; gabaybatas_lottie_loader(); ?>
    <script>
    window.AdminToast = window.AdminToast || (function () {
        let host = null;

        function ensureHost() {
            if (host && document.body.contains(host)) return host;
            host = document.querySelector('.admin-toast-host');
            if (!host) {
                host = document.createElement('div');
                host.className = 'admin-toast-host';
                document.body.appendChild(host);
            }
            return host;
        }

        function iconFor(type) {
            if (type === 'success') return 'uil-check-circle';
            if (type === 'error') return 'uil-exclamation-circle';
            return 'uil-info-circle';
        }

        function show(type, title, message, timeout) {
            const root = ensureHost();
            const toast = document.createElement('div');
            const t = type || 'info';
            toast.className = 'admin-toast ' + t;
            const icon = t === 'success'
                ? '<div class="gb-lottie" data-lottie="../assets/lottie/success.json" data-lottie-once style="width:34px;height:34px"><i class="uil uil-check-circle gb-lottie-fallback"></i></div>'
                : '<i class="uil ' + iconFor(t) + '"></i>';
            toast.innerHTML = '<div class="admin-toast-icon">' + icon + '</div>' +
                '<div class="admin-toast-body"><div class="admin-toast-title"></div><div class="admin-toast-message"></div></div>' +
                '<button type="button" class="admin-toast-close" aria-label="Close">&times;</button>';

            toast.querySelector('.admin-toast-title').textContent = title || 'Notice';
            toast.querySelector('.admin-toast-message').textContent = message || '';

            root.appendChild(toast);
            if (window.gbLottieScan) window.gbLottieScan(toast);
            requestAnimationFrame(function () { toast.classList.add('show'); });

            const life = typeof timeout === 'number' ? timeout : 3600;
            const close = function () {
                toast.classList.remove('show');
                setTimeout(function () {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 180);
            };

            toast.querySelector('.admin-toast-close').addEventListener('click', close);
            setTimeout(close, life);
        }

        return { show: show };
    })();
    </script>
    <script>
    window.AdminConfirm = window.AdminConfirm || (function () {
        let active = null;

        function closeOverlay(overlay) {
            if (!overlay) return;
            overlay.classList.remove('show');
            setTimeout(function () {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            }, 180);
            active = null;
        }

        function confirm(options) {
            if (active) closeOverlay(active);

            const opts = options || {};
            const title = opts.title || 'Confirm Action';
            const message = opts.message || 'Are you sure you want to continue?';
            const confirmText = opts.confirmText || 'Confirm';
            const cancelText = opts.cancelText || 'Cancel';

            return new Promise(function (resolve) {
                const overlay = document.createElement('div');
                overlay.className = 'admin-confirm-overlay';
                overlay.innerHTML = '' +
                    '<div class="admin-confirm" role="dialog" aria-modal="true" aria-label="Confirmation dialog">' +
                        '<div class="admin-confirm-head">' +
                            '<div class="admin-confirm-icon"><i class="uil uil-exclamation-triangle"></i></div>' +
                            '<div><div class="admin-confirm-title"></div><div class="admin-confirm-message"></div></div>' +
                        '</div>' +
                        '<div class="admin-confirm-actions">' +
                            '<button type="button" class="admin-confirm-btn" data-action="cancel"></button>' +
                            '<button type="button" class="admin-confirm-btn primary" data-action="confirm"></button>' +
                        '</div>' +
                    '</div>';

                overlay.querySelector('.admin-confirm-title').textContent = title;
                overlay.querySelector('.admin-confirm-message').textContent = message;
                overlay.querySelector('[data-action="confirm"]').textContent = confirmText;
                overlay.querySelector('[data-action="cancel"]').textContent = cancelText;

                let settled = false;

                function done(result) {
                    if (settled) return;
                    settled = true;
                    document.removeEventListener('keydown', onKey);
                    closeOverlay(overlay);
                    resolve(result);
                }

                overlay.addEventListener('click', function (e) {
                    if (e.target === overlay) done(false);
                });

                overlay.querySelector('[data-action="cancel"]').addEventListener('click', function () { done(false); });
                overlay.querySelector('[data-action="confirm"]').addEventListener('click', function () { done(true); });

                function onKey(e) {
                    if (!active || active !== overlay) return;
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        done(false);
                    }
                }

                document.addEventListener('keydown', onKey);

                document.body.appendChild(overlay);
                requestAnimationFrame(function () { overlay.classList.add('show'); });
                active = overlay;
            });
        }

        return { confirm: confirm };
    })();
    </script>
</head>
<body>