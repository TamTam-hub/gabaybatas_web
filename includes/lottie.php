<?php
/**
 * Lottie animations: empty states, success toasts and loading screens
 * (files in assets/lottie, credits in assets/lottie/CREDITS.txt).
 * The player (assets/vendor/lottie) loads only when a page shows an animation; until it loads,
 * or if it cannot, the fallback icon is shown instead. Pages live in admin/ or barangay/, so
 * the assets are one folder up.
 */

if (!function_exists('gabaybatas_lottie')) {
    /**
     * @param string $name         animation file in assets/lottie, without ".json"
     * @param string $fallbackIcon Unicons class shown if the animation cannot play
     * @param bool   $once         play once and stop on the last frame (e.g. a success check)
     */
    function gabaybatas_lottie(string $name, int $size = 140, string $fallbackIcon = 'uil-folder-open', bool $once = false): string
    {
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name));
        return '<div class="gb-lottie" data-lottie="../assets/lottie/' . $name . '.json"' . ($once ? ' data-lottie-once' : '')
            . ' style="width:' . $size . 'px;height:' . $size . 'px" aria-hidden="true">'
            . '<i class="uil ' . htmlspecialchars($fallbackIcon, ENT_QUOTES) . ' gb-lottie-fallback"></i></div>';
    }
}

