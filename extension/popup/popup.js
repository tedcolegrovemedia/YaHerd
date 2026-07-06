function send(msg) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage(msg, (res) => {
      if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
      res && res.ok ? resolve(res.data) : reject(new Error(res ? res.error : 'No response'));
    });
  });
}

const $ = (id) => document.getElementById(id);

async function currentTabOrigin() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  try {
    const url = new URL(tab.url);
    if (!['http:', 'https:'].includes(url.protocol)) return null;
    return url.origin;
  } catch (_) { return null; }
}

async function render() {
  const origin = await currentTabOrigin();
  let status = { loggedIn: false };
  // force: bypass the 5-minute project cache so newly created projects
  // are recognized as soon as the popup is opened.
  try { status = await send({ type: 'getStatus', origin, force: true }); } catch (_) {}

  $('login-view').classList.toggle('hidden', status.loggedIn);
  $('main-view').classList.toggle('hidden', !status.loggedIn);

  if (!status.loggedIn) {
    const { serverUrl } = await chrome.storage.local.get({ serverUrl: '' });
    $('serverUrl').value = serverUrl || 'https://yaherd.tedcolegrove.ai';
    if (status.error) $('login-error').textContent = status.error;
    return;
  }

  $('who').textContent = status.user ? `${status.user.display_name} (${status.user.email})` : '';
  $('dashboard-link').href = status.serverUrl;

  // Build status DOM with textContent — never inject server data as HTML.
  const box = $('site-status');
  const toggleRow = $('toggle-row');
  box.textContent = '';
  const span = (cls, text) => {
    const s = document.createElement('span');
    s.className = cls;
    s.textContent = text;
    return s;
  };
  if (!origin) {
    box.appendChild(span('no', 'This page can’t be commented on.'));
    toggleRow.classList.add('hidden');
  } else if (status.project) {
    box.appendChild(span('ok', 'Active: '));
    box.appendChild(document.createTextNode(status.project.name));
    box.appendChild(document.createElement('br'));
    box.appendChild(span('no', origin));
    toggleRow.classList.remove('hidden');
    $('site-toggle').checked = !status.disabled;
  } else {
    const s = span('no', origin);
    s.appendChild(document.createElement('br'));
    s.appendChild(document.createTextNode('is not part of any of your projects.'));
    box.appendChild(s);
    toggleRow.classList.add('hidden');
  }
}

$('login-btn').addEventListener('click', async () => {
  $('login-error').textContent = '';
  $('login-btn').disabled = true;
  try {
    await send({
      type: 'login',
      serverUrl: $('serverUrl').value.trim(),
      email: $('email').value.trim(),
      password: $('password').value,
    });
    await render();
  } catch (e) {
    $('login-error').textContent = e.message;
  }
  $('login-btn').disabled = false;
});

$('logout-btn').addEventListener('click', async () => {
  await send({ type: 'logout' });
  render();
});

$('site-toggle').addEventListener('change', async (ev) => {
  const origin = await currentTabOrigin();
  if (origin) await send({ type: 'toggleSite', origin, enabled: ev.target.checked });
});

document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Enter' && !$('login-view').classList.contains('hidden')) $('login-btn').click();
});

render();
