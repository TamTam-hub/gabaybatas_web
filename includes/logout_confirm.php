<?php
/**
 * The sidebar Logout button, the same on the admin and barangay portals: one look and
 * hover animation, and a red "Logout Now?" confirmation (window.AdminConfirm, danger style).
 * The link in the sidebars carries class="gb-logout".
 */

if (!function_exists('gabaybatas_logout_confirm')) {
    /** @param string $portal shown in the confirmation, e.g. "Admin" or "Barangay" */
    function gabaybatas_logout_confirm(string $portal): void
    {
        ?>
<style>
.sidebar .sidebar-footer a.gb-logout {
    display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: 10px;
    color: rgba(255,255,255,.78); font-size: 14px; font-weight: 600; text-decoration: none;
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.10);
    transition: background .2s ease, color .2s ease, border-color .2s ease;
}
.sidebar .sidebar-footer a.gb-logout i { font-size: 18px; transition: transform .25s ease, color .2s ease; }
.sidebar .sidebar-footer a.gb-logout:hover { background: rgba(239,68,68,.16); border-color: rgba(248,113,113,.38); color: #fecaca; }
.sidebar .sidebar-footer a.gb-logout:hover i { transform: translateX(4px); color: #f87171; }
.sidebar .sidebar-footer a.gb-logout:active { transform: scale(.98); }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const link = document.querySelector('.sidebar-footer a.gb-logout');
    if (!link || !window.AdminConfirm) return;
    link.addEventListener('click', async function (e) {
        e.preventDefault();
        const ok = await window.AdminConfirm.confirm({
            title: 'Logout Now?',
            message: <?= json_encode('You will be signed out of the ' . $portal . ' portal.') ?>,
            confirmText: 'Logout',
            cancelText: 'Stay Here',
            danger: true
        });
        if (ok) window.location.href = link.href;
    });
});
</script>
        <?php
    }
}
