/*
 * Long placeholders that do not fit their box scroll smoothly in a seamless loop, so they
 * can be read in full. The moving text is a thin layer drawn over the box (clicks pass
 * through); the box's own placeholder is only made transparent, so it still describes
 * the box. Placeholders that fit are left alone; typing hides the layer. With "reduce
 * motion" on, nothing moves and the full text is offered as a tooltip.
 * Used on every page (admin, barangay, login, track).
 */
(function () {
    'use strict';
    var SPEED = 38;          // pixels per second
    var GAP = 56;            // space between the end of the text and its repeat
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var measure = document.createElement('canvas').getContext('2d');
    var items = [];

    var style = document.createElement('style');
    style.textContent =
        'input.gb-ph-on::placeholder{color:transparent!important;}' +
        '.gb-ph{position:absolute;z-index:2;display:flex;align-items:center;overflow:hidden;pointer-events:none;white-space:nowrap;' +
        '-webkit-mask-image:linear-gradient(90deg,transparent 0,#000 14px,#000 calc(100% - 14px),transparent 100%);' +
        'mask-image:linear-gradient(90deg,transparent 0,#000 14px,#000 calc(100% - 14px),transparent 100%);}' +
        '.gb-ph[hidden]{display:none;}' +
        '.gb-ph-track{display:inline-flex;will-change:transform;animation:gbPhScroll linear infinite;}' +
        '.gb-ph-track span{padding-right:' + GAP + 'px;}' +
        '@keyframes gbPhScroll{from{transform:translateX(0);}to{transform:translateX(-50%);}}';
    document.head.appendChild(style);

    function textWidth(cs, text) {
        measure.font = cs.fontStyle + ' ' + cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
        return measure.measureText(text).width;
    }

    function update(item) {
        var input = item.input, cs = window.getComputedStyle(input);
        var padL = parseFloat(cs.paddingLeft), padR = parseFloat(cs.paddingRight);
        var padT = parseFloat(cs.paddingTop), padB = parseFloat(cs.paddingBottom);
        var room = input.clientWidth - padL - padR;
        var visible = input.offsetParent !== null && room > 0;
        var overflows = visible && textWidth(cs, item.text) > room - 2;
        var show = overflows && input.value === '';

        input.classList.toggle('gb-ph-on', overflows);
        item.layer.hidden = !show;
        if (!show) return;

        var layer = item.layer;
        // Place the layer over the input's text area, measured on screen and converted to the
        // layer's containing block (offsetParent: a positioned or animated/transformed ancestor).
        var ir = input.getBoundingClientRect(), cb = layer.offsetParent, ox, oy;
        if (!cb) {
            ox = window.scrollX; oy = window.scrollY;
        } else {
            var cr = cb.getBoundingClientRect();
            ox = cb.scrollLeft - cr.left - cb.clientLeft; oy = cb.scrollTop - cr.top - cb.clientTop;
        }
        layer.style.left = (ir.left + ox + input.clientLeft + padL) + 'px';
        layer.style.top = (ir.top + oy + input.clientTop + padT) + 'px';
        layer.style.width = room + 'px';
        layer.style.height = (input.clientHeight - padT - padB) + 'px';
        // Font first: the font shorthand also resets line-height.
        layer.style.font = cs.font;
        layer.style.lineHeight = layer.style.height;
        layer.style.letterSpacing = cs.letterSpacing;
        var ph = window.getComputedStyle(input, '::placeholder').color;
        layer.style.color = ph && ph !== 'rgba(0, 0, 0, 0)' ? ph : '#94a3b8';
        // One cycle moves by one copy of the text plus its gap, at a steady speed.
        var cycle = textWidth(cs, item.text) + GAP;
        item.track.style.animationDuration = (cycle / SPEED).toFixed(2) + 's';
    }

    function attach(input) {
        if (input.dataset.gbMarquee) return;
        var text = input.getAttribute('placeholder') || '';
        if (!text || !input.parentNode) return;
        input.dataset.gbMarquee = '1';
        if (!input.title) input.title = text;
        if (still) return;

        var layer = document.createElement('div');
        layer.className = 'gb-ph';
        layer.setAttribute('aria-hidden', 'true');
        layer.hidden = true;
        var track = document.createElement('div');
        track.className = 'gb-ph-track';
        var a = document.createElement('span'), b = document.createElement('span');
        a.textContent = b.textContent = text;
        track.appendChild(a);
        track.appendChild(b);
        layer.appendChild(track);
        // Appended at the end of the container so CSS like "input:focus + i" is not affected.
        input.parentNode.appendChild(layer);

        var item = {input: input, layer: layer, track: track, text: text};
        items.push(item);
        ['input', 'change', 'focus', 'blur'].forEach(function (ev) {
            input.addEventListener(ev, function () { update(item); });
        });
        if (window.ResizeObserver) new ResizeObserver(function () { update(item); }).observe(input);
        update(item);
    }

    function scan(root) {
        (root || document).querySelectorAll('input[placeholder]:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="date"])')
            .forEach(attach);
    }

    window.gbPlaceholderMarquee = scan;
    window.addEventListener('resize', function () { items.forEach(update); });
    // Boxes can move when the page around them changes (tabs, alerts); keep layers aligned.
    setInterval(function () { items.forEach(update); }, 700);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(); });
    else scan();
})();
