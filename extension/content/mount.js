// Shared mounting for both overlays: puts the shadow host in the browser's
// top layer (above any z-index, sticky header or open dialog on the page) and
// keeps the page's own input handlers from seeing events meant for us.
// Exposed as window.WCMount.
(function () {
  // Events that page scripts commonly hijack at window/document level:
  // drag-to-scroll (pointerdown + setPointerCapture), wheel hijacking,
  // keyboard shortcuts that preventDefault space/arrows, click-outside.
  // From outside a closed shadow root these all look like they target the
  // host element, so page checks like e.target.closest('button') fail.
  const SHIELDED = [
    'pointerdown', 'pointerup', 'pointermove', 'pointercancel',
    'mousedown', 'mouseup', 'mousemove', 'click', 'dblclick', 'auxclick', 'contextmenu',
    'touchstart', 'touchmove', 'touchend', 'touchcancel',
    'wheel', 'keydown', 'keyup', 'keypress', 'input', 'beforeinput',
    'paste', 'copy', 'cut', 'focusin', 'focusout', 'selectstart', 'dragstart',
  ];

  const HOST_STYLE = {
    position: 'fixed',
    inset: '0',
    top: '0',
    left: '0',
    width: '100vw',
    height: '100vh',
    'max-width': 'none',
    'max-height': 'none',
    margin: '0',
    padding: '0',
    border: '0',
    background: 'transparent',
    overflow: 'visible',
    'pointer-events': 'none',
    'z-index': '2147483647',
    display: 'block',
    transform: 'none',
    filter: 'none',
    opacity: '1',
    contain: 'none',
  };

  function raise(host) {
    if (!host.isConnected || typeof host.showPopover !== 'function') return;
    try {
      // Re-showing moves us back to the top of the top layer, so a dialog
      // or popover the page opened since doesn't end up above us.
      if (host.matches(':popover-open')) host.hidePopover();
      host.showPopover();
    } catch (_) { /* older browser: z-index fallback still applies */ }
  }

  function mount(host) {
    // Inline !important beats anything the page's stylesheets do to us.
    for (const [k, v] of Object.entries(HOST_STYLE)) host.style.setProperty(k, v, 'important');
    host.setAttribute('popover', 'manual');

    // Bubble-phase listeners on the host run after our own handlers inside
    // the shadow root, and stop the event before it reaches the page's
    // document/window listeners. We never preventDefault, so native
    // behavior (typing, scrolling our lists, focus) is untouched.
    const stop = (ev) => ev.stopPropagation();
    for (const type of SHIELDED) host.addEventListener(type, stop);

    document.documentElement.appendChild(host);
    raise(host);

    // If the page puts something new in the top layer (a modal, a
    // lightbox), hop back above it.
    let pending = false;
    const onTopLayerChange = (ev) => {
      if (ev && ev.target === host) return; // our own show/hide
      if (pending) return;
      pending = true;
      setTimeout(() => { pending = false; raise(host); }, 0);
    };
    document.addEventListener('toggle', onTopLayerChange, true);
    const observer = new MutationObserver((muts) => {
      for (const m of muts) {
        if (m.target !== host && m.attributeName === 'open' && m.target.tagName === 'DIALOG') {
          onTopLayerChange();
          return;
        }
      }
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['open'], subtree: true });

    return {
      raise: () => raise(host),
      unmount() {
        document.removeEventListener('toggle', onTopLayerChange, true);
        observer.disconnect();
        host.remove();
      },
    };
  }

  window.WCMount = { mount };
})();
