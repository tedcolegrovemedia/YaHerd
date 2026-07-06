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
  //
  // Responsive sites often keep desktop AND mobile copies of the same content
  // in the DOM (one hidden per breakpoint). querySelector alone would find the
  // hidden desktop node and the pin would fall back to raw coordinates — so we
  // consider every match, prefer a VISIBLE one that passes the text check, and
  // as a last resort hunt for the stored text among same-tag elements.
  function resolvePin(c) {
    const stored = c.anchor_text || '';
    const isVisible = (node) => {
      const r = node.getBoundingClientRect();
      return !!(r.width || r.height);
    };
    const textOk = (node) => {
      if (!stored) return true;
      const n = Math.ceil(stored.length / 2);
      return (node.textContent || '').trim().slice(0, n) === stored.slice(0, n);
    };
    const place = (node) => {
      const rect = node.getBoundingClientRect();
      return {
        x: rect.left + (c.anchor_offset_x ?? 0.5) * rect.width,
        y: rect.top + (c.anchor_offset_y ?? 0.5) * rect.height,
        approximate: false,
      };
    };

    if (c.anchor_selector) {
      let matches = [];
      try { matches = [...document.querySelectorAll(c.anchor_selector)]; } catch (_) {}
      matches = matches.filter((node) => node.tagName !== 'WEBCOMMENT-ROOT');
      const best = matches.find((node) => isVisible(node) && textOk(node));
      if (best) return place(best);
    }

    // Text rescue: the selector missed (breakpoint-specific DOM), but the
    // snippet is distinctive enough to find the element by content.
    if (stored.length >= 8) {
      const lastSegment = (c.anchor_selector || '').split('>').pop().trim();
      const tag = (lastSegment.match(/^([a-z][a-z0-9-]*)/i) || [])[1];
      if (tag) {
        const els = document.getElementsByTagName(tag);
        const cap = Math.min(els.length, 3000);
        for (let i = 0; i < cap; i++) {
          if (isVisible(els[i]) && textOk(els[i])) return place(els[i]);
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
