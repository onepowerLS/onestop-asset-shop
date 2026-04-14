/**
 * Fleet Hub–style tutorial: highlights [data-tutorial], spotlights with backdrop, sessionStorage state.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'am_tutorial_v1';

  function cfg() {
    return window.AM_TUTORIAL || { tracks: {}, track_order: [], strings: {}, query_map: {}, nav_map: {}, base_url: '' };
  }

  function getState() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function setState(o) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(o));
    } catch (e) {}
  }

  function clearState() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch (e) {}
  }

  function pathname() {
    return window.location.pathname || '/';
  }

  /** Match step.path to current URL (PHP app lives at site root or subfolder). */
  function pathMatches(stepPath) {
    var sp = (stepPath || '').replace(/^\//, '').toLowerCase();
    if (!sp) return false;
    var full = pathname().replace(/\/$/, '').toLowerCase();
    if (sp === 'index.php') {
      return /index\.php$/i.test(full) || full === '' || full === '/';
    }
    return full.endsWith(sp) || full.indexOf('/' + sp) !== -1;
  }

  function stepUrl(stepPath) {
    var base = cfg().base_url || '';
    var p = (stepPath || '').replace(/^\//, '');
    if (!base) return '/' + p;
    return base + '/' + p;
  }

  function getSteps(trackId) {
    var t = cfg().tracks[trackId];
    return t && t.steps ? t.steps : [];
  }

  function getTrackLabel(trackId) {
    var t = cfg().tracks[trackId];
    return t && t.label ? t.label : trackId;
  }

  function navTargetForPath(stepPath) {
    var m = cfg().nav_map || {};
    return m[stepPath] || 'nav-dashboard';
  }

  /** @returns {boolean} true if full page navigation was triggered (skip further init) */
  function bootstrapFromQuery() {
    var params = new URLSearchParams(window.location.search);
    var raw = params.get('tutorial');
    if (!raw) return false;
    var qm = cfg().query_map || {};
    var trackId = qm[raw];
    if (!trackId || !cfg().tracks[trackId]) return false;
    var steps = getSteps(trackId);
    if (!steps.length) return false;
    setState({ active: true, trackId: trackId, stepIndex: 0 });
    try {
      var url = new URL(window.location.href);
      url.searchParams.delete('tutorial');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    } catch (e) {}
    if (!pathMatches(steps[0].path)) {
      window.location.href = stepUrl(steps[0].path);
      return true;
    }
    return false;
  }

  function str(key, a, b) {
    var s = (cfg().strings || {})[key] || key;
    return s
      .replace(/%1\$s/g, String(a !== undefined && a !== null ? a : ''))
      .replace(/%2\$s/g, String(b !== undefined && b !== null ? b : ''));
  }

  function findTarget(selector) {
    return document.querySelector('[data-tutorial="' + selector.replace(/"/g, '') + '"]');
  }

  var overlayEl = null;
  var panelEl = null;

  function removeOverlay() {
    if (overlayEl && overlayEl.parentNode) overlayEl.parentNode.removeChild(overlayEl);
    overlayEl = null;
    panelEl = null;
  }

  function updateRect() {
    var st = getState();
    if (!st || !st.active) return;
    var steps = getSteps(st.trackId);
    var step = steps[st.stepIndex];
    if (!step) return;

    var highlight = document.getElementById('am-tutorial-highlight');
    var missEl = document.getElementById('am-tutorial-missing');
    if (!highlight || !missEl) return;

    var pathOk = pathMatches(step.path);
    var el = findTarget(step.target);
    var rect = null;
    var missing = false;

    if (pathOk && el) {
      var r = el.getBoundingClientRect();
      rect = { top: r.top - 6, left: r.left - 6, width: r.width + 12, height: r.height + 12 };
      missing = false;
    } else if (!pathOk) {
      var navSel = navTargetForPath(step.path);
      var navEl = findTarget(navSel);
      if (navEl) {
        var nr = navEl.getBoundingClientRect();
        rect = { top: nr.top - 4, left: nr.left - 4, width: nr.width + 8, height: nr.height + 8 };
        missing = true;
      } else {
        missing = true;
      }
    } else {
      missing = true;
    }

    missEl.style.display = missing ? 'block' : 'none';
    if (rect) {
      highlight.style.display = 'block';
      highlight.style.top = rect.top + 'px';
      highlight.style.left = rect.left + 'px';
      highlight.style.width = rect.width + 'px';
      highlight.style.height = rect.height + 'px';
    } else {
      highlight.style.display = 'none';
    }
  }

  function scrollToTarget() {
    var st = getState();
    if (!st || !st.active) return;
    var steps = getSteps(st.trackId);
    var step = steps[st.stepIndex];
    if (!step || !pathMatches(step.path)) return;
    var el = findTarget(step.target);
    if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }

  function render() {
    removeOverlay();
    var st = getState();
    if (!st || !st.active) return;

    var steps = getSteps(st.trackId);
    var step = steps[st.stepIndex];
    if (!step) {
      clearState();
      return;
    }

    var total = steps.length;
    var isLast = st.stepIndex >= total - 1;

    overlayEl = document.createElement('div');
    overlayEl.className = 'am-tutorial-overlay';
    overlayEl.setAttribute('role', 'dialog');
    overlayEl.setAttribute('aria-modal', 'true');
    overlayEl.innerHTML =
      '<div class="am-tutorial-backdrop"></div>' +
      '<div id="am-tutorial-highlight" class="am-tutorial-highlight" style="display:none"></div>' +
      '<div class="am-tutorial-panel-wrap">' +
      '<div class="am-tutorial-panel card shadow-lg border-0">' +
      '<div id="am-tutorial-missing" class="alert alert-warning py-1 px-2 small mb-2" style="display:none">' +
      str('missing') +
      '</div>' +
      '<div class="fw-semibold text-dark" id="am-tutorial-title"></div>' +
      '<p class="text-muted small mt-2 mb-0" id="am-tutorial-body"></p>' +
      '<div id="am-tutorial-suggestion" class="d-none mt-2 p-2 rounded border border-primary border-opacity-25 bg-primary-subtle small"></div>' +
      '<div class="d-flex flex-wrap align-items-center gap-2 mt-3">' +
      '<button type="button" class="btn btn-outline-secondary btn-sm" id="am-tutorial-back">' +
      str('back') +
      '</button>' +
      '<button type="button" class="btn btn-primary btn-sm" id="am-tutorial-next">' +
      (isLast ? str('finish') : str('next')) +
      '</button>' +
      '<button type="button" class="btn btn-link btn-sm text-secondary ms-auto" id="am-tutorial-exit">' +
      str('exit') +
      '</button>' +
      '</div>' +
      '<div class="text-muted mt-2" style="font-size:11px" id="am-tutorial-stepof"></div>' +
      '</div></div>';

    document.body.appendChild(overlayEl);

    document.getElementById('am-tutorial-title').textContent = step.title || '';
    document.getElementById('am-tutorial-body').textContent = step.body || '';
    document.getElementById('am-tutorial-next').textContent = isLast ? str('finish') : str('next');
    var sug = document.getElementById('am-tutorial-suggestion');
    if (step.suggestion) {
      sug.textContent = step.suggestion;
      sug.classList.remove('d-none');
    }
    var so = str('stepOf', String(st.stepIndex + 1), String(total));
    document.getElementById('am-tutorial-stepof').textContent = so;

    document.getElementById('am-tutorial-back').disabled = st.stepIndex <= 0;
    document.getElementById('am-tutorial-back').onclick = onBack;
    document.getElementById('am-tutorial-next').onclick = onNext;
    document.getElementById('am-tutorial-exit').onclick = onExit;

    panelEl = overlayEl.querySelector('.am-tutorial-panel');

    updateRect();
    setTimeout(scrollToTarget, 100);

    var ro = function () {
      updateRect();
    };
    window.addEventListener('resize', ro);
    var iv = window.setInterval(ro, 400);
    overlayEl._cleanup = function () {
      window.removeEventListener('resize', ro);
      window.clearInterval(iv);
    };
  }

  function onExit() {
    if (overlayEl && overlayEl._cleanup) overlayEl._cleanup();
    clearState();
    removeOverlay();
  }

  function onBack() {
    var st = getState();
    if (!st || st.stepIndex <= 0) return;
    var steps = getSteps(st.trackId);
    var curStep = steps[st.stepIndex];
    var prevIdx = st.stepIndex - 1;
    var prevStep = steps[prevIdx];
    st.stepIndex = prevIdx;
    setState(st);
    if (prevStep.path !== curStep.path) {
      window.location.href = stepUrl(prevStep.path);
      return;
    }
    if (overlayEl && overlayEl._cleanup) overlayEl._cleanup();
    render();
  }

  function onNext() {
    var st = getState();
    if (!st || !st.active) return;
    var steps = getSteps(st.trackId);
    if (st.stepIndex >= steps.length - 1) {
      onExit();
      return;
    }
    var curStep = steps[st.stepIndex];
    var nextIdx = st.stepIndex + 1;
    var nextStep = steps[nextIdx];
    st.stepIndex = nextIdx;
    setState(st);
    if (nextStep.path !== curStep.path) {
      window.location.href = stepUrl(nextStep.path);
      return;
    }
    if (overlayEl && overlayEl._cleanup) overlayEl._cleanup();
    render();
  }

  function start(trackId) {
    var steps = getSteps(trackId);
    if (!steps.length) return;
    setState({ active: true, trackId: trackId, stepIndex: 0 });
    if (!pathMatches(steps[0].path)) {
      window.location.href = stepUrl(steps[0].path);
      return;
    }
    render();
  }

  function init() {
    if (bootstrapFromQuery()) {
      return;
    }
    var st = getState();
    if (st && st.active) {
      requestAnimationFrame(function () {
        requestAnimationFrame(render);
      });
    }

    document.querySelectorAll('[data-tutorial-start]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tid = btn.getAttribute('data-tutorial-start');
        if (tid) start(tid);
      });
    });
  }

  window.AM_Tutorial = {
    start: start,
    exit: onExit,
    getTrackLabel: getTrackLabel,
    getSteps: getSteps,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
