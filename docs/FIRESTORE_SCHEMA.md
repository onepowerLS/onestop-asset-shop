# Firestore Collection Schema

The AM application uses a single Firebase project (`pr-system-4ea55`) with two collection namespaces:

- **`am_core_*`** -- Owned by Asset Management, written by the AM app
- **`pr_master_*`** -- Owned by Procurement, treated as read-only reference data by AM

## Collections

### am_core_assets

Primary item catalog. Every physical item in the system lives here regardless of classification.

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Human-readable item name |
| `description` | string | no | Detailed description |
| `item_class` | string | yes | `FixedAsset`, `Material`, `Consumable`, or `Inventory` |
| `category_id` | string | no | Reference to `pr_master_categories` doc ID |
| `country_id` | string | yes | Reference to `pr_master_countries` doc ID |
| `location_id` | string | no | Reference to `pr_master_locations` doc ID |
| `asset_tag` | string | auto | Auto-generated: `1PWR-{CLASS}-{COUNTRY}-{PADDED}` |
| `qr_code_id` | string | no | Assigned via QR generation: `1PWR-{COUNTRY}-{CLASS}-{PADDED}` |
| `serial_number` | string | no | Manufacturer serial (primarily FixedAsset) |
| `manufacturer` | string | no | Item manufacturer |
| `model` | string | no | Model number |
| `purchase_date` | string | no | ISO date |
| `purchase_price` | number | no | Purchase price or unit cost |
| `salvage_value` | number | no | Residual value (FixedAsset only) |
| `warranty_expiry` | string | no | ISO date |
| `condition_status` | string | yes | `New`, `Good`, `Fair`, `Poor`, `Damaged`, `Retired` |
| `status` | string | yes | See status enum below |
| `quantity` | integer | yes | Default 1; bulk tracking for Material/Consumable/Inventory |
| `unit_of_measure` | string | yes | `EA`, `M`, `KG`, `L`, `BOX`, `ROLL`, `SET` |
| `legacy_tag` | string | no | Original item UID/tag from pre-migration system (read-only after import) |
| `source` | string | no | Migration origin (e.g. `AssetSpreadsheetDB`) |
| `notes` | string | no | Free-text notes |
| `created_at` | string | auto | ISO timestamp |
| `updated_at` | string | auto | ISO timestamp |
| `created_by` | string | auto | Firebase UID of creator |

**Status values:** `Available`, `Allocated`, `CheckedOut`, `InProject`, `Consumed`, `Deployed`, `Missing`, `WrittenOff`, `Retired`

### am_core_allocations

Tracks which items are checked out to which employees.

| Field | Type | Description |
|---|---|---|
| `asset_id` | string | Reference to `am_core_assets` doc ID |
| `employee_id` | string | Reference to employee |
| `allocated_by` | string | Firebase UID of person who performed allocation |
| `allocation_date` | string | ISO timestamp |
| `expected_return_date` | string | ISO date (optional) |
| `actual_return_date` | string | ISO timestamp (set on check-in) |
| `status` | string | `Active`, `Returned`, `Overdue` |
| `notes` | string | Free-text |

### am_core_transactions

Immutable audit trail for every action taken on an item.

| Field | Type | Description |
|---|---|---|
| `transaction_type` | string | See types below |
| `asset_id` | string | Reference to `am_core_assets` doc ID |
| `quantity` | integer | Number of items affected |
| `from_location_id` | string | Source location (optional) |
| `to_location_id` | string | Destination location (optional) |
| `employee_id` | string | Employee involved (optional) |
| `performed_by` | string | Firebase UID |
| `qr_code_scanned` | string | QR code that triggered the transaction (optional) |
| `device_type` | string | `Desktop`, `Tablet`, `Mobile` |
| `notes` | string | Free-text |
| `transaction_date` | string | ISO timestamp |

**Transaction types:** `CheckOut`, `CheckIn`, `StockIngestion`, `StockTake`, `Transfer`, `Allocation`, `Return`, `WriteOff`, `QRScan`, `Consume`, `Deploy`

### am_core_inventory_levels

Stock tracking per item per location. Used for reorder alerts.

