# Database Migration Guide

## Overview

This guide explains how to migrate data from:
- **Old System**: `npower5_asset_management` database
- **Google Sheets**: RET Materials, FAC Items, O&M Database, Meters, Ready Boards, etc.
- **Dropbox Data Sources**: Excel spreadsheets and Access databases (see [DATA_SOURCES_ASSESSMENT.md](DATA_SOURCES_ASSESSMENT.md))
- **Into**: New consolidated `onestop_asset_shop` schema

> **Note**: The original migration source was found to be corrupt. See [DATA_SOURCES_ASSESSMENT.md](DATA_SOURCES_ASSESSMENT.md) for a comprehensive assessment of alternative data sources identified in January 2026.

## Key Improvements

1. **Multi-Country Support**: Every asset/transaction now tracks which country (Lesotho, Zambia, Benin)
2. **QR Code Integration**: New `qr_code_id` field and `qr_labels` table
3. **Unified Requests**: All Google Form requests consolidated into `requests` + `request_items`
4. **Stock Levels**: Real-time inventory tracking per location/country
5. **Audit Trail**: Complete transaction history with device type (Desktop/Tablet/Mobile)

## Migration Mapping

### 1. Assets Table

**Old → New Mapping:**
```sql
-- Old assets table → New assets table
asset_id          → asset_id (same)
name              → name (same)
description       → description (same)
serial_number     → serial_number (same)
Manufacturer      → manufacturer (same)
Model             → model (same)
PurchasePrice     → purchase_price (same)
CurrentValue      → current_value (same)
purchase_date     → purchase_date (same)
warranty_expiry   → warranty_expiry (same)
ConditionStatus   → condition_status (mapped to enum)
status            → status (mapped to new enum)
location          → location_id (lookup in locations table)
category_id       → category_id (same, but categories consolidated)
NewTagNumber      → asset_tag (same)
OldTagNumber      → (stored in notes or deprecated)
Quantity          → quantity (same)
```

**New Fields to Populate:**
- `item_class`: Determined from category's `item_class` (see Section 2 mapping)
- `qr_code_id`: Generate unique IDs (e.g., `1PWR-LSO-FA-001234`)
- `country_id`: Determine from location or default to Lesotho (LSO)
- `salvage_value`: Set for Fixed Assets only (typically 5-10% of purchase_price)

### 2. Categories & Item Classification

The old `category_type` enum has been replaced by a 4-tier model:

**Legacy category_type -> New item_class + department_scope mapping:**

| Old category_type | New item_class | department_scope | Rationale |
|---|---|---|---|
| RET | Material | RET | Construction inputs for mini-grid builds |
| FAC | Material | FAC | Building/facilities construction inputs |
| O&M | Material or Consumable | O&M | If durable component -> Material; if consumable supply -> Consumable |
| General | (varies) | General | Review each item individually |
| Meters | Inventory | General | Finished goods for customer deployment |
| ReadyBoards | Inventory | General | Finished goods for customer deployment |
| Tools | FixedAsset or Consumable | All | If purchase_price >= $200 -> FixedAsset; else -> Consumable |
| Other | (manual review) | General | Classify per decision tree in SOP |

**Migration SQL Example:**
```sql
-- Step 1: Migrate RET categories to Materials
INSERT INTO categories (category_code, category_name, item_class, department_scope)
SELECT 
    CONCAT('MAT-', LPAD(category_id, 3, '0')) as category_code,
    name as category_name,
    'Material' as item_class,
    'RET' as department_scope
FROM old_categories 
WHERE category_type = 'RET';

-- Step 2: Migrate Meters to Inventory
INSERT INTO categories (category_code, category_name, item_class, department_scope)
SELECT 
    CONCAT('INV-', LPAD(category_id, 3, '0')) as category_code,
    name as category_name,
    'Inventory' as item_class,
    'General' as department_scope
FROM old_categories 
WHERE category_type = 'Meters';

-- Step 3: Backfill item_class on assets from their category
UPDATE assets a
JOIN categories c ON a.category_id = c.category_id
SET a.item_class = c.item_class;
```

See [`docs/SOP-ITEM-CLASSIFICATION.md`](../docs/SOP-ITEM-CLASSIFICATION.md) for the full decision tree and edge cases.

### 3. Locations & Countries

**Old `location` (varchar) → New `locations` table:**

1. Extract unique locations from old `assets.location` field
2. Determine country from location name patterns:
   - Contains "Lesotho" or "LSO" → Lesotho
   - Contains "Zambia" or "ZMB" → Zambia
   - Contains "Benin" or "BEN" → Benin
   - Default → Lesotho

