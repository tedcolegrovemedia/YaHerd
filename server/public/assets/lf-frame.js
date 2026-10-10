// Runs INSIDE the sandboxed page replay (opaque origin). Draws numbered pins,
// handles element picking for the editor, and talks to the YaHerd page that
// embeds it via postMessage. It never touches YaHerd's API itself.
(function () {
  'use strict';
  if (window.__lfLoaded) return;
  window.__lfLoaded = true;

  const PARENT = (window.__LF && window.__LF.parentOrigin) || '*';
  let notes = [];
  let picking = false;
  let activeId = null;
  let preview = null;

  function send(msg) {
    if (window.parent === window) return;
    window.parent.postMessage(Object.assign({ lf: 1 }, msg), PARENT);
  }

  // ---------- Anchoring (same approach as the extension's anchor.js) ----------
  const UNSTABLE_ID = /\d{3,}|^(ember|react|:r|radix-|headlessui-)|^[0-9a-f]{8}-[0-9a-f]{4}/i;
  const UNSTABLE_CLASS = /^(css|sc|jsx|svelte)-|--|[0-9a-f]{5,}|\d{3,}|^_/;
  const esc = (s) => (window.CSS && CSS.escape ? CSS.escape(s) : s.replace(/([^\w-])/g, '\\$1'));
  const isUnique = (sel) => { try { return document.querySelectorAll(sel).length === 1; } catch (_) { return false; } };

  function uniqueAnchor(el) {
    if (el.id && !UNSTABLE_ID.test(el.id)) {
      const sel = '#' + esc(el.id);
      if (isUnique(sel)) return sel;
    }
    const classes = [...el.classList].filter((c) => c.length > 2 && !UNSTABLE_CLASS.test(c)).slice(0, 2);
    if (classes.length) {
      const sel = el.tagName.toLowerCase() + '.' + classes.map(esc).join('.');
      if (isUnique(sel)) return sel;
    }
    return null;
  }

  function nthSegment(el) {
    let seg = el.tagName.toLowerCase();
    const parent = el.parentElement;
    if (parent) {
      const same = [...parent.children].filter((c) => c.tagName === el.tagName);
      if (same.length > 1) seg += `:nth-of-type(${same.indexOf(el) + 1})`;
    }
    return seg;
  }

  function buildSelector(el) {
    const parts = [];
    let node = el;
    while (node && node.nodeType === 1 && node !== document.documentElement && parts.length < 10) {
      const anchor = uniqueAnchor(node);
      if (anchor) {
        const sel = [anchor, ...parts].join(' > ');
        if (isUnique(sel)) return sel;
      }
      parts.unshift(nthSegment(node));
      const sel = parts.join(' > ');
      if (isUnique(sel)) return sel;
      node = node.parentElement;
    }
    const sel = 'html > ' + parts.join(' > ');
    return isUnique(sel) && sel.length <= 1000 ? sel : null;
  }

  function describe(el, cx, cy) {
    const r = el.getBoundingClientRect();
    return {
      anchor_selector: buildSelector(el),
      anchor_offset_x: r.width ? (cx - r.left) / r.width : 0.5,
      anchor_offset_y: r.height ? (cy - r.top) / r.height : 0.5,
      anchor_text: (el.textContent || '').trim().slice(0, 200),
      fallback_x: Math.round(cx + window.scrollX),
      fallback_y: Math.round(cy + window.scrollY),
      viewport_w: window.innerWidth,
    };
  }

  function findElement(n) {
    if (!n.anchor_selector) return null;
    let matches = [];
    try { matches = [...document.querySelectorAll(n.anchor_selector)]; } catch (_) {}
    const visible = (el) => { const r = el.getBoundingClientRect(); return r.width || r.height; };
    return matches.find(visible) || null;
  }

  // Viewport position for a note: on its element, else stored page coords.
  function resolve(n) {
    const el = findElement(n);
    if (el) {
      const r = el.getBoundingClientRect();
      return {
        el,
        x: r.left + (n.anchor_offset_x ?? 0.5) * r.width,
        y: r.top + (n.anchor_offset_y ?? 0.5) * r.height,
        approximate: false,
      };
    }
    const scale = n.viewport_w ? window.innerWidth / n.viewport_w : 1;
    return { el: null, x: n.fallback_x * scale - window.scrollX, y: n.fallback_y - window.scrollY, approximate: true };
  }

  // ---------- Overlay (shadow DOM keeps the student's CSS out) ----------
  const host = document.createElement('lf-root');
  host.style.cssText = 'all:initial;position:fixed;inset:0;z-index:2147483647;pointer-events:none;';
  const root = host.attachShadow({ mode: 'closed' });
  root.innerHTML = `
    <style>
      :host { all: initial; }
      .pin {
        position: fixed; left: 0; top: 0; width: 26px; height: 26px; margin: -13px 0 0 -13px;
        border-radius: 50%; background: #4f46e5; color: #fff; border: 2px solid #fff;
        font: 700 12px/22px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        text-align: center; cursor: pointer; pointer-events: auto; box-sizing: border-box;
        box-shadow: 0 2px 8px rgba(16,24,40,.35); transition: transform .12s ease, background .12s ease;
      }
      .pin:hover { transform: scale(1.12); }
      .pin.approx { border-style: dashed; }
      .pin.acked { background: #047857; }
      .pin.active { transform: scale(1.25); box-shadow: 0 0 0 4px rgba(79,70,229,.35), 0 2px 8px rgba(16,24,40,.35); }
      .pin.preview { background: #f59e0b; pointer-events: none; }
      .box {
        position: fixed; pointer-events: none; border: 2px solid #4f46e5; border-radius: 3px;
        background: rgba(79,70,229,.08); display: none; box-sizing: border-box;
      }
      .box.flash { border-color: #f59e0b; background: rgba(245,158,11,.12); }
      .label {
        position: fixed; pointer-events: none; display: none; background: #14161a; color: #fff;
        font: 600 11px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; padding: 2px 6px; border-radius: 4px;
        white-space: nowrap; max-width: 60vw; overflow: hidden; text-overflow: ellipsis;
      }
    </style>
    <div class="box" id="box"></div><div class="label" id="label"></div><div id="pins"></div>`;
  const box = root.getElementById('box');
  const label = root.getElementById('label');
  const pinsEl = root.getElementById('pins');

  function mount() {
    if (!host.isConnected) document.documentElement.appendChild(host);
  }

  function tagLabel(el) {
    let s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    const cls = [...el.classList].slice(0, 2);
    if (cls.length) s += '.' + cls.join('.');
    return s;
  }

  function showBox(el, cls) {
    const r = el.getBoundingClientRect();
    Object.assign(box.style, { display: 'block', left: r.left + 'px', top: r.top + 'px', width: r.width + 'px', height: r.height + 'px' });
    box.className = 'box' + (cls ? ' ' + cls : '');
    return r;
  }
  function hideBox() { box.style.display = 'none'; label.style.display = 'none'; }

  function renderPins() {
    mount();
    pinsEl.textContent = '';
    const list = preview ? [...notes, Object.assign({ id: 'preview', number: '+' }, preview)] : notes;
    for (const n of list) {
      const pin = document.createElement('div');
      pin.className = 'pin' + (n.id === 'preview' ? ' preview' : '') + (n.closed ? ' acked' : '') + (n.id === activeId ? ' active' : '');
      pin.textContent = String(n.number);
      pin.dataset.id = n.id;
      pin.title = n.body ? n.body.slice(0, 120) : '';
      pinsEl.appendChild(pin);
    }
    layout();
  }

  let queued = false;
  function layout() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => {
      queued = false;
      const list = preview ? [...notes, Object.assign({ id: 'preview' }, preview)] : notes;
      [...pinsEl.children].forEach((pin, i) => {
        const n = list[i];
        if (!n) return;
        const p = resolve(n);
        pin.style.transform = `translate(${p.x}px, ${p.y}px)` + (pin.classList.contains('active') ? ' scale(1.25)' : '');
        pin.classList.toggle('approx', p.approximate);
      });
    });
  }

  pinsEl.addEventListener('click', (ev) => {
    const pin = ev.target.closest('.pin');
    if (!pin || pin.dataset.id === 'preview') return;
    ev.stopPropagation();
    focusNote(+pin.dataset.id, false);
    send({ type: 'pin', id: +pin.dataset.id });
  });

  function focusNote(id, scroll) {
    activeId = id;
    renderPins();
    const n = notes.find((x) => x.id === id);
    if (!n) return;
    const p = resolve(n);
    if (scroll) {
      if (p.el) p.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
      else window.scrollTo({ top: n.fallback_y - window.innerHeight / 2, behavior: 'smooth' });
    }
    if (p.el) {
      setTimeout(() => {
        showBox(p.el, 'flash');
        setTimeout(() => { if (!picking) hideBox(); }, 1400);
      }, scroll ? 450 : 0);
    }
  }

  // ---------- Picking (editor only) ----------
  function pickTarget(ev) {
    const el = document.elementFromPoint(ev.clientX, ev.clientY);
    return el && el !== host && el !== document.documentElement ? el : null;
  }

  function onMove(ev) {
    if (!picking) return;
    const el = pickTarget(ev);
    if (!el) return hideBox();
    const r = showBox(el);
    label.textContent = tagLabel(el);
    Object.assign(label.style, { display: 'block', left: Math.max(0, r.left) + 'px', top: Math.max(0, r.top - 20) + 'px' });
  }

  function swallow(ev) {
    if (!picking) return;
    ev.preventDefault();
    ev.stopImmediatePropagation();
  }

  function onPickClick(ev) {
    if (!picking) return;
    swallow(ev);
    const el = pickTarget(ev);
    if (!el) return;
    const srcEl = el.closest('[data-lf-src]');
    const src = srcEl ? srcEl.getAttribute('data-lf-src').split('-').map(Number) : null;
    setPicking(false);
    const anchor = describe(el, ev.clientX, ev.clientY);
    preview = anchor;
    renderPins();
    send({ type: 'picked', anchor, src, label: tagLabel(el) });
  }

  function setPicking(on) {
    picking = on;
    document.documentElement.style.cursor = on ? 'crosshair' : '';
    if (!on) hideBox();
  }

  window.addEventListener('mousemove', onMove, true);
  for (const t of ['mousedown', 'mouseup', 'pointerdown', 'pointerup', 'touchstart', 'submit']) {
    window.addEventListener(t, swallow, true);
  }
  window.addEventListener('click', (ev) => {
    if (picking) return onPickClick(ev);
    // Outside pick mode, links open the live site in a new tab rather than
    // navigating the snapshot away.
    const a = ev.target.closest && ev.target.closest('a[href]');
    if (!a) return;
    const href = a.getAttribute('href') || '';
    if (href.startsWith('#') || href.startsWith('javascript:')) return;
    ev.preventDefault();
    window.open(a.href, '_blank', 'noopener');
  }, true);
  window.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && picking) { setPicking(false); send({ type: 'pick-cancel' }); }
  }, true);

  window.addEventListener('scroll', layout, { capture: true, passive: true });
  window.addEventListener('resize', layout);
  setInterval(layout, 500); // late images/fonts shift layout

  // ---------- Messages from the embedding YaHerd page ----------
  window.addEventListener('message', (ev) => {
    if (ev.source !== window.parent || (PARENT !== '*' && ev.origin !== PARENT)) return;
    const m = ev.data || {};
    if (!m.lf) return;
    if (m.cmd === 'hello') send({ type: 'ready' });
    if (m.cmd === 'notes') { notes = Array.isArray(m.notes) ? m.notes : []; renderPins(); }
    if (m.cmd === 'pick') setPicking(!!m.on);
    if (m.cmd === 'focus') focusNote(m.id, true);
    if (m.cmd === 'clear-preview') { preview = null; renderPins(); }
  });

  function ready() { mount(); send({ type: 'ready' }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
  else ready();
})();
