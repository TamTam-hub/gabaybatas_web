<?php
/**
 * Shared table pager for the admin lists (Manage Users, Manage Cases, Records Archive):
 * "Showing 1–10 of 57", rows-per-page choice and numbered pages, so a long list
 * never has to be scrolled through.
 */

if (!function_exists('gabaybatas_per_page')) {
    /** Rows per page from ?per_page=, limited to the offered choices. */
    function gabaybatas_per_page(int $default = 10): int
    {
        $value = (int)($_GET['per_page'] ?? $default);
        return in_array($value, [10, 25, 50, 100], true) ? $value : $default;
    }
}

if (!function_exists('gabaybatas_pagination')) {
    /**
     * @param array  $query  current filters (kept on every link); 'page' and 'per_page' are set here
     * @param string $noun   plural word for the "Showing" line, e.g. "cases"
     */
    function gabaybatas_pagination(int $total, int $page, int $perPage, array $query, string $noun = 'records'): void
    {
        static $styled = false;
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $from = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
        $to = min($page * $perPage, $total);
        $query = array_filter($query, static fn($v) => $v !== '' && $v !== null);
        $link = static fn(int $p): string => '?' . htmlspecialchars(http_build_query($query + ['per_page' => $perPage, 'page' => $p]));

        // First, last, and two pages either side of the current one.
        $numbers = [];
        for ($p = 1; $p <= $pages; $p++) {
            if ($p === 1 || $p === $pages || abs($p - $page) <= 2) {
                $numbers[] = $p;
            }
        }
        ?>
        <div class="gb-pg">
            <div class="gb-pg-meta">
                <span>Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= $total ?></strong> <?= htmlspecialchars($noun) ?></span>
                <form method="GET" class="gb-pg-size">
                    <?php foreach ($query as $k => $v): if ($k === 'page' || $k === 'per_page') continue; ?>
                    <input type="hidden" name="<?= htmlspecialchars((string)$k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                    <?php endforeach; ?>
                    <label>Rows per page
                        <select name="per_page" onchange="this.form.submit()">
                            <?php foreach ([10, 25, 50, 100] as $n): ?>
                            <option value="<?= $n ?>" <?= $n === $perPage ? 'selected' : '' ?>><?= $n ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            </div>
            <?php if ($pages > 1): ?>
            <nav class="gb-pg-nav" aria-label="Pages">
                <a class="gb-pg-btn <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $link(max(1, $page - 1)) ?>" aria-label="Previous page"><i class="uil uil-angle-left"></i> Prev</a>
                <?php $prev = 0; foreach ($numbers as $p): ?>
                    <?php if ($p - $prev > 1): ?><span class="gb-pg-gap">…</span><?php endif; ?>
                    <a class="gb-pg-btn gb-pg-num <?= $p === $page ? 'is-current' : '' ?>" href="<?= $link($p) ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a>
                <?php $prev = $p; endforeach; ?>
                <a class="gb-pg-btn <?= $page >= $pages ? 'is-disabled' : '' ?>" href="<?= $link(min($pages, $page + 1)) ?>" aria-label="Next page">Next <i class="uil uil-angle-right"></i></a>
            </nav>
            <?php endif; ?>
        </div>
        <?php if (!$styled): $styled = true; ?>
        <style>
        .gb-pg{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:14px 20px;border-top:1px solid #eef2f7;background:#fcfdff;}
        .gb-pg-meta{display:flex;align-items:center;gap:16px;flex-wrap:wrap;font-size:12.5px;color:#64748b;}
        .gb-pg-meta strong{color:#1e293b;}
        .gb-pg-size label{display:inline-flex;align-items:center;gap:7px;font-size:12px;color:#64748b;}
        .gb-pg-size select{height:30px;border:1px solid #dbe3ef;border-radius:7px;padding:0 6px;font-size:12px;color:#334155;background:#fff;font-family:inherit;cursor:pointer;}
        .gb-pg-nav{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
        .gb-pg-btn{display:inline-flex;align-items:center;justify-content:center;gap:3px;min-width:32px;height:32px;padding:0 10px;border:1px solid #dbe3ef;border-radius:8px;background:#fff;color:#334155;font-size:12.5px;font-weight:600;text-decoration:none;transition:border-color .15s,background .15s,color .15s;}
        .gb-pg-btn:hover{border-color:#93c5fd;color:#1d4ed8;background:#f0f7ff;}
        .gb-pg-btn.is-current{background:#1d4ed8;border-color:#1d4ed8;color:#fff;}
        .gb-pg-btn.is-disabled{pointer-events:none;opacity:.4;}
        .gb-pg-num{padding:0 6px;}
        .gb-pg-gap{color:#94a3b8;padding:0 2px;}
        @media(max-width:640px){.gb-pg{flex-direction:column;align-items:flex-start;}}
        </style>
        <?php endif;
    }
}
