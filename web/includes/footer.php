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

    <!-- Core JS -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
    
    <!-- Simplebar (for sidebar scrolling) -->
    <script src="https://cdn.jsdelivr.net/npm/simplebar@latest/dist/simplebar.min.js"></script>
    
    <!-- DataTables (jQuery already loaded in header) -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- QR Code Scanner (Simple HID Scanner Support) -->
    <script>
        // Simple QR scanner for HID barcode scanners
        (function() {
            const qrInput = document.getElementById('qr-scanner-input');
            if (!qrInput) return;
            
            let scanBuffer = '';
            let scanTimeout;
            
            qrInput.addEventListener('keydown', function(e) {
                // Clear buffer on Enter
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (scanBuffer.trim()) {
                        const qrCode = scanBuffer.trim();
                        scanBuffer = '';
                        // Redirect to asset view
                        window.location.href = '<?php echo base_url('assets/view.php'); ?>?qr=' + encodeURIComponent(qrCode);
                    }
                } else if (e.key.length === 1) {
                    // Add character to buffer
                    scanBuffer += e.key;
                    // Reset timeout
                    clearTimeout(scanTimeout);
                    scanTimeout = setTimeout(function() {
                        scanBuffer = '';
                    }, 1000);
                }
            });
            
            // Focus the input when page loads (for HID scanners)
            qrInput.focus();
        })();
    </script>
    
    <!-- Volt Dashboard JS -->
    <script src="https://cdn.jsdelivr.net/npm/@themesberg/volt-bootstrap-5-dashboard@1.4.2/dist/js/volt.min.js"></script>
</body>
</html>
