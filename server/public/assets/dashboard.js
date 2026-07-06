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
  t.style.cssText =
    'position:fixed;bottom:20px;right:20px;padding:10px 16px;border-radius:8px;color:#fff;z-index:99;' +
    'font-size:14px;background:' + (isError ? '#dc2626' : '#10b981');
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
      await api('POST', '/api/users', {
        display_name: f.get('display_name'),
        email: f.get('email'),
        password: f.get('password'),
        role: f.get('role'),
      });
      location.reload();
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

  if (form.classList.contains('assign-form')) {
    ev.preventDefault();
    const ids = [...form.querySelectorAll('input[name="user_ids[]"]:checked')].map((i) => +i.value);
    try {
      await api('PUT', `/api/projects/${form.dataset.project}/users`, { user_ids: ids });
      toast('Assignments saved');
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
