<?php
/**
 * Top Navigation Bar
 */
?>
<nav class="navbar navbar-top navbar-expand navbar-dark bg-primary border-bottom">
    <div class="container-fluid">
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
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
