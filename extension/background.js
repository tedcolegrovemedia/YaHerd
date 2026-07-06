// WebComment service worker: holds the auth token, proxies API calls for the
// content script/popup, matches origins to projects, and captures screenshots.
// Kept stateless — everything is read from chrome.storage per request (MV3
// workers can be killed at any time).

const PROJECT_CACHE_TTL_MS = 5 * 60 * 1000;
const CROP_W = 600;
const CROP_H = 400;

async function getState() {
  return chrome.storage.local.get({
    serverUrl: '',
    token: '',
    user: null,
    projectsCache: null, // { at: epoch-ms, projects: [] }
    disabledOrigins: [],
  });
}

async function apiFetch(state, method, path, { json, formData } = {}) {
  if (!state.serverUrl) throw new Error('Not configured: set the server URL in the popup.');
  const headers = {};
  if (state.token) headers['Authorization'] = 'Bearer ' + state.token;
  if (json !== undefined) headers['Content-Type'] = 'application/json';
  const res = await fetch(state.serverUrl.replace(/\/$/, '') + path, {
    method,
    headers,
    body: formData !== undefined ? formData : json !== undefined ? JSON.stringify(json) : undefined,
  });
  let data = {};
  try { data = await res.json(); } catch (_) {}
  if (res.status === 401) {
    await chrome.storage.local.set({ token: '', user: null, projectsCache: null });
    throw new Error('Session expired — log in again from the extension popup.');
  }
  if (!res.ok) throw new Error(data.error || `API error (${res.status})`);
  return data;
}

async function getProjects(state, force = false) {
  const cache = state.projectsCache;
  if (!force && cache && Date.now() - cache.at < PROJECT_CACHE_TTL_MS) return cache.projects;
  const data = await apiFetch(state, 'GET', '/api/projects');
  await chrome.storage.local.set({ projectsCache: { at: Date.now(), projects: data.projects } });
  return data.projects;
}

function matchProject(origin, projects) {
  let url;
  try { url = new URL(origin); } catch (_) { return null; }
  for (const p of projects) {
    if (!p.is_active) continue;
    if (origin === p.base_origin) return p;
    if (p.match_subdomains) {
      try {
        const base = new URL(p.base_origin);
        if (url.protocol === base.protocol && url.hostname.endsWith('.' + base.hostname)) return p;
      } catch (_) {}
    }
  }
  return null;
}

// Capture the visible tab, crop CROP_W x CROP_H (CSS px) centered on the pin,
// draw a marker at the pin position, return a JPEG blob.
async function captureCrop(windowId, crop) {
  const dataUrl = await chrome.tabs.captureVisibleTab(windowId, { format: 'jpeg', quality: 85 });
  const bitmap = await createImageBitmap(await (await fetch(dataUrl)).blob());

  // The capture is in device pixels; crop.dpr converts CSS px -> device px.
  const dpr = crop.dpr || 1;
  const srcW = Math.min(CROP_W * dpr, bitmap.width);
  const srcH = Math.min(CROP_H * dpr, bitmap.height);
  let srcX = crop.vx * dpr - srcW / 2;
  let srcY = crop.vy * dpr - srcH / 2;
  srcX = Math.max(0, Math.min(srcX, bitmap.width - srcW));
  srcY = Math.max(0, Math.min(srcY, bitmap.height - srcH));

  const canvas = new OffscreenCanvas(Math.round(srcW / dpr), Math.round(srcH / dpr));
  const ctx = canvas.getContext('2d');
  ctx.drawImage(bitmap, srcX, srcY, srcW, srcH, 0, 0, canvas.width, canvas.height);

  // Pin marker at the comment location.
  const px = crop.vx - srcX / dpr;
  const py = crop.vy - srcY / dpr;
  ctx.beginPath();
  ctx.arc(px, py, 10, 0, Math.PI * 2);
  ctx.fillStyle = 'rgba(239,68,68,0.35)';
  ctx.fill();
  ctx.beginPath();
  ctx.arc(px, py, 5, 0, Math.PI * 2);
  ctx.fillStyle = '#ef4444';
  ctx.fill();
  ctx.lineWidth = 2;
  ctx.strokeStyle = '#ffffff';
  ctx.stroke();

  return canvas.convertToBlob({ type: 'image/jpeg', quality: 0.85 });
}

