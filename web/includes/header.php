<?php
/**
 * Header Template
 */
require_once __DIR__ . '/../config/app.php';
$page_title = $page_title ?? 'OneStop Asset Shop';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo base_url('assets/img/favicon/favicon-32x32.png'); ?>">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Volt Dashboard CSS -->
    <link href="https://cdn.jsdelivr.net/npm/@themesberg/volt-bootstrap-5-dashboard@1.4.2/dist/css/volt.min.css" rel="stylesheet">
    
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- QR Code Scanner (hidden input for HID scanner) -->
    <style>
        .qr-scanner-input {
            position: absolute;
            left: -9999px;
            opacity: 0;
            pointer-events: none;
        }
        .scan-mode-indicator {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #1f2937;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            z-index: 1000;
            display: none;
        }
        .scan-mode-indicator.active {
            display: block;
        }
        /* Ensure sidebar is fixed on the left */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
        }
        /* Ensure content area accounts for sidebar */
        .content {
            margin-left: 0;
        }
        @media (min-width: 992px) {
            .content {
                margin-left: 280px;
            }
        }
        /* Compact topbar */
        .navbar-top {
            min-height: 56px;
        }
        /* Logo styling in sidebar */
        .sidebar .nav-link img {
            max-width: 100%;
            height: auto;
        }
    </style>
</head>
<body>
    <!-- QR Scanner Input (hidden, for HID scanner) -->
    <input type="text" id="qr-scanner-input" class="qr-scanner-input" autocomplete="off" tabindex="-1">
    
    <!-- Scan Mode Indicator -->
    <div id="scan-mode-indicator" class="scan-mode-indicator">
        <i class="fas fa-qrcode me-2"></i>
        <span id="scan-mode-text">Scanning Mode</span>
    </div>

    <nav class="navbar navbar-dark navbar-theme-primary px-4 col-12 d-lg-none">
        <a class="navbar-brand me-lg-5" href="<?php echo base_url(); ?>">
            <img src="<?php echo base_url('assets/img/brand/1pwr_logo.png'); ?>" alt="1PWR Logo" style="max-height: 30px; width: auto;" />
        </a>
        <div class="d-flex align-items-center">
            <button class="navbar-toggler d-lg-none collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
    </nav>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="content">
        <?php include __DIR__ . '/topbar.php'; ?>
