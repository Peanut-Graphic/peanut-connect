/**
 * Peanut Connect — on-page feedback widget.
 *
 * Self-initializing. Reads window.peanutConnectFeedback (restUrl, nonce,
 * isAgency, reviewToken) and renders a pin overlay + minimal launcher into a
 * Shadow DOM mounted at #peanut-connect-feedback-root. Pin click → panel is
 * wired in a later task; for now hovering a pin shows the note via title.
 */
(function () {
  const cfg = window.peanutConnectFeedback;
  if (!cfg) return;

  const NAME_KEY = 'ppFeedbackReviewerName';
  function reviewerName() {
    let n = localStorage.getItem(NAME_KEY);
    if (!n) {
      n = (window.prompt('Your name (so your notes are labeled and colored):') || '').trim();
      if (n) localStorage.setItem(NAME_KEY, n);
    }
    return n || 'Anonymous';
  }

  function api(method, path, body) {
    const headers = { 'Content-Type': 'application/json' };
    // Send the WP nonce ONLY for logged-in agency users. A logged-out client sending an
    // (invalid) nonce can trip WP's "cookie check failed" 403; the review token authorizes them.
    if (cfg.isAgency && cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
    if (cfg.reviewToken) headers['X-Peanut-Review-Token'] = cfg.reviewToken;
    return fetch(cfg.restUrl.replace(/\/feedback$/, '') + path, {
      method, headers, credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined,
    }).then((r) => r.json());
  }

  // Build a stable-ish CSS selector for an element (id > nth-of-type path, capped depth).
  function selectorFor(el) {
    if (!el || el === document.body) return 'body';
    if (el.id) return '#' + CSS.escape(el.id);
    const parts = [];
    let node = el;
    for (let depth = 0; node && node.nodeType === 1 && depth < 5 && node !== document.body; depth++) {
      let part = node.tagName.toLowerCase();
      const sibs = Array.from(node.parentNode ? node.parentNode.children : []).filter((s) => s.tagName === node.tagName);
      if (sibs.length > 1) part += `:nth-of-type(${sibs.indexOf(node) + 1})`;
      parts.unshift(part);
      node = node.parentNode;
    }
    return parts.join(' > ');
  }

  // Given a click, compute the anchor element + normalized 0..1 offset within it.
  function anchorFromPoint(x, y) {
    const el = document.elementFromPoint(x, y) || document.body;
    const r = el.getBoundingClientRect();
    return {
      selector: selectorFor(el),
      nx: r.width ? (x - r.left) / r.width : 0,
      ny: r.height ? (y - r.top) / r.height : 0,
    };
  }

  // Resolve a stored anchor back to viewport coords (returns null if the element is gone).
  function pointFromAnchor(a) {
    let el = null;
    try { el = a.anchor_selector ? document.querySelector(a.anchor_selector) : null; } catch (e) { el = null; }
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { x: r.left + a.anchor_x * r.width, y: r.top + a.anchor_y * r.height };
  }

  const host = document.getElementById('peanut-connect-feedback-root') || document.body.appendChild(document.createElement('div'));
  const shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
  const style = document.createElement('style');
  style.textContent = window.__ppFeedbackCss || '';   // CSS injected as text by the PHP enqueue() (Task 8)
  shadow.appendChild(style);

  const overlay = document.createElement('div');
  overlay.className = 'pp-overlay';                    // fixed, full-viewport, pointer-events:none
  shadow.appendChild(overlay);

  // Minimal launcher so the widget is usable/testable on its own (a later task adds the full panel).
  const launcher = document.createElement('button');
  launcher.className = 'pp-launcher';
  launcher.textContent = '+ Add note';
  launcher.addEventListener('click', () => enterPlaceMode());
  shadow.appendChild(launcher);

  let placing = false;
  let items = [];

  function enterPlaceMode() { placing = true; document.body.style.cursor = 'crosshair'; }

  document.addEventListener('click', function (e) {
    if (!placing) return;
    // ignore clicks inside our own shadow host
    if (e.composedPath && e.composedPath().includes(host)) return;
    e.preventDefault(); e.stopPropagation();
    placing = false; document.body.style.cursor = '';

    const a = anchorFromPoint(e.clientX, e.clientY);
    const body = window.prompt('Note for this spot:');
    if (!body) return;

    api('POST', '/feedback', {
      page_url: location.pathname + location.search,
      page_title: document.title,
      anchor_selector: a.selector,
      anchor_x: Math.max(0, Math.min(1, a.nx)),
      anchor_y: Math.max(0, Math.min(1, a.ny)),
      viewport_width: window.innerWidth,
      author_name: reviewerName(),
      author_is_agency: !!cfg.isAgency,
      body: body,
    }).then((res) => { if (res && res.feedback) { items.push({ ...res.feedback, anchor_selector: a.selector, anchor_x: a.nx, anchor_y: a.ny }); renderPins(); } });
  }, true);

  function renderPins() {
    overlay.querySelectorAll('.pp-pin').forEach((n) => n.remove());
    items.forEach((it, i) => {
      const pt = pointFromAnchor(it);
      if (!pt) return;                       // element not on this page/size — skip (phase-2 hardening)
      const pin = document.createElement('button');
      pin.className = 'pp-pin' + (it.status === 'done' ? ' pp-done' : '');
      pin.style.left = pt.x + 'px';
      pin.style.top = pt.y + 'px';
      pin.style.background = it.color || '#2D6CDF';
      pin.textContent = String(i + 1);
      pin.title = (it.author_name || '') + ': ' + (it.body || '');
      // Pin click → panel is wired in a later task; for now the hover title shows the note.
      overlay.appendChild(pin);
    });
  }

  function load() {
    api('GET', '/feedback?page_url=' + encodeURIComponent(location.pathname + location.search))
      .then((res) => { items = (res && res.feedback) || []; renderPins(); });
  }
  window.addEventListener('resize', renderPins);
  load();
})();
