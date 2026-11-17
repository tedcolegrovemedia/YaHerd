// Feedback mode: map elements on the live page to lines of the page's saved
// HTML source. annotate() is a port of feedback_annotate() in
// server/src/feedback.php and must stay in step with it, so the line numbers
// the reviewer picks here are the ones the server quotes and the student sees.
(function () {
  const RAW = new Set(['script', 'style', 'textarea', 'title', 'xmp', 'noscript', 'iframe', 'noembed', 'noframes']);
  const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'param']);
  const AUTO_CLOSE = new Set(['li', 'p', 'dt', 'dd', 'option', 'tr', 'td', 'th']);

  function annotate(html) {
    html = html.replace(/\r\n/g, '\n');
    const len = html.length;
    const tags = []; // [insertOffset, startLine, endLine]
    const stack = []; // [name, tagIndex]
    let pos = 0;
    let line = 1;
    let linePos = 0;
    const lineAt = (p) => {
      for (; linePos < p; linePos++) if (html.charCodeAt(linePos) === 10) line++;
      return line;
    };

    let lt;
    while ((lt = html.indexOf('<', pos)) !== -1) {
      const next = html[lt + 1] || '';
      if (next === '!' && html.startsWith('<!--', lt)) {
        const end = html.indexOf('-->', lt + 4);
        if (end === -1) break;
        pos = end + 3;
        continue;
      }
      if (next === '!' || next === '?') {
        const end = html.indexOf('>', lt);
        if (end === -1) break;
        pos = end + 1;
        continue;
      }
      if (next === '/') {
        const re = /<\/([a-zA-Z][\w:-]*)[^>]*>/y;
        re.lastIndex = lt;
        const m = re.exec(html);
        if (!m) { pos = lt + 1; continue; }
        const name = m[1].toLowerCase();
        const closeEnd = lt + m[0].length;
        let i = stack.length - 1;
        while (i >= 0 && stack[i][0] !== name) i--;
        if (i >= 0) {
          const endLine = lineAt(closeEnd - 1);
          for (let j = stack.length - 1; j >= i; j--) tags[stack[j][1]][2] = endLine;
          stack.length = i;
        }
        pos = closeEnd;
        continue;
      }
      if (!/[a-zA-Z]/.test(next)) { pos = lt + 1; continue; }

      const re = /<([a-zA-Z][\w:-]*)/y;
      re.lastIndex = lt;
      const m = re.exec(html);
      const name = m[1].toLowerCase();
      // Find the tag's closing '>' while skipping quoted attribute values.
      let p = lt + m[0].length;
      while (p < len) {
        const c = html[p];
        if (c === '>') break;
        if (c === '"' || c === "'") {
          const q = html.indexOf(c, p + 1);
          if (q === -1) { p = len; break; }
          p = q + 1;
        } else {
          p++;
        }
      }
      if (p >= len) break;

      const startLine = lineAt(lt);
      const selfClose = html[p - 1] === '/';
      const idx = tags.length;
      tags.push([selfClose ? p - 1 : p, startLine, startLine]);
      pos = p + 1;

      if (selfClose || VOID.has(name)) {
        tags[idx][2] = lineAt(p);
        continue;
      }
      if (RAW.has(name)) {
        const close = new RegExp('</' + name + '\\s*>', 'ig');
        close.lastIndex = pos;
        const mm = close.exec(html);
        if (!mm) break;
        const closeEnd = mm.index + mm[0].length;
        tags[idx][2] = lineAt(closeEnd - 1);
        pos = closeEnd;
        continue;
      }
      if (AUTO_CLOSE.has(name) && stack.length && stack[stack.length - 1][0] === name) {
        const prev = stack.pop()[1];
        tags[prev][2] = Math.max(tags[prev][1], startLine - 1);
      }
      stack.push([name, idx]);
    }

    let out = '';
    let last = 0;
    for (const [at, s, e] of tags) {
      out += html.slice(last, at) + ` data-lf-src="${s}-${e}"`;
      last = at;
    }
    return out + html.slice(last);
  }

  // A parsed, annotated copy of a page's source, for looking up the line
  // range of live elements by selector.
  class SourceMap {
    constructor(html) {
      this.lines = html.replace(/\r\n/g, '\n').split('\n');
      this.doc = new DOMParser().parseFromString(annotate(html), 'text/html');
    }

    // [start, end] lines for a live element: match it (or its nearest
    // matchable ancestor) in the source by CSS selector.
    rangeFor(el) {
      for (let node = el; node && node.nodeType === 1; node = node.parentElement) {
        const sel = window.WCAnchor.buildSelector(node);
        if (!sel) continue;
        let match = null;
        try { match = this.doc.querySelector(sel); } catch (_) {}
        const tagged = match && match.closest('[data-lf-src]');
        if (tagged) return tagged.getAttribute('data-lf-src').split('-').map(Number);
        if (node === document.body) break;
      }
      return null;
    }
  }

  const MAX_SCRIPT_BYTES = 1024 * 1024;
  const MAX_SCRIPTS_BYTES = 3 * 1024 * 1024;

  // GET a same-origin URL with the reviewer's cookies, trying each cache mode
  // in turn. Bot protection (Cloudflare etc.) often challenges script-made
  // requests even when the page itself loaded, so the copy the browser
  // already has in its HTTP cache is the fallback. Returns the body, or null
  // for a failure or a challenge/login page (HTML where we wanted a script).
  async function fetchText(url, modes, wantHtml) {
    for (const cache of modes) {
      try {
        const res = await fetch(url, { credentials: 'include', cache, mode: 'same-origin' });
        if (!res.ok) continue;
        const isHtml = /html/i.test(res.headers.get('content-type') || '');
        if (isHtml !== wantHtml) continue;
        return await res.text();
      } catch (_) { /* not cached / blocked: try the next mode */ }
    }
    return null;
  }

  // Capture what the student's page needs for its saved copy.
  //
  // Best case — the original HTML plus every same-origin script it loads
  // (fetched here, where the reviewer's cookies get past bot protection). The
  // replay runs those scripts from YaHerd's copies, so dynamic content builds
  // and the page stays interactive, and line numbers match the real file.
  //
  // If any of the site's own scripts can't be captured (e.g. a data endpoint
  // that bot protection blocks and the cache doesn't hold), replaying the
  // original would render a broken page. Then we save the page exactly as it
  // is on screen, with its scripts switched off at replay (marked by
  // data-yaherd-snapshot on <html>) so they can't rebuild it a second time.
  async function capturePageSource() {
    const pageUrl = location.href.split('#')[0];
    const html = await fetchText(pageUrl, ['no-store', 'force-cache'], true);
    if (html !== null) {
      const { assets, complete } = await captureScripts(html);
      if (complete) return { html, rendered: false, assets };
    }
    return { html: renderedSnapshot(), rendered: true, assets: [] };
  }

  function renderedSnapshot() {
    const doctype = document.doctype ? `<!DOCTYPE ${document.doctype.name}>\n` : '';
    const clone = document.documentElement.cloneNode(true);
    clone.querySelectorAll('webcomment-root').forEach((n) => n.remove());
    clone.setAttribute('data-yaherd-snapshot', 'rendered');
    return doctype + clone.outerHTML;
  }

  // The page's same-origin <script src> files, keyed by the src attribute
  // exactly as written (what the server matches when it rewrites the
  // replay). complete: every one of them was captured. Cross-origin scripts
  // (CDNs) are left to load from their own hosts.
  async function captureScripts(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const baseEl = doc.querySelector('base[href]');
    let base = location.href;
    try { if (baseEl) base = new URL(baseEl.getAttribute('href'), location.href).href; } catch (_) {}

    const seen = new Set();
    const wanted = [];
    let complete = true;
    for (const s of doc.querySelectorAll('script[src]')) {
      const src = s.getAttribute('src').trim();
      if (!src || seen.has(src)) continue;
      let url;
      try { url = new URL(src, base); } catch (_) { continue; }
      if (url.origin !== location.origin) continue;
      seen.add(src);
      // Modules import their dependencies relative to their own URL, which
      // would point at YaHerd once moved: can't be replayed from copies.
      if ((s.getAttribute('type') || '').trim().toLowerCase() === 'module') { complete = false; continue; }
      wanted.push({ src, url: url.href });
    }

    const got = await Promise.all(wanted.map(async ({ src, url }) => {
      const body = await fetchText(url, ['force-cache', 'no-store'], false);
      return body !== null && body.length <= MAX_SCRIPT_BYTES ? { src, body } : null;
    }));

    const assets = [];
    let total = 0;
    for (const a of got) {
      if (!a || total + a.body.length > MAX_SCRIPTS_BYTES) { complete = false; continue; }
      assets.push(a);
      total += a.body.length;
    }
    return { assets, complete };
  }

  window.WCSource = { annotate, SourceMap, capturePageSource };
})();
