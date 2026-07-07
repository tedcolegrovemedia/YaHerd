// Dashboard mutations go through the JSON API using the PHP session cookie.
async function api(method, path, body) {
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

function toast(msg, isError) {
  const t = document.createElement('div');
  t.textContent = msg;
  t.className = 'toast ' + (isError ? 'toast-err' : 'toast-ok');
  t.setAttribute('role', 'status');
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 2500);
}

// ----- Board: drag & drop between status columns -----
let draggedCard = null;

document.addEventListener('dragstart', (ev) => {
  const card = ev.target.closest('.card[draggable]');
  if (!card) return;
  draggedCard = card;
  card.classList.add('dragging');
  ev.dataTransfer.effectAllowed = 'move';
  ev.dataTransfer.setData('text/plain', card.dataset.comment);
});

document.addEventListener('dragend', () => {
  if (draggedCard) draggedCard.classList.remove('dragging');
  draggedCard = null;
  document.querySelectorAll('.column.drag-over').forEach((c) => c.classList.remove('drag-over'));
});

document.addEventListener('dragover', (ev) => {
  const col = ev.target.closest('.column[data-status]');
  if (!col || !draggedCard) return;
  ev.preventDefault();
  ev.dataTransfer.dropEffect = 'move';
  document.querySelectorAll('.column.drag-over').forEach((c) => c !== col && c.classList.remove('drag-over'));
  col.classList.add('drag-over');
});

document.addEventListener('dragleave', (ev) => {
  const col = ev.target.closest('.column[data-status]');
  if (col && !col.contains(ev.relatedTarget)) col.classList.remove('drag-over');
});

function refreshColumnCounts() {
  document.querySelectorAll('.column[data-status]').forEach((col) => {
    const n = col.querySelectorAll('.card').length;
    const count = col.querySelector('.count');
    if (count) count.textContent = n;
    const empty = col.querySelector('.empty');
    if (empty) empty.hidden = n > 0;
  });
}

document.addEventListener('drop', async (ev) => {
  const col = ev.target.closest('.column[data-status]');
  if (!col || !draggedCard) return;
  ev.preventDefault();
  col.classList.remove('drag-over');
  const card = draggedCard;
  const from = card.closest('.column[data-status]');
  if (from === col) return;
  const status = col.dataset.status;
  col.querySelector('.cards').prepend(card);
  refreshColumnCounts();
  try {
    await api('PATCH', `/api/comments/${card.dataset.comment}/status`, { status });
    toast('Moved to ' + col.querySelector('h2').firstChild.textContent.trim());
  } catch (e) {
    from.querySelector('.cards').prepend(card); // roll back on failure
    refreshColumnCounts();
    toast(e.message, true);
  }
});

// ----- Screenshot lightbox -----
document.addEventListener('click', (ev) => {
  const icon = ev.target.closest('.shot-icon');
  if (icon) {
    ev.preventDefault();
    const box = document.createElement('div');
    box.className = 'lightbox';
    const img = document.createElement('img');
    img.src = icon.dataset.shot;
    img.alt = 'Pin screenshot';
    box.appendChild(img);
    box.addEventListener('click', () => box.remove());
    document.addEventListener('keydown', function esc(e) {
      if (e.key === 'Escape') { box.remove(); document.removeEventListener('keydown', esc); }
    });
    document.body.appendChild(box);
  }
});

document.addEventListener('change', async (ev) => {
  const el = ev.target;
  try {
    if (el.matches('.status-select')) {
      await api('PATCH', `/api/comments/${el.dataset.comment}/status`, { status: el.value });
      toast('Status updated');
      if (document.querySelector('.board')) location.reload();
    } else if (el.matches('.assignee-select')) {
      await api('PATCH', `/api/comments/${el.dataset.comment}/assignee`, {
        user_id: el.value ? +el.value : null,
      });
      toast(el.value ? 'Assigned to ' + el.options[el.selectedIndex].text.trim() : 'Unassigned');
    } else if (el.matches('.user-role')) {
      await api('PATCH', `/api/users/${el.dataset.user}`, { role: el.value });
      toast('Role updated');
    } else if (el.matches('.user-active')) {
      await api('PATCH', `/api/users/${el.dataset.user}`, { is_active: el.checked });
      toast(el.checked ? 'User activated' : 'User deactivated');
    } else if (el.matches('.notify-pref')) {
      await api('POST', '/api/me/notification-prefs', { [el.dataset.pref]: el.checked });
      toast('Notification preferences saved');
    }
  } catch (e) {
    toast(e.message, true);
  }
});

