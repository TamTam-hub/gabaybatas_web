<?php
/**
 * Pick specific records for a report (barangay Flexible Reports, admin Case Report and User Report).
 * Rows carry <input type="checkbox" class="gb-sel" value="{id}" data-code="C-0037">; ticks are kept
 * in sessionStorage, so they survive changing filters or pages until cleared or the tab closes.
 * The bar links to the PDF and to the page with ?{param}=… (cases: see gabaybatas_flex_params()).
 */

if (!function_exists('gabaybatas_case_selection_bar')) {
    /**
     * @param string $downloadUrl PDF endpoint, e.g. "generate_report.php"
     * @param string $pageUrl     this report page, e.g. "reports.php" (may already have a query)
     * @param string $param       query name carrying the picked ids ("cases" or "users")
     */
    function gabaybatas_case_selection_bar(string $downloadUrl, string $pageUrl, string $param = 'cases'): void
    {
        ?>
<div class="gb-selbar" id="gbSelBar" role="region" aria-label="Selected records" hidden>
    <span class="gb-selbar-count"><i class="uil uil-check-square"></i> <strong id="gbSelCount">0</strong> selected</span>
    <span class="gb-selbar-codes" id="gbSelCodes"></span>
    <a href="#" class="gb-selbar-btn is-primary" id="gbSelDownload"><i class="uil uil-file-download"></i> Download Selected PDF</a>
    <a href="#" class="gb-selbar-btn" id="gbSelShow"><i class="uil uil-filter"></i> Show only these</a>
    <button type="button" class="gb-selbar-btn is-ghost" id="gbSelClear"><i class="uil uil-times"></i> Clear</button>
</div>
<style>
.gb-sel-col { width: 44px; }
.gb-sel, .gb-sel-all { width: 17px; height: 17px; accent-color: #1a3a6b; cursor: pointer; vertical-align: middle; }
.gb-selbar { position: sticky; bottom: 16px; z-index: 20; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 14px; padding: 12px 16px; border-radius: 14px; background: #0e2242; color: #fff; box-shadow: 0 14px 34px rgba(14,34,66,.30); }
.gb-selbar[hidden] { display: none; }
.gb-selbar-count { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; }
.gb-selbar-count i { font-size: 18px; color: #e9c766; }
.gb-selbar-codes { flex: 1; min-width: 120px; font-size: 12.5px; color: rgba(255,255,255,.7); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gb-selbar-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 10px; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid rgba(255,255,255,.25); background: rgba(255,255,255,.08); color: #fff; }
.gb-selbar-btn:hover { background: rgba(255,255,255,.16); }
.gb-selbar-btn.is-primary { background: #fff; color: #0e2242; border-color: #fff; }
.gb-selbar-btn.is-primary:hover { background: #eef3fb; }
.gb-selbar-btn.is-ghost { background: transparent; }
</style>
<script>
(function () {
    const KEY = 'gbCaseSelection:' + location.pathname;
    const DOWNLOAD = <?= json_encode($downloadUrl) ?>;
    const PAGE = <?= json_encode($pageUrl) ?>;
    const PARAM = <?= json_encode($param) ?>;
    const join = (url, query) => url + (url.indexOf('?') === -1 ? '?' : '&') + query;
    let picked = {};
    try { picked = JSON.parse(sessionStorage.getItem(KEY) || '{}') || {}; } catch (e) { picked = {}; }

    const boxes = Array.from(document.querySelectorAll('.gb-sel'));
    const all = document.querySelector('.gb-sel-all');
    const bar = document.getElementById('gbSelBar');
    const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify(picked)); } catch (e) { /* not kept */ } };

    function render() {
        const ids = Object.keys(picked);
        const query = PARAM + '=' + ids.join(',');
        bar.hidden = ids.length === 0;
        document.getElementById('gbSelCount').textContent = ids.length;
        document.getElementById('gbSelCodes').textContent = Object.values(picked).join(', ');
        document.getElementById('gbSelDownload').href = join(DOWNLOAD, query);
        document.getElementById('gbSelShow').href = join(PAGE, query);
        boxes.forEach(b => { b.checked = Object.prototype.hasOwnProperty.call(picked, b.value); });
        if (all) all.checked = boxes.length > 0 && boxes.every(b => b.checked);
    }
    function set(box, on) {
        if (on) picked[box.value] = box.dataset.code; else delete picked[box.value];
    }
    boxes.forEach(b => b.addEventListener('change', () => { set(b, b.checked); save(); render(); }));
    if (all) all.addEventListener('change', () => { boxes.forEach(b => set(b, all.checked)); save(); render(); });
    document.getElementById('gbSelClear').addEventListener('click', () => { picked = {}; save(); render(); });
    render();
})();
</script>
        <?php
    }
}
