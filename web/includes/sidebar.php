<?php
/**
 * Sidebar Navigation
 */
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
                    <span class="sidebar-icon">
                        <strong class="text-white">1PWR</strong>
                    </span>
                </a>
            </li>

            <li class="nav-item <?php echo $current_page === 'index' || $current_page === 'dashboard' ? 'active' : ''; ?>">
                <a href="<?php echo base_url('index.php'); ?>" class="nav-link">
                    <span class="sidebar-icon">
                        <i class="fas fa-home"></i>
                    </span>
                    <span class="sidebar-text">Dashboard</span>
                </a>
            </li>

            <li class="nav-item <?php echo $current_page === 'assets' ? 'active' : ''; ?>">
                <a href="<?php echo base_url('assets/index.php'); ?>" class="nav-link">
                    <span class="sidebar-icon">
                        <i class="fas fa-box"></i>
                    </span>
                    <span class="sidebar-text">Assets</span>
                </a>
            </li>

            <li class="nav-item <?php echo $current_page === 'requests' ? 'active' : ''; ?>">
                <a href="<?php echo base_url('requests/index.php'); ?>" class="nav-link">
                    <span class="sidebar-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </span>
                    <span class="sidebar-text">Requests</span>
                </a>
            </li>

            <li class="nav-item <?php echo $current_page === 'inventory' ? 'active' : ''; ?>">
                <a href="<?php echo base_url('inventory/index.php'); ?>" class="nav-link">
                    <span class="sidebar-icon">
                        <i class="fas fa-warehouse"></i>
                    </span>
                    <span class="sidebar-text">Inventory</span>
                </a>
            </li>

            <li class="nav-item <?php echo $current_page === 'checkout' ? 'active' : ''; ?>">
                <a href="<?php echo base_url('checkout/index.php'); ?>" class="nav-link">
                    <span class="sidebar-icon">
                        <i class="fas fa-hand-holding"></i>
                    </span>
                    <span class="sidebar-text">Check-Out/In</span>
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
                            <a class="nav-link" href="<?php echo base_url('admin/qr-labels.php'); ?>">
                                <span class="sidebar-text">QR Labels</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>
