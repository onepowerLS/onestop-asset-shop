/**
 * QR Code Scanner Integration
 *
 * Handles scanning with Symcode 2D Wireless Barcode Scanner
 * Scanner acts as HID (keyboard input), so we listen for input events
 */

class QRScanner {
    constructor(options = {}) {
        this.scanBuffer = '';
        this.scanTimeout = null;
        this.onScanCallback = options.onScan || null;
        this.scanDelay = options.scanDelay || 100; // ms to wait for complete scan
        this.autoSubmit = options.autoSubmit !== false; // Scanner sends Enter after code
        this.isEnabled = false;

        // Hidden input field for capturing scanner input
        this.scanInput = null;
    }

    /**
     * Initialize scanner — prefer existing #qr-scanner-input from layout when present
     */
    init() {
        this.scanInput = document.getElementById('qr-scanner-input');
        if (!this.scanInput) {
            this.scanInput = document.createElement('input');
            this.scanInput.type = 'text';
            this.scanInput.style.position = 'absolute';
            this.scanInput.style.left = '-9999px';
            this.scanInput.style.opacity = '0';
            this.scanInput.setAttribute('autocomplete', 'off');
            this.scanInput.setAttribute('tabindex', '-1');
            document.body.appendChild(this.scanInput);
        }

        this.scanInput.addEventListener('input', (e) => this.handleInput(e));
        this.scanInput.addEventListener('keydown', (e) => this.handleKeyDown(e));

        window.addEventListener('load', () => {
            if (this.isEnabled && this.scanInput) {
                this.scanInput.focus();
            }
        });

        this.isEnabled = true;
        console.log('QR Scanner initialized');
    }

    /**
     * Enable scanning mode
     */
    enable() {
        this.isEnabled = true;
        if (this.scanInput) {
            this.scanInput.focus();
        }
    }

    /**
     * Disable scanning mode
     */
    disable() {
        this.isEnabled = false;
        if (this.scanInput) {
            this.scanInput.blur();
        }
    }

    /**
     * Handle input from scanner (or keyboard)
     */
    handleInput(e) {
        if (!this.isEnabled) return;

        const value = e.target.value;

        if (this.scanTimeout) {
            clearTimeout(this.scanTimeout);
        }

        if (this.isQRCodeFormat(value)) {
            this.scanTimeout = setTimeout(() => {
                this.processScan(value);
            }, this.scanDelay);
        }
    }

    /**
     * Handle keydown events (for Enter key from scanner)
     */
    handleKeyDown(e) {
        if (!this.isEnabled) return;

        if (e.key === 'Enter' && this.scanInput.value) {
            e.preventDefault();
            this.processScan(this.scanInput.value);
        }
    }

    /**
     * Check if string matches QR code format
     */
    isQRCodeFormat(str) {
        return /^(1PWR-[A-Z]{3}-\d{6}|[A-Z]{3}\d+)$/.test(str);
    }

    /**
     * Process scanned QR code
     */
    async processScan(qrCode) {
        if (!qrCode || !this.isQRCodeFormat(qrCode)) {
            console.warn('Invalid QR code format:', qrCode);
            return;
        }

        this.scanInput.value = '';

        console.log('QR Code scanned:', qrCode);

        if (this.onScanCallback) {
            try {
                await this.onScanCallback(qrCode);
            } catch (error) {
                console.error('Error in scan callback:', error);
                this.showError('Error processing scan: ' + error.message);
            }
        } else {
            this.lookupAsset(qrCode);
        }
    }

    /**
     * Lookup asset by QR code and redirect to asset page
     */
    async lookupAsset(qrCode) {
        try {
            const response = await fetch(`/api/assets/by-qr?qr_code=${encodeURIComponent(qrCode)}`);
            const data = await response.json();

            if (data.success && data.asset) {
                window.location.href = `/assets/view?id=${data.asset.asset_id}`;
            } else {
                this.showError('Asset not found for QR code: ' + qrCode);
            }
        } catch (error) {
            console.error('Error looking up asset:', error);
            this.showError('Error looking up asset');
        }
    }

    showError(message) {
        alert(message);
    }

    destroy() {
        this.disable();
        if (this.scanTimeout) {
            clearTimeout(this.scanTimeout);
        }
    }
}

class TabletQRScanner extends QRScanner {
    constructor(options = {}) {
        super(options);
        this.mode = options.mode || 'view';
        this.currentAction = null;
    }

    setMode(mode, actionCallback) {
        this.mode = mode;
        this.currentAction = actionCallback;
        this.updateModeIndicator(mode);
    }

    async processScan(qrCode) {
        if (!this.isEnabled) return;

        super.processScan(qrCode);

        switch (this.mode) {
            case 'checkout':
                await this.handleCheckout(qrCode);
                break;
            case 'checkin':
                await this.handleCheckin(qrCode);
                break;
            case 'stocktake':
                await this.handleStockTake(qrCode);
                break;
            default:
                await this.lookupAsset(qrCode);
        }
    }

    async handleCheckout(qrCode) {
        try {
            const response = await fetch(`/api/assets/by-qr?qr_code=${encodeURIComponent(qrCode)}`);
            const data = await response.json();

            if (data.success && data.asset) {
                if (this.currentAction) {
                    this.currentAction('checkout', data.asset);
                } else {
                    window.location.href = `/assets/checkout?asset_id=${data.asset.asset_id}`;
                }
            }
        } catch (error) {
            this.showError('Error during checkout: ' + error.message);
        }
    }

    async handleCheckin(qrCode) {
        try {
            const response = await fetch(`/api/assets/by-qr?qr_code=${encodeURIComponent(qrCode)}`);
            const data = await response.json();

            if (data.success && data.asset) {
                if (data.asset.status === 'Allocated' || data.asset.status === 'CheckedOut') {
                    const confirmReturn = confirm(`Return asset: ${data.asset.name}?`);
                    if (confirmReturn) {
                        await fetch(`/api/assets/checkin`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ asset_id: data.asset.asset_id })
                        });
                        this.showSuccess('Asset returned successfully');
                    }
                } else {
                    this.showError('Asset is not currently checked out');
                }
            }
        } catch (error) {
            this.showError('Error during check-in: ' + error.message);
        }
    }

    async handleStockTake(qrCode) {
        if (this.currentAction) {
            this.currentAction('stocktake', qrCode);
        }
    }

    updateModeIndicator(mode) {
        const indicator = document.getElementById('scan-mode-indicator');
        if (indicator) {
            const modeNames = {
                view: 'View Mode',
                checkout: 'Check-Out Mode',
                checkin: 'Check-In Mode',
                stocktake: 'Stock Take Mode'
            };
            indicator.textContent = modeNames[mode] || 'Scanning';
            indicator.className = `scan-mode-${mode}`;
        }
    }

    showSuccess(message) {
        console.log('Success:', message);
    }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { QRScanner, TabletQRScanner };
}

window.QRScanner = QRScanner;
window.TabletQRScanner = TabletQRScanner;
