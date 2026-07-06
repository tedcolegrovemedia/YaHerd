// Pin anchoring: build a stable CSS selector for a clicked element, and
// re-resolve stored anchors back to on-screen positions on later visits.
(function () {
  const UNSTABLE_ID = /\d{3,}|^(ember|react|:r|radix-|headlessui-)|^[0-9a-f]{8}-[0-9a-f]{4}/i;
  const UNSTABLE_CLASS = /^(css|sc|jsx|svelte)-|--|[0-9a-f]{5,}|\d{3,}|^_/;
  const MAX_SEGMENTS = 8;
  const MAX_LEN = 1000;

  function cssEscape(s) {
    return CSS && CSS.escape ? CSS.escape(s) : s.replace(/([^\w-])/g, '\\$1');
  }

  function isUnique(selector) {
    try { return document.querySelectorAll(selector).length === 1; } catch (_) { return false; }
  }

  function stableClasses(el) {
    return [...el.classList].filter((c) => c.length > 2 && !UNSTABLE_CLASS.test(c)).slice(0, 2);
  }

  // A segment that uniquely identifies el in the whole document, or null.
  function uniqueAnchor(el) {
    if (el.id && !UNSTABLE_ID.test(el.id)) {
      const sel = '#' + cssEscape(el.id);
      if (isUnique(sel)) return sel;
    }
    for (const attr of ['data-testid', 'data-test', 'name', 'aria-label']) {
      const v = el.getAttribute && el.getAttribute(attr);
      if (v && v.length < 80) {
        const sel = `${el.tagName.toLowerCase()}[${attr}="${cssEscape(v)}"]`;
        if (isUnique(sel)) return sel;
      }
    }
    const classes = stableClasses(el);
    if (classes.length) {
      const sel = el.tagName.toLowerCase() + '.' + classes.map(cssEscape).join('.');
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
    while (node && node !== document.body && node.nodeType === 1 && parts.length < MAX_SEGMENTS) {
      const anchor = uniqueAnchor(node);
      if (anchor) {
        parts.unshift(anchor);
        const sel = parts.join(' > ');
        if (isUnique(sel) && sel.length <= MAX_LEN) return sel;
        parts.shift();
      }
      parts.unshift(nthSegment(node));
      const sel = parts.join(' > ');
      if (isUnique(sel) && sel.length <= MAX_LEN) return sel;
      node = node.parentElement;
    }
    const sel = 'body > ' + parts.join(' > ');
    return sel.length <= MAX_LEN && isUnique(sel) ? sel : null;
  }

  // Describe a pin at client coords (cx, cy) over element el.
  function describePin(el, cx, cy) {
    const rect = el.getBoundingClientRect();
    return {
      anchor_selector: buildSelector(el),
      anchor_offset_x: rect.width ? (cx - rect.left) / rect.width : 0.5,
      anchor_offset_y: rect.height ? (cy - rect.top) / rect.height : 0.5,
      anchor_text: (el.textContent || '').trim().slice(0, 200),
      fallback_x: Math.round(cx + window.scrollX),
      fallback_y: Math.round(cy + window.scrollY),
      viewport_w: window.innerWidth,
    };
  }

  // Resolve a stored comment to viewport coords.
  // Returns { x, y, approximate } in client (viewport) coordinates.
  function resolvePin(c) {
    if (c.anchor_selector) {
      let el = null;
      try { el = document.querySelector(c.anchor_selector); } catch (_) {}
      if (el && el.tagName === 'WEBCOMMENT-ROOT') el = null; // never anchor to our own overlay
      if (el) {
        const text = (el.textContent || '').trim();
        const stored = c.anchor_text || '';
        const okText =
          !stored ||
          text.slice(0, Math.ceil(stored.length / 2)) === stored.slice(0, Math.ceil(stored.length / 2));
        if (okText) {
          const rect = el.getBoundingClientRect();
          if (rect.width || rect.height) {
            return {
              x: rect.left + (c.anchor_offset_x ?? 0.5) * rect.width,
              y: rect.top + (c.anchor_offset_y ?? 0.5) * rect.height,
              approximate: false,
            };
          }
        }
      }
    }
    // Fallback: stored document coords, scaled horizontally for viewport changes.
    const scale = c.viewport_w ? window.innerWidth / c.viewport_w : 1;
    return {
      x: c.fallback_x * scale - window.scrollX,
      y: c.fallback_y - window.scrollY,
      approximate: true,
    };
  }

  window.WCAnchor = { buildSelector, describePin, resolvePin };
})();