document.addEventListener('submit', async (ev) => {
  const form = ev.target;

  if (form.id === 'create-user-form') {
    ev.preventDefault();
    const f = new FormData(form);
    try {
      const res = await api('POST', '/api/users', {
        display_name: f.get('display_name'),
        email: f.get('email'),
        password: f.get('password'),
        role: f.get('role'),
      });
      if (res.email_sent === false) {
        toast('User created, but the login email could not be sent', true);
        setTimeout(() => location.reload(), 2500);
      } else {
        location.reload();
      }
    } catch (e) { toast(e.message, true); }
  }

  if (form.id === 'change-password-form') {
    ev.preventDefault();
    const f = new FormData(form);
    if (f.get('new_password') !== f.get('confirm_password')) {
      return toast('New passwords do not match', true);
    }
    try {
      await api('POST', '/api/me/password', {
        current_password: f.get('current_password'),
        new_password: f.get('new_password'),
      });
      form.reset();
      toast('Password updated');
    } catch (e) { toast(e.message, true); }
  }

  if (form.id === 'create-project-form') {
    ev.preventDefault();
    const f = new FormData(form);
    try {
      await api('POST', '/api/projects', {
        name: f.get('name'),
        base_origin: f.get('base_origin'),
        match_subdomains: !!f.get('match_subdomains'),
      });
      location.reload();
    } catch (e) { toast(e.message, true); }
  }

  if (form.classList.contains('pw-form')) {
    ev.preventDefault();
    const pw = form.querySelector('input[name=password]').value;
    if (pw.length < 8) return toast('Password must be at least 8 characters', true);
    try {
      await api('PATCH', `/api/users/${form.dataset.user}`, { password: pw });
      form.reset();
      toast('Password updated');
    } catch (e) { toast(e.message, true); }
  }

  if (form.id === 'reply-form') {
    ev.preventDefault();
    const body = form.querySelector('textarea[name=body]').value.trim();
    if (!body) return;
    try {
      await api('POST', `/api/comments/${form.dataset.comment}/replies`, { body });
      location.reload();
    } catch (e) { toast(e.message, true); }
  }
});

// ----- Admin: delete a project -----
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.delete-project');
  if (!btn) return;
  const name = btn.dataset.name || 'this project';
  if (!confirm(`Delete "${name}"? This permanently removes the project and all its comments and screenshots. This cannot be undone.`)) return;
  try {
    await api('DELETE', `/api/projects/${btn.dataset.project}`);
    btn.closest('.project-card').remove();
    toast('Project deleted');
  } catch (e) { toast(e.message, true); }
});

// ----- Notifications center (mark read) -----
function updateNotifBadge(delta) {
  const badge = document.querySelector('.notif-badge');
  if (!badge) return;
  if (delta === 0) { badge.hidden = true; return; }
  const cur = parseInt(badge.textContent, 10) || 0;
  const next = Math.max(0, cur - delta);
  badge.textContent = next > 9 ? '9+' : next;
  badge.hidden = next === 0;
}

document.addEventListener('click', async (ev) => {
  const one = ev.target.closest('.notif-read');
  const all = ev.target.closest('.notif-readall');
  if (!one && !all) return;

  if (one) {
    const item = one.closest('.notif-item');
    try {
      await api('POST', '/api/me/notifications/read', { id: +item.dataset.id });
      item.remove();
      updateNotifBadge(1);
    } catch (e) { return toast(e.message, true); }
  } else {
    const items = document.querySelectorAll('.notif-item');
    try {
      await api('POST', '/api/me/notifications/read', {});
      items.forEach((i) => i.remove());
      updateNotifBadge(0);
    } catch (e) { return toast(e.message, true); }
  }

  const list = document.querySelector('.notif-list');
  if (list && !list.querySelector('.notif-item')) {
    const empty = document.querySelector('.notif-empty');
    if (empty) empty.hidden = false;
    const readall = document.querySelector('.notif-readall');
    if (readall) readall.hidden = true;
  }
});

