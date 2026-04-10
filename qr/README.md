# QR Code Integration

## Hardware

- **Scanner**: Symcode 2D Wireless Barcode Scanner (USB/Bluetooth)
  - Acts as HID (Human Interface Device) - simulates keyboard input
  - Works with any text input field
  
- **Printer**: Brother P-touch CUBE Plus (PT-P710BT)
  - Bluetooth-enabled label printer
  - Supports various label sizes

## QR Code Format

**Structure**: `1PWR-{COUNTRY}-{CLASS_PREFIX}-{PADDED_SEQUENCE}`

The class prefix encodes the item classification:

| Item Class | Prefix |
|---|---|
| FixedAsset | `FA` |
| Material | `MT` |
| Consumable | `CO` |
| Inventory | `IN` |

**Examples:**
- `1PWR-LSO-FA-000012` -- Fixed Asset #12, Lesotho
- `1PWR-ZMB-MT-000045` -- Material #45, Zambia
- `1PWR-BEN-CO-000003` -- Consumable #3, Benin
- `1PWR-LSO-IN-000100` -- Inventory item #100, Lesotho

The sequence number is padded to 6 digits and is unique per country+class combination.

## Data Store

QR code IDs are stored in the `qr_code_id` field of the `am_core_assets` Firestore collection. Generation and assignment happen via the REST API endpoint.

## Generation API

**Endpoint**: `web/api/qr/generate.php`

**Method**: GET (requires authenticated session)

**Parameters**:
- `asset_id` (required) -- Firestore document ID of the asset
- `country_code` (optional) -- 3-letter ISO code; auto-detected from `pr_master_countries` if omitted

**Flow**:
1. Reads asset from `am_core_assets`
2. Resolves country code from `pr_master_countries`
3. Maps `item_class` to class prefix
4. Scans existing assets for highest sequence in that country+class
5. Assigns next sequence number
6. Writes `qr_code_id` back to the asset document
7. Returns JSON with `qr_code_id` and image URL from `api.qrserver.com`

## Admin Interface

**Page**: `web/admin/qr-labels.php`

Provides batch QR management:
- Coverage statistics (assigned vs pending)
- List of items without QR codes with one-click generation
- List of assigned QR codes with image previews
- Batch generation for all unassigned items

## Scanning

The hidden scan listener in the page footer captures HID scanner output:
1. Scanner reads QR code and types it as keyboard input
2. JavaScript detects the input pattern (`1PWR-...`) followed by Enter
3. Redirects to `assets/view.php?qr={scanned_code}`
4. Asset detail page loads by matching `qr_code_id` across `am_core_assets`

## Legacy Files

- `qr/generator.php` -- Original MySQL-based generator (superseded by `web/api/qr/generate.php`)
- `qr/scanner.js` -- Frontend scanning logic
- `qr/printer.js` -- Label printing interface (Brother PT-P710BT)
- `qr/tablet-mode.js` -- Tablet-optimized scanning UI
