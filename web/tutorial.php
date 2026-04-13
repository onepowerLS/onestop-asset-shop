<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/firebase.php';
require_once __DIR__ . '/config/ui_locale.php';
am_locale_bootstrap();
require_once __DIR__ . '/config/docs_i18n.php';

if (!is_logged_in()) {
    header('Location: /login.php');
    exit;
}

$t = am_i18n_tutorial();
$page_lang = am_locale();
$page_title = $t['page_title'];
include __DIR__ . '/includes/header.php';

$toggleEn = $page_lang === 'en' ? 'btn-primary' : 'btn-outline-primary';
$toggleFr = $page_lang === 'fr' ? 'btn-primary' : 'btn-outline-primary';
?>

<div class="container-fluid px-4 py-4" style="max-width: 960px;">

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h2 mb-1"><i class="fas fa-graduation-cap me-2 text-primary"></i><?php echo htmlspecialchars($t['page_title']); ?></h1>
        <p class="text-muted mb-0"><?php echo htmlspecialchars($t['subtitle']); ?></p>
    </div>
    <div class="d-flex align-items-center gap-2" role="group" aria-label="<?php echo htmlspecialchars($t['lang_label']); ?>">
        <span class="text-muted small"><?php echo htmlspecialchars($t['lang_label']); ?>:</span>
        <a href="<?php echo htmlspecialchars(base_url('tutorial.php?lang=en')); ?>" class="btn btn-sm <?php echo $toggleEn; ?>"><?php echo htmlspecialchars($t['lang_en']); ?></a>
        <a href="<?php echo htmlspecialchars(base_url('tutorial.php?lang=fr')); ?>" class="btn btn-sm <?php echo $toggleFr; ?>"><?php echo htmlspecialchars($t['lang_fr']); ?></a>
    </div>
</div>

<p class="lead fs-6"><?php echo htmlspecialchars($t['intro']); ?></p>

<div class="d-flex flex-wrap gap-2 mb-4">
    <a href="<?php echo htmlspecialchars(am_localized_url('help.php')); ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($t['back_help']); ?>
    </a>
</div>

<h2 class="h4 mb-3"><i class="fas fa-route me-2 text-primary"></i><?php echo htmlspecialchars($t['app_tour_title']); ?></h2>
<p class="text-muted"><?php echo htmlspecialchars($t['app_tour_lead']); ?></p>

<?php foreach ($t['tours'] as $ti => $tour): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h3 class="h5 mb-3"><?php echo (int) ($ti + 1); ?>. <?php echo htmlspecialchars($tour['title']); ?></h3>
        <ol class="mb-0 ps-3">
            <?php foreach ($tour['steps'] as $si => $step): ?>
            <li class="mb-2">
                <span class="text-muted small"><?php echo htmlspecialchars($t['step_label']); ?> <?php echo (int) ($si + 1); ?>:</span>
                <?php echo htmlspecialchars($step['text']); ?>
                <?php
                $href = trim((string) ($step['href'] ?? ''));
                if ($href !== '') {
                    $url = am_localized_url($href);
                    echo ' <a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($step['link_label'] ?: $t['open']) . '</a>';
                }
                ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
<?php endforeach; ?>

<h2 class="h4 mb-3 mt-5"><i class="fas fa-tasks me-2 text-primary"></i><?php echo htmlspecialchars($t['workflows_title']); ?></h2>
<p class="text-muted"><?php echo htmlspecialchars($t['workflows_lead']); ?></p>

<div class="accordion" id="workflowAccordion">
    <?php foreach ($t['workflows'] as $wi => $wf): ?>
    <div class="accordion-item border-0 shadow-sm mb-2">
        <h3 class="accordion-header" id="wf-h-<?php echo htmlspecialchars($wf['id']); ?>">
            <button class="accordion-button <?php echo $wi === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#wf-c-<?php echo htmlspecialchars($wf['id']); ?>" aria-expanded="<?php echo $wi === 0 ? 'true' : 'false'; ?>">
                <?php echo htmlspecialchars($wf['title']); ?>
            </button>
        </h3>
        <div id="wf-c-<?php echo htmlspecialchars($wf['id']); ?>" class="accordion-collapse collapse <?php echo $wi === 0 ? 'show' : ''; ?>" data-bs-parent="#workflowAccordion">
            <div class="accordion-body">
                <?php if (!empty($wf['intro'])): ?>
                <p class="text-muted"><?php echo htmlspecialchars($wf['intro']); ?></p>
                <?php endif; ?>
                <ol class="mb-3">
                    <?php foreach ($wf['steps'] as $st): ?>
                    <li><?php echo htmlspecialchars($st); ?></li>
                    <?php endforeach; ?>
                </ol>
                <?php
                $wh = trim((string) ($wf['href'] ?? ''));
                if ($wh !== '') {
                    $wu = am_localized_url($wh);
                    $wl = (string) ($wf['link_label'] ?? $t['open']);
                    echo '<a href="' . htmlspecialchars($wu) . '" class="btn btn-primary btn-sm"><i class="fas fa-external-link-alt me-1"></i>' . htmlspecialchars($wl) . '</a>';
                }
                ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="text-center text-muted py-4">
    <small>1PWR Asset Management v<?php echo APP_VERSION; ?></small>
</div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
