// Shadow-DOM overlay UI: pins, sidebar, pin-placement mode, comment bubbles.
// Exposed as window.WCOverlay; wired up by content.js.
(function () {
  const STATUSES = [
    ['queued', 'Queued'],
    ['working_on', 'Working on'],
    ['complete', 'Complete'],
  ];

  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function statusSelect(comment, onChange) {
    const sel = el('select', 'wc-status');
    for (const [value, label] of STATUSES) {
      const o = el('option', null, label);
      o.value = value;
      if (value === comment.status) o.selected = true;
      sel.appendChild(o);
    }
    sel.addEventListener('change', () => onChange(sel.value));
    return sel;
  }

  function fmtDate(s) {
    try { return new Date(s.replace(' ', 'T')).toLocaleString(); } catch (_) { return s; }
  }

  class Overlay {
    // deps: { api(method,path,body), createComment(fields,{vx,vy,dpr}), project, comments }
    constructor(deps) {
      this.deps = deps;
      this.comments = deps.comments;
      this.pinEls = new Map(); // comment id -> pin element
      this.sidebarOpen = false;
      this.bubble = null;

      this.host = document.createElement('webcomment-root');
      this.root = this.host.attachShadow({ mode: 'closed' });
      const style = el('style');
      style.textContent = deps.css;
      this.root.appendChild(style);
      this.layer = el('div', 'wc-layer');
      this.root.appendChild(this.layer);
      document.documentElement.appendChild(this.host);

      this.fab = el('button', 'wc-fab', '📌');
      this.fabBadge = el('span', 'wc-badge');
      this.fab.appendChild(this.fabBadge);
      this.fab.addEventListener('click', () => this.toggleSidebar());
      this.layer.appendChild(this.fab);

      this.renderPins();
      this.updateBadge();

      // Keep pins glued to their elements.
      let raf = 0;
      const reposition = () => {
        if (raf) return;
        raf = requestAnimationFrame(() => {
          raf = 0;
          this.positionPins();
        });
      };
      window.addEventListener('scroll', reposition, { passive: true, capture: true });
      window.addEventListener('resize', reposition, { passive: true });
      if (window.ResizeObserver) {
        new ResizeObserver(reposition).observe(document.body);
      }
      this._mo = new MutationObserver(() => {
        clearTimeout(this._moT);
        this._moT = setTimeout(() => this.positionPins(), 400);
      });
      this._mo.observe(document.body, { childList: true, subtree: true });
      // SPA hydration often shifts layout shortly after load.
      setTimeout(() => this.positionPins(), 1500);
    }

    destroy() {
      this._mo.disconnect();
      this.host.remove();
    }

    getMembers() {
      if (!this._members) {
        this._members = this.deps
          .api('GET', `/api/projects/${this.deps.project.id}/members`)
          .then((d) => d.members);
        this._members.catch(() => { this._members = null; }); // allow retry on failure
      }
      return this._members;
    }

    updateBadge() {
      const open = this.comments.filter((c) => c.status !== 'complete').length;
      this.fabBadge.textContent = open || '';
      this.fabBadge.style.display = open ? '' : 'none';
    }

    // ---------- pins ----------
    renderPins() {
      for (const pin of this.pinEls.values()) pin.remove();
      this.pinEls.clear();
      this.comments.forEach((c, i) => {
        const pin = el('div', 'wc-pin wc-status-' + c.status, String(i + 1));
        pin.title = c.body;
        pin.addEventListener('click', () => this.openDetail(c, pin));
        this.layer.appendChild(pin);
        this.pinEls.set(c.id, pin);
      });
      this.positionPins();
    }

    positionPins() {
      for (const c of this.comments) {
        const pin = this.pinEls.get(c.id);
        if (!pin) continue;
        const pos = window.WCAnchor.resolvePin(c);
        pin.style.left = pos.x + 'px';
        pin.style.top = pos.y + 'px';
        pin.classList.toggle('wc-approx', pos.approximate);
        const off = pos.x < -30 || pos.x > innerWidth + 30 || pos.y < -30 || pos.y > innerHeight + 30;
        pin.style.display = off ? 'none' : '';
      }
    }

    // ---------- sidebar ----------
    toggleSidebar(force) {
      this.sidebarOpen = force !== undefined ? force : !this.sidebarOpen;
      if (this.sidebarEl) { this.sidebarEl.remove(); this.sidebarEl = null; }
      if (!this.sidebarOpen) return;

      const sb = el('aside', 'wc-sidebar');
      const head = el('header');
      head.appendChild(el('span', 'wc-title', this.deps.project.name));
      const close = el('button', 'wc-close', '✕');
      close.addEventListener('click', () => this.toggleSidebar(false));
      head.appendChild(close);
      sb.appendChild(head);

      const add = el('button', 'wc-btn wc-add', '+ Pin a comment on this page');
      add.addEventListener('click', () => { this.toggleSidebar(false); this.enterPinMode(); });
      sb.appendChild(add);

      const cover = el('button', 'wc-btn wc-secondary wc-add', '🖼 Set board cover from this page');
      cover.style.marginTop = '0';
      cover.addEventListener('click', async () => {
        cover.disabled = true;
        cover.textContent = 'Capturing…';
        // Hide our UI for a clean shot, wait for the hidden frame to paint.
        this.host.style.visibility = 'hidden';
        await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
        try {
          await this.deps.setCover();
          this.host.style.visibility = '';
          cover.textContent = 'Cover saved ✓';
        } catch (e) {
          this.host.style.visibility = '';
          cover.textContent = 'Failed: ' + e.message;
        }
        cover.disabled = false;
        setTimeout(() => { cover.textContent = '🖼 Set board cover from this page'; }, 2500);
      });
      sb.appendChild(cover);

      const list = el('div', 'wc-list');
      for (const [value, label] of STATUSES) {
        const group = this.comments.filter((c) => c.status === value);
        list.appendChild(el('div', 'wc-group-label', `${label} (${group.length})`));
        if (!group.length) list.appendChild(el('div', 'wc-empty', '—'));
        for (const c of group) {
          const item = el('div', 'wc-item');
          const idx = this.comments.indexOf(c) + 1;
          item.appendChild(el('div', 'wc-excerpt', `#${idx} ${c.body.slice(0, 90)}${c.body.length > 90 ? '…' : ''}`));
          item.appendChild(el('div', 'wc-meta',
            `${c.author_name || ''} · ${fmtDate(c.created_at)}` +
            `${c.assignee_name ? ` · 👤 ${c.assignee_name}` : ''}` +
            `${c.reply_count ? ` · 💬 ${c.reply_count}` : ''}`));
          item.addEventListener('click', () => {
            this.toggleSidebar(false);
            const pin = this.pinEls.get(c.id);
            const pos = window.WCAnchor.resolvePin(c);
            window.scrollTo({ top: window.scrollY + pos.y - innerHeight / 2, behavior: 'smooth' });
            setTimeout(() => this.openDetail(c, pin), 450);
          });
          list.appendChild(item);
        }
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
      const banner = el('div', 'wc-mode-banner', 'Click anywhere on the page to pin a comment');
      const cancel = el('button', null, 'Cancel');
      banner.appendChild(cancel);
      this.layer.append(capture, highlight, banner);

      const cleanup = () => { capture.remove(); highlight.remove(); banner.remove(); };
      cancel.addEventListener('click', cleanup);

      const underlying = (ev) => {
        // Disable the capture layer itself (children of the shadow host keep
        // their own pointer-events, so hiding the host is not enough) and ask
        // the document what's really under the cursor.
        capture.style.pointerEvents = 'none';
        const target = document.elementFromPoint(ev.clientX, ev.clientY);
        capture.style.pointerEvents = '';
        if (!target || target === document.documentElement || target === this.host) {
          return document.body;
        }
        return target;
      };

      capture.addEventListener('mousemove', (ev) => {
        const t = underlying(ev);
        const r = t.getBoundingClientRect();
        highlight.style.display = '';
        highlight.style.left = r.left + 'px';
        highlight.style.top = r.top + 'px';
        highlight.style.width = r.width + 'px';
        highlight.style.height = r.height + 'px';
      });

      capture.addEventListener('click', (ev) => {
        const target = underlying(ev);
        cleanup();
        this.openComposer(target, ev.clientX, ev.clientY);
      });
    }

    placeBubble(bubble, x, y) {
      this.layer.appendChild(bubble);
      const bw = 320;
      const bh = bubble.offsetHeight || 200;
      let bx = x + 18, by = y - 20;
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
    }

    // ---------- composer ----------
    openComposer(target, cx, cy) {
      this.closeBubble();
      const pin = el('div', 'wc-pin wc-provisional', '+');
      pin.style.left = cx + 'px';
      pin.style.top = cy + 'px';
      this.layer.appendChild(pin);
      this.provisionalPin = pin;

      const bubble = el('div', 'wc-bubble');
      const ta = el('textarea');
      ta.placeholder = 'Describe the issue or feedback…';
      bubble.appendChild(ta);
      const err = el('div', 'wc-error');
      bubble.appendChild(err);
      const row = el('div', 'wc-row');
      const cancelBtn = el('button', 'wc-btn wc-secondary', 'Cancel');
      const saveBtn = el('button', 'wc-btn', 'Save comment');
      row.append(cancelBtn, saveBtn);
      bubble.appendChild(row);
      this.placeBubble(bubble, cx, cy);
      this.bubble = bubble;
      ta.focus();

      cancelBtn.addEventListener('click', () => this.closeBubble());
      saveBtn.addEventListener('click', async () => {
        const body = ta.value.trim();
        if (!body) { err.textContent = 'Write something first.'; return; }
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        const anchor = window.WCAnchor.describePin(target, cx, cy);
        const fields = {
          project_id: this.deps.project.id,
          page_url: location.href,
          body,
          ...anchor,
        };
        // Hide our UI so it doesn't appear in the screenshot; wait two frames
        // so the hidden state is actually painted before capture.
        this.host.style.visibility = 'hidden';
        await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
        try {
          const data = await this.deps.createComment(fields, {
            vx: cx,
            vy: cy,
            dpr: window.devicePixelRatio || 1,
          });
          this.host.style.visibility = '';
          this.closeBubble();
          this.comments.push(data.comment);
          this.renderPins();
          this.updateBadge();
        } catch (e) {
          this.host.style.visibility = '';
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save comment';
          err.textContent = e.message;
        }
      });
    }

    // ---------- detail ----------
    async openDetail(c, pinEl) {
      this.closeBubble();
      const pos = window.WCAnchor.resolvePin(c);
      const bubble = el('div', 'wc-bubble');
      const idx = this.comments.indexOf(c) + 1;
      bubble.appendChild(el('div', 'wc-meta', `#${idx} · ${c.author_name || ''} · ${fmtDate(c.created_at)}`));
      bubble.appendChild(el('div', 'wc-body', c.body));

      const row = el('div', 'wc-row');
      row.style.justifyContent = 'space-between';
      row.appendChild(
        statusSelect(c, async (status) => {
          try {
            await this.deps.api('PATCH', `/api/comments/${c.id}/status`, { status });
            c.status = status;
            if (pinEl) pinEl.className = 'wc-pin wc-status-' + status;
            this.updateBadge();
          } catch (e) { err.textContent = e.message; }
        })
      );
      const closeBtn = el('button', 'wc-btn wc-secondary', 'Close');
      closeBtn.addEventListener('click', () => this.closeBubble());
      row.appendChild(closeBtn);
      bubble.appendChild(row);

      // Assignee
      const assignRow = el('div', 'wc-row');
      assignRow.style.justifyContent = 'flex-start';
      assignRow.appendChild(el('span', 'wc-meta', '👤'));
      const assignSel = el('select', 'wc-status');
      assignSel.appendChild(new Option('Unassigned', ''));
      assignSel.disabled = true;
      assignRow.appendChild(assignSel);
      bubble.appendChild(assignRow);
      this.getMembers().then((members) => {
        for (const m of members) {
          assignSel.appendChild(new Option(m.display_name, String(m.id), false, m.id === c.assignee_id));
        }
        assignSel.disabled = false;
      }).catch(() => {});
      assignSel.addEventListener('change', async () => {
        const userId = assignSel.value ? +assignSel.value : null;
        try {
          await this.deps.api('PATCH', `/api/comments/${c.id}/assignee`, { user_id: userId });
          c.assignee_id = userId;
          c.assignee_name = userId ? assignSel.options[assignSel.selectedIndex].text : null;
        } catch (e) { err.textContent = e.message; }
      });

      bubble.appendChild(el('h4', null, 'Replies'));
      const replies = el('div', 'wc-replies');
      replies.appendChild(el('div', 'wc-empty', 'Loading…'));
      bubble.appendChild(replies);

      const replyTa = el('textarea');
      replyTa.placeholder = 'Reply…';
      replyTa.style.minHeight = '40px';
      bubble.appendChild(replyTa);
      const err = el('div', 'wc-error');
      bubble.appendChild(err);
      const replyRow = el('div', 'wc-row');
      const replyBtn = el('button', 'wc-btn', 'Reply');
      replyRow.appendChild(replyBtn);
      bubble.appendChild(replyRow);

      this.placeBubble(bubble, pos.x, pos.y);
      this.bubble = bubble;

      const renderReplies = (list) => {
        replies.textContent = '';
        if (!list.length) { replies.appendChild(el('div', 'wc-empty', 'No replies yet.')); return; }
        for (const r of list) {
          const item = el('div', 'wc-reply');
          item.appendChild(el('div', 'wc-meta', `${r.author_name} · ${fmtDate(r.created_at)}`));
          item.appendChild(el('div', null, r.body));
          replies.appendChild(item);
        }
      };

      let list = [];
      try {
        list = (await this.deps.api('GET', `/api/comments/${c.id}/replies`)).replies;
        renderReplies(list);
      } catch (e) { err.textContent = e.message; }

      replyBtn.addEventListener('click', async () => {
        const body = replyTa.value.trim();
        if (!body) return;
        replyBtn.disabled = true;
        try {
          await this.deps.api('POST', `/api/comments/${c.id}/replies`, { body });
          list.push({ author_name: 'You', created_at: new Date().toISOString(), body });
          c.reply_count = (c.reply_count || 0) + 1;
          renderReplies(list);
          replyTa.value = '';
        } catch (e) { err.textContent = e.message; }
        replyBtn.disabled = false;
      });
    }
  }

  window.WCOverlay = {
    create(deps) { return new Overlay(deps); },
  };
})();
