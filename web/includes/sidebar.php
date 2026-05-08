<?php
/**
 * Sidebar Navigation
 */
require_once __DIR__ . '/../config/authz.php';
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<nav id="sidebarMenu" class="sidebar d-lg-block bg-gray-800 text-white collapse" data-simplebar>
    <div class="sidebar-inner px-4 pt-3">
        <div class="user-card d-flex d-md-none align-items-center justify-content-between justify-content-md-center pb-4">
            <div class="d-flex align-items-center">
                <div class="avatar-lg me-4">
                    <i class="fas fa-user-circle fa-3x text-gray-300"></i>
                </div>
                <div class="d-block">
                    <?php if (is_logged_in()): ?>
                        <h2 class="h5 mb-3">Hi, <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></h2>
                        <a href="<?php echo base_url('logout.php'); ?>" class="btn btn-secondary btn-sm d-inline-flex align-items-center">
                            <i class="fas fa-sign-out-alt me-1"></i>
                            Sign Out
                        </a>
                    <?php else: ?>
                        <a href="<?php echo base_url('login.php'); ?>" class="btn btn-secondary btn-sm d-inline-flex align-items-center">Sign In</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="collapse-close d-md-none">
                <a href="#sidebarMenu" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="true" aria-label="Toggle navigation">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </div>

        <ul class="nav flex-column pt-3 pt-md-0">
            <li class="nav-item">
                <a href="<?php echo base_url(); ?>" class="nav-link d-flex align-items-center">
                    <span class="sidebar-icon" style="font-weight:700;font-size:1.4rem;color:#1976d2;">1PWR</span>
                    <span class="sidebar-text ms-1" style="font-weight:500;">Asset Management</span>
                </a>
            </li>

            <li class="nav-item <?php echo $current_page === 'index' || $current_page === 'dashboard' ? 'active' : ''; ?>">
                <a href="<?php echo base_url('index.php'); ?>" class="nav-link" data-tutorial="nav-dashboard">
                    <span class="sidebar-icon">
                        <i class="fas fa-home"></i>
                    </span>
                    <span class="sidebar-text">Dashboard</span>
                </a>
            </li>

            <?php
            $classParam = $_GET['item_class'] ?? '';
            ?>
            <li class="nav-item">
                <span class="nav-link collapsed d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#submenu-catalog" data-tutorial="nav-catalog">
                    <span>
                        <span class="sidebar-icon">
                            <i class="fas fa-th-large"></i>
                        </span>
                        <span class="sidebar-text">Catalog</span>
                    </span>
                    <span class="link-arrow">
                        <i class="fas fa-chevron-right"></i>
                    </span>
                </span>
                <div class="multi-level collapse <?php echo in_array($current_page, ['assets', 'index']) && $classParam ? 'show' : ''; ?>" id="submenu-catalog">
                    <ul class="flex-column nav">
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo base_url('assets/index.php'); ?>" data-tutorial="nav-assets-all">
                                <span class="sidebar-text">All Items</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $classParam === 'FixedAsset' ? 'active' : ''; ?>" href="<?php echo base_url('assets/index.php?item_class=FixedAsset'); ?>">
                                <i class="fas fa-building me-1 text-primary"></i>
                                <span class="sidebar-text">Fixed Assets</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $classParam === 'Material' ? 'active' : ''; ?>" href="<?php echo base_url('assets/index.php?item_class=Material'); ?>">
                                <i class="fas fa-cubes me-1 text-warning"></i>
                                <span class="sidebar-text">Materials</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $classParam === 'Consumable' ? 'active' : ''; ?>" href="<?php echo base_url('assets/index.php?item_class=Consumable'); ?>">
                                <i class="fas fa-recycle me-1 text-info"></i>
                                <span class="sidebar-text">Consumables</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $classParam === 'Inventory' ? 'active' : ''; ?>" href="<?php echo base_url('assets/index.php?item_class=Inventory'); ?>">
                                <i class="fas fa-boxes-stacked me-1 text-success"></i>
                                <span class="sidebar-text">Inventory</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <?php
            $requestsPages = ['requests', 'workflow-index', 'workflow-new', 'workflow-view', 'archived-ret', 'archived-ret-view'];
            $requestsOpen = in_array($current_page, $requestsPages, true);
            ?>
            <li class="nav-item">
                <span class="nav-link collapsed d-flex justify-content-between align-items-center <?php echo $requestsOpen ? 'active' : ''; ?>" data-bs-toggle="collapse" data-bs-target="#submenu-requests" style="cursor:pointer;" data-tutorial="nav-requests">
                    <span>
                        <span class="sidebar-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </span>
                        <span class="sidebar-text">Requests</span>
                    </span>
                    <span class="link-arrow">
                        <i class="fas fa-chevron-right"></i>
                    </span>
                </span>
                <div class="multi-level collapse <?php echo $requestsOpen ? 'show' : ''; ?>" id="submenu-requests">
                    <ul class="flex-column nav">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'requests' ? 'active' : ''; ?>" href="<?php echo base_url('requests/index.php'); ?>" data-tutorial="nav-requests-procurement">
                                <span class="sidebar-text">Ready board request</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo in_array($current_page, ['workflow-index', 'workflow-new', 'workflow-view'], true) ? 'active' : ''; ?>" href="<?php echo base_url('requests/workflow-index.php'); ?>" data-tutorial="nav-requests-workflows">
                                <span class="sidebar-text">Service workflows</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo in_array($current_page, ['dispatch-new', 'dispatch-view'], true) ? 'active' : ''; ?>" href="<?php echo base_url('requests/dispatch-new.php'); ?>">
                                <span class="sidebar-text">Dispatch request</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo in_array($current_page, ['archived-ret', 'archived-ret-view'], true) ? 'active' : ''; ?>" href="<?php echo base_url('requests/archived-ret.php'); ?>">
                                <span class="sidebar-text">RET archives</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <li class="nav-item <?php echo $current_page === 'inventory' ? 'active' : ''; ?>">
                <a href="<?php echo base_url('inventory/index.php'); ?>" class="nav-link" data-tutorial="nav-inventory">
                    <span class="sidebar-icon">
                        <i class="fas fa-warehouse"></i>
                    </span>
                    <span class="sidebar-text">Stock Levels</span>
                </a>
            </li>

            <?php
            $inReviews = str_contains((string)($_SERVER['PHP_SELF'] ?? ''), '/reviews/');
            ?>
            <?php if (is_logged_in() && am_can_duplicate_review()): ?>
            <li class="nav-item">
                <span class="nav-link collapsed d-flex justify-content-between align-items-center <?php echo $inReviews ? 'active' : ''; ?>" data-bs-toggle="collapse" data-bs-target="#submenu-reviews" style="cursor:pointer;">
                    <span>
                        <span class="sidebar-icon"><i class="fas fa-clone"></i></span>
                        <span class="sidebar-text">Data quality</span>
                    </span>
                    <span class="link-arrow"><i class="fas fa-chevron-right"></i></span>
                </span>
                <div class="multi-level collapse <?php echo $inReviews ? 'show' : ''; ?>" id="submenu-reviews">
                    <ul class="flex-column nav">
                        <li class="nav-item">
                            <a class="nav-link <?php echo str_contains((string)($_SERVER['PHP_SELF'] ?? ''), 'duplicate-review') ? 'active' : ''; ?>" href="<?php echo base_url('reviews/duplicate-review.php'); ?>">Duplicate review</a>
                        </li>
                    </ul>
                </div>
            </li>
            <?php endif; ?>

            <?php if (!am_is_auditor_readonly()): ?>
            <li class="nav-item <?php echo $current_page === 'checkout' ? 'active' : ''; ?>">
                <a href="<?php echo base_url('checkout/index.php'); ?>" class="nav-link" data-tutorial="nav-checkout">
                    <span class="sidebar-icon">
                        <i class="fas fa-hand-holding"></i>
                    </span>
                    <span class="sidebar-text">Check-Out/In</span>
                </a>
            </li>
            <?php endif; ?>

            <?php
            $inLoadout = str_contains((string)($_SERVER['PHP_SELF'] ?? ''), '/loadout/');
            ?>
            <li class="nav-item <?php echo $inLoadout ? 'active' : ''; ?>">
                <a href="<?php echo base_url('loadout/index.php'); ?>" class="nav-link" data-tutorial="nav-loadout">
                    <span class="sidebar-icon">
                        <i class="fas fa-dolly"></i>
                    </span>
                    <span class="sidebar-text">Load-out manifests</span>
                </a>
            </li>

            <?php
            $inSim = str_contains((string)($_SERVER['PHP_SELF'] ?? ''), '/sim/');
            $inPhoneReq = str_contains((string)($_SERVER['PHP_SELF'] ?? ''), '/phone-requests/');
            $telecomOpen = $inSim || $inPhoneReq;
            ?>
            <li class="nav-item">
                <span class="nav-link collapsed d-flex justify-content-between align-items-center <?php echo $telecomOpen ? 'active' : ''; ?>" data-bs-toggle="collapse" data-bs-target="#submenu-telecom" style="cursor:pointer;" data-tutorial="nav-telecom">
                    <span>
                        <span class="sidebar-icon"><i class="fas fa-sim-card"></i></span>
                        <span class="sidebar-text">Telecom</span>
                    </span>
                    <span class="link-arrow"><i class="fas fa-chevron-right"></i></span>
                </span>
                <div class="multi-level collapse <?php echo $telecomOpen ? 'show' : ''; ?>" id="submenu-telecom">
                    <ul class="flex-column nav">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $inSim ? 'active' : ''; ?>" href="<?php echo base_url('sim/index.php'); ?>">SIM registry</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $inPhoneReq ? 'active' : ''; ?>" href="<?php echo base_url('phone-requests/index.php'); ?>">Phone requests</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo base_url('it/index.php'); ?>">IT Helpdesk</a>
                        </li>
                    </ul>
                </div>
            </li>

            <li class="nav-item">
                <a href="<?php echo base_url('reports/index.php'); ?>" class="nav-link" data-tutorial="nav-reports">
                    <span class="sidebar-icon">
                        <i class="fas fa-chart-bar"></i>
                    </span>
                    <span class="sidebar-text">Reports</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="<?php echo base_url('reports/mutation-log.php'); ?>" class="nav-link <?php echo $current_page === 'mutation-log' ? 'active' : ''; ?>">
                    <span class="sidebar-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </span>
                    <span class="sidebar-text">Mutation log</span>
                </a>
            </li>

            <?php if (!am_is_auditor_readonly()): ?>
            <li class="nav-item">
                <a href="<?php echo base_url('tablet/index.php'); ?>" class="nav-link" data-tutorial="nav-tablet">
                    <span class="sidebar-icon">
                        <i class="fas fa-tablet-screen-button"></i>
                    </span>
                    <span class="sidebar-text">Tablet Mode</span>
                </a>
            </li>
            <?php endif; ?>

            <li class="nav-item mt-4 mb-1">
                <small class="nav-link text-gray-500 text-uppercase fw-bold py-1" style="font-size:0.7rem;letter-spacing:0.05em;">Switch Tool</small>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="https://pr.1pwrafrica.com/" target="_blank" rel="noopener">
                    <span class="sidebar-icon">
                        <i class="fas fa-file-invoice text-warning"></i>
                    </span>
                    <span class="sidebar-text">Procurement</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="http://prod.1pwrafrica.com/" target="_blank" rel="noopener">
                    <span class="sidebar-icon">
                        <i class="fas fa-clipboard-check text-info"></i>
                    </span>
                    <span class="sidebar-text">Job Cards</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="<?php echo base_url('index.php?tutorial=1'); ?>" class="nav-link" data-tutorial="nav-tutorial">
                    <span class="sidebar-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </span>
                    <span class="sidebar-text">Start tutorial</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page === 'tutorial' ? 'active' : ''; ?>">
                <a href="<?php echo base_url('tutorial.php'); ?>" class="nav-link ps-4">
                    <span class="sidebar-text small text-gray-400">Text guide</span>
                </a>
            </li>

            <li class="nav-item <?php echo $current_page === 'help' ? 'active' : ''; ?>">
                <a href="<?php echo base_url('help.php'); ?>" class="nav-link" data-tutorial="nav-help">
                    <span class="sidebar-icon">
                        <i class="fas fa-question-circle"></i>
                    </span>
                    <span class="sidebar-text">Help</span>
                </a>
            </li>

            <?php if (is_logged_in() && ($_SESSION['role'] ?? '') === 'Admin'): ?>
            <li class="nav-item">
                <span class="nav-link collapsed d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#submenu-admin">
                    <span>
                        <span class="sidebar-icon">
                            <i class="fas fa-cog"></i>
                        </span>
                        <span class="sidebar-text">Admin</span>
                    </span>
                    <span class="link-arrow">
                        <i class="fas fa-chevron-right"></i>
                    </span>
                </span>
                <div class="multi-level collapse" id="submenu-admin">
                    <ul class="flex-column nav">
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo base_url('admin/employees.php'); ?>">
                                <span class="sidebar-text">Employees</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo base_url('admin/locations.php'); ?>">
                                <span class="sidebar-text">Locations</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo base_url('admin/categories.php'); ?>">
                                <span class="sidebar-text">Categories</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo base_url('admin/duplicate-assets.php'); ?>">
                                <span class="sidebar-text">Duplicate assets</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo base_url('admin/ugp-parts.php'); ?>">
                                <span class="sidebar-text">UGP parts alignment</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo base_url('admin/qr-labels.php'); ?>">
                                <span class="sidebar-text">QR Labels</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo base_url('admin/migrate.php'); ?>">
                                <span class="sidebar-text">Data Migration</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo base_url('admin/provision-auditor.php'); ?>">
                                <span class="sidebar-text">Provision auditor</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>
