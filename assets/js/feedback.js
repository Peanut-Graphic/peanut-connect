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

  // Normalize the page key used to bucket feedback: strip volatile/secret query params
  // (the review token, UTM/ad-click params) so create and list always agree on the same
  // key — otherwise a token client's URL would fragment pins away from a clean-URL agency
  // user's view, and the token itself would leak into stored HUB data.
  function pageKey() {
    try {
      const u = new URL(location.href);
      ['pp_review', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid']
        .forEach((k) => u.searchParams.delete(k));
      const qs = u.searchParams.toString();
      return u.pathname + (qs ? '?' + qs : '');
    } catch (e) { return location.pathname; }
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
  launcher.textContent = '+ Mark it up';
  launcher.addEventListener('click', () => enterPlaceMode());
  shadow.appendChild(launcher);

  let placing = false;
  let items = [];

  function enterPlaceMode() { placing = true; document.body.style.cursor = 'crosshair'; }

  // --- Movable notes panel (Task 10): draggable + collapsible + persisted, to-do list + filter ---
  let panelState;
  try {
    const raw = JSON.parse(localStorage.getItem('ppFeedbackPanel') || '{}');
    panelState = (raw && typeof raw === 'object' && typeof raw.x === 'number' && typeof raw.y === 'number')
      ? { x: raw.x, y: raw.y, open: raw.open !== false }
      : { x: 20, y: 20, open: true };
  } catch (e) {
    panelState = { x: 20, y: 20, open: true };
  }
  const panel = document.createElement('div');
  panel.className = 'pp-panel';
  panel.style.left = panelState.x + 'px';
  panel.style.top = panelState.y + 'px';
  panel.innerHTML =
    '<div class="pp-panel-head"><span class="pp-grip">Mark It Up</span>' +
    '<button class="pp-help-btn" type="button" title="How to use" aria-label="How to use">?</button>' +
    '<button class="pp-add">+ Add</button><button class="pp-toggle"></button></div>' +
    '<div class="pp-help" hidden><strong>How to use</strong><ol>' +
    '<li>Click <strong>+ Mark it up</strong> (or <strong>+ Add</strong>).</li>' +
    '<li>Click the spot on the page you want to comment on.</li>' +
    '<li>Type your note — a colored pin drops there and saves to Hub.</li>' +
    '<li>Tick a note off in this list once it\'s handled.</li>' +
    '</ol></div>' +
    '<div class="pp-filter"><select class="pp-by"><option value="">Everyone</option></select></div>' +
    '<ul class="pp-list"></ul>';
  panel.setAttribute('role', 'region');
  panel.setAttribute('aria-label', 'Mark It Up feedback');
  shadow.appendChild(panel);
  panel.querySelector('.pp-help-btn').addEventListener('click', () => {
    const h = panel.querySelector('.pp-help');
    if (h) h.hidden = !h.hidden;
  });

  function savePanel() { localStorage.setItem('ppFeedbackPanel', JSON.stringify(panelState)); }
  function applyCollapsed() {
    panel.classList.toggle('pp-collapsed', !panelState.open);
    const toggle = panel.querySelector('.pp-toggle');
    toggle.textContent = panelState.open ? '–' : '+';
    toggle.setAttribute('aria-label', panelState.open ? 'Collapse notes' : 'Expand notes');
  }
  applyCollapsed();
  panel.querySelector('.pp-toggle').addEventListener('click', () => { panelState.open = !panelState.open; applyCollapsed(); savePanel(); });
  panel.querySelector('.pp-add').addEventListener('click', enterPlaceMode);

  // drag by the head
  (function drag() {
    const head = panel.querySelector('.pp-panel-head'); let sx, sy, ox, oy, on = false;
    head.addEventListener('mousedown', (e) => {
      if (e.target.closest && e.target.closest('button')) return; // don't start a drag from the head's buttons
      on = true; sx = e.clientX; sy = e.clientY; ox = panelState.x; oy = panelState.y; e.preventDefault();
    });
    window.addEventListener('mousemove', (e) => { if (!on) return; panelState.x = Math.max(0, ox + e.clientX - sx); panelState.y = Math.max(0, oy + e.clientY - sy); panel.style.left = panelState.x + 'px'; panel.style.top = panelState.y + 'px'; });
    window.addEventListener('mouseup', () => { if (on) { on = false; savePanel(); } });
  })();

  let filterBy = '';
  function renderList() {
    const sel = panel.querySelector('.pp-by');
    const names = Array.from(new Set(items.map((i) => i.author_name).filter(Boolean)));
    sel.innerHTML = '';
    const everyoneOpt = document.createElement('option');
    everyoneOpt.value = '';
    everyoneOpt.textContent = 'Everyone';
    everyoneOpt.selected = !filterBy;
    sel.appendChild(everyoneOpt);
    names.forEach((n) => {
      const opt = document.createElement('option');
      opt.value = n;
      opt.textContent = n;
      opt.selected = (n === filterBy);
      sel.appendChild(opt);
    });
    sel.onchange = () => { filterBy = sel.value; renderList(); };

    const ul = panel.querySelector('.pp-list');
    ul.innerHTML = '';
    items.filter((i) => !filterBy || i.author_name === filterBy).forEach((it, idx) => {
      const li = document.createElement('li');
      li.className = 'pp-row';
      const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = it.status === 'done';
      cb.addEventListener('change', () => {
        const status = cb.checked ? 'done' : 'open';
        api('PATCH', '/feedback/' + it.id, { status }).then((res) => {
          if (res && res.success) {
            it.status = status; renderPins(); renderList();
          } else {
            cb.checked = (it.status === 'done');
          }
        }).catch(() => { cb.checked = (it.status === 'done'); });
      });
      const dot = document.createElement('span'); dot.className = 'pp-dot'; dot.style.background = it.color || '#2D6CDF';
      const txt = document.createElement('span'); txt.className = 'pp-txt'; txt.textContent = (it.author_name ? it.author_name + ': ' : '') + it.body;
      if (it.status === 'done') txt.classList.add('pp-strike');
      li.append(cb, dot, txt);
      ul.appendChild(li);
    });
  }

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
      page_url: pageKey(),
      page_title: document.title,
      anchor_selector: a.selector,
      anchor_x: Math.max(0, Math.min(1, a.nx)),
      anchor_y: Math.max(0, Math.min(1, a.ny)),
      viewport_width: window.innerWidth,
      author_name: reviewerName(),
      author_is_agency: !!cfg.isAgency,
      body: body,
    }).then((res) => { if (res && res.feedback) { items.push({ anchor_selector: a.selector, anchor_x: Math.max(0, Math.min(1, a.nx)), anchor_y: Math.max(0, Math.min(1, a.ny)), ...res.feedback }); renderPins(); renderList(); } });
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
      pin.setAttribute('aria-label', (it.author_name ? it.author_name + ': ' : '') + (it.body || 'note'));
      // Pin click → panel is wired in a later task; for now the hover title shows the note.
      // NOTE (v1 limitation): pin placement is mouse-only (click-to-place via elementFromPoint);
      // there is no keyboard equivalent yet. Documented, not fixed, in this pass.
      overlay.appendChild(pin);
    });
  }

  function load() {
    api('GET', '/feedback?page_url=' + encodeURIComponent(pageKey()))
      .then((res) => { items = (res && res.feedback) || []; renderPins(); renderList(); });
  }
  window.addEventListener('resize', renderPins);
  load();
})();
