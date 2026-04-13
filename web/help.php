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

$help = am_i18n_help();
$page_lang = am_locale();
$page_title = $help['page_title'];
include __DIR__ . '/includes/header.php';

$toggleEn = $page_lang === 'en' ? 'btn-primary' : 'btn-outline-primary';
$toggleFr = $page_lang === 'fr' ? 'btn-primary' : 'btn-outline-primary';
?>

<div class="container-fluid px-4 py-4" style="max-width: 960px;">

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h2 mb-1"><?php echo htmlspecialchars($help['page_title']); ?></h1>
        <p class="text-muted mb-0"><?php echo htmlspecialchars($help['subtitle']); ?></p>
    </div>
    <div class="d-flex align-items-center gap-2" role="group" aria-label="<?php echo htmlspecialchars($help['lang_label']); ?>">
        <span class="text-muted small"><?php echo htmlspecialchars($help['lang_label']); ?>:</span>
        <a href="<?php echo htmlspecialchars(base_url('help.php?lang=en')); ?>" class="btn btn-sm <?php echo $toggleEn; ?>"><?php echo htmlspecialchars($help['lang_en']); ?></a>
        <a href="<?php echo htmlspecialchars(base_url('help.php?lang=fr')); ?>" class="btn btn-sm <?php echo $toggleFr; ?>"><?php echo htmlspecialchars($help['lang_fr']); ?></a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
<div class="card-body">
<nav id="help-toc" class="mb-0 p-3 bg-light rounded" aria-label="<?php echo htmlspecialchars($help['toc_title']); ?>">
    <h5 class="mb-3"><i class="fas fa-list me-2"></i><?php echo htmlspecialchars($help['toc_title']); ?></h5>
    <ol class="mb-0" style="columns:2; column-gap:2rem;">
        <?php foreach ($help['toc'] as $anchor => $label): ?>
        <li><a href="#<?php echo htmlspecialchars($anchor); ?>"><?php echo htmlspecialchars($label); ?></a></li>
        <?php endforeach; ?>
    </ol>
</nav>
</div>
</div>

<?php foreach ($help['sections'] as $section): ?>
<div class="card border-0 shadow-sm mb-4" id="<?php echo htmlspecialchars($section['id']); ?>">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas <?php echo htmlspecialchars($section['icon']); ?> me-2 text-primary"></i><?php echo htmlspecialchars($section['title']); ?></h2>
    <?php echo $section['html']; // phpcs:ignore -- trusted authored HTML
    ?>
</div>
</div>
<?php endforeach; ?>

<div class="text-center text-muted py-3">
    <small>1PWR Asset Management v<?php echo APP_VERSION; ?> — <?php echo htmlspecialchars($help['footer']); ?></small>
</div>

<div class="text-center pb-4">
    <a href="<?php echo htmlspecialchars(am_localized_url('tutorial.php')); ?>" class="btn btn-outline-primary">
        <i class="fas fa-graduation-cap me-1"></i> <?php echo $page_lang === 'fr' ? 'Ouvrir le tutoriel' : 'Open tutorial'; ?>
    </a>
</div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
