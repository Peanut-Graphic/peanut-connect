/**
 * Peanut Connect — "Mark It Up" on-page feedback widget.
 *
 * Reviewers HIGHLIGHT text (select → "Note on this") or POINT at a spot
 * (+ Mark it up → click), leave a note, and everything relays same-origin to
 * Hub. A note renders as a yellow highlight (for text) + a red "?" marker; a
 * click on the marker opens a dark note tooltip (the "review question" look).
 * Notes are also listed in a movable panel as checkable to-dos.
 *
 * Storage: a highlight note serializes its text-range descriptor as JSON in
 * anchor_selector ({k:'hl',c,q,p,s}); a point note stores a plain CSS selector
 * there. Hub just stores/returns the string, so highlights need no schema change.
 */
(function () {
  const cfg = window.peanutConnectFeedback;
  if (!cfg) return;

  const NAME_KEY = 'ppFeedbackReviewerName';
  function reviewerName() {
    let n = localStorage.getItem(NAME_KEY);
    if (!n) {
      n = (window.prompt('Your name (so your notes are labeled):') || '').trim();
      if (n) localStorage.setItem(NAME_KEY, n);
    }
    return n || 'Anonymous';
  }

  const AUTHOR_KEY = 'ppFeedbackAuthorKey';
  const MINE_KEY = 'ppFeedbackMyNotes';
  function authorKey() {
    let k = localStorage.getItem(AUTHOR_KEY);
    if (!k) {
      k = (crypto.randomUUID && crypto.randomUUID()) ||
        (Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
      localStorage.setItem(AUTHOR_KEY, k);
    }
    return k;
  }
  function myNoteIds() {
    try { return JSON.parse(localStorage.getItem(MINE_KEY) || '[]'); } catch (e) { return []; }
  }
  function rememberMine(id) {
    const ids = myNoteIds();
    if (ids.indexOf(id) === -1) { ids.push(id); localStorage.setItem(MINE_KEY, JSON.stringify(ids.slice(-500))); }
  }
  function isMine(id) { return myNoteIds().indexOf(id) !== -1; }

  function api(method, path, body) {
    const headers = { 'Content-Type': 'application/json' };
    if (cfg.isAgency && cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
    if (cfg.reviewToken) headers['X-Peanut-Review-Token'] = cfg.reviewToken;
    return fetch(cfg.restUrl.replace(/\/feedback$/, '') + path, {
      method, headers, credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined,
    }).then((r) => r.json());
  }

  function pageKey() {
    try {
      const u = new URL(location.href);
      ['pp_review', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid']
        .forEach((k) => u.searchParams.delete(k));
      const qs = u.searchParams.toString();
      return u.pathname + (qs ? '?' + qs : '');
    } catch (e) { return location.pathname; }
  }

  function selectorFor(el) {
    if (!el || el === document.body) return 'body';
    if (el.id) return '#' + CSS.escape(el.id);
    const parts = [];
    let node = el;
    for (let depth = 0; node && node.nodeType === 1 && depth < 6 && node !== document.body; depth++) {
      let part = node.tagName.toLowerCase();
      const sibs = Array.from(node.parentNode ? node.parentNode.children : []).filter((s) => s.tagName === node.tagName);
      if (sibs.length > 1) part += ':nth-of-type(' + (sibs.indexOf(node) + 1) + ')';
      parts.unshift(part);
      node = node.parentNode;
    }
    return parts.join(' > ');
  }

  function anchorFromPoint(x, y) {
    const el = document.elementFromPoint(x, y) || document.body;
    const r = el.getBoundingClientRect();
    return { selector: selectorFor(el), nx: r.width ? (x - r.left) / r.width : 0, ny: r.height ? (y - r.top) / r.height : 0 };
  }
  function pointFromAnchor(a) {
    let el = null;
    try { el = a.anchor_selector ? document.querySelector(a.anchor_selector) : null; } catch (e) { el = null; }
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { x: r.left + a.anchor_x * r.width, y: r.top + a.anchor_y * r.height };
  }

  // ---- text-range highlight: describe a selection, and re-find it later ----
  function describeRange(range) {
    const anc = range.commonAncestorContainer;
    const container = anc.nodeType === 1 ? anc : anc.parentElement;
    const quote = range.toString();
    const cText = container ? (container.textContent || '') : '';
    const start = quote ? cText.indexOf(quote) : -1;
    const prefix = start > 0 ? cText.slice(Math.max(0, start - 24), start) : '';
    const suffix = start >= 0 ? cText.slice(start + quote.length, start + quote.length + 24) : '';
    return { k: 'hl', c: selectorFor(container), q: quote, p: prefix, s: suffix };
  }
  // Map char offsets within a container element to a DOM Range across its text nodes.
  function rangeFromOffsets(container, qStart, qEnd) {
    const range = document.createRange();
    let pos = 0, started = false;
    const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, null);
    let node;
    while ((node = walker.nextNode())) {
      const len = node.nodeValue.length;
      if (!started && pos + len > qStart) { range.setStart(node, qStart - pos); started = true; }
      if (started && pos + len >= qEnd) { range.setEnd(node, qEnd - pos); return range; }
      pos += len;
    }
    return null;
  }
  function rangeFromDescriptor(d) {
    let container = null;
    try { container = d.c ? document.querySelector(d.c) : null; } catch (e) { container = null; }
    if (!container || !d.q) return null;
    const full = container.textContent || '';
    let qStart = -1;
    if (d.p || d.s) {
      const withCtx = full.indexOf((d.p || '') + d.q + (d.s || ''));
      if (withCtx >= 0) qStart = withCtx + (d.p || '').length;
    }
    if (qStart < 0) qStart = full.indexOf(d.q);
    if (qStart < 0) return null;
    return rangeFromOffsets(container, qStart, qStart + d.q.length);
  }
  function noteAnchor(it) {
    const s = it.anchor_selector || '';
    if (s.charAt(0) === '{') {
      try {
        const d = JSON.parse(s);
        if (d && d.k === 'hl') return { kind: 'hl', d };
        if (d && d.k === 'draw') return { kind: 'draw', d };
      } catch (e) {}
    }
    return { kind: 'point', selector: s };
  }

  // Freehand drawing: capture points in DOCUMENT coordinates (so they survive
  // scrolling) plus the document width they were drawn at (so they scale if the
  // page later reflows to a different width).
  function docWidth() { return document.documentElement.scrollWidth || window.innerWidth; }
  function drawPointsToViewport(d) {
    const scale = (d.w && docWidth()) ? docWidth() / d.w : 1;
    return (d.pts || []).map((p) => [p[0] * scale - window.scrollX, p[1] * scale - window.scrollY]);
  }

  // ---- shadow + overlay ----
  const host = document.getElementById('peanut-connect-feedback-root') || document.body.appendChild(document.createElement('div'));
  const shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
  const style = document.createElement('style');
  style.textContent = window.__ppFeedbackCss || '';
  shadow.appendChild(style);

  const overlay = document.createElement('div');
  overlay.className = 'pp-overlay';
  shadow.appendChild(overlay);

  // SVG layer for saved freehand drawings (+ the live stroke while drawing).
  const SVGNS = 'http://www.w3.org/2000/svg';
  const drawSvg = document.createElementNS(SVGNS, 'svg');
  drawSvg.setAttribute('class', 'pp-draw-svg');
  overlay.appendChild(drawSvg);

  // ---- launcher with open-count badge ----
  const launcher = document.createElement('button');
  launcher.className = 'pp-launcher';
  launcher.innerHTML = '<span class="pp-launcher-txt">+ Mark it up</span><span class="pp-count" hidden>0</span>';
  launcher.addEventListener('click', () => enterPlaceMode());
  shadow.appendChild(launcher);
  function updateBadge() {
    const open = items.filter((i) => i.status !== 'done').length;
    const c = launcher.querySelector('.pp-count');
    c.textContent = String(open);
    c.hidden = open === 0;
  }

  let placing = false;
  let items = [];
  function enterPlaceMode() { if (drawing) setDrawMode(false); placing = true; document.body.style.cursor = 'crosshair'; hideChip(); }

  // ---- dark note tooltip (the "review question" look) ----
  const tip = document.createElement('div');
  tip.className = 'pp-tip';
  tip.hidden = true;
  shadow.appendChild(tip);
  function showTip(mark, it) {
    tip.innerHTML = '';
    const label = document.createElement('div');
    label.className = 'pp-tip-label';
    label.textContent = (it.author_is_agency ? 'Review' : (it.author_name || 'Note'));
    const body = document.createElement('div');
    body.className = 'pp-tip-body';
    body.textContent = it.body || '';
    tip.append(label, body);
    tip.style.left = (parseFloat(mark.style.left) || 0) + 'px';
    tip.style.top = ((parseFloat(mark.style.top) || 0) + 22) + 'px';
    tip.hidden = false;
  }
  function hideTip() { tip.hidden = true; }

  // ---- selection → "Note on this" chip (highlight flow) ----
  let pendingRange = null;
  const chip = document.createElement('button');
  chip.className = 'pp-selchip';
  chip.textContent = '+ Note on this';
  chip.hidden = true;
  chip.addEventListener('mousedown', (e) => e.preventDefault()); // keep the selection alive
  chip.addEventListener('click', () => { const r = pendingRange; hideChip(); if (r) createHighlight(r); });
  shadow.appendChild(chip);
  function hideChip() { chip.hidden = true; pendingRange = null; }
  document.addEventListener('selectionchange', () => {
    if (placing) return;
    const sel = document.getSelection();
    if (!sel || sel.isCollapsed || !sel.toString().trim() || sel.rangeCount === 0) { hideChip(); return; }
    const range = sel.getRangeAt(0);
    if (host.contains(range.commonAncestorContainer)) { hideChip(); return; } // ignore our own UI
    pendingRange = range.cloneRange();
    const r = range.getBoundingClientRect();
    chip.style.left = Math.max(4, r.left) + 'px';
    chip.style.top = Math.max(4, r.top - 34) + 'px';
    chip.hidden = false;
  });

  function createHighlight(range) {
    const d = describeRange(range);
    if (!d.q) return;
    const body = window.prompt('Your note about the highlighted text:');
    if (!body) return;
    api('POST', '/feedback', {
      page_url: pageKey(), page_title: document.title,
      anchor_selector: JSON.stringify(d), anchor_x: 0, anchor_y: 0,
      viewport_width: window.innerWidth, author_name: reviewerName(),
      author_is_agency: !!cfg.isAgency, author_key: authorKey(), body: body,
    }).then((res) => { if (res && res.feedback) { rememberMine(res.feedback.id); items.push({ ...res.feedback, anchor_selector: JSON.stringify(d) }); render(); } });
    try { document.getSelection().removeAllRanges(); } catch (e) {}
  }

  // ---- point placement (click a spot; good for images/buttons) ----
  document.addEventListener('click', function (e) {
    if (!placing) return;
    if (e.composedPath && e.composedPath().includes(host)) return;
    e.preventDefault(); e.stopPropagation();
    placing = false; document.body.style.cursor = '';
    const a = anchorFromPoint(e.clientX, e.clientY);
    const body = window.prompt('Note for this spot:');
    if (!body) return;
    const nx = Math.max(0, Math.min(1, a.nx)), ny = Math.max(0, Math.min(1, a.ny));
    api('POST', '/feedback', {
      page_url: pageKey(), page_title: document.title, anchor_selector: a.selector,
      anchor_x: nx, anchor_y: ny, viewport_width: window.innerWidth,
      author_name: reviewerName(), author_is_agency: !!cfg.isAgency, author_key: authorKey(), body: body,
    }).then((res) => { if (res && res.feedback) { rememberMine(res.feedback.id); items.push({ anchor_selector: a.selector, anchor_x: nx, anchor_y: ny, ...res.feedback }); render(); } });
  }, true);

  // ---- freehand drawing (circle/scribble, then add a note) ----
  let drawing = false;      // draw MODE is on
  let stroke = null;        // { pts: [[docX,docY],...] } while a stroke is in progress
  let livePath = null;      // the <path> shown while dragging
  const DRAW_COLOR = '#DC2626';

  function setDrawMode(on) {
    drawing = on;
    overlay.classList.toggle('pp-drawing', on);
    const btn = panel.querySelector('.pp-draw-btn');
    if (btn) btn.classList.toggle('pp-on', on);
    if (on) { placing = false; document.body.style.cursor = ''; hideChip(); hideTip(); }
    else { endStroke(true); }
  }
  function strokeLen(pts) {
    let d = 0;
    for (let i = 1; i < pts.length; i++) { d += Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]); }
    return d;
  }
  function ptsToViewportPath(pts) {
    return pts.map((p, i) => (i ? 'L' : 'M') + (p[0] - window.scrollX).toFixed(1) + ' ' + (p[1] - window.scrollY).toFixed(1)).join(' ');
  }
  function endStroke(cancel) {
    if (livePath) { livePath.remove(); livePath = null; }
    stroke = null;
    if (cancel) return;
  }
  overlay.addEventListener('pointerdown', (e) => {
    if (!drawing) return;
    e.preventDefault();
    try { overlay.setPointerCapture(e.pointerId); } catch (err) {}
    stroke = { pts: [[e.clientX + window.scrollX, e.clientY + window.scrollY]] };
    livePath = document.createElementNS(SVGNS, 'path');
    livePath.setAttribute('stroke', DRAW_COLOR);
    drawSvg.appendChild(livePath);
  });
  overlay.addEventListener('pointermove', (e) => {
    if (!drawing || !stroke) return;
    stroke.pts.push([e.clientX + window.scrollX, e.clientY + window.scrollY]);
    livePath.setAttribute('d', ptsToViewportPath(stroke.pts));
  });
  overlay.addEventListener('pointerup', (e) => {
    if (!drawing || !stroke) return;
    try { overlay.releasePointerCapture(e.pointerId); } catch (err) {}
    const pts = stroke.pts;
    endStroke(true);
    if (pts.length < 2 || strokeLen(pts) < 20) return; // ignore stray taps
    createDrawing(pts);
  });

  function createDrawing(pts) {
    const body = window.prompt('Note for this drawing (optional):') || 'Drawing';
    const d = { k: 'draw', w: docWidth(), pts: pts.map((p) => [Math.round(p[0]), Math.round(p[1])]) };
    api('POST', '/feedback', {
      page_url: pageKey(), page_title: document.title,
      anchor_selector: JSON.stringify(d), anchor_x: 0, anchor_y: 0,
      viewport_width: window.innerWidth, author_name: reviewerName(),
      author_is_agency: !!cfg.isAgency, author_key: authorKey(), body: body,
    }).then((res) => { if (res && res.feedback) { rememberMine(res.feedback.id); items.push({ ...res.feedback, anchor_selector: JSON.stringify(d) }); render(); } });
  }

  // dismiss tooltip on any outside click (markers stopPropagation so they don't self-dismiss)
  document.addEventListener('click', () => { if (!tip.hidden) hideTip(); }, true);

  // ---- movable notes panel: draggable + collapsible + persisted, to-do list + filter ----
  let panelState;
  try {
    const raw = JSON.parse(localStorage.getItem('ppFeedbackPanel') || '{}');
    panelState = (raw && typeof raw === 'object' && typeof raw.x === 'number' && typeof raw.y === 'number')
      ? { x: raw.x, y: raw.y, open: raw.open !== false } : { x: 20, y: 20, open: true };
  } catch (e) { panelState = { x: 20, y: 20, open: true }; }
  const panel = document.createElement('div');
  panel.className = 'pp-panel';
  panel.style.left = panelState.x + 'px';
  panel.style.top = panelState.y + 'px';
  panel.innerHTML =
    '<div class="pp-panel-head"><span class="pp-grip">Mark It Up</span>' +
    '<button class="pp-draw-btn" type="button" title="Draw on the page" aria-label="Draw on the page">✎ Draw</button>' +
    '<button class="pp-help-btn" type="button" title="How to use" aria-label="How to use">?</button>' +
    '<button class="pp-toggle"></button></div>' +
    '<div class="pp-help" hidden><strong>How to use</strong><ol>' +
    '<li><strong>Select text</strong> on the page, then click <strong>+ Note on this</strong> — it highlights the text and adds your note.</li>' +
    '<li>Or click <strong>+ Mark it up</strong> and click a spot (for an image or button).</li>' +
    '<li>Or click <strong>✎ Draw</strong> and drag to circle or scribble on the page, then add a note. Click <strong>✎ Draw</strong> again to stop.</li>' +
    '<li>Type your note. A marker appears; click it to read the note. Tick a note off in this list once it\'s handled.</li>' +
    '</ol></div>' +
    '<div class="pp-filter"><select class="pp-by"><option value="">Everyone</option></select></div>' +
    '<ul class="pp-list"></ul>';
  panel.setAttribute('role', 'region');
  panel.setAttribute('aria-label', 'Mark It Up feedback');
  shadow.appendChild(panel);
  panel.querySelector('.pp-help-btn').addEventListener('click', () => {
    const h = panel.querySelector('.pp-help'); if (h) h.hidden = !h.hidden;
  });
  panel.querySelector('.pp-draw-btn').addEventListener('click', () => setDrawMode(!drawing));

  function savePanel() { localStorage.setItem('ppFeedbackPanel', JSON.stringify(panelState)); }
  function applyCollapsed() {
    panel.classList.toggle('pp-collapsed', !panelState.open);
    const toggle = panel.querySelector('.pp-toggle');
    toggle.textContent = panelState.open ? '–' : '+';
    toggle.setAttribute('aria-label', panelState.open ? 'Collapse notes' : 'Expand notes');
  }
  applyCollapsed();
  panel.querySelector('.pp-toggle').addEventListener('click', () => { panelState.open = !panelState.open; applyCollapsed(); savePanel(); });

  (function drag() {
    const head = panel.querySelector('.pp-panel-head'); let sx, sy, ox, oy, on = false;
    head.addEventListener('mousedown', (e) => {
      if (e.target.closest && e.target.closest('button')) return;
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
    const everyone = document.createElement('option');
    everyone.value = ''; everyone.textContent = 'Everyone'; everyone.selected = !filterBy;
    sel.appendChild(everyone);
    names.forEach((n) => { const o = document.createElement('option'); o.value = n; o.textContent = n; o.selected = (n === filterBy); sel.appendChild(o); });
    sel.onchange = () => { filterBy = sel.value; render(); };

    const ul = panel.querySelector('.pp-list');
    ul.innerHTML = '';
    items.filter((i) => !filterBy || i.author_name === filterBy).forEach((it) => {
      const li = document.createElement('li');
      li.className = 'pp-row';
      const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = it.status === 'done';
      cb.addEventListener('change', () => {
        const status = cb.checked ? 'done' : 'open';
        api('PATCH', '/feedback/' + it.id, { status }).then((res) => {
          if (res && res.success) { it.status = status; render(); } else { cb.checked = (it.status === 'done'); }
        }).catch(() => { cb.checked = (it.status === 'done'); });
      });
      const dot = document.createElement('span'); dot.className = 'pp-dot'; dot.style.background = it.color || '#2D6CDF';
      const txt = document.createElement('span'); txt.className = 'pp-txt'; txt.textContent = (it.author_name ? it.author_name + ': ' : '') + it.body;
      if (it.status === 'done') txt.classList.add('pp-strike');
      li.append(cb, dot, txt);
      ul.appendChild(li);
    });
  }

  // ---- render highlights + markers ----
  function renderMarkers() {
    overlay.querySelectorAll('.pp-mark, .pp-hl').forEach((n) => n.remove());
    drawSvg.querySelectorAll('path.pp-drawpath').forEach((n) => n.remove());
    items.filter((i) => !filterBy || i.author_name === filterBy).forEach((it) => {
      const a = noteAnchor(it);
      let mx, my;
      if (a.kind === 'hl') {
        const range = rangeFromDescriptor(a.d);
        if (!range) return;
        const rects = range.getClientRects();
        if (!rects.length) return;
        for (let i = 0; i < rects.length; i++) {
          const rc = rects[i];
          const hl = document.createElement('div');
          hl.className = 'pp-hl' + (it.status === 'done' ? ' pp-done' : '');
          hl.style.left = rc.left + 'px'; hl.style.top = rc.top + 'px';
          hl.style.width = rc.width + 'px'; hl.style.height = rc.height + 'px';
          overlay.appendChild(hl);
        }
        const first = rects[0];
        mx = first.left; my = first.top;
      } else if (a.kind === 'draw') {
        const vp = drawPointsToViewport(a.d);
        if (vp.length < 2) return;
        const path = document.createElementNS(SVGNS, 'path');
        path.setAttribute('class', 'pp-drawpath' + (it.status === 'done' ? ' pp-done' : ''));
        path.setAttribute('stroke', it.color || '#DC2626');
        path.setAttribute('d', vp.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' '));
        drawSvg.appendChild(path);
        mx = vp[0][0]; my = vp[0][1];
      } else {
        const pt = pointFromAnchor(it);
        if (!pt) return;
        mx = pt.x; my = pt.y;
      }
      const mark = document.createElement('button');
      mark.className = 'pp-mark' + (it.status === 'done' ? ' pp-done' : '');
      mark.style.left = mx + 'px'; mark.style.top = my + 'px';
      mark.textContent = '?';
      mark.setAttribute('aria-label', (it.author_name ? it.author_name + ': ' : '') + (it.body || 'note'));
      mark.addEventListener('click', (e) => { e.stopPropagation(); showTip(mark, it); });
      overlay.appendChild(mark);
    });
  }

  function render() { renderMarkers(); renderList(); updateBadge(); }

  function load() {
    api('GET', '/feedback?page_url=' + encodeURIComponent(pageKey()))
      .then((res) => { items = (res && res.feedback) || []; render(); });
  }
  window.addEventListener('resize', () => { renderMarkers(); hideTip(); });
  window.addEventListener('scroll', () => { renderMarkers(); hideTip(); }, { passive: true });
  load();
})();