| Field | Type | Description |
|---|---|---|
| `asset_id` | string | Reference to `am_core_assets` doc ID |
| `location_id` | string | Reference to location |
| `country_id` | string | Reference to country |
| `quantity_on_hand` | integer | Physical count |
| `quantity_allocated` | integer | Reserved/checked-out |
| `reorder_level` | integer | Alert threshold (optional) |
| `last_counted_at` | string | ISO timestamp |
| `last_counted_by` | string | Firebase UID |

### pr_master_categories

Reference data for item categories. AM reads these; Procurement manages them.

| Field | Type | Description |
|---|---|---|
| `category_code` | string | Short code: `FA-VEH`, `MAT-ELE`, etc. |
| `category_name` | string | Human name |
| `item_class` | string | `FixedAsset`, `Material`, `Consumable`, `Inventory` |
| `department_scope` | string | `RET`, `FAC`, `O&M`, `General`, `All` |
| `useful_life_years` | integer | Depreciation period (FixedAsset only) |
| `depreciation_method` | string | `None`, `StraightLine`, `DecliningBalance`, `UnitsOfProduction` |
| `reorder_enabled` | integer | 0 or 1 |
| `description` | string | Category description |
| `active` | integer | 0 or 1 |

### pr_master_countries

| Field | Type | Description |
|---|---|---|
| `country_id` | string | Numeric ID (legacy) |
| `country_code` | string | ISO 3-letter: `LSO`, `ZMB`, `BEN` |
| `country_name` | string | Full name |
| `active` | integer | 0 or 1 |

### pr_master_locations

| Field | Type | Description |
|---|---|---|
| `location_id` | string | Numeric ID (legacy) |
| `location_code` | string | Hierarchical code: `LSO-MAS-001` |
| `location_name` | string | Full name |
| `location_type` | string | `Country`, `Region`, `Site`, `Building`, `Room`, `Cabinet`, `Other` |
| `country_id` | string | Reference to country |
| `parent_location_id` | string | Parent for hierarchy (optional) |
| `active` | integer | 0 or 1 |

### pr_master_requests

| Field | Type | Description |
|---|---|---|
| `request_number` | string | Auto-generated: `REQ-2026-0001` |
| `item_class` | string | What class of item is being requested |
| `department_scope` | string | Requesting department context |
| `requested_by` | string | Firebase UID |
| `requested_for_country` | string | Country reference |
| `requested_for_location` | string | Location reference (optional) |
| `priority` | string | `Low`, `Normal`, `High`, `Urgent` |
| `status` | string | `Draft`, `Submitted`, `Approved`, `Rejected`, `Fulfilled`, `Cancelled` |
| `description` | string | What is being requested |
| `requested_date` | string | ISO timestamp |
| `required_date` | string | ISO date |
| `fulfilled_date` | string | ISO timestamp |
| `notes` | string | Free-text |

### users

Shared Firebase Auth user profiles (owned by PR system).

| Field | Type | Description |
|---|---|---|
| `firstName` | string | First name |
| `lastName` | string | Last name |
| `role` | string | PR role name |
| `permissionLevel` | integer | 1-6 |
| `department` | string | Department name |
| `organization` | string | Organization |
| `isActive` | boolean | Active flag |

AM maps PR roles to AM roles: `Admin`, `Manager`, `Viewer` (see `am_map_pr_role_to_am()` in `web/config/firebase.php`).

## Firestore PHP API

All write operations use helpers in `web/config/firestore.php`:

```php
// Create a document (auto-generated ID)
$result = am_firestore_create_document('am_core_assets', $data);
// $result = ['ok' => true, 'id' => 'abc123', 'data' => [...]]

// Create with explicit ID
$result = am_firestore_create_document('am_core_assets', $data, 'my-doc-id');

// Read a single document
$doc = am_firestore_get_document('am_core_assets', 'abc123');

// Read entire collection
$docs = am_firestore_get_collection('am_core_assets', 2000);

// Update specific fields
$result = am_firestore_update_document('am_core_assets', 'abc123', [
    'status' => 'CheckedOut',
    'updated_at' => date('c'),
]);

// Delete
$result = am_firestore_delete_document('am_core_assets', 'abc123');
```

All functions require a valid Firebase ID token in `$_SESSION['firebase_id_token']`.
