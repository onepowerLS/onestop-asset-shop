# QR Code Integration

## Hardware

- **Scanner**: Symcode 2D Wireless Barcode Scanner (USB/Bluetooth)
  - Acts as HID (Human Interface Device) - simulates keyboard input
  - Works with any text input field
  
- **Printer**: Brother P-touch CUBE Plus (PT-P710BT)
  - Bluetooth-enabled label printer
  - Supports various label sizes

## QR Code Format

**Structure**: `1PWR-{COUNTRY}-{ASSET_ID}`

**Examples:**
- `1PWR-LSO-000123` (Lesotho, Asset ID 123)
- `1PWR-ZMB-000456` (Zambia, Asset ID 456)
- `1PWR-BEN-000789` (Benin, Asset ID 789)

**Alternative (for shorter codes)**: `{COUNTRY}{ASSET_ID}` → `LSO123`, `ZMB456`

## Implementation

### 1. QR Code Generation (Backend)

- Generate unique QR code ID when asset is created
- Store in `assets.qr_code_id` field
- Create entry in `qr_labels` table when label is printed

### 2. QR Code Printing (Frontend)

- "Print Label" button on asset detail page
- Generate QR code image using library (e.g., `qrcode.js`)
- Send to Brother printer via:
  - **Option A**: Browser Print API (if printer supports)
  - **Option B**: Backend API that generates PDF/Image for download
  - **Option C**: Bluetooth Web API (experimental, may require app wrapper)

### 3. QR Code Scanning (Frontend)

- **Global Scan Listener**: Listen for keyboard input in a hidden input field
- When scanner reads QR code, it types the code + Enter
- JavaScript detects the input and triggers asset lookup
- Open asset detail page or perform action (check-in/out)

### 4. Tablet Workflow

- **Stock Ingestion**: Scan QR → Auto-fill asset details → Enter quantity
- **Check-out**: Scan QR → Select employee → Confirm
- **Check-in**: Scan QR → Auto-return → Confirm
- **Stock Taking**: Scan QR → Enter counted quantity → Next item

## Files

- `qr/generator.php` - Backend QR code generation
- `qr/scanner.js` - Frontend scanning logic
- `qr/printer.js` - Label printing interface
- `qr/tablet-mode.js` - Tablet-optimized scanning UI
