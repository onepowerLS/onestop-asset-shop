<?php
/**
 * Public "What's New" archive — companion to Help and Tutorial.
 * Shows all active entries, newest first. Logged-in users see this alongside the popup.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/whats_new.php';
require_login();

$page_title = "What's new";

$entries = am_whats_new_entries(true);
$colors = am_whats_new_category_colors();
$labels = am_whats_new_category_labels();

include __DIR__ . '/includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('help.php'); ?>">Help</a></li>
                    <li class="breadcrumb-item active">What's new</li>
                </ol>
            </nav>
            <h1 class="h2 mt-2">What's new</h1>
            <p class="mb-0 text-muted">Feature updates shipped to the AM tool. The same entries appear in the popup primer at login when they're new to you.</p>
        </div>
        <a href="<?php echo base_url('help.php'); ?>" class="btn btn-sm btn-outline-secondary">Back to Help</a>
    </div>

    <?php if (empty($entries)): ?>
    <div class="card border-0 shadow">
        <div class="card-body text-center text-gray-500 py-5">No updates have been posted yet.</div>
    </div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($entries as $e):
            $cat = (string)($e['category'] ?? 'feature');
            $icon = (string)($e['icon'] ?? 'fa-star');
            $date = substr((string)($e['released_at'] ?? ''), 0, 10);
        ?>
        <div class="col-12">
            <div class="card border-0 shadow">
                <div class="card-body d-flex gap-3 align-items-start">
                    <span class="am-wn-icon bg-<?php echo $colors[$cat] ?? 'secondary'; ?>-subtle text-<?php echo $colors[$cat] ?? 'secondary'; ?>" style="width:48px;height:48px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
                        <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
                    </span>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-start">
                            <div>
                                <h3 class="h5 mb-1"><?php echo htmlspecialchars($e['title'] ?? ''); ?></h3>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="badge bg-<?php echo $colors[$cat] ?? 'secondary'; ?>"><?php echo htmlspecialchars($labels[$cat] ?? $cat); ?></span>
                                    <span class="text-muted small"><?php echo htmlspecialchars($date); ?></span>
                                </div>
                            </div>
                            <?php if (!empty($e['deep_link'])): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($e['deep_link']); ?>">Open <i class="fas fa-arrow-up-right-from-square ms-1"></i></a>
                            <?php endif; ?>
                        </div>
                        <p class="mb-0"><?php echo htmlspecialchars($e['summary'] ?? ''); ?></p>
                        <?php if (!empty($e['details'])): ?>
                        <div class="text-muted mt-2 am-wn-details"><?php echo $e['details']; ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.am-wn-details p { margin-bottom: .65rem; }
.am-wn-details ul { margin-bottom: .65rem; padding-left: 1.25rem; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