const handlers = {
  async login(msg) {
    const serverUrl = msg.serverUrl.replace(/\/$/, '');
    const state = { serverUrl, token: '' };
    const data = await apiFetch(state, 'POST', '/api/login', {
      json: { email: msg.email, password: msg.password, label: 'extension' },
    });
    await chrome.storage.local.set({
      serverUrl,
      token: data.token,
      user: data.user,
      projectsCache: null,
    });
    return { user: data.user };
  },

  async logout() {
    const state = await getState();
    if (state.token) {
      try { await apiFetch(state, 'POST', '/api/logout'); } catch (_) {}
    }
    await chrome.storage.local.set({ token: '', user: null, projectsCache: null });
    return { ok: true };
  },

  // Called by content script on page load and by the popup.
  async getStatus(msg) {
    const state = await getState();
    if (!state.token || !state.serverUrl) return { loggedIn: false };
    let projects;
    try {
      projects = await getProjects(state, msg.force === true);
    } catch (e) {
      return { loggedIn: false, error: e.message };
    }
    const project = msg.origin ? matchProject(msg.origin, projects) : null;
    return {
      loggedIn: true,
      user: state.user,
      serverUrl: state.serverUrl,
      project,
      disabled: msg.origin ? state.disabledOrigins.includes(msg.origin) : false,
    };
  },

  async toggleSite(msg) {
    const state = await getState();
    const set = new Set(state.disabledOrigins);
    msg.enabled ? set.delete(msg.origin) : set.add(msg.origin);
    await chrome.storage.local.set({ disabledOrigins: [...set] });
    return { ok: true };
  },

  // Generic authenticated JSON API call for content script / popup.
  async api(msg) {
    const state = await getState();
    return apiFetch(state, msg.method, msg.path, { json: msg.body });
  },

  // Capture the visible tab as the project's board cover (user-initiated).
  async setCover(msg, sender) {
    const state = await getState();
    const dataUrl = await chrome.tabs.captureVisibleTab(sender.tab.windowId, { format: 'jpeg', quality: 80 });
    const bitmap = await createImageBitmap(await (await fetch(dataUrl)).blob());
    const scale = Math.min(1, 1200 / bitmap.width);
    const canvas = new OffscreenCanvas(Math.round(bitmap.width * scale), Math.round(bitmap.height * scale));
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await canvas.convertToBlob({ type: 'image/jpeg', quality: 0.8 });
    const fd = new FormData();
    fd.append('screenshot', blob, 'cover.jpg');
    return apiFetch(state, 'POST', `/api/projects/${msg.projectId}/cover`, { formData: fd });
  },

  // Create a comment: capture + crop screenshot, then multipart POST.
  async createComment(msg, sender) {
    const state = await getState();
    let blob = null;
    try {
      blob = await captureCrop(sender.tab.windowId, msg.crop);
    } catch (e) {
      // Screenshot is best-effort; the comment still gets created.
      console.warn('WebComment: screenshot capture failed', e);
    }
    const fd = new FormData();
    for (const [k, v] of Object.entries(msg.fields)) fd.append(k, v == null ? '' : String(v));
    if (blob) fd.append('screenshot', blob, 'pin.jpg');
    return apiFetch(state, 'POST', '/api/comments', { formData: fd });
  },
};

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  const handler = handlers[msg.type];
  if (!handler) return false;
  handler(msg, sender)
    .then((data) => sendResponse({ ok: true, data }))
    .catch((e) => sendResponse({ ok: false, error: e.message }));
  return true; // async sendResponse
});
