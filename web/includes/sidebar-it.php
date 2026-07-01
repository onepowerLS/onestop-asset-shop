<?php
/**
 * IT helpdesk sidebar (use with $am_sidebar_file from web/it/* pages).
 */
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/it_am.php';
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<nav id="sidebarMenu" class="sidebar d-lg-block bg-gray-800 text-white collapse" data-simplebar>
    <div class="sidebar-inner px-4 pt-3">
        <div class="user-card d-flex d-md-none align-items-center justify-content-between justify-content-md-center pb-4">
            <div class="d-flex align-items-center">
                <div class="avatar-lg me-4">
                    <i class="fas fa-headset fa-3x text-gray-300"></i>
                </div>
                <div class="d-block">
                    <?php if (is_logged_in()): ?>
                        <h2 class="h5 mb-3">Hi, <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></h2>
                        <a href="<?php echo base_url('logout.php'); ?>" class="btn btn-secondary btn-sm d-inline-flex align-items-center">
                            <i class="fas fa-sign-out-alt me-1"></i> Sign Out
                        </a>
                    <?php else: ?>
                        <a href="<?php echo base_url('login.php'); ?>" class="btn btn-secondary btn-sm">Sign In</a>
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
                <a href="<?php echo htmlspecialchars(am_it_url('index.php')); ?>" class="nav-link d-flex align-items-center">
                    <span class="sidebar-icon" style="font-weight:700;font-size:1.4rem;color:#7c3aed;">1PWR</span>
                    <span class="sidebar-text ms-1" style="font-weight:500;">IT Helpdesk</span>
                </a>
            </li>

            <li class="nav-item <?php echo $current_page === 'index' ? 'active' : ''; ?>">
                <a href="<?php echo htmlspecialchars(am_it_url('index.php')); ?>" class="nav-link">
                    <span class="sidebar-icon"><i class="fas fa-gauge-high"></i></span>
                    <span class="sidebar-text">Dashboard</span>
                </a>
            </li>

            <li class="nav-item <?php echo $current_page === 'tickets' ? 'active' : ''; ?>">
                <a href="<?php echo htmlspecialchars(am_it_url('tickets.php')); ?>" class="nav-link">
                    <span class="sidebar-icon"><i class="fas fa-ticket-alt"></i></span>
                    <span class="sidebar-text">Tickets</span>
                </a>
            </li>

            <li class="nav-item <?php echo $current_page === 'ticket-new' ? 'active' : ''; ?>">
                <a href="<?php echo htmlspecialchars(am_it_url('ticket-new.php')); ?>" class="nav-link">
                    <span class="sidebar-icon"><i class="fas fa-plus-circle"></i></span>
                    <span class="sidebar-text">New ticket</span>
                </a>
            </li>

            <li class="nav-item mt-4 mb-1">
                <small class="nav-link text-gray-500 text-uppercase fw-bold py-1" style="font-size:0.7rem;">Asset tools</small>
            </li>
            <li class="nav-item">
                <a href="<?php echo base_url('sim/index.php'); ?>" class="nav-link">
                    <span class="sidebar-icon"><i class="fas fa-sim-card"></i></span>
                    <span class="sidebar-text">SIM registry</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="<?php echo base_url('phone-requests/index.php'); ?>" class="nav-link">
                    <span class="sidebar-icon"><i class="fas fa-mobile-screen"></i></span>
                    <span class="sidebar-text">Phone requests</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="<?php echo base_url('index.php'); ?>" class="nav-link">
                    <span class="sidebar-icon"><i class="fas fa-arrow-left"></i></span>
                    <span class="sidebar-text">Asset Management</span>
                </a>
            </li>
        </ul>
    </div>
</nav>