// ----- Tasks: archive / restore / delete -----
document.addEventListener('click', async (ev) => {
  const arch = ev.target.closest('.archive-task');
  const del = ev.target.closest('.delete-task');
  if (!arch && !del) return;

  const btn = arch || del;
  const id = btn.dataset.comment;
  const onDetail = btn.closest('.task-detail');
  const boardHref = () => {
    const acts = btn.closest('.task-actions');
    return `/board?project=${acts ? acts.dataset.project : ''}`;
  };

  if (arch) {
    const makeArchived = arch.dataset.archived === '0';
    try {
      await api('PATCH', `/api/comments/${id}/archive`, { archived: makeArchived });
      toast(makeArchived ? 'Task archived' : 'Task restored');
      if (onDetail) { location.href = boardHref(); return; }
      const card = arch.closest('.card');
      if (card) card.remove();
      refreshColumnCounts();
    } catch (e) { toast(e.message, true); }
    return;
  }

  if (!confirm('Delete this task permanently? This removes the comment, its replies, and screenshot. This cannot be undone.')) return;
  try {
    await api('DELETE', `/api/comments/${id}`);
    toast('Task deleted');
    if (onDetail) { location.href = boardHref(); return; }
    const card = del.closest('.card');
    if (card) card.remove();
    refreshColumnCounts();
  } catch (e) { toast(e.message, true); }
});

// ----- Admin: delete a user -----
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.delete-user');
  if (!btn) return;
  const name = btn.dataset.name || 'this user';
  if (!confirm(`Delete ${name}? This permanently removes their account. This cannot be undone.`)) return;
  try {
    await api('DELETE', `/api/users/${btn.dataset.user}`);
    btn.closest('.user-row').remove();
    toast('User deleted');
  } catch (e) { toast(e.message, true); }
});

// ----- Admin: project-member assignment (chips + typeahead) -----
(function initAssign() {
  const dataEl = document.getElementById('all-users');
  if (!dataEl) return;
  let allUsers = [];
  try { allUsers = JSON.parse(dataEl.textContent) || []; } catch (_) {}

  document.querySelectorAll('.assign-box').forEach((box) => {
    const chips = box.querySelector('.chips');
    const input = box.querySelector('.assign-input');
    const menu = box.querySelector('.assign-menu');
    const projectId = box.dataset.project;
    let activeIndex = -1;

    const currentIds = () =>
      [...chips.querySelectorAll('.chip')].map((c) => +c.dataset.id);

    async function save() {
      try {
        await api('PUT', `/api/projects/${projectId}/users`, { user_ids: currentIds() });
        toast('Assignments saved');
      } catch (e) { toast(e.message, true); }
    }

    function addChip(user) {
      const chip = document.createElement('span');
      chip.className = 'chip';
      chip.dataset.id = user.id;
      chip.textContent = user.name + ' ';
      const x = document.createElement('button');
      x.type = 'button';
      x.className = 'chip-x';
      x.setAttribute('aria-label', 'Remove');
      x.innerHTML = '&times;';
      chip.appendChild(x);
      chips.appendChild(chip);
    }

    function closeMenu() { menu.hidden = true; menu.innerHTML = ''; activeIndex = -1; }

    function matches() {
      const q = input.value.trim().toLowerCase();
      const taken = new Set(currentIds());
      return allUsers.filter((u) =>
        !taken.has(u.id) &&
        (q === '' || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q))
      ).slice(0, 8);
    }

    function renderMenu() {
      const list = matches();
      if (!list.length) { closeMenu(); return; }
      menu.innerHTML = '';
      list.forEach((u, i) => {
        const opt = document.createElement('div');
        opt.className = 'assign-opt' + (i === activeIndex ? ' active' : '');
        opt.dataset.id = u.id;
        opt.innerHTML = `<span>${escapeHtml(u.name)}</span><small>${escapeHtml(u.email)}</small>`;
        menu.appendChild(opt);
      });
      menu.hidden = false;
    }

    function pick(id) {
      const user = allUsers.find((u) => u.id === +id);
      if (!user) return;
      addChip(user);
      input.value = '';
      closeMenu();
      save();
    }

    input.addEventListener('input', () => { activeIndex = -1; renderMenu(); });
    input.addEventListener('focus', renderMenu);
    input.addEventListener('keydown', (e) => {
      const opts = [...menu.querySelectorAll('.assign-opt')];
      if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(activeIndex + 1, opts.length - 1); renderMenu(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0); renderMenu(); }
      else if (e.key === 'Enter') {
        if (activeIndex >= 0 && opts[activeIndex]) { e.preventDefault(); pick(opts[activeIndex].dataset.id); }
      } else if (e.key === 'Escape') { closeMenu(); }
    });

    menu.addEventListener('mousedown', (e) => {
      const opt = e.target.closest('.assign-opt');
      if (opt) { e.preventDefault(); pick(opt.dataset.id); }
    });

    chips.addEventListener('click', (e) => {
      const x = e.target.closest('.chip-x');
      if (!x) return;
      x.closest('.chip').remove();
      save();
    });

    document.addEventListener('click', (e) => { if (!box.contains(e.target)) closeMenu(); });
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
})();
