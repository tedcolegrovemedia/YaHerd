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

  const box = $('site-status');
  const toggleRow = $('toggle-row');
  if (!origin) {
    box.innerHTML = '<span class="no">This page can’t be commented on.</span>';
    toggleRow.classList.add('hidden');
  } else if (status.project) {
    box.innerHTML = `<span class="ok">Active:</span> ${status.project.name}<br><span class="no">${origin}</span>`;
    toggleRow.classList.remove('hidden');
    $('site-toggle').checked = !status.disabled;
  } else {
    box.innerHTML = `<span class="no">${origin}<br>is not part of any of your projects.</span>`;
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
