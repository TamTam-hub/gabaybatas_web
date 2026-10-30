<?php
/**
 * Header bell ("Recent Activity") shared by the admin and barangay headers.
 * Set $gbBellEndpoint (JSON feed from includes/activity_feed.php) before
 * including. Read state is kept per user in the browser.
 */
$gbBellEndpoint = $gbBellEndpoint ?? 'notifications.php';
$gbBellKey = 'gb.bell.seen.' . ($_SESSION['role'] ?? 'user') . '.' . (int)($_SESSION['user_id'] ?? 0);
?>
<style>
    .gb-bell-btn { position: relative; }
    .gb-bell-badge { position: absolute; top: -4px; right: -4px; min-width: 17px; height: 17px; padding: 0 4px; border-radius: 999px;
        background: #ef4444; color: #fff; font-size: 10px; font-weight: 700; line-height: 17px; text-align: center;
        border: 2px solid #fff; box-sizing: content-box; display: none; }
    .gb-bell-badge.show { display: block; }
    .topbar .notif-dot { display: none !important; }

    .gb-bell-pop { position: absolute; top: calc(100% + 10px); right: 0; width: 380px; max-width: calc(100vw - 24px); background: #fff;
        border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 18px 40px rgba(15, 23, 42, .16); z-index: 1200;
        display: none; }
    .gb-bell-pop.open { display: block; animation: gb-bell-in .14s ease-out; }
    @keyframes gb-bell-in { from { transform: translateY(-6px); } to { transform: none; } }
    .gb-bell-head { display: flex; align-items: center; justify-content: space-between; padding: 13px 16px; border-bottom: 1px solid #eef2f7; }
    .gb-bell-head strong { font-size: 14px; color: #0f172a; }
    .gb-bell-head small { display: block; font-size: 11.5px; color: #64748b; font-weight: 500; margin-top: 1px; }
    .gb-bell-read { border: none; background: none; color: #1d4ed8; font-size: 12px; font-weight: 600; cursor: pointer; padding: 4px 6px; border-radius: 6px; }
    .gb-bell-read:hover { background: #eff6ff; }
    .gb-bell-read:disabled { color: #94a3b8; cursor: default; background: none; }
    .gb-bell-list { max-height: 420px; overflow-y: auto; }
    .gb-bell-item { display: flex; gap: 11px; padding: 11px 16px; text-decoration: none; border-bottom: 1px solid #f1f5f9; position: relative; transition: background .12s; }
    .gb-bell-item:last-child { border-bottom: none; }
    .gb-bell-item:hover { background: #f8fafc; }
    .gb-bell-item.unread { background: #f5f9ff; }
    .gb-bell-item.unread::before { content: ""; position: absolute; left: 6px; top: 50%; width: 6px; height: 6px; margin-top: -3px; border-radius: 50%; background: #2563eb; }
    .gb-bell-icon { width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 17px; }
    .gb-bell-body { flex: 1; min-width: 0; }
    .gb-bell-title { font-size: 12.8px; font-weight: 600; color: #0f172a; line-height: 1.35; }
    .gb-bell-meta { font-size: 11.5px; color: #64748b; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gb-bell-time { font-size: 11px; color: #94a3b8; margin-top: 2px; }
    .gb-bell-empty { padding: 30px 16px; text-align: center; color: #94a3b8; font-size: 12.5px; }
    .gb-bell-empty i { display: block; font-size: 28px; margin-bottom: 6px; color: #cbd5e1; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const host = document.querySelector('.topbar .topbar-right');
    const btn = host ? host.querySelector('.notif-btn, .icon-btn[title="Notifications"]') : null;
    if (!host || !btn) return;

    const ENDPOINT = <?= json_encode($gbBellEndpoint) ?>;
    const SEEN_KEY = <?= json_encode($gbBellKey) ?>;
    const readSeen = function () { try { return Number(localStorage.getItem(SEEN_KEY) || 0); } catch (e) { return 0; } };
    const writeSeen = function (ts) { try { localStorage.setItem(SEEN_KEY, String(ts)); } catch (e) { /* private mode */ } };

    btn.classList.add('gb-bell-btn');
    btn.setAttribute('role', 'button');
    btn.setAttribute('tabindex', '0');
    btn.setAttribute('aria-label', 'Recent activity');
    btn.setAttribute('aria-expanded', 'false');
    btn.removeAttribute('title');

    const badge = document.createElement('span');
    badge.className = 'gb-bell-badge';
    btn.appendChild(badge);

    const pop = document.createElement('div');
    pop.className = 'gb-bell-pop';
    pop.innerHTML = '<div class="gb-bell-head"><div><strong>Recent Activity</strong><small class="gb-bell-sub">Loading…</small></div>'
        + '<button type="button" class="gb-bell-read" disabled>Mark all as read</button></div>'
        + '<div class="gb-bell-list"><div class="gb-bell-empty">Loading…</div></div>';
    host.appendChild(pop);
    const list = pop.querySelector('.gb-bell-list');
    const sub = pop.querySelector('.gb-bell-sub');
    const readBtn = pop.querySelector('.gb-bell-read');

    let items = [];
    let failed = false;

    function ago(ts) {
        const diff = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
        if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
        if (diff < 172800) return 'Yesterday';
        if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
        return new Date(ts * 1000).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function render() {
        const seen = readSeen();
        const unread = items.filter(function (i) { return i.timestamp > seen; }).length;
        badge.textContent = unread > 9 ? '9+' : String(unread);
        badge.classList.toggle('show', unread > 0);
        readBtn.disabled = unread === 0;
        sub.textContent = failed ? 'Unable to load activity'
            : (unread ? unread + ' new · ' : '') + items.length + ' recent ' + (items.length === 1 ? 'item' : 'items');

        list.innerHTML = '';
        if (!items.length) {
            list.innerHTML = '<div class="gb-bell-empty"><i class="uil uil-bell-slash"></i>' + (failed ? 'Activity could not be loaded.' : 'No recent activity yet.') + '</div>';
            return;
        }
        items.forEach(function (n) {
            const a = document.createElement('a');
            a.className = 'gb-bell-item' + (n.timestamp > seen ? ' unread' : '');
            a.href = n.url || '#';
            a.title = new Date(n.timestamp * 1000).toLocaleString();
            a.innerHTML = '<div class="gb-bell-icon"><i class="uil"></i></div>'
                + '<div class="gb-bell-body"><div class="gb-bell-title"></div><div class="gb-bell-meta"></div><div class="gb-bell-time"></div></div>';
            const icon = a.querySelector('.gb-bell-icon');
            icon.style.background = (n.color || '#64748b') + '1a';
            icon.style.color = n.color || '#64748b';
            icon.querySelector('i').classList.add(n.icon || 'uil-bell');
            a.querySelector('.gb-bell-title').textContent = n.title || 'Activity';
            a.querySelector('.gb-bell-meta').textContent = n.meta || '';
            a.querySelector('.gb-bell-time').textContent = ago(n.timestamp);
            list.appendChild(a);
        });
    }

    function load() {
        fetch(ENDPOINT + (ENDPOINT.indexOf('?') < 0 ? '?' : '&') + 't=' + Date.now(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('feed'); return r.json(); })
            .then(function (d) { items = Array.isArray(d.items) ? d.items : []; failed = false; render(); })
            .catch(function () { failed = true; render(); });
    }

    function markAllRead() {
        const newest = items.reduce(function (m, i) { return Math.max(m, i.timestamp || 0); }, 0);
        if (newest > readSeen()) writeSeen(newest);
        render();
    }

    function setOpen(open) {
        const wasOpen = pop.classList.contains('open');
        pop.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        // Items stay highlighted while the panel is open and count as read once it closes.
        if (wasOpen && !open) markAllRead();
    }

    btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); setOpen(!pop.classList.contains('open')); });
    btn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setOpen(!pop.classList.contains('open')); }
    });
    readBtn.addEventListener('click', function (e) { e.stopPropagation(); markAllRead(); });
    pop.addEventListener('click', function (e) { e.stopPropagation(); });
    list.addEventListener('click', function () { markAllRead(); });
    document.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });

    load();
    setInterval(load, 60000);
});
</script>
