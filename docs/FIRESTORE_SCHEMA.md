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
| `ugp_part_id` | string | no | Stable part id from **UGP** (`ugp.1pwrafrica.com`); canonical link for assembly/inventory alignment (see `docs/UGP_AM_PART_ALIGNMENT.md`) |
| `ugp_last_sync_at` | string | no | ISO timestamp when last synced from UGP |
| `source` | string | no | Migration origin (e.g. `AssetSpreadsheetDB`, `UGP`) |
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

### am_core_loadout_manifests

Load-out / packing manifests for items leaving HQ toward a field site. Optional `trip_id` ties a manifest to a trip in Fleet Management (`fm.1pwrafrica.com`). AM owns this collection; FM typically **reads** by `trip_id` or calls the AM HTTP API.

| Field | Type | Required | Description |
|---|---|---|---|
| `manifest_number` | string | yes | Human-readable id, e.g. `LO-2026-0001` |
| `title` | string | no | Short description |
| `status` | string | yes | `Draft`, `Packed`, `Shipped`, `Delivered`, `Cancelled` |
| `origin_label` | string | no | e.g. `HQ / Warehouse` |
| `destination_site_id` | string | no | Site id from `sites` / `referenceData_sites` (via AM site picker) |
| `destination_site_label` | string | no | Denormalized display label |
| `country_id` | string | no | `pr_master_countries` id |
| `trip_id` | string | no | Fleet trip document id (set from FM or AM UI) |
| `trip_label` | string | no | Optional display name for the trip |
| `lines` | array | no | Line items: `line_no`, `asset_id`, `quantity`, `notes`, `name_snapshot`, `tag_snapshot` |
| `notes` | string | no | Manifest-level notes |
| `source_system` | string | no | e.g. `am.1pwrafrica.com` |
| `linked_from_fm` | boolean | no | Set when linked via FM API |
| `created_at` | string | auto | ISO timestamp |
| `updated_at` | string | auto | ISO timestamp |
| `created_by` | string | auto | Firebase UID |
| `updated_by` | string | auto | Firebase UID |

**HTTP API (AM):** `GET/POST https://am.1pwrafrica.com/api/loadout-manifests/index.php` — authenticate with `Authorization: Bearer <Firebase ID token>` (same project as AM), or `api_key` + `FIREBASE_ADMIN_BEARER_TOKEN` for server jobs. See `web/api/loadout-manifests/index.php`.

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
| `capabilities` | map | Optional flags for fine-grained AM/IT actions (see below) |
| `amCountryAccess` | array of strings | **AM:** ISO-style country codes the user may operate in: `LSO`, `ZMB`, `BEN`. If omitted or empty, non-Admin users have **no** AM country access until set. **Admin** mapped from PR (`permissionLevel` / role) defaults to all three in AM when this field is empty. Nexus-shaped profiles may use `systemAccess.am.countryAccess` instead (same codes). |

**Capabilities (optional, boolean values):** `sim_team_assign` (Finance — assign SIM to teams/pools), `sim_phone_link` (IT — link SIM to handset assets), `it_queue_manage`, `am_ops_queue_manage`. Admins retain full access regardless. Set in Firestore on `users/{uid}`; loaded into PHP session at login.

**Country scope (AM UI):** Session stores allowed codes plus an optional **filter** (all permitted countries vs one country). See `docs/COUNTRY_AND_LANGUAGE.md`. **Procurement** (`pr.1pwrafrica.com`) country rules are separate; mirror the same `amCountryAccess` / org policy there if users should align across tools.

### am_core_sim_cards

SIM registry (telecom). UI: `web/sim/`.

| Field | Type | Description |
|---|---|---|
| `msisdn_normalized` | string | Digits-only MSISDN (search key) |
| `msisdn_display` | string | Raw entry / display |
| `contact_value` | string | Plan, top-up, or billing notes |
| `pool` | string | Source bucket (e.g. workbook tab name) |
| `sim_location` | string | Site / location label |
| `person_assigned` | string | Person or team description |
| `status` | string | `Active`, `Suspended`, `Deactivated`, `Lost`, `Unknown` |
| `locate_status` | string | Locate / could not locate |
| `notes` | string | Free text |
| `created_at`, `updated_at` | string | ISO timestamps |
| `created_by`, `updated_by` | string | Firebase UIDs |

### am_core_sim_assignments

Time-stamped SIM assignments. `assignment_type` drives Firestore rules (team vs phone asset).

| Field | Type | Description |
|---|---|---|
| `sim_id` | string | `am_core_sim_cards` document id |
| `assignment_type` | string | `team`, `phone_asset`, `vehicle_tracker`, `site_gateway`, `other` |
| `team_label` | string | For `team` |
| `asset_id` | string | For `phone_asset` — `am_core_assets` id |
| `site_label` | string | Optional |
| `notes` | string | Optional |
| `valid_from` | string | ISO timestamp |
| `valid_to` | string | Optional; empty = current |
| `assigned_by` | string | Firebase UID |
| `created_at` | string | ISO timestamp |

### am_core_phone_requests

Requests for new phones (procurement / IT fulfillment). UI: `web/phone-requests/`.

| Field | Type | Description |
|---|---|---|
| `request_number` | string | e.g. `PHR-2026-0001` |
| `justification` | string | Required |
| `country_id` | string | `pr_master_countries` ref |
| `status` | string | `Submitted`, `Approved`, `Rejected`, `Fulfilled`, `Cancelled` |
| `requested_by` | string | Firebase UID |
| `requested_by_name` | string | Display |
| `requested_at` | string | ISO timestamp |
| `notes` | string | Optional |
| `fulfilled_notes` | string | Manager |

### it_support_tickets

IT vs AM operations helpdesk. UI: `web/it/` (same app; can be served at `it.1pwrafrica.com`). Legacy Google Form rows can be imported with `source` = `import_google_form_v1`.

| Field | Type | Description |
|---|---|---|
| `ticket_number` | string | e.g. `IT-2026-0001` |
| `queue` | string | `it` or `am_operations` |
| `title`, `description` | string | |
| `status` | string | `Open`, `InProgress`, `Resolved`, `Closed`, `Cancelled` |
| `priority` | string | `Low`, `Normal`, `High`, `Urgent` |
| `requester_uid`, `requester_name` | string | |
| `assignee_uid`, `assignee_name` | string | Optional |
| `vehicle_related` | boolean | Flag only; vehicles handled in FM |
| `source` | string | `web`, `import_google_form_v1`, etc. |
| `legacy_import_id` | string | Optional stable id from import |
| `linked_asset_ids` | array | Optional asset ids |
| `linked_sim_id` | string | Optional |
| `comments` | array | Maps: `author_uid`, `author_name`, `body`, `created_at` |
| `created_at`, `updated_at` | string | ISO timestamps |

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
