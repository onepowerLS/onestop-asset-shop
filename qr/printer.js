/**
 * Label Printer Interface for Brother P-touch CUBE Plus (PT-P710BT)
 * 
 * Handles QR code label generation and printing
 */

class LabelPrinter {
    constructor(options = {}) {
        this.apiEndpoint = options.apiEndpoint || '/api/qr/label-data';
        this.printEndpoint = options.printEndpoint || '/api/qr/print';
        this.printerModel = options.printerModel || 'Brother PT-P710BT';
    }
    
    /**
     * Generate label preview and print dialog
     */
    async generateLabel(assetId, userId = null) {
        try {
            // Fetch label data from backend
            const response = await fetch(`${this.apiEndpoint}?asset_id=${assetId}`);
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Failed to generate label data');
            }
            
            const labelData = result.data;
            
            // Show print preview/dialog
            this.showPrintDialog(labelData, assetId, userId);
            
        } catch (error) {
            console.error('Error generating label:', error);
            alert('Error generating label: ' + error.message);
        }
    }
    
    /**
     * Show print dialog with label preview
     */
    showPrintDialog(labelData, assetId, userId) {
        // Create modal/dialog
        const modal = document.createElement('div');
        modal.className = 'label-print-modal';
        modal.innerHTML = `
            <div class="label-print-content">
                <h3>Print QR Label</h3>
                <div class="label-preview">
                    <div class="qr-code-preview">
                        <img src="${labelData.qr_image_url}" alt="QR Code" />
                    </div>
                    <div class="label-info">
                        <p><strong>Asset Tag:</strong> ${labelData.asset_tag}</p>
                        <p><strong>Name:</strong> ${labelData.name}</p>
                        <p><strong>Serial:</strong> ${labelData.serial}</p>
                        <p><strong>Country:</strong> ${labelData.country}</p>
                        <p><strong>QR Code:</strong> ${labelData.qr_code}</p>
                    </div>
                </div>
                <div class="print-actions">
                    <button class="btn-print" onclick="labelPrinter.printLabel(${assetId}, ${userId || 'null'})">
                        Print Label
                    </button>
                    <button class="btn-download" onclick="labelPrinter.downloadLabel(${assetId})">
                        Download PDF
                    </button>
                    <button class="btn-cancel" onclick="labelPrinter.closePrintDialog()">
                        Cancel
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        this.currentModal = modal;
    }
    
    /**
     * Close print dialog
     */
    closePrintDialog() {
        if (this.currentModal) {
            this.currentModal.remove();
            this.currentModal = null;
        }
    }
    
    /**
     * Print label (sends to printer or downloads print file)
     */
    async printLabel(assetId, userId) {
        try {
            // Record print in database
            const printResponse = await fetch(this.printEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    asset_id: assetId,
                    user_id: userId,
                    printer_model: this.printerModel
                })
            });
            
            const printResult = await printResponse.json();
            
            if (!printResult.success) {
                throw new Error(printResult.error || 'Failed to record print');
            }
            
            // Generate print-ready file
            // Option 1: Use browser print API (if printer supports)
            await this.printViaBrowser(assetId);
            
            // Option 2: Download file for manual printing
            // await this.downloadLabel(assetId);
            
            this.closePrintDialog();
            alert('Label sent to printer!');
            
        } catch (error) {
            console.error('Error printing label:', error);
            alert('Error printing label: ' + error.message);
        }
    }
    
    /**
     * Print via browser Print API
     */
    async printViaBrowser(assetId) {
        // Fetch label HTML/PDF
        const response = await fetch(`/api/qr/label-print?asset_id=${assetId}&format=html`);
        const html = await response.text();
        
        // Create print window
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>QR Label - Asset ${assetId}</title>
                <style>
                    @media print {
                        @page { size: 24mm 12mm; margin: 0; }
                        body { margin: 0; padding: 2mm; font-size: 8pt; }
                    }
                    body { font-family: Arial, sans-serif; }
                    .label { width: 24mm; height: 12mm; border: 1px solid #000; padding: 2mm; }
                    .qr-code { float: left; width: 8mm; height: 8mm; }
                    .label-info { float: left; margin-left: 2mm; }
                    .label-info p { margin: 0; line-height: 1.2; }
                </style>
            </head>
            <body>
                ${html}
            </body>
            </html>
        `);
        printWindow.document.close();
        
        // Wait for content to load, then print
        printWindow.onload = () => {
            setTimeout(() => {
                printWindow.print();
                // Close window after printing (optional)
                // printWindow.close();
            }, 250);
        };
    }
    
    /**
     * Download label as PDF for manual printing
     */
    async downloadLabel(assetId) {
        try {
            const response = await fetch(`/api/qr/label-print?asset_id=${assetId}&format=pdf`);
            const blob = await response.blob();
            
            // Create download link
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `asset-${assetId}-label.pdf`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
        } catch (error) {
            console.error('Error downloading label:', error);
            alert('Error downloading label: ' + error.message);
        }
    }
    
    /**
     * Generate label HTML for printing
     */
    generateLabelHTML(labelData) {
        return `
            <div class="label">
                <div class="qr-code">
                    <img src="${labelData.qr_image_url}" alt="QR" style="width: 100%; height: 100%;" />
                </div>
                <div class="label-info">
                    <p><strong>${labelData.asset_tag}</strong></p>
                    <p>${labelData.name.substring(0, 20)}</p>
                    <p>${labelData.qr_code}</p>
                </div>
            </div>
        `;
    }
}

// Initialize global instance
const labelPrinter = new LabelPrinter();

// Export for modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = LabelPrinter;
}
