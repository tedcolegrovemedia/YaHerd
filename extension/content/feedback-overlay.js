// Feedback-mode overlay: comment on a student's live page. The page is saved
// to its review (captured from this browser, so bot protection is no problem)
// when the first comment is left; notes are pinned and line-mapped against
// that saved copy, which is what the student's share link shows.
// Exposed as window.WCFeedback; wired up by content.js.
(function () {
  const MAX_CODE_LINES = 80;

  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function codePreview(lines, start, end) {
    const box = el('div', 'wc-code');
    box.appendChild(el('div', 'wc-code-head', start === end ? `HTML · line ${start}` : `HTML · lines ${start}–${end}`));
    const slice = lines.slice(start - 1, end);
    const indents = slice.filter((l) => l.trim()).map((l) => l.match(/^[ \t]*/)[0].length);
    const cut = indents.length ? Math.min(...indents) : 0;
    slice.forEach((l, i) => {
      const row = el('div', 'wc-cl');
      row.append(el('span', 'wc-ln', String(start + i)), el('span', 'wc-lc', l.slice(cut) || ' '));
      box.appendChild(row);
    });
    return box;
  }

  class FeedbackOverlay {
    // deps: { css, match: {project, review, page}, api(method, path, body),
    //         invalidate(), dashboardUrl }
    constructor(deps) {
      this.deps = deps;
      this.review = deps.match.review;
      this.page = deps.match.page; // null until the first comment saves it
      this.notes = [];
      this.pinEls = new Map();
      this.bubble = null;
      this.sourceMap = null;
      this.pendingHtml = null;

      this.host = document.createElement('webcomment-root');
      this.root = this.host.attachShadow({ mode: 'closed' });
      const style = el('style');
      style.textContent = deps.css;
      this.root.appendChild(style);
      this.layer = el('div', 'wc-layer');
      this.root.appendChild(this.layer);
      document.documentElement.appendChild(this.host);

      this.fab = el('button', 'wc-fab wc-fab-fb', '💬');
      this.fab.title = `Feedback: ${this.review.title}`;
      this.fabBadge = el('span', 'wc-badge');
      this.fab.appendChild(this.fabBadge);
      this.fab.addEventListener('click', () => this.toggleSidebar());
      this.layer.appendChild(this.fab);

      let raf = 0;
      const reposition = () => {
        if (raf) return;
        raf = requestAnimationFrame(() => {
          raf = 0;
          this.positionPins();
          this.repositionBubble();
        });
      };
      window.addEventListener('scroll', reposition, { passive: true, capture: true });
      window.addEventListener('resize', reposition, { passive: true });
      this._timer = setInterval(() => this.positionPins(), 1000);

      this.loadNotes();
    }

    destroy() {
      clearInterval(this._timer);
      this.host.remove();
    }

    async loadNotes() {
      if (this.page) {
        try {
          this.notes = (await this.deps.api('GET', `/api/feedback/pages/${this.page.id}/notes`)).notes;
        } catch (e) { console.warn('YaHerd: could not load feedback', e); }
      }
      this.renderPins();
      this.updateBadge();
    }

    updateBadge() {
      const n = this.notes.length;
      this.fabBadge.textContent = n || '';
      this.fabBadge.style.display = n ? '' : 'none';
    }

    // ---------- source (for line numbers) ----------
    // Always the saved copy once the page is in the review — that's what the
    // server quotes from. Before that, the live source we're about to save.
    async getSourceMap() {
      if (this.sourceMap) return this.sourceMap;
      let html;
      if (this.page) {
        html = (await this.deps.api('GET', `/api/feedback/pages/${this.page.id}/source`)).lines.join('\n');
      } else {
        const cap = await window.WCSource.capturePageSource();
        html = cap.html;
        this.pendingHtml = html;
        this.capturedRendered = cap.rendered;
      }
      this.sourceMap = new window.WCSource.SourceMap(html);
      return this.sourceMap;
    }

    async ensurePage() {
      if (this.page) return this.page;
      if (!this.pendingHtml) await this.getSourceMap();
      const res = await this.deps.api('POST', `/api/feedback/reviews/${this.review.id}/pages`, {
        url: location.href.split('#')[0],
        html: this.pendingHtml,
      });
      this.page = { id: res.page_id };
      this.pendingHtml = null;
      this.deps.invalidate();
      return this.page;
    }

    async updateSnapshot() {
      const cap = await window.WCSource.capturePageSource();
      if (this.page) {
        await this.deps.api('POST', `/api/feedback/pages/${this.page.id}/snapshot`, { html: cap.html });
      }
      this.sourceMap = null;
      this.pendingHtml = this.page ? null : cap.html;
    }

    // ---------- pins ----------
    renderPins() {
      for (const pin of this.pinEls.values()) pin.remove();
      this.pinEls.clear();
      for (const n of this.notes) {
        const pin = el('div', 'wc-pin wc-fb-pin' + (n.closed ? ' wc-fb-acked' : ''), String(n.number));
        pin.title = n.body;
        pin.addEventListener('click', () => this.openDetail(n));
        this.layer.appendChild(pin);
        this.pinEls.set(n.id, pin);
      }
      this.positionPins();
    }

    positionPins() {
      for (const n of this.notes) {
        const pin = this.pinEls.get(n.id);
        if (!pin) continue;
        const pos = window.WCAnchor.resolvePin(n);
        pin.style.left = pos.x + 'px';
        pin.style.top = pos.y + 'px';
        pin.classList.toggle('wc-approx', pos.approximate);
        const off = pos.x < -30 || pos.x > innerWidth + 30 || pos.y < -30 || pos.y > innerHeight + 30;
        pin.style.display = off ? 'none' : '';
      }
    }

    // ---------- sidebar ----------
    toggleSidebar(force) {
      const open = force !== undefined ? force : !this.sidebarEl;
      if (this.sidebarEl) { this.sidebarEl.remove(); this.sidebarEl = null; }
      if (!open) return;

      const sb = el('aside', 'wc-sidebar');
      const head = el('header');
      const titles = el('div', 'wc-title');
      titles.append(el('div', null, this.review.title), el('div', 'wc-sub', this.deps.match.project.name + ' · Feedback'));
      head.appendChild(titles);
      const close = el('button', 'wc-close', '✕');
      close.addEventListener('click', () => this.toggleSidebar(false));
      head.appendChild(close);
      sb.appendChild(head);

      sb.appendChild(el('div', 'wc-fb-status', this.page
        ? 'This page is in the review. The student sees the saved copy.'
        : 'This page isn’t in the review yet. It’s saved when you leave the first comment.'));

      const add = el('button', 'wc-btn wc-add', '+ Comment on this page');
      add.addEventListener('click', () => { this.toggleSidebar(false); this.enterPinMode(); });
      sb.appendChild(add);

      if (this.page) {
        const snap = el('button', 'wc-btn wc-secondary wc-add', '↻ Update saved copy from this page');
        snap.style.marginTop = '0';
        snap.addEventListener('click', async () => {
          if (!confirm('Replace the saved copy with the page as it is now? Pins re-attach to their elements; code already quoted in comments stays as it was.')) return;
          snap.disabled = true;
          snap.textContent = 'Saving…';
          try {
            await this.updateSnapshot();
            snap.textContent = 'Saved ✓';
          } catch (e) { snap.textContent = 'Failed: ' + e.message; }
          setTimeout(() => { snap.textContent = '↻ Update saved copy from this page'; snap.disabled = false; }, 2500);
        });
        sb.appendChild(snap);
      }

      const links = el('div', 'wc-fb-links');
      const dash = el('a', null, 'Open review in dashboard ↗');
      dash.href = `${this.deps.dashboardUrl}/feedback/review?id=${this.review.id}${this.page ? '&page=' + this.page.id : ''}`;
      dash.target = '_blank';
      dash.rel = 'noopener';
      const copy = el('button', 'wc-linkbtn', 'Copy student link');
      copy.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(this.review.share_url); copy.textContent = 'Copied ✓'; }
        catch (_) { prompt('Copy this link:', this.review.share_url); }
        setTimeout(() => { copy.textContent = 'Copy student link'; }, 2000);
      });
      links.append(dash, copy);
      sb.appendChild(links);

      const list = el('div', 'wc-list');
      list.appendChild(el('div', 'wc-group-label', `Comments on this page (${this.notes.length})`));
      if (!this.notes.length) list.appendChild(el('div', 'wc-empty', 'None yet.'));
      for (const n of this.notes) {
        const item = el('div', 'wc-item');
        item.appendChild(el('div', 'wc-excerpt', `#${n.number} ${n.body.slice(0, 90)}${n.body.length > 90 ? '…' : ''}`));
        item.appendChild(el('div', 'wc-meta', [n.created_ago, n.closed ? '✓ acknowledged' : null, n.code ? `lines ${n.code_start}–${n.code_end}` : null].filter(Boolean).join(' · ')));
        item.addEventListener('click', () => {
          this.toggleSidebar(false);
          const pos = window.WCAnchor.resolvePin(n);
          window.scrollTo({ top: window.scrollY + pos.y - innerHeight / 2, behavior: 'smooth' });
          setTimeout(() => this.openDetail(n), 450);
        });
        list.appendChild(item);
      }
      sb.appendChild(list);
      this.layer.appendChild(sb);
      this.sidebarEl = sb;
    }

    // ---------- pin mode ----------
    enterPinMode() {
      this.closeBubble();
      const capture = el('div', 'wc-capture');
      const highlight = el('div', 'wc-highlight');
      highlight.style.display = 'none';
      const banner = el('div', 'wc-mode-banner', 'Click the part of the page you want to comment on');
      const cancel = el('button', null, 'Cancel');
      banner.appendChild(cancel);
      this.layer.append(capture, highlight, banner);

      const cleanup = () => { capture.remove(); highlight.remove(); banner.remove(); document.removeEventListener('keydown', onKey, true); };
      const onKey = (ev) => { if (ev.key === 'Escape') cleanup(); };
      document.addEventListener('keydown', onKey, true);
      cancel.addEventListener('click', cleanup);

      const underlying = (ev) => {
        capture.style.pointerEvents = 'none';
        const t = document.elementFromPoint(ev.clientX, ev.clientY);
        capture.style.pointerEvents = '';
        if (!t || t === document.documentElement || t === this.host) return document.body;
        return t;
      };

      capture.addEventListener('mousemove', (ev) => {
        const r = underlying(ev).getBoundingClientRect();
        Object.assign(highlight.style, { display: '', left: r.left + 'px', top: r.top + 'px', width: r.width + 'px', height: r.height + 'px' });
      });
      capture.addEventListener('click', (ev) => {
        const t = underlying(ev);
        cleanup();
        this.openComposer(t, ev.clientX, ev.clientY);
      });
      // Warm the source while the reviewer picks an element.
      this.getSourceMap().catch(() => {});
    }

    // ---------- bubbles ----------
    placeBubble(bubble, x, y) {
      if (!bubble.isConnected) this.layer.appendChild(bubble);
      const bw = 340;
      const bh = bubble.offsetHeight || 200;
      let bx = x + 18;
      let by = y - 20;
      if (bx + bw > innerWidth - 10) bx = x - bw - 18;
      if (bx < 10) bx = 10;
      if (by + bh > innerHeight - 10) by = innerHeight - bh - 10;
      if (by < 10) by = 10;
      bubble.style.left = bx + 'px';
      bubble.style.top = by + 'px';
    }

    closeBubble() {
      if (this.bubble) { this.bubble.remove(); this.bubble = null; }
      if (this.provisionalPin) { this.provisionalPin.remove(); this.provisionalPin = null; }
      this.ctx = null;
    }

    // Where the open bubble's spot currently is on screen.
    ctxPos() {
      const c = this.ctx;
      if (!c) return null;
      if (c.note) return window.WCAnchor.resolvePin(c.note);
      if (c.target && c.target.isConnected) {
        const r = c.target.getBoundingClientRect();
        if (r.width || r.height) return { x: r.left + c.fx * r.width, y: r.top + c.fy * r.height };
      }
      return { x: c.pageX - window.scrollX, y: c.pageY - window.scrollY };
    }

    repositionBubble() {
      if (!this.bubble || !this.ctx) return;
      const pos = this.ctxPos();
      if (this.provisionalPin) {
        this.provisionalPin.style.left = pos.x + 'px';
        this.provisionalPin.style.top = pos.y + 'px';
      }
      this.placeBubble(this.bubble, pos.x, pos.y);
    }

    // The "show code" control shared by the composer and the edit form.
    codeControl(range) {
      const wrap = el('div', 'wc-fb-code');
      const toggle = el('label', 'wc-check');
      const cb = el('input');
      cb.type = 'checkbox';
      toggle.append(cb, document.createTextNode(' Show code from this section'));
      const rangeRow = el('div', 'wc-range');
      const startIn = el('input');
      const endIn = el('input');
      for (const i of [startIn, endIn]) { i.type = 'number'; i.min = '1'; }
      rangeRow.append(document.createTextNode('Lines '), startIn, document.createTextNode(' – '), endIn);
      const preview = el('div');
      const status = el('div', 'wc-meta', 'Finding this element’s code…');
      wrap.append(toggle, status, rangeRow, preview);

      let lines = [];
      const refresh = () => {
        rangeRow.style.display = cb.checked ? '' : 'none';
        preview.textContent = '';
        if (!cb.checked || !lines.length) return;
        let s = Math.min(Math.max(1, +startIn.value || 1), lines.length);
        let e = Math.min(Math.max(s, +endIn.value || s), lines.length, s + MAX_CODE_LINES - 1);
        startIn.value = s;
        endIn.value = e;
        preview.appendChild(codePreview(lines, s, e));
        this.repositionBubble();
      };
      cb.addEventListener('change', refresh);
      startIn.addEventListener('change', refresh);
      endIn.addEventListener('change', refresh);
      rangeRow.style.display = 'none';

      const ready = range.then((r) => {
        lines = r.lines;
        status.textContent = r.range ? '' : 'Couldn’t match this element in the page source. Pick lines by hand if you want to show code.';
        status.style.display = r.range ? 'none' : '';
        const [s, e] = r.range || [1, 1];
        startIn.value = s;
        endIn.value = r.range && e - s >= 40 ? s + 14 : e;
        cb.checked = r.checked;
        refresh();
      }).catch((err) => { status.textContent = 'Couldn’t load the page source: ' + err.message; });

      return {
        el: wrap,
        ready,
        value: () => (cb.checked && lines.length ? { code_start: +startIn.value, code_end: +endIn.value } : { code_start: null }),
      };
    }

    openComposer(target, cx, cy) {
      this.closeBubble();
      const rect = target.getBoundingClientRect();
      this.ctx = {
        target,
        fx: rect.width ? (cx - rect.left) / rect.width : 0.5,
        fy: rect.height ? (cy - rect.top) / rect.height : 0.5,
        pageX: cx + window.scrollX,
        pageY: cy + window.scrollY,
      };
      const pin = el('div', 'wc-pin wc-fb-pin wc-provisional', '+');
      pin.style.left = cx + 'px';
      pin.style.top = cy + 'px';
      this.layer.appendChild(pin);
      this.provisionalPin = pin;

      const bubble = el('div', 'wc-bubble wc-fb-bubble');
      const ta = el('textarea');
      ta.placeholder = 'What should the student look at here?';
      bubble.appendChild(ta);

      const code = this.codeControl(this.getSourceMap().then((map) => {
        const range = map.rangeFor(target);
        return { lines: map.lines, range, checked: !!range && range[1] - range[0] < 40 };
      }));
      bubble.appendChild(code.el);

      const err = el('div', 'wc-error');
      const row = el('div', 'wc-row');
      const cancelBtn = el('button', 'wc-btn wc-secondary', 'Cancel');
      const saveBtn = el('button', 'wc-btn', 'Save comment');
      row.append(cancelBtn, saveBtn);
      bubble.append(err, row);
      this.bubble = bubble;
      this.placeBubble(bubble, cx, cy);
      ta.focus();

      cancelBtn.addEventListener('click', () => this.closeBubble());
      saveBtn.addEventListener('click', async () => {
        const body = ta.value.trim();
        if (!body) { err.textContent = 'Write something first.'; return; }
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        try {
          await code.ready;
          const pos = this.ctxPos();
          const anchor = window.WCAnchor.describePin(target, pos.x, pos.y);
          const page = await this.ensurePage();
          const res = await this.deps.api('POST', `/api/feedback/pages/${page.id}/notes`, {
            body,
            anchor,
            ...code.value(),
          });
          this.notes = res.notes;
          this.closeBubble();
          this.renderPins();
          this.updateBadge();
        } catch (e) {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save comment';
          err.textContent = e.message;
        }
      });
    }

    openDetail(n) {
      this.closeBubble();
      this.ctx = { note: n };
      const pos = window.WCAnchor.resolvePin(n);
      const bubble = el('div', 'wc-bubble wc-fb-bubble');
      bubble.appendChild(el('div', 'wc-meta', [`#${n.number}`, n.author_name, n.created_ago, n.closed ? '✓ acknowledged by student' : null].filter(Boolean).join(' · ')));
      const body = el('div', 'wc-body', n.body);
      bubble.appendChild(body);
      let preview = null;
      if (n.code) {
        preview = codePreview(n.code.split('\n'), n.code_start, n.code_end);
        bubble.appendChild(preview);
      }
      const err = el('div', 'wc-error');
      const row = el('div', 'wc-row');
      row.style.justifyContent = 'space-between';
      const del = el('button', 'wc-linkbtn wc-danger', 'Delete');
      const right = el('div', 'wc-row');
      right.style.marginTop = '0';
      const edit = el('button', 'wc-btn wc-secondary', 'Edit');
      const close = el('button', 'wc-btn', 'Close');
      right.append(edit, close);
      row.append(del, right);
      bubble.append(err, row);
      this.bubble = bubble;
      this.placeBubble(bubble, pos.x, pos.y);

      close.addEventListener('click', () => this.closeBubble());
      del.addEventListener('click', async () => {
        if (!confirm(`Delete comment ${n.number}? This can't be undone.`)) return;
        try {
          this.notes = (await this.deps.api('DELETE', `/api/feedback/notes/${n.id}`)).notes;
          this.closeBubble();
          this.renderPins();
          this.updateBadge();
        } catch (e) { err.textContent = e.message; }
      });
      edit.addEventListener('click', () => {
        const ta = el('textarea');
        ta.value = n.body;
        body.replaceWith(ta);
        const code = this.codeControl(this.getSourceMap().then((map) => ({
          lines: map.lines,
          range: n.code ? [n.code_start, n.code_end] : null,
          checked: !!n.code,
        })));
        (preview || ta).after(code.el);
        if (preview) preview.remove();
        edit.remove();
        close.textContent = 'Save';
        close.replaceWith(close.cloneNode(true));
        const save = right.lastChild;
        save.addEventListener('click', async () => {
          const text = ta.value.trim();
          if (!text) { err.textContent = 'Write something first.'; return; }
          save.disabled = true;
          try {
            await code.ready;
            this.notes = (await this.deps.api('PATCH', `/api/feedback/notes/${n.id}`, { body: text, ...code.value() })).notes;
            this.closeBubble();
            this.renderPins();
          } catch (e) { err.textContent = e.message; save.disabled = false; }
        });
        ta.focus();
        this.repositionBubble();
      });
    }
  }

  window.WCFeedback = {
    create(deps) { return new FeedbackOverlay(deps); },
  };
})();
