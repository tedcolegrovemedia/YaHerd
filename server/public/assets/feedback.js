// Feedback review UI: the editor (/feedback/review, data-mode="edit") and the
// public student view (/f/<token>, data-mode="view"). A review has several
// pages; both views show one at a time in a sandboxed iframe and talk to
// lf-frame.js through postMessage.
(function () {
  'use strict';
  const app = document.querySelector('.fb-app');
  if (!app) return;

  const MODE = app.dataset.mode;
  const TOKEN = app.dataset.token;
  const REVIEW_ID = app.dataset.review;
  const frame = app.querySelector('.fb-frame');
  const empty = app.querySelector('.fb-stage-empty');
  const list = document.getElementById('fb-notes');
  const pageSelect = document.getElementById('fb-page-select');
  const initial = JSON.parse(document.getElementById('fb-initial').textContent || '{}');
  let pages = initial.pages || [];
  let pageId = initial.current || null;
  let activeId = null;

  const currentPage = () => pages.find((p) => p.id === pageId) || null;
  const notesOf = (p) => (p ? p.notes || [] : []);

  async function call(method, path, body) {
    const res = await fetch(path, {
      method,
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || res.statusText);
    return data;
  }

  function flash(msg, isError) {
    if (typeof toast === 'function') return toast(msg, isError);
    const t = document.createElement('div');
    t.textContent = msg;
    t.className = 'toast ' + (isError ? 'toast-err' : 'toast-ok');
    t.setAttribute('role', 'status');
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2500);
  }

  // The replay runs in an opaque origin, so '*' is the only usable target;
  // nothing sent here is more than the share link already reveals.
  function tell(msg) {
    if (frame.contentWindow) frame.contentWindow.postMessage(Object.assign({ lf: 1 }, msg), '*');
  }
  // Sent on every change; the frame also asks for a copy whenever it
  // (re)loads, so messages sent before it was listening don't matter. The
  // page list lets links inside the replay switch to other reviewed pages.
  const syncFrame = () => tell({
    cmd: 'notes',
    notes: notesOf(currentPage()),
    pages: pages.map((p) => ({ id: p.id, url: p.url })),
  });

  window.addEventListener('message', (ev) => {
    if (ev.source !== frame.contentWindow) return;
    const m = ev.data || {};
    if (!m.lf) return;
    if (m.type === 'ready') syncFrame();
    if (m.type === 'pin') setActive(m.id, true);
    if (m.type === 'goto' && pages.some((p) => p.id === m.id)) showPage(m.id);
    if (m.type === 'picked' && MODE === 'edit') onPicked(m);
    if (m.type === 'pick-cancel' && MODE === 'edit') endPick();
  });

  // ---------- Code rendering ----------
  const escHtml = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  // Light HTML highlighting: tags, attribute names, strings, comments.
  function highlight(lines) {
    let inTag = false;
    const re = /<!--.*?(?:-->|$)|<\/?[A-Za-z][\w:-]*|\/?>|"[^"]*"?|'[^']*'?|[A-Za-z_:@][\w:.-]*/g;
    return lines.map((line) => {
      let out = '';
      let i = 0;
      let m;
      re.lastIndex = 0;
      while ((m = re.exec(line))) {
        const t = m[0];
        out += escHtml(line.slice(i, m.index));
        let cls = null;
        if (t.startsWith('<!--')) cls = 'c-com';
        else if (t[0] === '<') { cls = 'c-tag'; inTag = true; }
        else if (t === '>' || t === '/>') { if (inTag) { cls = 'c-tag'; inTag = false; } }
        else if (inTag && (t[0] === '"' || t[0] === "'")) cls = 'c-str';
        else if (inTag) cls = 'c-attr';
        out += cls ? `<span class="${cls}">${escHtml(t)}</span>` : escHtml(t);
        i = m.index + t.length;
      }
      return out + escHtml(line.slice(i));
    });
  }

  // Strip the indentation the lines share so deeply nested code reads well.
  function dedent(lines) {
    const indents = lines.filter((l) => l.trim()).map((l) => l.match(/^[ \t]*/)[0].length);
    const n = indents.length ? Math.min(...indents) : 0;
    return lines.map((l) => l.slice(n));
  }

  function codeBlock(n) {
    const lines = dedent(n.code.split('\n'));
    const html = highlight(lines);
    const wrap = document.createElement('div');
    wrap.className = 'fb-code';
    const head = document.createElement('div');
    head.className = 'fb-code-head';
    head.textContent = n.code_start === n.code_end ? `HTML · line ${n.code_start}` : `HTML · lines ${n.code_start}–${n.code_end}`;
    const body = document.createElement('div');
    body.className = 'fb-code-lines';
    body.innerHTML = html.map((h, i) =>
      `<div class="cl"><span class="ln">${n.code_start + i}</span><span class="lc">${h || ' '}</span></div>`).join('');
    wrap.append(head, body);
    return wrap;
  }

  // ---------- Notes list ----------
  function btn(label, cls, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = cls;
    b.textContent = label;
    b.addEventListener('click', (ev) => { ev.stopPropagation(); onClick(); });
    return b;
  }

  function noteCard(n) {
    const card = document.createElement('article');
    card.className = 'fb-note' + (n.closed ? ' acked' : '') + (n.id === activeId ? ' active' : '');
    card.dataset.id = n.id;
    card.tabIndex = 0;

    const head = document.createElement('header');
    const num = document.createElement('span');
    num.className = 'fb-num';
    num.textContent = n.number;
    const meta = document.createElement('span');
    meta.className = 'fb-meta';
    meta.textContent = [n.author_name, n.created_ago].filter(Boolean).join(' · ');
    head.append(num, meta);
    if (n.closed) {
      const pill = document.createElement('span');
      pill.className = 'pill pill-complete';
      pill.textContent = '✓ Acknowledged';
      pill.title = n.closed_ago ? 'Acknowledged ' + n.closed_ago : '';
      head.append(pill);
    }

    const body = document.createElement('div');
    body.className = 'fb-body';
    body.textContent = n.body;
    card.append(head, body);
    if (n.code) card.append(codeBlock(n));

    const foot = document.createElement('footer');
    if (MODE === 'edit') {
      foot.append(
        btn('Edit', 'fb-link', () => startEdit(n)),
        ...(n.closed ? [btn('Clear acknowledgment', 'fb-link', () => editNote(n.id, { closed: false }))] : []),
        btn('Delete', 'fb-link danger', () => deleteNote(n)),
      );
    } else {
      foot.append(n.closed
        ? btn('Undo', 'fb-link', () => studentToggle(n, 'reopen'))
        : btn('Got it', 'fb-done', () => studentToggle(n, 'close')));
    }
    card.append(foot);
    card.addEventListener('click', () => setActive(n.id, false));
    card.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' && ev.target === card) setActive(n.id, false); });
    return card;
  }

  function pageOptionLabel(p) {
    const ns = notesOf(p);
    if (!ns.length) return p.label;
    const pending = ns.filter((n) => !n.closed).length;
    if (MODE === 'edit') return `${p.label} · ${ns.length} comment${ns.length === 1 ? '' : 's'}`;
    return pending ? `${p.label} · ${pending} to read` : `${p.label} · ✓`;
  }

  function renderPageSelect() {
    if (!pageSelect) return;
    pageSelect.textContent = '';
    for (const p of pages) {
      const o = new Option(pageOptionLabel(p), String(p.id), false, p.id === pageId);
      pageSelect.append(o);
    }
    pageSelect.disabled = pages.length < 2 && MODE !== 'edit';
  }

  function render() {
    const page = currentPage();
    const notes = notesOf(page);
    list.textContent = '';
    if (page && !notes.length) {
      const p = document.createElement('p');
      p.className = 'empty';
      p.textContent = MODE === 'edit' ? 'No comments on this page yet. Click “+ Add comment”, then click part of the page.' : 'No feedback on this page.';
      list.append(p);
    }
    notes.forEach((n) => list.append(noteCard(n)));

    const all = pages.flatMap(notesOf);
    const acked = all.filter((n) => n.closed).length;
    const progress = document.getElementById('fb-progress');
    if (progress) progress.textContent = all.length ? `${acked} of ${all.length} acknowledged` : '';
    renderPageSelect();
    syncFrame();
  }

  function setActive(id, fromFrame) {
    activeId = id;
    list.querySelectorAll('.fb-note').forEach((c) => c.classList.toggle('active', +c.dataset.id === id));
    const card = list.querySelector(`.fb-note[data-id="${id}"]`);
    if (card && fromFrame) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    if (!fromFrame) tell({ cmd: 'focus', id });
  }

  function showPage(id) {
    pageId = id;
    activeId = null;
    const page = currentPage();
    frame.hidden = !page;
    if (empty) empty.hidden = !!page;
    if (page) frame.src = `/f/${TOKEN}/frame/${page.id}`;
    const url = new URL(location.href);
    if (page) url.searchParams.set('page', page.id); else url.searchParams.delete('page');
    url.searchParams.delete('warning');
    history.replaceState(null, '', url);
    if (MODE === 'edit') onPageChange(page);
    render();
  }

  if (pageSelect) pageSelect.addEventListener('change', () => showPage(+pageSelect.value));

  async function studentToggle(n, action) {
    try {
      const res = await call('POST', `/api/share/${TOKEN}/notes/${n.id}/${action}`);
      currentPage().notes = res.notes;
      render();
    } catch (e) { flash(e.message, true); }
  }

  // ======================= Editor only =======================
  let onPageChange = () => {};
  let onPicked = () => {};
  let endPick = () => {};
  let startEdit = () => {};
  let editNote = async () => {};
  let deleteNote = async () => {};

  if (MODE === 'edit') {
    const addBtn = document.getElementById('fb-add');
    const hint = document.getElementById('fb-pick-hint');
    const form = document.getElementById('fb-compose');
    const target = document.getElementById('fb-target');
    const attach = form.elements.attach_code;
    const codePick = form.querySelector('.fb-code-pick');
    const sourceBox = form.querySelector('.fb-source');
    const pageUrl = document.getElementById('fb-page-url');
    const MAX_LINES = 80;
    let draft = null; // { anchor, src, editId }
    let sourceLines = null;

    const setNotes = (ns) => { currentPage().notes = ns; };

    onPageChange = (page) => {
      cancelCompose();
      endPick();
      sourceLines = null;
      addBtn.disabled = !page;
      document.getElementById('fb-resnap').disabled = !page;
      document.getElementById('fb-del-page').disabled = !page;
      if (page) { pageUrl.href = page.url; pageUrl.textContent = page.url; }
    };

    function startPick() {
      cancelCompose();
      addBtn.hidden = true;
      hint.hidden = false;
      tell({ cmd: 'pick', on: true });
    }
    endPick = () => {
      hint.hidden = true;
      addBtn.hidden = false;
      tell({ cmd: 'pick', on: false });
    };
    addBtn.addEventListener('click', startPick);
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !hint.hidden) endPick();
    });

    async function loadSource() {
      if (sourceLines) return sourceLines;
      const res = await call('GET', `/api/feedback/pages/${pageId}/source`);
      sourceLines = res.lines;
      const html = highlight(sourceLines);
      sourceBox.innerHTML = html.map((h, i) =>
        `<div class="sl" data-line="${i + 1}"><button type="button" class="ln" tabindex="-1">${i + 1}</button><span class="lc">${h || ' '}</span></div>`).join('');
      return sourceLines;
    }

    function setRange(start, end, scroll) {
      const max = sourceLines ? sourceLines.length : 1;
      start = Math.min(Math.max(1, start | 0), max);
      end = Math.min(Math.max(start, end | 0), max, start + MAX_LINES - 1);
      form.elements.code_start.value = start;
      form.elements.code_end.value = end;
      sourceBox.querySelectorAll('.sl.sel').forEach((el) => el.classList.remove('sel'));
      for (let i = start; i <= end; i++) {
        const row = sourceBox.children[i - 1];
        if (row) row.classList.add('sel');
      }
      if (scroll) {
        const row = sourceBox.children[start - 1];
        if (row) sourceBox.scrollTop = row.offsetTop - sourceBox.clientHeight / 3;
      }
    }

    sourceBox.addEventListener('click', (ev) => {
      const row = ev.target.closest('.sl');
      if (!row) return;
      const line = +row.dataset.line;
      const start = +form.elements.code_start.value || line;
      if (ev.shiftKey) setRange(Math.min(start, line), Math.max(start, line));
      else setRange(line, line);
    });
    for (const name of ['code_start', 'code_end']) {
      form.elements[name].addEventListener('change', () =>
        setRange(+form.elements.code_start.value, +form.elements.code_end.value, true));
    }

    async function showCodePicker(start, end) {
      codePick.hidden = false;
      try {
        await loadSource();
        setRange(start, end, true);
      } catch (e) { flash(e.message, true); }
    }
    attach.addEventListener('change', () => {
      if (!attach.checked) { codePick.hidden = true; return; }
      const [s, e] = draft && draft.src ? draft.src : [1, 1];
      showCodePicker(s, Math.min(e, s + 14));
    });

    function openCompose(label) {
      target.textContent = label;
      form.hidden = false;
      form.elements.body.focus();
    }

    onPicked = (m) => {
      endPick();
      draft = { anchor: m.anchor, src: m.src, editId: null };
      form.reset();
      codePick.hidden = true;
      openCompose(m.label + (m.src ? `  ·  line ${m.src[0]}` : ''));
      // Default to the element's code when it's short enough to be useful.
      if (m.src && m.src[1] - m.src[0] < 40) {
        attach.checked = true;
        showCodePicker(m.src[0], m.src[1]);
      }
    };

    startEdit = (n) => {
      endPick();
      draft = { anchor: null, src: n.code_start ? [n.code_start, n.code_end] : null, editId: n.id };
      form.reset();
      form.elements.body.value = n.body;
      attach.checked = !!n.code;
      codePick.hidden = true;
      if (n.code) showCodePicker(n.code_start, n.code_end);
      openCompose(`Editing comment ${n.number}`);
      setActive(n.id, false);
    };

    function cancelCompose() {
      draft = null;
      form.hidden = true;
      form.reset();
      tell({ cmd: 'clear-preview' });
    }
    document.getElementById('fb-cancel').addEventListener('click', cancelCompose);

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!draft) return;
      const payload = { body: form.elements.body.value };
      if (attach.checked) {
        payload.code_start = +form.elements.code_start.value;
        payload.code_end = +form.elements.code_end.value;
      } else {
        payload.code_start = null;
      }
      const submit = form.querySelector('button[type=submit]');
      submit.disabled = true;
      try {
        let res;
        if (draft.editId) {
          res = await call('PATCH', `/api/feedback/notes/${draft.editId}`, payload);
        } else {
          res = await call('POST', `/api/feedback/pages/${pageId}/notes`, Object.assign(payload, { anchor: draft.anchor }));
          activeId = res.id;
        }
        setNotes(res.notes);
        cancelCompose();
        render();
        flash('Comment saved');
      } catch (e) { flash(e.message, true); }
      submit.disabled = false;
    });

    editNote = async (id, changes) => {
      try {
        setNotes((await call('PATCH', `/api/feedback/notes/${id}`, changes)).notes);
        render();
      } catch (e) { flash(e.message, true); }
    };

    deleteNote = async (n) => {
      if (!confirm(`Delete comment ${n.number}? This can't be undone.`)) return;
      try {
        setNotes((await call('DELETE', `/api/feedback/notes/${n.id}`)).notes);
        render();
      } catch (e) { flash(e.message, true); }
    };

    // Merge a fresh page list from the server, keeping notes we already have.
    function mergePages(fresh) {
      const known = new Map(pages.map((p) => [p.id, p]));
      pages = fresh.map((p) => ({ ...p, notes: known.has(p.id) ? known.get(p.id).notes : [] }));
    }

    // ---------- Toolbar ----------
    app.querySelectorAll('.fb-viewports button').forEach((b) => b.addEventListener('click', () => {
      app.querySelectorAll('.fb-viewports button').forEach((x) => x.classList.toggle('active', x === b));
      frame.style.width = b.dataset.width ? b.dataset.width + 'px' : '';
    }));

    document.getElementById('fb-add-page').addEventListener('click', async (ev) => {
      const url = prompt('URL of another page on this site:', currentPage() ? currentPage().url : '');
      if (!url) return;
      const b = ev.currentTarget;
      b.disabled = true;
      b.textContent = 'Saving…';
      try {
        const res = await call('POST', `/api/feedback/reviews/${REVIEW_ID}/pages`, { url });
        mergePages(res.review.pages);
        showPage(res.page_id);
        flash(res.created ? 'Page added' : 'That page is already in this review');
      } catch (e) { flash(e.message, true); }
      b.disabled = false;
      b.textContent = '+ Page';
    });

    document.getElementById('fb-del-page').addEventListener('click', async () => {
      const page = currentPage();
      if (!page) return;
      const n = notesOf(page).length;
      if (!confirm(`Remove “${page.label}” from this review${n ? ` and delete its ${n} comment${n === 1 ? '' : 's'}` : ''}? This can't be undone.`)) return;
      try {
        await call('DELETE', `/api/feedback/pages/${page.id}`);
        pages = pages.filter((p) => p.id !== page.id);
        showPage(pages.length ? pages[0].id : null);
        flash('Page removed');
      } catch (e) { flash(e.message, true); }
    });

    document.getElementById('fb-copy').addEventListener('click', async (ev) => {
      try {
        await navigator.clipboard.writeText(ev.currentTarget.dataset.url);
        flash('Share link copied');
      } catch (_) {
        prompt('Copy this link:', ev.currentTarget.dataset.url);
      }
    });

    document.getElementById('fb-relink').addEventListener('click', async () => {
      if (!confirm('Create a new share link? Anyone using the current link (including the student) will lose access until you send them the new one.')) return;
      try {
        await call('PATCH', `/api/feedback/reviews/${REVIEW_ID}`, { new_link: true });
        // The frames are served under the token, so reload onto the new link.
        location.reload();
      } catch (e) { flash(e.message, true); }
    });

    document.getElementById('fb-resnap').addEventListener('click', async (ev) => {
      if (!confirm('Re-download this page from the live site? Pins re-attach to their elements; code already quoted in comments stays as it was.')) return;
      const b = ev.currentTarget;
      b.disabled = true;
      try {
        await call('POST', `/api/feedback/pages/${pageId}/snapshot`);
        sourceLines = null;
        frame.src = frame.src;
        flash('Snapshot refreshed');
      } catch (e) { flash(e.message, true); }
      b.disabled = false;
    });
  }

  showPage(pageId);
  // In case the frame announced itself before this script was running.
  tell({ cmd: 'hello' });
})();
