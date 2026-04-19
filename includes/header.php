<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GabayBatas — Barangay Portal</title>

    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@400;600;700&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unicons.iconscout.com/release/v4.0.8/css/line.css" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy:        #1a3a6b;
            --navy-dark:   #122d54;
            --navy-deeper: #0e2242;
            --navy-light:  rgba(26,58,107,.08);
            --accent:      #2a5298;
            --page-bg:     #edf0f5;
            --white:       #ffffff;
            --border:      #d4dbe8;
            --text-main:   #1a2535;
            --text-muted:  #5a6a7e;
            --text-light:  #8a9bb0;
            --green:       #0e7c45;
            --green-bg:    #d8f3e7;
            --orange:      #b55e00;
            --orange-bg:   #fdebd0;
            --blue-soft:   #dce8ff;
            --blue-text:   #1a3a6b;
            --sidebar-w:   248px;
        }

        body {
            background: var(--page-bg);
            color: var(--text-main);
            font-family: 'Source Sans 3', sans-serif;
            font-size: 14px;
        }

        /* ─── SIDEBAR ─── */
        .sidebar {
            width: var(--sidebar-w);
            height: 100vh;
            position: fixed;
            top: 0; left: 0;
            background: var(--navy);
            display: flex;
            flex-direction: column;
            z-index: 100;
            border-right: 1px solid var(--navy-dark);
        }

        .sidebar-brand {
            padding: 22px 20px 16px;
            border-bottom: 1px solid rgba(255,255,255,.1);
        }

        .sidebar-brand .brand-row {
            display: flex; align-items: center; gap: 12px;
        }

        .brand-shield {
            width: 36px; height: 36px;
            background: rgba(255,255,255,.15);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: #fff;
            flex-shrink: 0;
        }

        .brand-name {
            font-family: 'Source Serif 4', serif;
            font-size: 17px; font-weight: 700;
            color: #fff; line-height: 1;
        }

        .brand-sub {
            font-size: 10.5px;
            color: rgba(255,255,255,.55);
            margin-top: 3px;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .sidebar-section {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            color: rgba(255,255,255,.35);
            padding: 18px 20px 8px;
        }

        .sidebar nav {
            flex: 1;
            overflow-y: auto;
            padding: 0 10px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            margin-bottom: 2px;
            border-radius: 6px;
            color: rgba(255,255,255,.75);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            transition: background .18s, color .18s;
        }

        .sidebar a i { font-size: 17px; flex-shrink: 0; }

        .sidebar a:hover {
            background: rgba(255,255,255,.1);
            color: #fff;
        }

        .sidebar a.active {
            background: rgba(255,255,255,.18);
            color: #fff;
            font-weight: 600;
        }

        .sidebar-footer {
            padding: 12px 10px;
            border-top: 1px solid rgba(255,255,255,.1);
        }

        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            color: rgba(255,255,255,.6);
            text-decoration: none;
            font-size: 13.5px;
            transition: background .18s, color .18s;
        }
        .sidebar-footer a:hover {
            background: rgba(255,255,255,.08);
            color: rgba(255,255,255,.9);
        }
        .sidebar-footer a i { font-size: 17px; }

        /* ─── MAIN CONTENT ─── */
        .main {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        /* ─── TOPBAR ─── */
        .topbar {
            position: sticky; top: 0; z-index: 50;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 28px;
            height: 60px;
            background: var(--white);
            border-bottom: 1px solid var(--border);
        }

        @keyframes gbFadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes gbSoftScale {
            from { opacity: 0; transform: translateY(10px) scale(.985); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes gbGlowPulse {
            0%, 100% { box-shadow: 0 1px 4px rgba(26,58,107,.06); }
            50% { box-shadow: 0 8px 20px rgba(26,58,107,.10); }
        }

        body.gb-motion-ready .gb-motion-item {
            opacity: 0;
            transform: translateY(18px);
            will-change: transform, opacity;
            transition: opacity .32s cubic-bezier(.2,.8,.2,1), transform .32s cubic-bezier(.2,.8,.2,1), box-shadow .18s ease, border-color .18s ease;
            transition-delay: var(--gb-delay, 0ms);
        }

        body.gb-motion-ready .gb-motion-item.gb-visible {
            opacity: 1;
            transform: translateY(0);
        }

        body.gb-motion-ready .gb-motion-item.gb-scale {
            transform: translateY(14px) scale(.975);
        }

        body.gb-motion-ready .gb-motion-item.gb-scale.gb-visible {
            transform: translateY(0) scale(1);
        }

        body.gb-motion-ready .gb-motion-item.gb-table-row {
            transform: translateY(10px);
        }

        body.gb-motion-ready .gb-motion-item.gb-table-row.gb-visible {
            transform: translateY(0);
        }

        .topbar-title {
            font-family: 'Source Serif 4', serif;
            font-size: 26px; font-weight: 700;
            color: var(--text-main);
            background: linear-gradient(180deg, rgba(26,58,107,.06) 0%, rgba(26,58,107,.03) 100%);
            border: 1px solid rgba(26,58,107,.12);
            border-left: 4px solid var(--navy);
            padding: 7px 16px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            line-height: 1;
            box-shadow: 0 2px 8px rgba(26,58,107,.04);
            letter-spacing: .2px;
            animation: gbFadeUp .45s ease both;
        }

        .topbar-right {
            display: flex; align-items: center; gap: 12px;
            position: relative;
        }

        .icon-btn {
            width: 34px; height: 34px;
            border: 1px solid var(--border);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-muted); font-size: 17px;
            cursor: pointer;
            transition: background .15s, border-color .15s, transform .15s ease, box-shadow .15s ease;
        }
        .icon-btn:hover { background: var(--navy-light); border-color: var(--navy); color: var(--navy); transform: translateY(-1px); box-shadow: 0 6px 14px rgba(26,58,107,.08); }
        .icon-btn:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(26,58,107,.06); }

        .topbar-divider {
            width: 1px; height: 28px;
            background: var(--border);
        }

        .user-pill {
            display: flex; align-items: center; gap: 10px;
            cursor: pointer;
            border-radius: 8px;
            padding: 4px 6px;
            transition: background .15s, transform .15s ease, box-shadow .15s ease;
        }

        .user-pill:hover,
        .user-pill:focus-visible {
            background: #f4f7fb;
            outline: none;
            transform: translateY(-1px);
            box-shadow: 0 8px 16px rgba(26,58,107,.08);
        }

        .user-avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: var(--navy);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }

        .user-text strong { display: block; font-size: 13px; font-weight: 600; line-height: 1.2; }
        .user-text span { font-size: 11.5px; color: var(--text-light); }

        /* ─── PAGE BODY ─── */
        .page-body {
            padding: 24px 28px;
            flex: 1;
        }

        /* ─── CARD BOX ─── */
        .card-box {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 22px 24px;
            box-shadow: 0 1px 4px rgba(26,58,107,.06);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
            animation: gbSoftScale .4s ease both;
        }

        .card-box:hover {
            transform: translateY(-4px) scale(1.005);
            box-shadow: 0 16px 34px rgba(26,58,107,.14);
            border-color: rgba(26,58,107,.20);
        }

        /* ─── STAT CARDS ─── */
        .stat-cards-row {
            display: flex;
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-cards-row .card-box {
            flex: 1;
        }

        .stat-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .stat-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .stat-value {
            font-family: 'Source Serif 4', serif;
            font-size: 36px; font-weight: 700;
            color: var(--text-main);
            line-height: 1;
        }

        .stat-sub {
            font-size: 11.5px;
            color: var(--text-light);
            margin-top: 4px;
        }

        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .stat-blue   { background: var(--blue-soft);   color: var(--navy); }
        .stat-orange { background: var(--orange-bg);   color: var(--orange); }
        .stat-green  { background: var(--green-bg);    color: var(--green); }

        /* ─── TABLE ─── */
        .table-wrap { overflow-x: auto; margin-top: 14px; }

        .table-dashboard {
            width: 100%;
            border-collapse: collapse;
        }

        .table-dashboard thead th {
            background: #f4f7fb;
            color: var(--text-muted);
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: .8px;
            text-transform: uppercase;
            padding: 11px 16px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            text-align: center;
        }

        .table-dashboard thead th:first-child { border-radius: 6px 0 0 0; }
        .table-dashboard thead th:last-child  { border-radius: 0 6px 0 0; }

        .table-dashboard tbody td {
            padding: 13px 16px;
            border-bottom: 1px solid #edf1f7;
            color: var(--text-main);
            font-size: 13.5px;
            vertical-align: middle;
            text-align: center;
        }

        .table-dashboard tbody tr:last-child td { border-bottom: none; }

        .table-dashboard tbody tr:hover td { background: #f9fbff; }

        .table-dashboard tbody tr {
            transition: transform .16s ease, box-shadow .16s ease;
        }

        .table-dashboard tbody tr:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(26,58,107,.05);
        }

        .table-dashboard tbody tr.gb-motion-item {
            transition: opacity .35s ease, transform .35s ease, background .15s ease;
        }

        /* ─── BADGES ─── */
        .badge-status {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: .2px;
        }

        .badge-status::before {
            content: '';
            width: 6px; height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .pending  { background: var(--orange-bg); color: var(--orange); }
        .pending::before  { background: var(--orange); }

        .progress { background: var(--blue-soft);  color: var(--blue-text); }
        .progress::before { background: var(--navy); }

        .resolved { background: var(--green-bg);   color: var(--green); }
        .resolved::before { background: var(--green); }

        /* ─── TABLE ACTION ─── */
        .table-action {
            display: inline-flex; align-items: center; gap: 4px;
            color: var(--navy);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            padding: 5px 10px;
            border: 1px solid var(--border);
            border-radius: 5px;
            transition: background .12s ease, border-color .12s ease, transform .12s ease, box-shadow .12s ease, opacity .12s ease;
        }
        .table-action:hover {
            background: var(--navy-light);
            border-color: var(--navy);
            transform: translateY(-2px);
            box-shadow: 0 10px 18px rgba(26,58,107,.12);
        }
        .table-action:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(26,58,107,.06); }
        .table-action i { font-size: 14px; }

        .btn-add-case,
        .btn-filter,
        .btn-reset,
        .btn-submit,
        .btn-cancel,
        .btn-save-profile,
        .btn-change-password,
        .btn-cancel-profile,
        .btn-download,
        .btn-edit,
        .acs-save,
        .acs-cancel,
        .acs-back-btn,
        .uc-save,
        .uc-cancel,
        .uc-back-btn,
        .vc-update-btn,
        .vc-back-btn,
        .table-action,
        .home-view-btn,
        .ac-view-btn,
        .ac-delete-btn,
        .ac-tab {
            transition: transform .08s ease !important, box-shadow .08s ease !important, background .08s ease !important, border-color .08s ease !important, color .08s ease !important, opacity .08s ease !important;
        }

        .btn-add-case:hover,
        .btn-filter:hover,
        .btn-reset:hover,
        .btn-submit:hover,
        .btn-cancel:hover,
        .btn-save-profile:hover,
        .btn-change-password:hover,
        .btn-cancel-profile:hover,
        .btn-download:hover,
        .btn-edit:hover,
        .acs-save:hover,
        .acs-cancel:hover,
        .acs-back-btn:hover,
        .uc-save:hover,
        .uc-cancel:hover,
        .uc-back-btn:hover,
        .vc-update-btn:hover,
        .vc-back-btn:hover,
        .table-action:hover,
        .home-view-btn:hover,
        .ac-view-btn:hover,
        .ac-delete-btn:hover,
        .ac-tab:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 7px 14px rgba(26,58,107,.09) !important;
        }

        .btn-add-case:active,
        .btn-filter:active,
        .btn-reset:active,
        .btn-submit:active,
        .btn-cancel:active,
        .btn-save-profile:active,
        .btn-change-password:active,
        .btn-cancel-profile:active,
        .btn-download:active,
        .btn-edit:active,
        .acs-save:active,
        .acs-cancel:active,
        .acs-back-btn:active,
        .uc-save:active,
        .uc-cancel:active,
        .uc-back-btn:active,
        .vc-update-btn:active,
        .vc-back-btn:active,
        .table-action:active,
        .home-view-btn:active,
        .ac-view-btn:active,
        .ac-delete-btn:active,
        .ac-tab:active {
            transform: translateY(0) !important;
            box-shadow: 0 2px 6px rgba(26,58,107,.06) !important;
        }

        .ac-view-btn,
        .ac-delete-btn {
            transition: background .08s ease, border-color .08s ease, color .08s ease, transform .08s ease, box-shadow .08s ease, opacity .08s ease;
        }

        .ac-view-btn::after,
        .ac-delete-btn::after {
            display: none;
        }

        .ac-view-btn:hover,
        .ac-delete-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(26,58,107,.08);
        }

        .ac-view-btn:active,
        .ac-delete-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(26,58,107,.06);
        }

        /* ─── SECTION HEADING ─── */
        .section-heading {
            font-family: 'Source Serif 4', serif;
            font-size: 16px; font-weight: 600;
            color: var(--text-main);
            margin-bottom: 4px;
        }
        .section-heading-sub {
            font-size: 12.5px; color: var(--text-light);
            margin-bottom: 16px;
        }

        /* ─── WELCOME BANNER ─── */
        .welcome-banner {
            background: var(--navy);
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: gbFadeUp .45s ease both;
        }

        .welcome-banner h5 {
            font-family: 'Source Serif 4', serif;
            font-size: 18px; font-weight: 600;
            color: #fff; margin: 0 0 4px;
        }

        .welcome-banner p {
            font-size: 13px;
            color: rgba(255,255,255,.65);
            margin: 0;
        }

        .welcome-banner .banner-date {
            font-size: 12px;
            color: rgba(255,255,255,.5);
            text-align: right;
            white-space: nowrap;
        }

        /* ─── RESPONSIVE ─── */
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main { margin-left: 0; }
            .page-body { padding: 16px; }
            .topbar { padding: 0 16px; }
            .welcome-banner { flex-direction: column; align-items: flex-start; gap: 8px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }

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
            border: 1px solid #dbe3ef;
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

        .admin-toast.show { opacity: 1; transform: translateY(0); }

        .admin-toast-icon {
            width: 26px; height: 26px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; flex-shrink: 0; margin-top: 1px;
        }

        .admin-toast-body { min-width: 0; flex: 1; }
        .admin-toast-title { font-size: 12.5px; font-weight: 700; color: var(--text-main); line-height: 1.25; margin-bottom: 2px; }
        .admin-toast-message { font-size: 12px; color: var(--text-muted); line-height: 1.35; }
        .admin-toast-close { border: none; background: transparent; color: #94a3b8; font-size: 14px; cursor: pointer; align-self: flex-start; line-height: 1; padding: 2px; }
        .admin-toast.success .admin-toast-icon { background: #d8f3e7; color: #0e7c45; }
        .admin-toast.info .admin-toast-icon { background: #dce8ff; color: #1a3a6b; }
        .admin-toast.error .admin-toast-icon { background: #fdebd0; color: #b55e00; }

        .admin-confirm-overlay {
            position: fixed;
            inset: 0;
            z-index: 190;
            background: rgba(10, 18, 32, .45);
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

        .admin-confirm-overlay.show .admin-confirm { transform: translateY(0) scale(1); }
        .admin-confirm-head { padding: 14px 16px 10px; border-bottom: 1px solid #edf2f7; display: flex; align-items: flex-start; gap: 10px; }
        .admin-confirm-icon { width: 30px; height: 30px; border-radius: 9px; background: #e8ecf0; color: #1a3a6b; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; margin-top: 1px; }
        .admin-confirm-title { font-size: 14px; font-weight: 700; color: #111827; line-height: 1.3; margin-bottom: 3px; }
        .admin-confirm-message { font-size: 12.5px; color: #5a6a7e; line-height: 1.4; }
        .admin-confirm-actions { padding: 12px 16px 14px; display: flex; justify-content: flex-end; gap: 8px; }
        .admin-confirm-btn { border: 1px solid #dbe3ef; background: #fff; color: #475569; border-radius: 9px; padding: 8px 14px; font-size: 12.5px; font-weight: 600; cursor: pointer; }
        .admin-confirm-btn.primary { background: var(--navy); border-color: var(--navy); color: #fff; }
        .admin-confirm-btn.primary:hover { background: var(--navy-dark); border-color: var(--navy-dark); }

        .notif-popover {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 320px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 14px 28px rgba(15,23,42,.14);
            overflow: hidden;
            z-index: 120;
            opacity: 0;
            transform: translateY(-6px);
            pointer-events: none;
            transition: opacity .18s ease, transform .18s ease;
        }

        .notif-popover.open {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .notif-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-bottom: 1px solid #eef2f7;
            background: #fbfdff;
        }

        .notif-head strong {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-main);
        }

        .notif-count {
            font-size: 11px;
            color: var(--text-light);
        }

        .notif-list {
            max-height: 280px;
            overflow-y: auto;
        }

        .notif-item {
            display: block;
            text-decoration: none;
            color: inherit;
            border-bottom: 1px solid #eef2f7;
            padding: 10px 12px;
            transition: background .15s;
        }

        .notif-item:hover {
            background: #f8fbff;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-item-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 2px;
        }

        .notif-item-message {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.35;
        }

        .notif-item-time {
            margin-top: 4px;
            font-size: 11px;
            color: var(--text-light);
        }

        .notif-empty {
            font-size: 12.5px;
            color: var(--text-muted);
            padding: 16px 14px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .notif-popover {
                width: min(320px, calc(100vw - 24px));
                right: 0;
            }
        }
    </style>
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
            toast.innerHTML = '<div class="admin-toast-icon"><i class="uil ' + iconFor(t) + '"></i></div>' +
                '<div class="admin-toast-body"><div class="admin-toast-title"></div><div class="admin-toast-message"></div></div>' +
                '<button type="button" class="admin-toast-close" aria-label="Close">&times;</button>';

            toast.querySelector('.admin-toast-title').textContent = title || 'Notice';
            toast.querySelector('.admin-toast-message').textContent = message || '';

            root.appendChild(toast);
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
                const done = function (result) {
                    if (settled) return;
                    settled = true;
                    document.removeEventListener('keydown', onKey);
                    closeOverlay(overlay);
                    resolve(result);
                };

                function onKey(e) {
                    if (!active || active !== overlay) return;
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        done(false);
                    }
                }

                overlay.addEventListener('click', function (e) {
                    if (e.target === overlay) done(false);
                });

                overlay.querySelector('[data-action="cancel"]').addEventListener('click', function () { done(false); });
                overlay.querySelector('[data-action="confirm"]').addEventListener('click', function () { done(true); });

                document.addEventListener('keydown', onKey);
                document.body.appendChild(overlay);
                requestAnimationFrame(function () { overlay.classList.add('show'); });
                active = overlay;
            });
        }

        return { confirm: confirm };
    })();

    document.addEventListener('DOMContentLoaded', function () {
        const logoutLink = document.querySelector('.sidebar-footer a[href="../logout.php"]');
        if (!logoutLink || !window.AdminConfirm) return;

        logoutLink.addEventListener('click', async function (e) {
            e.preventDefault();
            const ok = await window.AdminConfirm.confirm({
                title: 'Logout Now?',
                message: 'You will be signed out of the Barangay portal.',
                confirmText: 'Logout',
                cancelText: 'Stay Here'
            });
            if (ok) window.location.href = logoutLink.href;
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        const topbarRight = document.querySelector('.topbar .topbar-right');
        const bellBtn = topbarRight ? (topbarRight.querySelector('.notif-btn') || topbarRight.querySelector('.icon-btn[title="Notifications"]')) : null;

        if (topbarRight && bellBtn) {
            let popover = topbarRight.querySelector('.notif-popover');
            if (!popover) {
                popover = document.createElement('div');
                popover.className = 'notif-popover';
                popover.innerHTML = '' +
                    '<div class="notif-head">' +
                        '<strong>Notifications</strong>' +
                        '<span class="notif-count">0 new</span>' +
                    '</div>' +
                    '<div class="notif-list">' +
                        '<div class="notif-empty">Loading notifications...</div>' +
                    '</div>';

                topbarRight.appendChild(popover);
            }

            bellBtn.setAttribute('role', 'button');
            bellBtn.setAttribute('tabindex', '0');
            bellBtn.setAttribute('aria-label', 'Open notifications');
            bellBtn.setAttribute('aria-expanded', 'false');

            const closePopover = function () {
                popover.classList.remove('open');
                bellBtn.setAttribute('aria-expanded', 'false');
            };

            const setNotifications = function (items) {
                const countEl = popover.querySelector('.notif-count');
                const listEl = popover.querySelector('.notif-list');
                if (!listEl || !countEl) return;

                const safeItems = Array.isArray(items) ? items : [];
                countEl.textContent = safeItems.length + (safeItems.length === 1 ? ' new' : ' new');

                if (safeItems.length === 0) {
                    listEl.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
                    return;
                }

                listEl.innerHTML = safeItems.map(function (item) {
                    const title = String(item.title || 'Case Update')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;');
                    const message = String(item.message || '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;');
                    const time = String(item.time || '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;');
                    const url = String(item.url || '#').replace(/"/g, '&quot;');

                    return '' +
                        '<a class="notif-item" href="' + url + '">' +
                            '<div class="notif-item-title">' + title + '</div>' +
                            '<div class="notif-item-message">' + message + '</div>' +
                            '<div class="notif-item-time">' + time + '</div>' +
                        '</a>';
                }).join('');
            };

            const loadNotifications = function () {
                fetch('notifications.php', { credentials: 'same-origin' })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('Request failed');
                        }
                        return response.json();
                    })
                    .then(function (data) {
                        if (data && data.success) {
                            setNotifications(data.items || []);
                            return;
                        }
                        setNotifications([]);
                    })
                    .catch(function () {
                        const listEl = popover.querySelector('.notif-list');
                        if (listEl) {
                            listEl.innerHTML = '<div class="notif-empty">Unable to load notifications.</div>';
                        }
                    });
            };

            const togglePopover = function () {
                const willOpen = !popover.classList.contains('open');
                if (willOpen) {
                    popover.classList.add('open');
                    bellBtn.setAttribute('aria-expanded', 'true');
                } else {
                    closePopover();
                }
            };

            bellBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                togglePopover();
            });

            bellBtn.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    togglePopover();
                }
            });

            document.addEventListener('click', function (e) {
                if (!topbarRight.contains(e.target)) {
                    closePopover();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closePopover();
                }
            });

            loadNotifications();
        }

        const profilePills = document.querySelectorAll('.topbar .user-pill');
        if (profilePills.length > 0) {
            const path = (window.location.pathname || '').toLowerCase();
            const target = path.includes('/barangay/') ? 'profile.php' : 'barangay/profile.php';

            profilePills.forEach(function (pill) {
                pill.setAttribute('role', 'button');
                pill.setAttribute('tabindex', '0');
                pill.setAttribute('aria-label', 'Open My Profile');

                const goProfile = function () {
                    if (!window.location.pathname.toLowerCase().endsWith('/barangay/profile.php')) {
                        window.location.href = target;
                    }
                };

                pill.addEventListener('click', goProfile);
                pill.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        goProfile();
                    }
                });
            });
        }

        document.body.classList.add('gb-motion-ready');

        const motionSelector = [
            '.welcome-banner',
            '.card-box',
            '.stat-cards-row .card-box',
            '.stat-mini',
            '.an-kpi',
            '.an-status-pill',
            '.an-card',
            '.section-card',
            '.profile-hero',
            '.profile-alert',
            '.page-header',
            '.filter-card',
            '.acs-card',
            '.ac-card',
            '.table-wrap',
            '.table-dashboard',
            '.table-action',
            '.btn-add-case',
            '.btn-filter',
            '.btn-reset',
            '.btn-save-profile',
            '.btn-change-password',
            '.btn-edit',
            '.acs-save',
            '.acs-cancel',
            '.ac-tab',
            '.ac-view-btn',
            '.ac-delete-btn'
        ].join(', ');

        const motionItems = Array.from(document.querySelectorAll(motionSelector));
        const tableRows = Array.from(document.querySelectorAll('.table-dashboard tbody tr'));

        motionItems.forEach(function (item, index) {
            item.classList.add('gb-motion-item');
            if (item.matches('.card-box, .stat-mini, .an-kpi, .an-status-pill, .an-card, .profile-hero, .page-header, .filter-card, .acs-card, .ac-card, .profile-alert, .table-wrap, .table-dashboard')) {
                item.classList.add('gb-scale');
            }
            item.style.setProperty('--gb-delay', (index * 45) + 'ms');
        });

        tableRows.forEach(function (row, index) {
            row.classList.add('gb-motion-item', 'gb-table-row');
            row.style.setProperty('--gb-delay', (index * 35) + 'ms');
        });

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(function (entries, obs) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('gb-visible');
                        obs.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -4px 0px' });

            motionItems.concat(tableRows).forEach(function (item) {
                observer.observe(item);
            });
        } else {
            motionItems.concat(tableRows).forEach(function (item) {
                item.classList.add('gb-visible');
            });
        }
    });
    </script>
</head>
<body>