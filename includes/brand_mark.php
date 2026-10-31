<?php
// GabayBatas logo: a shield holding the scales of justice (drawn on a 64×64 grid, set slightly low so the shield looks centred).
// The lines follow the surrounding text colour; the balance point is gold.

if (!function_exists('gabaybatas_brand_mark')) {
    function gabaybatas_brand_mark(string $size = '1.4em'): string
    {
        $size = htmlspecialchars($size, ENT_QUOTES);
        return '<svg class="gb-brand-mark" viewBox="0 -1.75 64 64" width="' . $size . '" height="' . $size . '" style="display:block" aria-hidden="true" focusable="false">'
            . '<path d="M32 5 L53 12.5 V30 C53 43.5 44 52.5 32 59 C20 52.5 11 43.5 11 30 V12.5 Z" fill="none" stroke="currentColor" stroke-width="3.6" stroke-linejoin="round"/>'
            . '<g fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M32 21 V44"/><path d="M25.5 45.5 H38.5"/><path d="M21 25 H43"/>'
            . '<path d="M21 25 L17.5 34 M21 25 L24.5 34"/><path d="M43 25 L39.5 34 M43 25 L46.5 34"/></g>'
            . '<path d="M16.5 34 H25.5 Q21 40 16.5 34 Z M38.5 34 H47.5 Q43 40 38.5 34 Z" fill="currentColor"/>'
            . '<circle cx="32" cy="20" r="3" fill="#c9a227"/>'
            . '</svg>';
    }
}

if (!function_exists('gabaybatas_favicon_links')) {
    /** Browser-tab icon links; $base is the path back to the site root ('' or '../'). */
    function gabaybatas_favicon_links(string $base = ''): string
    {
        $base = htmlspecialchars($base, ENT_QUOTES);
        return '<link rel="icon" type="image/svg+xml" href="' . $base . 'images/favicon.svg">'
            . '<link rel="icon" type="image/png" sizes="32x32" href="' . $base . 'images/favicon-32.png">'
            . '<link rel="apple-touch-icon" href="' . $base . 'images/apple-touch-icon.png">';
    }
}
