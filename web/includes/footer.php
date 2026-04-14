<?php
/**
 * Footer Template
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firebase.php';
?>
        <!-- Footer stays inside <main> so body flex is only sidebar | main (not a third column) -->
        <footer class="bg-white rounded shadow p-4 mt-auto mb-4 mx-3">
            <div class="row">
                <div class="col-12 col-md-4 col-xl-6 mb-4 mb-md-0">
                    <p class="mb-0 text-center text-md-start">
                        © <?php echo date('Y'); ?> - <span class="fw-bold"><?php echo APP_NAME; ?></span>. All rights reserved.
                    </p>
                </div>
                <div class="col-12 col-md-8 col-xl-6 text-center text-md-end">
                    <p class="mb-0">
                        <a href="https://1pwrafrica.com" class="text-primary fw-bold" target="_blank">OnePower Africa</a> - 
                        Asset Management System v<?php echo APP_VERSION; ?>
                    </p>
                </div>
            </div>
        </footer>
        </main>

    <!-- jQuery must be first — DataTables and inline scripts expect window.$ / jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <!-- Core JS -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/simplebar@6.2.5/dist/simplebar.min.js"></script>
    <!-- DataTables (after jQuery) -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- QR Code Scanner -->
    <script src="<?php echo base_url('qr/scanner.js'); ?>"></script>
    
    <!-- Initialize QR Scanner -->
    <script>
        // Initialize QR scanner on page load
        document.addEventListener('DOMContentLoaded', function() {
            const scanner = new QRScanner({
                onScan: async function(qrCode) {
                    // Default behavior: redirect to asset page
                    window.location.href = '<?php echo base_url('assets/view.php'); ?>?qr=' + encodeURIComponent(qrCode);
                },
                scanDelay: 100
            });
            scanner.init();
            window.qrScanner = scanner; // Make available globally
        });
    </script>
    
<?php if (is_logged_in()) { include __DIR__ . '/tutorial_scripts.php'; } ?>

    <!-- Volt peer deps (volt.js expects these globals — not bundled in the npm src file) -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/smooth-scroll@16.1.3/dist/smooth-scroll.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@themesberg/volt-bootstrap-5-dashboard@1.4.1/src/assets/js/volt.js"></script>

<?php if (is_logged_in()) :
    $amFb = am_firebase_config();
?>
    <!-- Refresh Firebase ID token for PHP → Firestore (token expires ~1h; session alone goes stale) -->
    <script type="module">
    import { initializeApp } from 'https://www.gstatic.com/firebasejs/11.3.0/firebase-app.js';
    import { getAuth, onAuthStateChanged } from 'https://www.gstatic.com/firebasejs/11.3.0/firebase-auth.js';

    const firebaseConfig = {
        apiKey: <?php echo json_encode($amFb['api_key'], JSON_UNESCAPED_SLASHES); ?>,
        authDomain: 'pr-system-4ea55.firebaseapp.com',
        projectId: <?php echo json_encode($amFb['project_id'], JSON_UNESCAPED_SLASHES); ?>,
        appId: '1:562987209098:web:2f788d189f1c0867cb3873'
    };

    const app = initializeApp(firebaseConfig);
    const auth = getAuth(app);

    onAuthStateChanged(auth, async function (user) {
        if (!user) return;
        try {
            var idToken = await user.getIdToken(true);
            await fetch(<?php echo json_encode(base_url('auth/refresh-session.php')); ?>, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_token: idToken }),
                credentials: 'same-origin'
            });
        } catch (e) {
            console.warn('AM: could not refresh session token for Firestore', e);
        }
    });
    </script>
<?php endif; ?>
</body>
</html>
