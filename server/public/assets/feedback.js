// Feedback review UI: the editor (/feedback/page, data-mode="edit") and the
// public student view (/f/<token>, data-mode="view"). Both embed the page
// replay in a sandboxed iframe and talk to lf-frame.js through postMessage.
(function () {
  'use strict';
  const app = document.querySelector('.fb-app');
  if (!app) return;

  const MODE = app.dataset.mode;
  const TOKEN = app.dataset.token;
  const PAGE_ID = app.dataset.page;
  const frame = app.querySelector('.fb-frame');
  const list = document.getElementById('fb-notes');
  let notes = JSON.parse(document.getElementById('fb-initial').textContent || '[]');
  let activeId = null;

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
  // (re)loads, so messages sent before it was listening don't matter.
  const syncFrame = () => tell({ cmd: 'notes', notes });

  window.addEventListener('message', (ev) => {
    if (ev.source !== frame.contentWindow) return;
    const m = ev.data || {};
    if (!m.lf) return;
    if (m.type === 'ready') syncFrame();
    if (m.type === 'pin') setActive(m.id, true);
    if (m.type === 'picked' && MODE === 'edit') onPicked(m);
    if (m.type === 'pick-cancel' && MODE === 'edit') endPick();
  });
  // In case the frame announced itself before this script was running.
  tell({ cmd: 'hello' });

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

  function render() {
    list.textContent = '';
    if (!notes.length) {
      const p = document.createElement('p');
      p.className = 'empty';
      p.textContent = MODE === 'edit' ? 'No comments yet. Click “+ Add comment”, then click part of the page.' : 'No feedback on this page yet.';
      list.append(p);
    }
    notes.forEach((n) => list.append(noteCard(n)));
    const acked = notes.filter((n) => n.closed).length;
    const progress = document.getElementById('fb-progress');
    if (progress) progress.textContent = notes.length ? `${acked} of ${notes.length} acknowledged` : '';
    syncFrame();
  }

  function setActive(id, fromFrame) {
    activeId = id;
    list.querySelectorAll('.fb-note').forEach((c) => c.classList.toggle('active', +c.dataset.id === id));
    const card = list.querySelector(`.fb-note[data-id="${id}"]`);
    if (card && fromFrame) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    if (!fromFrame) tell({ cmd: 'focus', id });
  }

  async function studentToggle(n, action) {
    try {
      const res = await call('POST', `/api/share/${TOKEN}/notes/${n.id}/${action}`);
      notes = res.notes;
      render();
    } catch (e) { flash(e.message, true); }
  }

  render();
  if (MODE !== 'edit') return;

  // ======================= Editor only =======================
  const addBtn = document.getElementById('fb-add');
  const hint = document.getElementById('fb-pick-hint');
  const form = document.getElementById('fb-compose');
  const target = document.getElementById('fb-target');
  const attach = form.elements.attach_code;
  const codePick = form.querySelector('.fb-code-pick');
  const sourceBox = form.querySelector('.fb-source');
  const MAX_LINES = 80;
  let draft = null; // { anchor, src, editId }
  let sourceLines = null;

  function startPick() {
    cancelCompose();
    addBtn.hidden = true;
    hint.hidden = false;
    tell({ cmd: 'pick', on: true });
  }
  function endPick() {
    hint.hidden = true;
    addBtn.hidden = false;
    tell({ cmd: 'pick', on: false });
  }
  addBtn.addEventListener('click', startPick);
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && !hint.hidden) endPick();
  });

  async function loadSource() {
    if (sourceLines) return sourceLines;
    const res = await call('GET', `/api/feedback/pages/${PAGE_ID}/source`);
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

  function onPicked(m) {
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
  }

  function startEdit(n) {
    endPick();
    draft = { anchor: null, src: n.code_start ? [n.code_start, n.code_end] : null, editId: n.id };
    form.reset();
    form.elements.body.value = n.body;
    attach.checked = !!n.code;
    codePick.hidden = true;
    if (n.code) showCodePicker(n.code_start, n.code_end);
    openCompose(`Editing comment ${n.number}`);
    setActive(n.id, false);
  }

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
        res = await call('POST', `/api/feedback/pages/${PAGE_ID}/notes`, Object.assign(payload, { anchor: draft.anchor }));
        activeId = res.id;
      }
      notes = res.notes;
      cancelCompose();
      render();
      flash('Comment saved');
    } catch (e) { flash(e.message, true); }
    submit.disabled = false;
  });

  async function editNote(id, changes) {
    try {
      notes = (await call('PATCH', `/api/feedback/notes/${id}`, changes)).notes;
      render();
    } catch (e) { flash(e.message, true); }
  }

  async function deleteNote(n) {
    if (!confirm(`Delete comment ${n.number}? This can't be undone.`)) return;
    try {
      notes = (await call('DELETE', `/api/feedback/notes/${n.id}`)).notes;
      render();
    } catch (e) { flash(e.message, true); }
  }

  // ---------- Toolbar ----------
  app.querySelectorAll('.fb-viewports button').forEach((b) => b.addEventListener('click', () => {
    app.querySelectorAll('.fb-viewports button').forEach((x) => x.classList.toggle('active', x === b));
    frame.style.width = b.dataset.width ? b.dataset.width + 'px' : '';
  }));

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
      const res = await call('PATCH', `/api/feedback/pages/${PAGE_ID}`, { new_link: true });
      // The frame is served under the token, so reload onto the new link.
      location.reload();
      flash('New link created: ' + res.share_url);
    } catch (e) { flash(e.message, true); }
  });

  document.getElementById('fb-resnap').addEventListener('click', async (ev) => {
    if (!confirm('Re-download the live page? Pins re-attach to their elements; code already quoted in comments stays as it was.')) return;
    const b = ev.currentTarget;
    b.disabled = true;
    try {
      await call('POST', `/api/feedback/pages/${PAGE_ID}/snapshot`);
      sourceLines = null;
      frame.src = frame.src;
      flash('Snapshot refreshed');
    } catch (e) { flash(e.message, true); }
    b.disabled = false;
  });
})();
