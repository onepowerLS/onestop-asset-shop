<?php
/**
 * What's New modal — auto-opens on first page load after new entries appear.
 * Include this from footer.php for logged-in users.
 *
 * See docs/SYSTEM_SPECS.md for the policy that governs when entries are added.
 */
require_once __DIR__ . '/../config/whats_new.php';
$amWnColors = am_whats_new_category_colors();
$amWnLabels = am_whats_new_category_labels();
?>
<style>
#amWhatsNewModal .modal-dialog { max-width: 640px; }
#amWhatsNewModal .am-wn-slide { display: none; }
#amWhatsNewModal .am-wn-slide.active { display: block; }
#amWhatsNewModal .am-wn-icon {
    width: 56px; height: 56px; border-radius: 12px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.6rem; flex-shrink: 0;
}
#amWhatsNewModal .am-wn-progress { height: 4px; background: #eef2f7; border-radius: 4px; overflow: hidden; }
#amWhatsNewModal .am-wn-progress > span { display: block; height: 100%; background: #1976d2; transition: width .2s ease; }
#amWhatsNewModal .am-wn-details { white-space: normal; }
#amWhatsNewModal .am-wn-details p { margin-bottom: .65rem; }
#amWhatsNewModal .am-wn-details ul { margin-bottom: .65rem; padding-left: 1.25rem; }
#amWhatsNewModal .am-wn-dots { gap: .35rem; }
#amWhatsNewModal .am-wn-dot { width: 7px; height: 7px; border-radius: 50%; background: #cfd8e3; }
#amWhatsNewModal .am-wn-dot.active { background: #1976d2; }
</style>