if (!function_exists('gabaybatas_lottie_loader')) {
    /** Printed once in the admin and barangay headers. */
    function gabaybatas_lottie_loader(): void
    {
        ?>
<style>
.gb-lottie { position: relative; margin: 0 auto 6px; display: grid; place-items: center; flex-shrink: 0; }
.gb-lottie svg { position: absolute; inset: 0; }
.gb-lottie-fallback { font-size: 34px; color: #cbd5e1; }
/* Success toast: the animated check replaces the round icon */
.admin-toast-icon .gb-lottie { margin: 0; }
.admin-toast-icon .gb-lottie-fallback { font-size: inherit; color: inherit; }
.admin-toast.success .admin-toast-icon:has(svg) { background: transparent; }
/* Flash banners */
.rv-alert .gb-lottie, .ar-alert .gb-lottie { margin: 0; }
.rv-alert .gb-lottie-fallback, .ar-alert .gb-lottie-fallback { font-size: 18px; color: inherit; }
.gb-loading { position: fixed; inset: 0; z-index: 2000; display: none; align-items: center; justify-content: center; background: rgba(15, 23, 42, .45); backdrop-filter: blur(2px); }
.gb-loading.show { display: flex; animation: gbLoadingIn .18s ease-out; }
.gb-loading-card { min-width: 260px; max-width: 340px; padding: 22px 26px 24px; border-radius: 16px; background: #fff; text-align: center; box-shadow: 0 24px 70px rgba(15, 23, 42, .28); }
.gb-loading-card .gb-lottie { margin-bottom: 4px; }
.gb-loading-card .gb-lottie-fallback { color: #6366f1; animation: gbSpin 1s linear infinite; }
.gb-loading-text { font-size: 14px; font-weight: 700; color: #0f172a; }
.gb-loading-sub { margin-top: 3px; font-size: 12px; color: #64748b; }
@keyframes gbLoadingIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes gbSpin { to { transform: rotate(360deg); } }
</style>
<script>
(function () {
    var src = '../assets/vendor/lottie/lottie_light.min.js', loading = null;
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function player() {
        if (window.lottie) return Promise.resolve(window.lottie);
        if (!loading) {
            loading = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.src = src;
                s.onload = function () { resolve(window.lottie); };
                s.onerror = reject;
                document.head.appendChild(s);
            });
        }
        return loading;
    }
    // Start every animation inside root that has not started yet (also used by the bell and toasts).
    window.gbLottieScan = function (root) {
        var els = (root || document).querySelectorAll('[data-lottie]:not([data-lottie-ready])');
        if (!els.length) return;
        player().then(function (lottie) {
            els.forEach(function (el) {
                el.setAttribute('data-lottie-ready', '1');
                var once = el.hasAttribute('data-lottie-once');
                var anim = lottie.loadAnimation({container: el, renderer: 'svg', loop: !still && !once, autoplay: !still, path: el.getAttribute('data-lottie')});
                anim.addEventListener('DOMLoaded', function () {
                    var fallback = el.querySelector('.gb-lottie-fallback');
                    if (fallback) fallback.remove();
                    if (still) anim.goToAndStop(once ? anim.totalFrames - 1 : Math.floor(anim.totalFrames * 0.45), true);
                });
            });
        }).catch(function () { /* keep the fallback icons */ });
    };

    // ── Loading screen: gbLoading.show('Saving case…') / gbLoading.hide() ──
    var overlay = null, pollTimer = null, giveUpTimer = null;
    function ensureOverlay() {
        if (overlay) return overlay;
        overlay = document.createElement('div');
        overlay.className = 'gb-loading';
        overlay.setAttribute('role', 'status');
        overlay.innerHTML = '<div class="gb-loading-card">'
            + '<div class="gb-lottie" data-lottie="../assets/lottie/loading.json" style="width:170px;height:120px" aria-hidden="true"><i class="uil uil-spinner-alt gb-lottie-fallback"></i></div>'
            + '<div class="gb-loading-text"></div><div class="gb-loading-sub"></div></div>';
        document.body.appendChild(overlay);
        window.gbLottieScan(overlay);
        return overlay;
    }
    window.gbLoading = {
        show: function (text, sub) {
            var el = ensureOverlay();
            el.querySelector('.gb-loading-text').textContent = text || 'Please wait…';
            el.querySelector('.gb-loading-sub').textContent = sub || '';
            el.classList.add('show');
        },
        hide: function () {
            clearInterval(pollTimer);
            clearTimeout(giveUpTimer);
            if (overlay) overlay.classList.remove('show');
        }
    };
    // Coming back with the browser's Back button must not leave the screen up.
    window.addEventListener('pageshow', function (e) { if (e.persisted) window.gbLoading.hide(); });

    // ── Document downloads (PDF reports, KP forms): show the screen until the file arrives ──
    var downloadUrl = /generate_report\.php|kp_monitoring_report\.php|kp_document\.php|[?&]download=pdf/;
    function waitForDownload(token, label) {
        window.gbLoading.show(label, 'Your download will start in a moment.');
        clearInterval(pollTimer);
        clearTimeout(giveUpTimer);
        pollTimer = setInterval(function () {
            if (document.cookie.indexOf('gb_dl=' + token) !== -1) {
                document.cookie = 'gb_dl=; Max-Age=0; path=/';
                window.gbLoading.hide();
            }
        }, 300);
        giveUpTimer = setTimeout(window.gbLoading.hide, 30000);
    }
    function newToken() { return Math.random().toString(36).slice(2, 12) + Date.now().toString(36); }
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || a.target === '_blank') return;
        if (!downloadUrl.test(a.getAttribute('href'))) return;
        var token = newToken();
        var url = new URL(a.href, window.location.href);
        url.searchParams.set('dl_token', token);
        e.preventDefault();
        waitForDownload(token, 'Preparing your PDF…');
        window.location.href = url.toString();
    });
    document.addEventListener('submit', function (e) {
        var form = e.target;
        // The form's own handlers run first; a cancelled submit (e.g. a confirm dialog) shows nothing.
        if (e.defaultPrevented || !downloadUrl.test(form.getAttribute('action') || '')) return;
        var token = newToken(), input = form.querySelector('input[name="dl_token"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'dl_token';
            form.appendChild(input);
        }
        input.value = token;
        waitForDownload(token, /kp_document/.test(form.getAttribute('action')) ? 'Preparing the KP form…' : 'Preparing your document…');
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { window.gbLottieScan(); });
    } else {
        window.gbLottieScan();
    }
})();
</script>
        <?php
    }
}
