<?php
/**
 * Header Template
 * 
 * Includes Volt Dashboard CSS and sets up the page structure
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/locale.php';
require_once __DIR__ . '/../config/version.php';
if (function_exists('is_logged_in') && is_logged_in()) {
    require_once __DIR__ . '/../config/country_scope.php';
    am_ensure_country_scope_from_session();
}
am_locale_bootstrap();
$page_title = $page_title ?? 'OneStop Asset Shop';
$html_lang = $page_lang ?? am_session_lang();
$app_name_display = $app_name_display ?? APP_NAME;
$am_build = am_app_version();

$am_firestore_reauth = false;
if (function_exists('is_logged_in') && is_logged_in() && !empty($_SESSION['am_firestore_reauth'])) {
    $am_firestore_reauth = true;
    unset($_SESSION['am_firestore_reauth']);
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($html_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="am-build-commit" content="<?php echo htmlspecialchars($am_build['commit']); ?>">
    <meta name="am-build-short" content="<?php echo htmlspecialchars($am_build['short']); ?>">
    <meta name="am-build-branch" content="<?php echo htmlspecialchars($am_build['branch']); ?>">
    <meta name="am-build-at" content="<?php echo htmlspecialchars($am_build['built_at']); ?>">
    <meta name="am-build-source" content="<?php echo htmlspecialchars($am_build['source']); ?>">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars($app_name_display); ?></title>
    <script>
    // Build / deploy identification (for cross-checking what the browser is running)
    window.AM_BUILD = <?php echo json_encode($am_build, JSON_UNESCAPED_SLASHES); ?>;
    (function(){
        try {
            var b = window.AM_BUILD || {};
            var css = 'color:#fff;background:#1976d2;padding:2px 6px;border-radius:3px;font-weight:600;';
            console.log('%cAM build%c %s  branch=%s  built=%s  src=%s',
                css, '',
                (b.short || '?') + ' (' + (b.commit || '?').slice(0,12) + ')',
                b.branch || '?',
                b.built_at || '?',
                b.source || '?');
        } catch (e) {}
    })();
    </script>
    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='24' font-size='24' font-weight='bold' fill='%231976d2'>1P</text></svg>">
    
    <!-- Base CSS (Volt SCSS is source-only on npm; dist/css/volt.min.css is not published — Bootstrap + am-layout) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/am-layout.css'); ?>" rel="stylesheet">
    
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
    </style>
</head>
<body<?php echo !empty($am_firestore_reauth) ? ' data-am-firestore-reauth="1"' : ''; ?>>
    <?php
    if (!empty($am_firestore_reauth)) {
        require_once __DIR__ . '/../config/firebase.php';
        $amFbReauth = am_firebase_config();
    ?>
    <script type="module">
    import { initializeApp } from 'https://www.gstatic.com/firebasejs/11.3.0/firebase-app.js';
    import { getAuth, onAuthStateChanged } from 'https://www.gstatic.com/firebasejs/11.3.0/firebase-auth.js';
    const firebaseConfig = {
        apiKey: <?php echo json_encode($amFbReauth['api_key'] ?? '', JSON_UNESCAPED_SLASHES); ?>,
        authDomain: 'pr-system-4ea55.firebaseapp.com',
        projectId: <?php echo json_encode($amFbReauth['project_id'] ?? '', JSON_UNESCAPED_SLASHES); ?>,
        appId: '1:562987209098:web:2f788d189f1c0867cb3873'
    };
    const app = initializeApp(firebaseConfig);
    const auth = getAuth(app);
    const k = 'am_fs_reauth_done';
    onAuthStateChanged(auth, async function (user) {
        if (!user) return;
        if (sessionStorage.getItem(k) === '1') {
            return;
        }
        sessionStorage.setItem(k, '1');
        try {
            const idToken = await user.getIdToken(true);
            const r = await fetch(<?php echo json_encode(base_url('auth/refresh-session.php')); ?>, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_token: idToken }),
                credentials: 'same-origin'
            });
            if (r.ok) {
                location.reload();
            }
        } catch (e) {}
    });
    </script>
    <?php } ?>
    <!-- QR Scanner Input (hidden, for HID scanner) -->
    <input type="text" id="qr-scanner-input" class="qr-scanner-input" autocomplete="off" tabindex="-1">
    
    <!-- Scan Mode Indicator -->
    <div id="scan-mode-indicator" class="scan-mode-indicator">
        <i class="fas fa-qrcode me-2"></i>
        <span id="scan-mode-text">Scanning Mode</span>
    </div>

    <nav class="navbar navbar-dark navbar-theme-primary px-4 col-12 d-lg-none">
        <a class="navbar-brand me-lg-5" href="<?php echo base_url(isset($am_nav_home) ? $am_nav_home : ''); ?>">
            <span style="font-weight:700;font-size:1.1rem;color:#fff;">1PWR</span> <span style="font-weight:400;color:rgba(255,255,255,0.8);"><?php echo htmlspecialchars($am_brand_suffix ?? 'AM'); ?></span>
        </a>
        <div class="d-flex align-items-center">
            <button class="navbar-toggler d-lg-none collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
    </nav>

    <?php include $am_sidebar_file ?? (__DIR__ . '/sidebar.php'); ?>

    <main class="content" data-tutorial="main-content">
        <?php include __DIR__ . '/topbar.php'; ?>
