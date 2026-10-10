function send(msg) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage(msg, (res) => {
      if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
      res && res.ok ? resolve(res.data) : reject(new Error(res ? res.error : 'No response'));
    });
  });
}

const $ = (id) => document.getElementById(id);

async function currentTab() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  try {
    const url = new URL(tab.url);
    if (!['http:', 'https:'].includes(url.protocol)) return null;
    return { id: tab.id, href: tab.url, origin: url.origin, url };
  } catch (_) { return null; }
}

async function currentTabOrigin() {
  const tab = await currentTab();
  return tab ? tab.origin : null;
}

// Ask the page's content script for its HTML (fetched with the reviewer's
// cookies, so bot protection that let them in lets this through too).
function captureTab(tabId) {
  return new Promise((resolve, reject) => {
    chrome.tabs.sendMessage(tabId, { type: 'captureSource' }, (res) => {
      if (chrome.runtime.lastError) return reject(new Error('Reload the page, then try again.'));
      res && res.ok ? resolve(res.data) : reject(new Error(res ? res.error : 'No response from the page'));
    });
  });
}

let feedbackProjects = [];

function fillReviewSelect() {
  const project = feedbackProjects.find((p) => String(p.id) === $('fb-project').value);
  const sel = $('fb-review');
  sel.textContent = '';
  sel.appendChild(new Option('New review…', ''));
  for (const r of project ? project.reviews : []) sel.appendChild(new Option(r.title, String(r.id)));
  $('fb-new-fields').classList.toggle('hidden', sel.value !== '');
}

async function render() {
  const tab = await currentTab();
  const origin = tab ? tab.origin : null;
  let status = { loggedIn: false };
  // force: bypass the 5-minute project cache so newly created projects
  // are recognized as soon as the popup is opened.
  try {
    status = await send({ type: 'getStatus', origin, href: tab ? tab.href : null, force: true, withFeedbackProjects: true });
  } catch (_) {}

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
  feedbackProjects = status.feedbackProjects || [];
  $('fb-start').classList.toggle('hidden', !origin || !!status.feedback || !feedbackProjects.length);
  if (origin && !status.feedback && feedbackProjects.length) {
    $('fb-project').textContent = '';
    for (const p of feedbackProjects) $('fb-project').appendChild(new Option(p.name, String(p.id)));
    fillReviewSelect();
    const path = tab.url.pathname;
    $('fb-site').value = tab.url.origin + path.slice(0, path.lastIndexOf('/') + 1);
  }

  if (!origin) {
    box.appendChild(span('no', 'This page can’t be commented on.'));
    toggleRow.classList.add('hidden');
  } else if (status.feedback) {
    box.appendChild(span('fb-tag', 'Feedback: '));
    box.appendChild(document.createTextNode(status.feedback.review.title));
    box.appendChild(document.createElement('br'));
    box.appendChild(span('no', status.feedback.project.name + ' · ' + (status.feedback.page
      ? 'this page is in the review'
      : 'this page joins the review when you comment on it')));
    toggleRow.classList.remove('hidden');
    $('site-toggle').checked = !status.disabled;
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

$('fb-project').addEventListener('change', fillReviewSelect);
$('fb-review').addEventListener('change', () => {
  $('fb-new-fields').classList.toggle('hidden', $('fb-review').value !== '');
});

$('fb-start-btn').addEventListener('click', async () => {
  const tab = await currentTab();
  if (!tab) return;
  $('fb-error').textContent = '';
  const reviewId = $('fb-review').value;
  const title = $('fb-title').value.trim();
  if (!reviewId && !title) { $('fb-error').textContent = 'Give the review a name.'; return; }
  const btn = $('fb-start-btn');
  btn.disabled = true;
  btn.textContent = 'Saving this page…';
  try {
    const cap = await captureTab(tab.id);
    const url = tab.href.split('#')[0];
    if (reviewId) {
      await send({ type: 'api', method: 'POST', path: `/api/feedback/reviews/${reviewId}/pages`, body: { url, html: cap.html } });
    } else {
      await send({
        type: 'api', method: 'POST', path: `/api/feedback/projects/${$('fb-project').value}/reviews`,
        body: { title, url, site_url: $('fb-site').value.trim(), html: cap.html },
      });
    }
    await send({ type: 'invalidateFeedback' });
    chrome.tabs.sendMessage(tab.id, { type: 'reboot' }, () => void chrome.runtime.lastError);
    await render();
  } catch (e) {
    $('fb-error').textContent = e.message;
  }
  btn.disabled = false;
  btn.textContent = 'Start reviewing';
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
