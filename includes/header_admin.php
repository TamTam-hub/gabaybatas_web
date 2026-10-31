<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GabayBatas — Admin Portal</title>
    <?php require_once __DIR__ . '/brand_mark.php'; echo gabaybatas_favicon_links('../'); ?>
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

        /* ═══ UI refresh (admin pages) ════════════════════════════════════════
           Same navy government theme as the login page and the barangay portal:
           navy sidebar with a white pill for the current page, flag strip on the top
           bar, plain light background, clearer text and visible focus rings.
           Scoped to body.gb-admin-ui; information and flow are unchanged. */
        :root {
            --accent:       #1a3a6b;
            --accent-light: #eef3fb;
            --accent-glow:  rgba(26,58,107,.16);
            --page-bg:      #eef2f8;
            --border:       #dde4ef;
            --text-main:    #14213a;
            --text-sub:     #4f5f75;
        }
        body.gb-admin-ui { background: #eef2f8; background-image: none; font-size: 14.5px; -webkit-font-smoothing: antialiased; }
        body.gb-admin-ui::before { display: none; }

        /* Sidebar */
        .gb-admin-ui .sidebar {
            background: radial-gradient(circle at 0% 0%, rgba(255,255,255,.08), transparent 45%), linear-gradient(175deg, #1a3a6b 0%, #0e2242 70%, #0b1a33 100%);
            border-right: 0; box-shadow: 4px 0 24px rgba(11,26,51,.12);
        }
        .gb-admin-ui .sidebar-brand { padding: 22px 20px 18px; border-bottom-color: rgba(255,255,255,.08); }
        .gb-admin-ui .brand-icon { width: 40px; height: 40px; border-radius: 11px; font-size: 20px; background: rgba(255,255,255,.14); border-color: rgba(255,255,255,.12); box-shadow: none; }
        .gb-admin-ui .brand-title { font-size: 19px; }
        .gb-admin-ui .brand-logo-row { margin-bottom: 0; }
        .gb-admin-ui .brand-title { line-height: 1.2; }
        .gb-admin-ui .brand-sub { margin: 4px 0 0; color: rgba(255,255,255,.62); font-size: 10.5px; }
        .gb-admin-ui .nav-label { color: rgba(255,255,255,.45); font-weight: 700; letter-spacing: 1.6px; padding: 18px 14px 8px; }
        .gb-admin-ui .sidebar nav { padding: 4px 12px; }
        .gb-admin-ui .sidebar nav a { padding: 11px 14px; margin-bottom: 3px; border-radius: 10px; font-size: 14px; color: rgba(255,255,255,.82); border: 1px solid transparent; }
        .gb-admin-ui .sidebar nav a i { font-size: 18px; }
        .gb-admin-ui .sidebar nav a:hover { background: rgba(255,255,255,.09); color: #fff; transform: none; }
        .gb-admin-ui .sidebar nav a.active { background: #fff; color: #0e2242; font-weight: 700; border-color: transparent; box-shadow: 0 6px 16px rgba(0,0,0,.18); }
        .gb-admin-ui .sidebar nav a.active i { color: #1a3a6b; }
        .gb-admin-ui .sidebar-footer { padding: 12px; border-top-color: rgba(255,255,255,.08); }

        /* Top bar: flag strip and a plain navy serif title */
        .gb-admin-ui .topbar { background: rgba(255,255,255,.96); box-shadow: 0 1px 0 rgba(15,23,42,.02); border-bottom-color: var(--border); padding: 0 32px; }
        .gb-admin-ui .topbar::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #1a3a6b 0 33%, #b91c1c 33% 66%, #c9a227 66%); }
        .gb-admin-ui .topbar-title { font-size: 23px; color: #0b1a33; letter-spacing: 0; }
        .gb-admin-ui .topbar-badge { color: #1a3a6b; background: #eef3fb; border-color: #d6e2f5; }
        .gb-admin-ui .notif-btn { width: 38px; height: 38px; border-radius: 10px; }
        .gb-admin-ui .notif-btn:hover { background: #eef3fb; color: #1a3a6b; border-color: #c9d6ea; }
        .gb-admin-ui .user-avatar { background: linear-gradient(135deg, #24508f, #0e2242); }
        .gb-admin-ui .user-text strong { font-size: 13.5px; }
        .gb-admin-ui .user-text span { font-size: 12px; color: #6f7f96; }

        /* Keyboard focus is always visible */
        .gb-admin-ui a:focus-visible, .gb-admin-ui button:focus-visible, .gb-admin-ui input:focus-visible,
        .gb-admin-ui select:focus-visible, .gb-admin-ui textarea:focus-visible, .gb-admin-ui summary:focus-visible {
            outline: 3px solid rgba(36,80,143,.4); outline-offset: 2px;
        }
        .gb-admin-ui .sidebar a:focus-visible { outline-color: rgba(255,255,255,.6); }

        /* Admin forms (Add / Edit / Archive User) and report builders (User Report, Case Report):
           sentence-case labels, taller inputs with a navy focus ring, navy buttons. */
        .gb-admin-ui .au-body, .gb-admin-ui .eu-body, .gb-admin-ui .du-body, .gb-admin-ui .ur-body { padding: 26px 32px 44px; }
        .gb-admin-ui .au-sub, .gb-admin-ui .eu-sub, .gb-admin-ui .du-sub, .gb-admin-ui .ur-sub { font-size: 13.5px; color: #4f5f75; }
        .gb-admin-ui .au-back-btn, .gb-admin-ui .eu-back-btn, .gb-admin-ui .du-back-btn, .gb-admin-ui .ur-back {
            color: #1a3a6b; background: #eef3fb; border: 1px solid #d6e2f5; border-radius: 10px; padding: 9px 14px; font-size: 13px; font-weight: 600; text-decoration: none;
        }
        .gb-admin-ui .au-back-btn:hover, .gb-admin-ui .eu-back-btn:hover, .gb-admin-ui .du-back-btn:hover, .gb-admin-ui .ur-back:hover { background: #dfe8f7; }
        .gb-admin-ui .au-card, .gb-admin-ui .eu-card, .gb-admin-ui .du-card, .gb-admin-ui .ur-card {
            border-radius: 14px; border-color: #e1e7f0; box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 24px rgba(15,23,42,.045);
        }
        .gb-admin-ui .au-card, .gb-admin-ui .eu-card, .gb-admin-ui .du-card { padding: 22px 24px; }
        .gb-admin-ui .au-field label, .gb-admin-ui .eu-field label, .gb-admin-ui .du-label, .gb-admin-ui .ur-field label {
            font-size: 13px; font-weight: 700; text-transform: none; letter-spacing: .1px; color: #334155;
        }
        .gb-admin-ui .au-field input, .gb-admin-ui .au-field select, .gb-admin-ui .eu-field input, .gb-admin-ui .eu-field select,
        .gb-admin-ui .du-reason, .gb-admin-ui .ur-field input, .gb-admin-ui .ur-field select {
            min-height: 44px; font-size: 14.5px; color: #14213a; background: #fff; border: 1.5px solid #d6deea; border-radius: 10px; font-family: inherit;
        }
        .gb-admin-ui .ur-field input, .gb-admin-ui .ur-field select { min-height: 42px; height: auto; font-size: 14px; }
        .gb-admin-ui .ur-field input[type="checkbox"] { min-height: 0; }
        .gb-admin-ui .au-field input:focus, .gb-admin-ui .au-field select:focus, .gb-admin-ui .eu-field input:focus, .gb-admin-ui .eu-field select:focus,
        .gb-admin-ui .du-reason:focus, .gb-admin-ui .ur-field input:focus, .gb-admin-ui .ur-field select:focus {
            outline: none; border-color: #24508f; box-shadow: 0 0 0 4px rgba(36,80,143,.12);
        }
        .gb-admin-ui .au-submit, .gb-admin-ui .eu-submit, .gb-admin-ui .ur-btn.apply {
            background: linear-gradient(135deg, #24508f, #0e2242); border-color: transparent; color: #fff; border-radius: 10px; font-weight: 700; box-shadow: 0 6px 14px rgba(26,58,107,.2);
        }
        .gb-admin-ui .au-submit:hover, .gb-admin-ui .eu-submit:hover, .gb-admin-ui .ur-btn.apply:hover { filter: brightness(1.08); }
        .gb-admin-ui .au-cancel, .gb-admin-ui .eu-cancel, .gb-admin-ui .du-cancel { border: 1.5px solid #d6deea; border-radius: 10px; color: #334155; font-weight: 700; }
        .gb-admin-ui .du-delete { border-radius: 10px; font-weight: 700; }
        .gb-admin-ui .au-submit, .gb-admin-ui .eu-submit, .gb-admin-ui .au-cancel, .gb-admin-ui .eu-cancel, .gb-admin-ui .du-cancel, .gb-admin-ui .du-delete { padding: 11px 18px; font-size: 14px; }
        .gb-admin-ui .eu-account-meta span, .gb-admin-ui .du-info-item .du-label { font-size: 12px; color: #6f7f96; }
        .gb-admin-ui .eu-account-meta strong, .gb-admin-ui .du-value { font-size: 14.5px; color: #14213a; }
        .gb-admin-ui .du-warning { border-radius: 12px; }

        /* Report builders */
        .gb-admin-ui .ur-btn { min-height: 42px; padding: 10px 15px; font-size: 13.5px; font-weight: 700; border-radius: 10px; border: 1.5px solid #d6deea; }
        .gb-admin-ui .ur-btn.download { background: #fff; color: #1a3a6b; border-color: #c9d6ea; }
        .gb-admin-ui .ur-btn.download:hover { background: #f6f9ff; border-color: #1a3a6b; }
        .gb-admin-ui .ur-check-label { font-size: 13.5px !important; }
        .gb-admin-ui .ur-check-label input[type="checkbox"] { width: 16px; height: 16px; accent-color: #1a3a6b; }
        .gb-admin-ui .ur-meta { font-size: 13.5px; color: #334155; }
        .gb-admin-ui .ur-table th, .gb-admin-ui .ur-table td { border-color: #e1e7f0; padding: 11px 12px; font-size: 13.5px; }
        .gb-admin-ui .ur-table th { background: #f5f7fb; color: #334155; }
        .gb-admin-ui .ur-table tbody tr:hover td { background: #f6f9ff; }

        /* Manage Users: navy buttons, left-aligned table, softer badges */
        .gb-admin-ui .um-body { padding: 26px 32px 44px; }
        .gb-admin-ui .um-sub { font-size: 14.5px; color: #14213a; }
        .gb-admin-ui .um-add-btn { background: linear-gradient(135deg, #24508f, #0e2242); border-radius: 10px; padding: 11px 18px; font-size: 13.5px; font-weight: 700; box-shadow: 0 6px 14px rgba(26,58,107,.2); }
        .gb-admin-ui .um-add-btn:hover { background: linear-gradient(135deg, #24508f, #0e2242); filter: brightness(1.08); }
        .gb-admin-ui .um-btn-ghost { text-decoration: none; color: #1a3a6b; border: 1.5px solid #c9d6ea; border-radius: 10px; min-height: 38px; font-size: 13px; font-weight: 700; }
        .gb-admin-ui .um-btn-ghost:hover { background: #f6f9ff; border-color: #1a3a6b; color: #1a3a6b; }
        .gb-admin-ui .um-stat, .gb-admin-ui .um-card { border-color: #e1e7f0; box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 24px rgba(15,23,42,.045); }
        .gb-admin-ui .um-stat-num { font-size: 24px; color: #0b1a33; }
        .gb-admin-ui .um-stat-label { font-size: 12.5px; color: #4f5f75; }
        .gb-admin-ui .um-toolbar { border-bottom-color: #e1e7f0; }
        .gb-admin-ui .um-search-input { min-height: 40px; font-size: 13.5px; border: 1.5px solid #d6deea; border-radius: 10px; background: #fff; }
        .gb-admin-ui .um-select { height: 40px; font-size: 13px; border: 1.5px solid #d6deea; border-radius: 10px; color: #14213a; }
        .gb-admin-ui .um-search-input:focus, .gb-admin-ui .um-select:focus { border-color: #24508f; box-shadow: 0 0 0 4px rgba(36,80,143,.12); }
        .gb-admin-ui .um-table { font-size: 13.5px; }
        .gb-admin-ui .um-table th { text-align: left; font-size: 11.5px; color: #4f5f75; font-weight: 700; background: #f5f7fb; border-bottom-color: #e1e7f0; }
        .gb-admin-ui .um-table td { text-align: left; border-bottom-color: #eef2f7; }
        .gb-admin-ui .um-table tbody tr:hover td { background: #f6f9ff; }
        .gb-admin-ui .um-user-cell { justify-content: flex-start; }
        .gb-admin-ui .um-name { font-size: 14px; color: #14213a; }
        .gb-admin-ui .um-email-sm, .gb-admin-ui .um-cell-muted { font-size: 12.5px; color: #4f5f75; }
        .gb-admin-ui .um-role-badge { font-size: 12px; font-weight: 700; padding: 4px 11px; background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .um-role-badge.admin { background: #fdf6e3; color: #8a6d12; }
        .gb-admin-ui .um-role-badge.barangay { background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .um-role-badge.resident { background: #f1f5f9; color: #334155; }
        .gb-admin-ui .um-status-badge { font-size: 12px; font-weight: 700; padding: 4px 11px; }
        .gb-admin-ui .um-action-btn { width: 34px; height: 34px; border-radius: 9px; font-size: 16px; }
        .gb-admin-ui .um-action-btn.edit { background: #eef3fb; color: #1a3a6b; border-color: #d6e2f5; }
        .gb-admin-ui .um-action-btn.edit:hover { background: #dfe8f7; }
        .gb-admin-ui .um-footer { border-top-color: #e1e7f0; background: #fafbfd; }
        .gb-admin-ui .um-page-meta, .gb-admin-ui .um-page-current { font-size: 13px; }
        .gb-admin-ui .um-page-btn { border: 1.5px solid #d6deea; border-radius: 9px; padding: 7px 13px; font-size: 13px; color: #1a3a6b; }
        .gb-admin-ui .um-page-btn:hover { border-color: #1a3a6b; color: #1a3a6b; background: #f6f9ff; }

        /* Manage Cases: navy tabs and buttons, clearer table text */
        .gb-admin-ui .ac-sub { font-size: 14.5px; color: #14213a; }
        .gb-admin-ui .ac-tab { border: 1.5px solid #e1e7f0; border-radius: 10px; color: #334155; font-size: 13.5px; }
        .gb-admin-ui .ac-tab:hover { border-color: #c9d6ea; background: #f6f9ff; color: #14213a; }
        .gb-admin-ui .ac-tab.active { background: linear-gradient(135deg, #24508f, #0e2242); border-color: transparent; color: #fff; box-shadow: 0 6px 14px rgba(26,58,107,.22); }
        .gb-admin-ui .ac-tab:not(.active) .ac-tab-count { background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .ac-card { border-color: #e1e7f0; box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 24px rgba(15,23,42,.045); }
        .gb-admin-ui .ac-toolbar { border-bottom-color: #e1e7f0; }
        .gb-admin-ui .ac-search-form { flex: 1 1 auto; min-width: 200px; }
        .gb-admin-ui .ac-filter-form { flex: 0 0 auto; }
        .gb-admin-ui .ac-search-input { min-height: 42px; border: 1.5px solid #d6deea; background: #fff; font-size: 14px; }
        .gb-admin-ui .ac-search-input::placeholder { color: #8a99ad; }
        .gb-admin-ui .ac-select { height: 42px; border: 1.5px solid #d6deea; color: #14213a; font-size: 13.5px; }
        .gb-admin-ui .ac-search-input:focus, .gb-admin-ui .ac-select:focus { border-color: #24508f; box-shadow: 0 0 0 4px rgba(36,80,143,.12); }
        .gb-admin-ui .ac-btn-ghost { min-height: 42px; color: #1a3a6b; border: 1.5px solid #c9d6ea; font-weight: 700; }
        .gb-admin-ui .ac-btn-ghost:hover { background: #f6f9ff; border-color: #1a3a6b; color: #1a3a6b; }
        .gb-admin-ui .ac-table { font-size: 13.5px; }
        .gb-admin-ui .ac-table th { color: #4f5f75; background: #f5f7fb; border-bottom: 1px solid #e1e7f0; }
        .gb-admin-ui .ac-table td { border-bottom-color: #eef2f7; color: #14213a; }
        .gb-admin-ui .ac-table tbody tr:hover td { background: #f6f9ff; }
        .gb-admin-ui .ac-case-id { font-size: 12.5px; background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .ac-avatar { background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .ac-muted, .gb-admin-ui .ac-date small { color: #4f5f75; }
        .gb-admin-ui .ac-date strong { font-size: 13px; color: #14213a; }
        .gb-admin-ui .ac-badge { font-size: 12.5px; }
        .gb-admin-ui .ac-menu-btn { border: 1.5px solid #d6deea; border-radius: 9px; color: #1a3a6b; }
        .gb-admin-ui .ac-menu-btn:hover { border-color: #1a3a6b; background: #f6f9ff; color: #1a3a6b; }
        .gb-admin-ui .ac-dropdown { border-color: #e1e7f0; border-radius: 12px; box-shadow: 0 12px 28px rgba(15,23,42,.14); }
        .gb-admin-ui .ac-dropdown-item { font-size: 13.5px; color: #14213a; }
        .gb-admin-ui .ac-dropdown-item:hover { background: #f6f9ff; color: #1a3a6b; }
        .gb-admin-ui .ac-dropdown-item.ac-delete { color: #b91c1c; }
        .gb-admin-ui .ac-dropdown-item.ac-delete:hover { background: #fef2f2; color: #991b1b; }
        .gb-admin-ui .ac-view-btn { color: #1a3a6b; background: #eef3fb; border-color: #d6e2f5; }
        .gb-admin-ui .ac-edit-btn { color: #1a3a6b; background: #fff; border-color: #c9d6ea; }
        .gb-admin-ui .ac-view-btn:hover, .gb-admin-ui .ac-edit-btn:hover { background: #dfe8f7; color: #0e2242; }
        .gb-admin-ui .ac-footer { border-top-color: #e1e7f0; background: #fafbfd; }
        .gb-admin-ui .ac-page-btn { border: 1.5px solid #d6deea; color: #1a3a6b; }
        .gb-admin-ui .ac-page-btn:hover:not(.disabled) { border-color: #1a3a6b; color: #1a3a6b; background: #f6f9ff; }

        /* View Case and Archive Case */
        .gb-admin-ui .cv-body, .gb-admin-ui .dc-body, .gb-admin-ui .acs-body { padding: 26px 32px 44px; }
        .gb-admin-ui .cv-sub, .gb-admin-ui .dc-sub { font-size: 13.5px; color: #4f5f75; }
        .gb-admin-ui .cv-back-btn, .gb-admin-ui .dc-back-btn {
            color: #1a3a6b; background: #eef3fb; border: 1px solid #d6e2f5; border-radius: 10px; padding: 9px 14px; font-size: 13px;
        }
        .gb-admin-ui .cv-back-btn:hover, .gb-admin-ui .dc-back-btn:hover { background: #dfe8f7; }
        .gb-admin-ui .cv-edit-btn { color: #fff; background: linear-gradient(135deg, #24508f, #0e2242); border-color: transparent; border-radius: 10px; padding: 9px 15px; font-size: 13px; font-weight: 700; box-shadow: 0 6px 14px rgba(26,58,107,.2); }
        .gb-admin-ui .cv-edit-btn:hover { background: linear-gradient(135deg, #24508f, #0e2242); filter: brightness(1.08); }
        .gb-admin-ui .cv-card, .gb-admin-ui .dc-card {
            border-color: #e1e7f0; border-radius: 14px; padding: 22px 24px; box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 24px rgba(15,23,42,.045);
        }
        .gb-admin-ui .cv-grid { gap: 16px; }
        .gb-admin-ui .cv-item label, .gb-admin-ui .dc-item span {
            font-size: 13px; text-transform: none; letter-spacing: .1px; color: #334155; font-weight: 700;
        }
        .gb-admin-ui .cv-value { font-size: 14.5px; color: #14213a; background: #f8fafd; border-color: #e1e7f0; padding: 11px 13px; }
        .gb-admin-ui .cv-multiline { line-height: 1.65; }
        .gb-admin-ui .cv-evidence-heading p { font-size: 12.5px; color: #4f5f75; }
        .gb-admin-ui .cv-evidence-count, .gb-admin-ui .cv-evidence-view { background: #eef3fb; color: #1a3a6b; font-size: 12px; }
        .gb-admin-ui .cv-evidence-view:hover { background: #dfe8f7; }
        .gb-admin-ui .cv-evidence:hover { border-color: #c9d6ea; box-shadow: 0 5px 14px rgba(26,58,107,.08); }
        .gb-admin-ui .cv-evidence-info strong { font-size: 13px; }
        .gb-admin-ui .cv-evidence-info small { font-size: 11.5px; }
        .gb-admin-ui .cv-badge { font-size: 12.5px; }
        .gb-admin-ui .cv-archived { border-radius: 12px; font-size: 13.5px; }
        .gb-admin-ui .cv-back-link, .gb-admin-ui .dc-back-link { color: #1a3a6b; font-size: 13.5px; }
        .gb-admin-ui .dc-item { background: #f8fafd; border-color: #e1e7f0; }
        .gb-admin-ui .dc-item strong { font-size: 14.5px; color: #14213a; }
        .gb-admin-ui .dc-warning-title { font-size: 14.5px; }
        .gb-admin-ui .dc-warning-text { font-size: 13.5px; }
        .gb-admin-ui .dc-reason-label { font-size: 13px; color: #334155; }
        .gb-admin-ui .dc-reason { min-height: 90px; font-size: 14.5px; color: #14213a; border: 1.5px solid #d6deea; border-radius: 10px; padding: 11px 13px; }
        .gb-admin-ui .dc-reason:focus { outline: none; border-color: #24508f; box-shadow: 0 0 0 4px rgba(36,80,143,.12); }
        .gb-admin-ui .dc-cancel, .gb-admin-ui .dc-delete { border-radius: 10px; padding: 11px 18px; font-size: 14px; font-weight: 700; }
        .gb-admin-ui .dc-cancel { border: 1.5px solid #d6deea; color: #334155; }
        .gb-admin-ui .dc-actions { flex-wrap: wrap; }

        /* Edit Case: the Status Correction button stays amber */
        .gb-admin-ui .acs-save.uc-status-btn { background: #b45309; box-shadow: none; }
        .gb-admin-ui .acs-save.uc-status-btn:hover { background: #92400e; filter: none; }

        /* Residency Verification: navy instead of teal, larger small print */
        .gb-admin-ui .rv-hero { border-color: #d6e2f5; background: linear-gradient(120deg, #fff 0%, #eef3fb 100%); }
        .gb-admin-ui .rv-kicker, .gb-admin-ui .rv-modal-head span { color: #1a3a6b; font-size: 12px; }
        .gb-admin-ui .rv-hero p { font-size: 14px; color: #4f5f75; }
        .gb-admin-ui .rv-stat { border-color: #e1e7f0; border-radius: 14px; }
        .gb-admin-ui .rv-stat:hover { border-color: #c9d6ea; }
        .gb-admin-ui .rv-stat strong { font-size: 24px; color: #0b1a33; }
        .gb-admin-ui .rv-stat small { font-size: 12.5px; color: #4f5f75; }
        .gb-admin-ui .rv-panel { border-color: #e1e7f0; }
        .gb-admin-ui .rv-panel-head h2 { color: #0b1a33; }
        .gb-admin-ui .rv-panel-head p { font-size: 13px; color: #4f5f75; }
        .gb-admin-ui .rv-count { font-size: 12px; color: #1a3a6b; background: #eef3fb; }
        .gb-admin-ui .rv-search-form { border: 1.5px solid #d6deea; background: #fff; }
        .gb-admin-ui .rv-search-form:focus-within { border-color: #24508f; box-shadow: 0 0 0 4px rgba(36,80,143,.12); }
        .gb-admin-ui .rv-search-form > i { color: #1a3a6b; }
        .gb-admin-ui .rv-search-form input { font-size: 14px; color: #14213a; }
        .gb-admin-ui .rv-search-form button, .gb-admin-ui .rv-review-form button:not(.rv-review-close) {
            background: linear-gradient(135deg, #24508f, #0e2242); border-radius: 8px; font-size: 13px; padding: 9px 15px;
        }
        .gb-admin-ui .rv-search-form button:hover, .gb-admin-ui .rv-review-form button:not(.rv-review-close):hover { background: linear-gradient(135deg, #24508f, #0e2242); filter: brightness(1.08); }
        .gb-admin-ui .rv-tabs a { font-size: 13px; color: #334155; border: 1.5px solid #e1e7f0; border-radius: 10px; padding: 9px 13px; }
        .gb-admin-ui .rv-tabs a:hover { border-color: #c9d6ea; background: #f6f9ff; }
        .gb-admin-ui .rv-tabs a.active { background: linear-gradient(135deg, #24508f, #0e2242); border-color: transparent; color: #fff; }
        .gb-admin-ui .rv-tabs b { font-size: 11.5px; background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .rv-tabs a.active b { background: rgba(255,255,255,.22); color: #fff; }
        .gb-admin-ui .rv-table th { font-size: 11.5px; color: #4f5f75; background: #f5f7fb; border-bottom-color: #e1e7f0; }
        .gb-admin-ui .rv-table td { font-size: 13.5px; color: #14213a; border-bottom-color: #eef2f7; }
        .gb-admin-ui .rv-table td strong { font-size: 13.5px; color: #14213a; }
        .gb-admin-ui .rv-table td:nth-child(6) strong { white-space: nowrap; }
        .gb-admin-ui .rv-review-form textarea { min-height: 80px; }
        .gb-admin-ui .rv-table td small { font-size: 12px; color: #4f5f75; }
        .gb-admin-ui .rv-avatar { background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .rv-docs a, .gb-admin-ui .rv-details summary { color: #1a3a6b; font-size: 12.5px; }
        .gb-admin-ui .rv-status { font-size: 12px; padding: 4px 10px; }
        .gb-admin-ui .rv-note { font-size: 12px; }
        .gb-admin-ui .rv-review-current { font-size: 12.5px; }
        .gb-admin-ui .rv-review-form { border-color: #e1e7f0; border-radius: 12px; }
        .gb-admin-ui .rv-review-form label { font-size: 12.5px; text-transform: none; letter-spacing: .1px; color: #334155; }
        .gb-admin-ui .rv-review-form select, .gb-admin-ui .rv-review-form textarea { font-size: 13.5px; font-weight: 400; border: 1.5px solid #d6deea; border-radius: 8px; padding: 8px 10px; }
        .gb-admin-ui .rv-review-form select:focus, .gb-admin-ui .rv-review-form textarea:focus { outline: none; border-color: #24508f; box-shadow: 0 0 0 3px rgba(36,80,143,.12); }
        .gb-admin-ui .rv-delete-button { font-size: 12px; border-radius: 8px; }
        .gb-admin-ui .rv-zoom-tools button:hover { border-color: #1a3a6b; color: #1a3a6b; background: #f6f9ff; }
        .gb-admin-ui .rv-empty i { color: #9fb3d1; }
        .gb-admin-ui .rv-empty span { font-size: 13px; }

        /* Records Archive: navy tabs and buttons, readable table text */
        .gb-admin-ui .ar-intro { font-size: 14px; color: #4f5f75; }
        .gb-admin-ui .ar-tab { border: 1.5px solid #e1e7f0; border-radius: 10px; color: #334155; }
        .gb-admin-ui .ar-tab:hover { border-color: #c9d6ea; background: #f6f9ff; color: #14213a; }
        .gb-admin-ui .ar-tab span { background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .ar-tab.active { background: linear-gradient(135deg, #24508f, #0e2242); border-color: transparent; color: #fff; box-shadow: 0 6px 14px rgba(26,58,107,.22); }
        .gb-admin-ui .ar-tab.active span { background: rgba(255,255,255,.22); color: #fff; }
        .gb-admin-ui .ar-card { border-color: #e1e7f0; box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 24px rgba(15,23,42,.045); }
        .gb-admin-ui .ar-toolbar { border-bottom-color: #e1e7f0; }
        .gb-admin-ui .ar-search input, .gb-admin-ui .ar-toolbar select { height: 42px; border: 1.5px solid #d6deea; border-radius: 10px; background: #fff; color: #14213a; font-size: 13.5px; }
        .gb-admin-ui .ar-search input { padding-left: 36px; }
        .gb-admin-ui .ar-search i { color: #6f7f96; }
        .gb-admin-ui .ar-search input:focus, .gb-admin-ui .ar-toolbar select:focus { border-color: #24508f; box-shadow: 0 0 0 4px rgba(36,80,143,.12); }
        .gb-admin-ui .ar-btn { height: 42px; border: 1.5px solid #d6deea; border-radius: 10px; color: #334155; font-size: 13.5px; font-weight: 700; }
        .gb-admin-ui .ar-btn.primary { background: linear-gradient(135deg, #24508f, #0e2242); border-color: transparent; color: #fff; box-shadow: 0 6px 14px rgba(26,58,107,.2); }
        .gb-admin-ui .ar-btn.primary:hover { filter: brightness(1.08); }
        .gb-admin-ui .ar-table { font-size: 13.5px; }
        .gb-admin-ui .ar-table th { color: #4f5f75; background: #f5f7fb; border-bottom: 1px solid #e1e7f0; }
        .gb-admin-ui .ar-table td { color: #14213a; border-bottom-color: #eef2f7; }
        .gb-admin-ui .ar-table tbody tr:hover td { background: #f6f9ff; }
        .gb-admin-ui .ar-table td strong { color: #14213a; }
        .gb-admin-ui .ar-table td small, .gb-admin-ui .ar-muted { font-size: 12px; color: #4f5f75; }
        .gb-admin-ui .ar-id { font-size: 12.5px; background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .ar-avatar { background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .ar-date strong { font-size: 13px; }
        .gb-admin-ui .ar-reason { font-size: 13px; color: #334155; }
        .gb-admin-ui .ar-badge { font-size: 12.5px; }
        .gb-admin-ui .ar-hold { font-size: 11px; }
        .gb-admin-ui .ar-act { border-radius: 9px; padding: 7px 12px; font-size: 12.5px; font-weight: 700; }
        .gb-admin-ui .ar-act.view { border-color: #d6e2f5; background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .ar-act.view:hover { background: #dfe8f7; filter: none; }
        .gb-admin-ui .ar-empty i { color: #9fb3d1; }

        /* Analytics: same cards as the rest of the admin pages; chart colours stay (they match the status groups) */
        .gb-admin-ui .an-body, .gb-admin-ui .rp-body { padding: 26px 32px 44px; }
        .gb-admin-ui .an-sub, .gb-admin-ui .rp-intro { font-size: 14px; color: #4f5f75; }
        .gb-admin-ui .an-live { font-size: 12.5px; color: #4f5f75; }
        .gb-admin-ui .an-date { font-size: 13px; color: #1a3a6b; background: #eef3fb; border-color: #d6e2f5; font-weight: 600; }
        .gb-admin-ui .an-kpi, .gb-admin-ui .an-card, .gb-admin-ui .rp-quick, .gb-admin-ui .rp-card {
            border-color: #e1e7f0; box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 24px rgba(15,23,42,.045);
        }
        .gb-admin-ui .an-kpi-label { font-size: 13px; text-transform: none; letter-spacing: .1px; color: #334155; }
        .gb-admin-ui .an-kpi-val { color: #0b1a33; }
        .gb-admin-ui .an-kpi-sub { font-size: 12.5px; color: #4f5f75; }
        .gb-admin-ui .an-card-title { font-size: 16px; color: #14213a; }
        .gb-admin-ui .an-card-sub { font-size: 13px; color: #4f5f75; }
        .gb-admin-ui .an-switch { background: #eef3fb; border-radius: 10px; }
        .gb-admin-ui .an-switch a { font-size: 12.5px; color: #4f5f75; border-radius: 8px; }
        .gb-admin-ui .an-switch a.active { color: #fff; background: linear-gradient(135deg, #24508f, #0e2242); box-shadow: 0 3px 8px rgba(26,58,107,.22); }
        .gb-admin-ui .an-legend { font-size: 13px; color: #334155; }
        .gb-admin-ui .an-type-list div, .gb-admin-ui .an-outcome-row { font-size: 13.5px; color: #14213a; }
        .gb-admin-ui .an-track { height: 7px; background: #eef2f7; }
        .gb-admin-ui .an-usage-item:hover { background: #f6f9ff; }
        .gb-admin-ui .an-usage-label { font-size: 14px; color: #14213a; }
        .gb-admin-ui .an-usage-item strong { color: #0b1a33; }
        .gb-admin-ui .an-empty { font-size: 13px; color: #6f7f96; }

        /* Reports: navy download buttons on every card; each period keeps its own icon colour */
        .gb-admin-ui .rp-section h2 { font-size: 17px; color: #0b1a33; }
        .gb-admin-ui .rp-section p { font-size: 13.5px; color: #4f5f75; }
        .gb-admin-ui .rp-quick { border-top: 3px solid #1a3a6b; }
        .gb-admin-ui .rp-quick-title { font-size: 16px; color: #14213a; }
        .gb-admin-ui .rp-quick-sub { font-size: 13px; color: #4f5f75; }
        .gb-admin-ui .rp-quick-count { font-size: 12px; color: #1a3a6b; background: #eef3fb; }
        .gb-admin-ui .rp-quick-btn, .gb-admin-ui .rp-btn.amber {
            background: linear-gradient(135deg, #24508f, #0e2242); border-radius: 10px; font-size: 13.5px; font-weight: 700; box-shadow: 0 6px 14px rgba(26,58,107,.2);
        }
        .gb-admin-ui .rp-quick-btn { padding: 11px 14px; }
        .gb-admin-ui .rp-quick-btn:hover, .gb-admin-ui .rp-btn.amber:hover { background: linear-gradient(135deg, #24508f, #0e2242); filter: brightness(1.08); }
        .gb-admin-ui .rp-btn { height: 42px; padding: 0 16px; }
        .gb-admin-ui .rp-card h2, .gb-admin-ui .rp-card h3 { color: #14213a; }
        .gb-admin-ui .rp-month-form label { font-size: 13px; text-transform: none; letter-spacing: .1px; color: #334155; }
        .gb-admin-ui .rp-month-form select { height: 42px; font-size: 14px; color: #14213a; border: 1.5px solid #d6deea; border-radius: 10px; }
        .gb-admin-ui .rp-month-form select:focus { outline: none; border-color: #24508f; box-shadow: 0 0 0 4px rgba(36,80,143,.12); }
        .gb-admin-ui .rp-link { border: 1.5px solid #e1e7f0; border-radius: 12px; }
        .gb-admin-ui .rp-link:hover { border-color: #1a3a6b; background: #f6f9ff; }
        .gb-admin-ui .rp-link-icon { background: #eef3fb; color: #1a3a6b; }
        .gb-admin-ui .rp-link strong { font-size: 14px; color: #14213a; }
        .gb-admin-ui .rp-link small { font-size: 12.5px; color: #4f5f75; }
        .gb-admin-ui .rp-link:hover > i { color: #1a3a6b; }
    </style>
    <?php $gbBellEndpoint = 'recent_activity.php'; include __DIR__ . '/activity_bell.php'; ?>
    <?php require_once __DIR__ . '/lottie.php'; gabaybatas_lottie_loader(); ?>
    <script src="../assets/js/placeholder-marquee.js" defer></script>
    <?php require_once __DIR__ . '/logout_confirm.php'; gabaybatas_logout_confirm('Admin'); ?>
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
<body class="gb-admin-ui">