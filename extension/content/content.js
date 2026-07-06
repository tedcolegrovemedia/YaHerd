// Bootstrap: ask the worker whether this page belongs to one of my projects;
// if so, load comments for this page and mount the overlay. Also watches for
// SPA URL changes and reloads pins for the new page.
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

  async function boot() {
    currentHref = location.href;
    if (overlay) { overlay.destroy(); overlay = null; }

    let status;
    try {
      status = await send({ type: 'getStatus', origin: location.origin });
    } catch (_) {
      return; // extension not ready / not configured
    }
    if (!status.loggedIn || !status.project || status.disabled) return;

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

    const css = await fetch(chrome.runtime.getURL('content/overlay.css')).then((r) => r.text());

    overlay = window.WCOverlay.create({
      css,
      project: status.project,
      comments,
      api: (method, path, body) => send({ type: 'api', method, path, body }),
      createComment: (fields, crop) => send({ type: 'createComment', fields, crop }),
      setCover: () => send({ type: 'setCover', projectId: status.project.id }),
    });
  }

  // SPA route changes: poll the URL (cheap and framework-agnostic).
  setInterval(() => {
    if (location.href !== currentHref) boot();
  }, 800);

  boot();
})();
