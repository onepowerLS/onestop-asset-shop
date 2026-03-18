# SOP: Item Classification for Asset Management

**Document ID:** SOP-AM-001
**Effective Date:** 2026-01-25
**Applies to:** All 1PWR countries (Lesotho, Zambia, Benin)
**Owner:** Asset Management Team

---

## 1. Purpose

This procedure establishes how every physical item entering 1PWR's asset management system must be classified. Correct classification drives accounting treatment (capitalize vs. expense), inventory tracking, depreciation, and reorder alerts.

## 2. Classification Model

Every item belongs to exactly one of four classes. The class determines how the system tracks, values, and reports the item.

| Class | Accounting Treatment | Tracking Unit | Examples |
|---|---|---|---|
| **Fixed Asset** | Capitalize (IAS 16), depreciate over useful life | Individual (serial/QR) | Vehicles, generators, welding machines, laptops, installed mini-grid equipment |
| **Material** | Expense to project on issuance (IAS 2 raw materials) | Batch/lot by quantity | Wire, conduit, utility poles, solar panels, battery cells |
| **Consumable** | Expense immediately on issuance | Quantity on hand | PPE, office supplies, lubricants, cable ties, cleaning supplies |
| **Inventory** | Carry as current asset (IAS 2 finished goods) until deployed/sold | Individual or batch | Meters, ready boards, spare parts, pre-packaged install kits |

## 3. Decision Tree

Use this flowchart when registering a new item. Answer each question in order:

```
START: New item to register
  |
  Q1: Is this item intended for resale or deployment to a customer?
  |       |
  |      YES --> INVENTORY
  |       |
  |      NO
  |       |
  Q2: Will this item be consumed/used up within a single task or project?
  |       |
  |      YES
  |       |   Q2a: Is the unit cost above the capitalization threshold?
  |       |         |
  |       |        YES --> FIXED ASSET (e.g., expensive power tool)
  |       |        NO  --> CONSUMABLE
  |       |
  |      NO
  |       |
  Q3: Is this item an input for construction or installation work?
  |       |
  |      YES --> MATERIAL
  |       |
  |      NO
  |       |
  Q4: Does this item have a useful life > 1 year AND value above
      the capitalization threshold?
          |
         YES --> FIXED ASSET
         NO  --> CONSUMABLE
```

### Capitalization Threshold

Items costing **above USD 200 equivalent** (or local currency equivalent) AND with a useful life exceeding 12 months are capitalized as Fixed Assets. Items below this threshold are classified as Consumables even if durable.

## 4. Category Codes

Each item class has subcategories. Use the correct prefix when selecting a category:

### 4.1 Fixed Assets (FA-xxx)

| Code | Category | Useful Life | Examples |
|---|---|---|---|
| FA-VEH | Vehicles | 10 years | Cars, trucks, motorbikes |
| FA-HVY | Heavy Equipment | 15 years | Generators, compressors, cranes |
| FA-TLM | Tools & Machinery | 7 years | Welding machines, power tools (above threshold) |
| FA-ITE | IT Equipment | 4 years | Laptops, servers, networking, tablets |
| FA-FNF | Furniture & Fixtures | 10 years | Desks, chairs, shelving, signage |
| FA-INF | Installed Infrastructure | 25 years | Commissioned mini-grid components, installed meters, distribution gear |

### 4.2 Materials (MAT-xxx)

| Code | Category | Department | Examples |
|---|---|---|---|
| MAT-ELE | Electrical | RET | Wire, conduit, connectors, breakers, junction boxes |
| MAT-STR | Structural | RET | Utility poles, brackets, foundations, mounting hardware |
| MAT-SOL | Solar | RET | Panels, inverters, charge controllers, combiners |
| MAT-BAT | Battery Storage | RET | Battery cells, racks, BMS components |
| MAT-FAC | Facilities | FAC | Building materials, plumbing, fencing, painting |
| MAT-ONM | O&M Components | O&M | Pre-assembled replacement modules |

### 4.3 Consumables (CON-xxx)

| Code | Category | Examples |
|---|---|---|
| CON-SAF | Safety & PPE | Hard hats, gloves, vests, goggles, harnesses |
| CON-OFC | Office Supplies | Paper, toner, pens, labels |
| CON-MNT | Maintenance Supplies | Lubricants, fasteners, tape, cable ties, sealant |
| CON-CLN | Cleaning Supplies | Detergent, brooms, waste bags |
| CON-HND | Hand Tools | Screwdrivers, pliers, wrenches (below threshold) |

### 4.4 Inventory (INV-xxx)

| Code | Category | Examples |
|---|---|---|
| INV-MTR | Meters | Produced/procured meters for customer deployment |
| INV-RDB | Ready Boards | Pre-assembled customer connection boards |
| INV-SPR | Spare Parts | Replacement components for installed infrastructure |
| INV-WIP | Work-in-Progress | Partially assembled items awaiting completion |
| INV-KIT | Kits & Assemblies | Pre-packaged installation kits for field deployment |

## 5. Common Edge Cases

| Item | Correct Class | Rationale |
|---|---|---|
| Meter (in warehouse, awaiting deployment) | **Inventory** (INV-MTR) | Finished good held for customer deployment |
| Meter (installed at customer site) | **Fixed Asset** (FA-INF) | Now part of commissioned infrastructure, depreciated |
| Solar panel (in warehouse for project) | **Material** (MAT-SOL) | Construction input, expensed to project |
| Solar panel (installed, generating) | **Fixed Asset** (FA-INF) | Part of commissioned plant |
| Multimeter (test instrument, $500) | **Fixed Asset** (FA-TLM) | Above threshold, multi-year life |
| Screwdriver set ($25) | **Consumable** (CON-HND) | Below threshold |
| Spare inverter (for O&M stock) | **Inventory** (INV-SPR) | Held as replacement stock |
| Cable ties (bulk box) | **Consumable** (CON-MNT) | Low value, consumed in tasks |
| Ready board (pre-built, in warehouse) | **Inventory** (INV-RDB) | Finished good for deployment |
| Utility pole (on delivery truck) | **Material** (MAT-STR) | Construction input in transit |

## 6. Lifecycle Transitions

Some items change class during their lifecycle. The system tracks this via status transitions:

```
Material (MAT-SOL panel)
  --> status: "InProject" (allocated to a build)
  --> Upon commissioning: reclassified to Fixed Asset (FA-INF)
     with new asset_tag, serial, and depreciation schedule

Inventory (INV-MTR meter)
  --> status: "Deployed" (installed at customer site)
  --> Reclassified to Fixed Asset (FA-INF)
     with installation date as commissioning date

Consumable (CON-MNT lubricant)
  --> status: "Consumed"
  --> No reclassification; item exits active tracking
```

## 7. Registration Checklist

When adding a new item to the system:

1. Determine the item class using the Decision Tree (Section 3)
2. Select the appropriate category code (Section 4)
3. For Fixed Assets: record serial number, purchase price, warranty, and assign a QR label
4. For Materials: record quantity, unit of measure, and receiving location
5. For Consumables: record quantity, unit of measure; set reorder level if stocked
6. For Inventory: record quantity, unit of measure; set reorder level; assign QR labels for individual tracking if applicable

## 8. Review

This SOP will be reviewed annually or when new item types are introduced that do not fit the existing categories. Category additions require approval from the Asset Management lead and Finance.
