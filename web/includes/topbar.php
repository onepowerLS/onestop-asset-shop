<?php
/**
 * Top Navigation Bar
 */
require_once __DIR__ . '/../config/locale.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/tutorial_interactive.php';
if (function_exists('is_logged_in') && is_logged_in()) {
    am_ensure_country_scope_from_session();
}
$returnPath = (string)($_SERVER['REQUEST_URI'] ?? '/index.php');
if ($returnPath === '' || $returnPath[0] !== '/') {
    $returnPath = '/index.php';
}
?>
<nav class="navbar navbar-top navbar-expand navbar-dark bg-primary border-bottom">
    <div class="container-fluid">
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <?php if (is_logged_in()): ?>
            <ul class="navbar-nav align-items-center ms-auto me-2 flex-row gap-2">
                <li class="nav-item d-flex align-items-center">
                    <form method="post" action="<?php echo htmlspecialchars(base_url('session/update.php')); ?>" class="d-flex align-items-center gap-1 mb-0">
                        <input type="hidden" name="action" value="set_lang">
                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($returnPath); ?>">
                        <label class="text-white small mb-0 me-1 d-none d-md-inline"><?php echo htmlspecialchars(am_ui('nav_language')); ?></label>
                        <select name="ui_lang" class="form-select form-select-sm py-0" style="width:auto;min-width:7rem;" onchange="this.form.submit()" title="<?php echo htmlspecialchars(am_ui('nav_language')); ?>">
                            <option value="en" <?php echo am_session_lang() === 'en' ? 'selected' : ''; ?>><?php echo htmlspecialchars(am_ui('lang_en')); ?></option>
                            <option value="fr" <?php echo am_session_lang() === 'fr' ? 'selected' : ''; ?>><?php echo htmlspecialchars(am_ui('lang_fr')); ?></option>
                        </select>
                    </form>
                </li>
                <?php
                $allow = am_country_allow_codes();
                if (count($allow) > 1):
                    $cur = am_country_filter_mode();
                ?>
                <li class="nav-item d-flex align-items-center">
                    <form method="post" action="<?php echo htmlspecialchars(base_url('session/update.php')); ?>" class="d-flex align-items-center gap-1 mb-0">
                        <input type="hidden" name="action" value="set_country_filter">
                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($returnPath); ?>">
                        <label class="text-white small mb-0 me-1 d-none d-md-inline"><?php echo htmlspecialchars(am_ui('nav_country_scope')); ?></label>
                        <select name="country_filter" class="form-select form-select-sm py-0" style="width:auto;min-width:10rem;" onchange="this.form.submit()" title="<?php echo htmlspecialchars(am_ui('nav_country_scope')); ?>">
                            <option value="all" <?php echo $cur === 'all' ? 'selected' : ''; ?>><?php echo htmlspecialchars(am_ui('scope_all')); ?></option>
                            <?php foreach ($allow as $code):
                                $lab = match ($code) {
                                    'LSO' => am_ui('scope_lso'),
                                    'ZMB' => am_ui('scope_zmb'),
                                    'BEN' => am_ui('scope_ben'),
                                    default => $code,
                                };
                            ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $cur === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($lab); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </li>
                <?php elseif (count($allow) === 1): ?>
                <li class="nav-item">
                    <span class="text-white-50 small px-2"><?php echo htmlspecialchars(am_ui('nav_country_scope')); ?>: <strong class="text-white"><?php echo htmlspecialchars($allow[0]); ?></strong></span>
                </li>
                <?php endif; ?>
                <li class="nav-item dropdown" data-tutorial="header-tutorial-menu">
                    <a class="nav-link text-white dropdown-toggle py-1 px-2 d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo htmlspecialchars(am_ui('tutorial_mode')); ?>">
                        <i class="fas fa-graduation-cap me-1"></i>
                        <span class="d-none d-md-inline"><?php echo htmlspecialchars(am_ui('tutorial_mode')); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><h6 class="dropdown-header"><?php echo htmlspecialchars(am_ui('tutorial_choose')); ?></h6></li>
                        <?php
                        $amTutorialMenu = am_tutorial_client_config();
                        foreach ($amTutorialMenu['track_order'] as $amTid):
                            $amTlab = $amTutorialMenu['tracks'][$amTid]['label'] ?? $amTid;
                        ?>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(am_tutorial_start_url($amTid)); ?>">
                                <?php echo htmlspecialchars($amTlab); ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            </ul>
            <?php endif; ?>
            <ul class="navbar-nav align-items-center ms-md-auto">
                <li class="nav-item dropdown ms-lg-3">
                    <a class="nav-link dropdown-toggle pt-1 px-0" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="media d-flex align-items-center">
                            <div class="media-body ms-2 text-dark align-items-center d-none d-lg-block">
                                <span class="mb-0 font-small fw-bold text-gray-900">
                                    <?php 
                                    if (is_logged_in()) {
                                        echo htmlspecialchars($_SESSION['username'] ?? 'User');
                                    } else {
                                        echo 'Guest';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </a>
                    <div class="dropdown-menu dashboard-dropdown dropdown-menu-end mt-2 py-1">
                        <?php if (is_logged_in()): ?>
                            <a class="dropdown-item d-flex align-items-center" href="<?php echo base_url('profile.php'); ?>">
                                <i class="fas fa-user me-2"></i>
                                My Profile
                            </a>
                            <a class="dropdown-item d-flex align-items-center" href="<?php echo base_url('settings.php'); ?>">
                                <i class="fas fa-cog me-2"></i>
                                Settings
                            </a>
                            <div role="separator" class="dropdown-divider my-1"></div>
                            <a class="dropdown-item d-flex align-items-center text-danger" href="<?php echo base_url('logout.php'); ?>">
                                <i class="fas fa-sign-out-alt me-2"></i>
                                Logout
                            </a>
                        <?php else: ?>
                            <a class="dropdown-item d-flex align-items-center" href="<?php echo base_url('login.php'); ?>">
                                <i class="fas fa-sign-in-alt me-2"></i>
                                Login
                            </a>
                        <?php endif; ?>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>
