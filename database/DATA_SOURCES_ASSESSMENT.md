# Data Sources Assessment

**Assessment Date**: January 25, 2026  
**Purpose**: Identify clean data sources for migration into the OneStop Asset Shop system

## Executive Summary

The previous migration source was found to be corrupt. This assessment identifies alternative data sources from the 1PWR Dropbox file system that can be used for migration without duplicates.

## Primary Data Sources

### 1. FY2025 PPE Asset Register (RECOMMENDED - Most Current)

| Attribute | Value |
|-----------|-------|
| **File** | `FY2025 1PWR PPE ASSET REGISTER _REV.xlsx` |
| **Location** | `/1PWR/OnePower/financial/14 - annual financial statements/March 2025/parts/` |
| **Last Modified** | September 26, 2025 |
| **Size** | 78 KB |
| **Status** | Audited financial statement supporting document |

**Pros**:
- Most recent official asset register (FY2025)
- Used for audited financial statements - high data integrity
- Contains Property, Plant & Equipment (PPE) data

**Cons**:
- May focus on capitalized assets only (not consumables/inventory)
- Financial focus may exclude operational details

### 2. Asset Spreadsheet Database

| Attribute | Value |
|-----------|-------|
| **File** | `Asset spreadsheet database.xlsx` |
| **Location** | `/1PWR Asset Management/1_Assets Management/Assets Database/` |
| **Last Modified** | December 6, 2024 |
| **Size** | 5.5 MB |

**Pros**:
- Comprehensive asset database (5.5 MB indicates substantial data)
- Located in primary Asset Management folder
- Spreadsheet format - easy to parse and validate

**Cons**:
- Last updated December 2024 (may not include 2025 arrivals)

### 3. Microsoft Access Database (Latest Version)

| Attribute | Value |
|-----------|-------|
| **File** | `Assets Microsoft Access Database (3).accdb` |
| **Location** | `/1PWR Asset Management/8_Other/Asset Database/` |
| **Last Modified** | December 13, 2024 |
| **Size** | 9.4 MB |

**Pros**:
- Relational database structure (may preserve relationships)
- Large file size indicates comprehensive data
- Most recent Access database version

**Cons**:
- Requires Access database tools to export
- Last updated December 2024

## Secondary Data Sources (2025 Updates)

### Vehicle Tracking

| File | Location | Modified | Size |
|------|----------|----------|------|
| `Updated-Vehicle-Trackers-2025.xlsx` | `/1_Assets Management/Vehicle Management/Vehicles Tracking/` | Oct 17, 2025 | 188 KB |

### Inventory & Parts

| File | Location | Modified | Size |
|------|----------|----------|------|
| `PV Tracker Parts Checklist V2_by Kananelo Mokobori.xlsx` | `/2_Inventory Management/Tracker hardware parts/` | Nov 6, 2025 | 6.7 MB |
| `Consolidated_power_house_parts_list_new_LEB_MAS_SHG_inventory.xlsx` | `/2_Inventory Management/Power Houses Parts List/` | Jan 10, 2025 | 101 KB |
| `Pueco Stock.xlsx` | `/1_Assets Management/Assets Database/` | Dec 16, 2024 | 21 KB |

### PUECO Stock Tracking

| File | Location | Modified | Size |
|------|----------|----------|------|
| `2025-03-06 - Stock & Sales Tracking PUECO (1).xlsx` | `/1PWR - PUECO/(3) EEA Retail/Inventory Sales Contracts Invoices/` | Jan 23, 2026 | 368 KB |

## Asset Reconciliation Files (2025)

These files verify existing assets and can be used to validate migration data:

| File | Date | Location |
|------|------|----------|
| `AM_RECON_01-07-2025_Mofokeng- Exercise 1.xlsx` | July 2025 | `/Assets Reconciliation for 2025/` |
| `AM_RECON_01-07-2025_Mofokeng- Exercise 2.xlsx` | July 2025 | `/Assets Reconciliation for 2025/` |
| `AM Recon_04-09-2025_MOFOKENG AND MOLIBELI_Exercise 1.xlsx` | September 2025 | `/Assets Reconciliation for 2025/` |
| `AM Recon_05-09-2025_MOFOKENG AND MOLIBELI_Exercise 2.xlsx` | September 2025 | `/Assets Reconciliation for 2025/` |
| `AM Recon_13-10-2025_MOFOKENG AND MOLIBELI_Exercise 1.xlsx` | October 2025 | `/Assets Reconciliation for 2025/` |
| `AM Recon_13-10-2025_MOFOKENG AND MOLIBELI_Exercise 2.xlsx` | October 2025 | `/Assets Reconciliation for 2025/` |