<div class="modal fade" id="amWhatsNewModal" tabindex="-1" aria-labelledby="amWhatsNewLabel" aria-hidden="true" data-bs-backdrop="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-3">
                    <span class="am-wn-icon bg-primary-subtle text-primary"><i class="fas fa-bullhorn" id="amWnIconEl"></i></span>
                    <div>
                        <h3 class="modal-title fs-5 mb-0" id="amWhatsNewLabel">What's new</h3>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <span class="badge bg-secondary" id="amWnCategoryBadge">Update</span>
                            <span class="text-muted small" id="amWnReleasedAt"></span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="am-wn-progress mb-3"><span id="amWnProgressBar" style="width: 0%"></span></div>

                <div id="amWnSlides">
                    <!-- slides injected by JS -->
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-1 am-wn-dots" id="amWnDots"></div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-link text-muted btn-sm" id="amWnSkip">Maybe later</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="amWnBack" disabled><i class="fas fa-chevron-left me-1"></i>Back</button>
                    <button type="button" class="btn btn-primary btn-sm" id="amWnNext">Next<i class="fas fa-chevron-right ms-1"></i></button>
                    <button type="button" class="btn btn-success btn-sm" id="amWnFinish" style="display:none"><i class="fas fa-check me-1"></i>Got it</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var STORAGE_KEY = 'am_whats_new_dismissed_batch';
    var modalEl = document.getElementById('amWhatsNewModal');
    if (!modalEl) return;
    var slidesEl = document.getElementById('amWnSlides');
    var dotsEl = document.getElementById('amWnDots');
    var progressBar = document.getElementById('amWnProgressBar');
    var backBtn = document.getElementById('amWnBack');
    var nextBtn = document.getElementById('amWnNext');
    var finishBtn = document.getElementById('amWnFinish');
    var skipBtn = document.getElementById('amWnSkip');
    var categoryBadge = document.getElementById('amWnCategoryBadge');
    var releasedAtEl = document.getElementById('amWnReleasedAt');
    var iconEl = document.getElementById('amWnIconEl');

    var entries = [];
    var current = 0;
    var apiBase = <?php echo json_encode(base_url('api/whats-new/')); ?>;
    var archiveUrl = <?php echo json_encode(base_url('whats-new.php')); ?>;

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderSlides() {
        slidesEl.innerHTML = '';
        dotsEl.innerHTML = '';
        entries.forEach(function (entry, idx) {
            var slide = document.createElement('div');
            slide.className = 'am-wn-slide' + (idx === 0 ? ' active' : '');
            var detailsHtml = entry.details
                ? '<div class="am-wn-details mt-3 text-muted">' + entry.details + '</div>'
                : '';
            var linkHtml = entry.deep_link
                ? '<a class="btn btn-sm btn-outline-primary mt-3" href="' + escapeHtml(entry.deep_link) + '">Open <i class="fas fa-arrow-up-right-from-square ms-1"></i></a>'
                : '';
            slide.innerHTML =
                '<h4 class="h5 mb-2">' + escapeHtml(entry.title) + '</h4>' +
                '<p class="mb-0">' + escapeHtml(entry.summary) + '</p>' +
                detailsHtml + linkHtml;
            slidesEl.appendChild(slide);

            var dot = document.createElement('span');
            dot.className = 'am-wn-dot' + (idx === 0 ? ' active' : '');
            dotsEl.appendChild(dot);
        });
    }

    function showSlide(idx) {
        current = Math.max(0, Math.min(entries.length - 1, idx));
        slidesEl.querySelectorAll('.am-wn-slide').forEach(function (el, i) {
            el.classList.toggle('active', i === current);
        });
        dotsEl.querySelectorAll('.am-wn-dot').forEach(function (el, i) {
            el.classList.toggle('active', i === current);
        });
        if (progressBar) {
            progressBar.style.width = (entries.length ? ((current + 1) / entries.length * 100) : 0) + '%';
        }
        backBtn.disabled = current === 0;
        var isLast = current === entries.length - 1;
        nextBtn.style.display = isLast ? 'none' : '';
        finishBtn.style.display = isLast ? '' : 'none';

        var entry = entries[current] || {};
        if (categoryBadge) {
            categoryBadge.textContent = entry.category_label || 'Update';
            categoryBadge.className = 'badge bg-' + (entry.category_color || 'secondary');
        }
        if (releasedAtEl) {
            var d = (entry.released_at || '').slice(0, 10);
            releasedAtEl.textContent = d;
        }
        if (iconEl && entry.icon) {
            iconEl.className = 'fas ' + entry.icon;
        }
    }

    function dismissAll() {
        var ids = entries.map(function (e) { return e.id; });
        if (ids.length === 0) return;
        fetch(apiBase + 'dismiss.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ entry_ids: ids }),
            credentials: 'same-origin'
        }).catch(function () {});
        try {
            sessionStorage.setItem(STORAGE_KEY, ids.join('|') + '@' + Date.now());
        } catch (e) {}
    }

    function openModal() {
        renderSlides();
        showSlide(0);
        var bs = bootstrap.Modal.getOrCreateInstance(modalEl);
        bs.show();
    }

    nextBtn.addEventListener('click', function () { showSlide(current + 1); });
    backBtn.addEventListener('click', function () { showSlide(current - 1); });
    finishBtn.addEventListener('click', function () {
        dismissAll();
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    });
    skipBtn.addEventListener('click', function () {
        dismissAll();
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    });
    modalEl.addEventListener('hidden.bs.modal', function () {
        // Closing the modal any way (X, ESC, backdrop) also dismisses.
        dismissAll();
    });

    // Only auto-open once per session batch to avoid re-popping on every page load.
    function shouldAutoOpen() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            if (!raw) return true;
            return false;
        } catch (e) { return true; }
    }

    function init() {
        fetch(apiBase + 'unseen.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) return;
                entries = data.entries || [];
                if (entries.length === 0) return;
                if (!shouldAutoOpen()) return;
                // Defer until after DOMContentLoaded handlers (tutorial etc.) so the modal stacks cleanly.
                setTimeout(openModal, 300);
            })
            .catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
