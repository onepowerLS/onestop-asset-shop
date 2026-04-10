<?php
/**
 * Footer Template
 */
?>
        </main>

        <!-- Footer -->
        <footer class="bg-white rounded shadow p-5 mb-4 mt-4">
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
    </div>

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
    
    <!-- SweetAlert2 + Volt (npm package has no dist/js/volt.min.js — use published src/assets/js/volt.js) -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/@themesberg/volt-bootstrap-5-dashboard@1.4.1/src/assets/js/volt.js"></script>
</body>
</html>