## Archive Sources (Historical Reference)

Located in `/1_Assets Management/Assets Database/Archive/`:

| File | Modified | Size | Notes |
|------|----------|------|-------|
| `1PWR Lesotho.accdb` | Mar 27, 2023 | 7.1 MB | Legacy Lesotho database |
| `Assets Microsoft Access Database (1) (1).accdb` | Aug 24, 2023 | 11.2 MB | Older version |
| `Assets Microsoft Access Database (2).accdb` | Aug 23, 2023 | 11.2 MB | Older version |
| `Asset Register.xlsx` | Jan 3, 2023 | 304 KB | Historical register |
| `Onepower+Lesotho+PTY+LTD_Asset+List 14-09-2022.xlsx` | Jan 3, 2023 | 31 KB | 2022 asset list |

## Recommended Migration Strategy

### Phase 1: Base Asset Data
1. **Primary Source**: Use `FY2025 1PWR PPE ASSET REGISTER _REV.xlsx` as the authoritative source for capitalized assets
2. **Supplement**: Cross-reference with `Asset spreadsheet database.xlsx` for operational details

### Phase 2: Inventory & Parts Data
1. Import `PV Tracker Parts Checklist V2_by Kananelo Mokobori.xlsx` for tracker parts inventory
2. Import `Consolidated_power_house_parts_list_new_LEB_MAS_SHG_inventory.xlsx` for powerhouse parts
3. Import PUECO stock tracking for retail inventory

### Phase 3: Vehicle Assets
1. Import `Updated-Vehicle-Trackers-2025.xlsx` for vehicle fleet data

### Phase 4: Validation
1. Cross-reference imported data against 2025 reconciliation files
2. Identify and resolve any discrepancies
3. Flag assets that appear in reconciliation but not in source data (potential 2025 arrivals)

## Data Quality Notes

### Known Issues
- The original migration source was corrupt (reason for this assessment)
- Some 2025 asset arrivals may not be captured in the December 2024 spreadsheet database
- Reconciliation files exist for 2025 but primary database wasn't updated

### Recommendations
1. Before migration, export and analyze the FY2025 PPE register structure
2. Compare asset counts between FY2025 PPE register and Asset spreadsheet database
3. Use reconciliation files to identify any missing assets
4. Consider extracting tables from the Access database for comparison

## File Paths Reference

All paths are relative to `/Users/mattmso/Dropbox/1PWR/`:

```
1PWR Asset Management/
├── 1_Assets Management/
│   ├── Assets Database/
│   │   ├── Asset spreadsheet database.xlsx          # Primary spreadsheet
│   │   ├── Assets Microsoft Access Database.accdb   # Primary Access DB
│   │   ├── Pueco Stock.xlsx
│   │   └── Archive/                                 # Historical versions
│   ├── Assets Reconciliation/
│   │   └── Assets Reconciliation for 2025/          # 2025 recon files
│   └── Vehicle Management/
│       └── Vehicles Tracking/
│           └── Updated-Vehicle-Trackers-2025.xlsx
├── 2_Inventory Management/
│   ├── Tracker hardware parts/
│   │   └── PV Tracker Parts Checklist V2_by Kananelo Mokobori.xlsx
│   └── Power Houses Parts List/
│       └── Consolidated_power_house_parts_list_new_LEB_MAS_SHG_inventory.xlsx
└── 8_Other/
    └── Asset Database/
        └── Assets Microsoft Access Database (3).accdb  # Latest Access version

OnePower/
└── financial/
    └── 14 - annual financial statements/
        └── March 2025/
            └── parts/
                └── FY2025 1PWR PPE ASSET REGISTER _REV.xlsx  # FY2025 official

1PWR - PUECO/
└── (3) EEA Retail/
    └── Inventory Sales Contracts Invoices/
        └── 2025-03-06 - Stock & Sales Tracking PUECO (1).xlsx
```

## Next Steps

1. [ ] Export and analyze FY2025 PPE Asset Register structure
2. [ ] Export tables from Access database for comparison
3. [ ] Create field mapping between source files and target schema
4. [ ] Develop deduplication logic based on asset tag/serial numbers
5. [ ] Build migration scripts with validation checks
6. [ ] Run test migration with subset of data
7. [ ] Validate against reconciliation files
8. [ ] Execute full migration
