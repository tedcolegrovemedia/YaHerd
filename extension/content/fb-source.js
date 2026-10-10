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

  // The page's original HTML, fetched with the reviewer's own cookies (so
  // bot protection that already let them in lets this through too). Falls
  // back to the rendered DOM when the source can't be re-fetched.
  async function capturePageSource() {
    try {
      const res = await fetch(location.href.split('#')[0], { credentials: 'include', cache: 'no-store' });
      const type = res.headers.get('content-type') || '';
      if (res.ok && /html/i.test(type)) return { html: await res.text(), rendered: false };
    } catch (_) {}
    const doctype = document.doctype ? `<!DOCTYPE ${document.doctype.name}>\n` : '';
    const clone = document.documentElement.cloneNode(true);
    clone.querySelectorAll('webcomment-root').forEach((n) => n.remove());
    return { html: doctype + clone.outerHTML, rendered: true };
  }

  window.WCSource = { annotate, SourceMap, capturePageSource };
})();
