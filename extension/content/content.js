// Bootstrap: ask the worker whether this page belongs to one of my projects
// (a collaborative project by origin, or a feedback review by URL); if so,
// mount the matching overlay. Also watches for SPA URL changes and reloads
// for the new page, and answers the popup (capture this page's source,
// re-check after starting a review).
(function () {
  if (window.top !== window) return; // skip iframes
  let overlay = null;
  let currentHref = null;

  function send(msg) {
    return new Promise((resolve, reject) => {
      chrome.runtime.sendMessage(msg, (res) => {
        if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
        if (!res) return reject(new Error('No response from extension'));
        res.ok ? resolve(res.data) : reject(new Error(res.error));
      });
    });
  }

  function pagePath() {
    return location.pathname + location.search;
  }

  async function boot(force) {
    currentHref = location.href;
    if (overlay) { overlay.destroy(); overlay = null; }

    let status;
    try {
      status = await send({ type: 'getStatus', origin: location.origin, href: location.href, force: force === true });
    } catch (_) {
      return; // extension not ready / not configured
    }
    if (!status.loggedIn || status.disabled || (!status.project && !status.feedback)) return;

    const css = await fetch(chrome.runtime.getURL('content/overlay.css')).then((r) => r.text());
    const api = (method, path, body) => send({ type: 'api', method, path, body });

    if (status.feedback) {
      overlay = window.WCFeedback.create({
        css,
        match: status.feedback,
        api,
        invalidate: () => send({ type: 'invalidateFeedback' }).catch(() => {}),
        dashboardUrl: status.serverUrl,
      });
      return;
    }

    let comments = [];
    try {
      const data = await send({
        type: 'api',
        method: 'GET',
        path: `/api/comments?project_id=${status.project.id}&page_path=${encodeURIComponent(pagePath())}`,
      });
      comments = data.comments;
    } catch (e) {
      console.warn('WebComment: could not load comments', e);
      return;
    }

    overlay = window.WCOverlay.create({
      css,
      project: status.project,
      comments,
      api,
      createComment: (fields, crop) => send({ type: 'createComment', fields, crop }),
      setCover: () => send({ type: 'setCover', projectId: status.project.id }),
    });
  }

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (msg.type === 'captureSource') {
      window.WCSource.capturePageSource()
        .then((cap) => sendResponse({ ok: true, data: cap }))
        .catch((e) => sendResponse({ ok: false, error: e.message }));
      return true;
    }
    if (msg.type === 'reboot') {
      boot(true).then(() => sendResponse({ ok: true }));
      return true;
    }
    return false;
  });

  // SPA route changes: poll the URL (cheap and framework-agnostic).
  setInterval(() => {
    if (location.href !== currentHref) boot();
  }, 800);

  boot();
})();