3. Create hierarchical structure:
   ```
   Country (Lesotho)
     └── Region (Maseru)
         └── Site (Ha Makebe Minigrid)
             └── Building (Powerhouse)
                 └── Room/Cabinet
   ```

### 4. Requests (Google Forms -> Database)

**Google Forms to migrate (using new item_class + department_scope):**
- RET Items Request Form -> `requests` with `item_class='Material'`, `department_scope='RET'`
- FAC Items Request Form -> `requests` with `item_class='Material'`, `department_scope='FAC'`
- Meters Request Form -> `requests` with `item_class='Inventory'`, `department_scope='General'`
- Ready Board Request Form -> `requests` with `item_class='Inventory'`, `department_scope='General'`

**Migration Process:**
1. Export each Google Form to CSV
2. Parse CSV and create `requests` records with correct `item_class` and `department_scope`
3. Create `request_items` for each line item
4. Set `requested_for_country` based on form metadata or default

### 5. Allocations

**Old → New:**
```sql
allocation_id     → allocation_id (same)
asset_id          → asset_id (same)
employee_id       → employee_id (same)
allocated_by      → allocated_by (same)
allocation_date   → allocation_date (same)
return_date       → actual_return_date (same)
status            → status (mapped to new enum)
```

**New Fields:**
- `expected_return_date`: Set based on business rules or NULL
- `notes`: Migrate from old comments if available

### 6. QR Code Generation

**After migration, generate QR codes for all assets:**

```sql
-- Generate QR codes for existing assets
UPDATE assets 
SET qr_code_id = CONCAT('1PWR-', 
    (SELECT country_code FROM countries WHERE country_id = assets.country_id),
    '-',
    LPAD(asset_id, 6, '0')
)
WHERE qr_code_id IS NULL;
```

### 7. Inventory Levels

**Initialize from current asset quantities:**

```sql
-- Create inventory records for all assets
INSERT INTO inventory_levels (asset_id, location_id, country_id, quantity_on_hand)
SELECT 
    asset_id,
    location_id,
    country_id,
    COALESCE(quantity, 1) as quantity_on_hand
FROM assets
WHERE status IN ('Available', 'Unallocated');
```

## Migration Scripts

See `database/migrations/` folder for step-by-step migration scripts:
1. `01_create_schema.sql` - Create new tables
2. `02_migrate_countries_locations.sql` - Set up countries and locations
3. `03_migrate_categories.sql` - Consolidate categories
4. `04_migrate_assets.sql` - Migrate assets with country mapping
5. `05_migrate_allocations.sql` - Migrate allocations
6. `06_generate_qr_codes.sql` - Generate QR codes
7. `07_initialize_inventory.sql` - Set up inventory levels
8. `08_migrate_google_forms.sql` - Import Google Form data (manual CSV import)

## Validation Queries

After migration, run these to verify:

```sql
-- Check item counts by class
SELECT item_class, COUNT(*) as item_count
FROM assets
GROUP BY item_class
ORDER BY item_count DESC;

-- Check item counts by country and class
SELECT c.country_name, a.item_class, COUNT(*) as item_count
FROM assets a
JOIN countries c ON a.country_id = c.country_id
GROUP BY c.country_name, a.item_class
ORDER BY c.country_name, a.item_class;

-- Verify all assets have item_class set
SELECT COUNT(*) as missing_class
FROM assets
WHERE item_class IS NULL OR item_class = '';

-- Check category distribution
SELECT cat.item_class, cat.category_name, COUNT(a.asset_id) as items
FROM categories cat
LEFT JOIN assets a ON a.category_id = cat.category_id
GROUP BY cat.item_class, cat.category_name
ORDER BY cat.item_class, items DESC;

-- Check QR code coverage
SELECT 
    item_class,
    COUNT(*) as total,
    COUNT(qr_code_id) as with_qr,
    COUNT(*) - COUNT(qr_code_id) as missing_qr
FROM assets
GROUP BY item_class;

-- Check inventory levels
SELECT 
    c.country_name,
    COUNT(DISTINCT il.location_id) as locations_with_inventory
FROM inventory_levels il
JOIN countries c ON il.country_id = c.country_id
GROUP BY c.country_name;
```

## Rollback Plan

If migration fails:
1. Keep old database intact (don't drop)
2. New schema in separate database: `onestop_asset_shop`
3. Can run both systems in parallel during transition
4. Rollback = simply point application back to old database
