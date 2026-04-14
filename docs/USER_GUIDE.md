# OneStop Asset Shop -- User Guide

## Logging In

Navigate to `https://am.1pwrafrica.com`. Enter your 1PWR email and password (or your legacy username). The system authenticates through Firebase, then checks your role in the shared `users` collection.

**Roles:**

| Role | Access |
|---|---|
| Admin | Full access to all pages including admin (categories, locations, employees, QR labels) |
| Manager | All operational pages; cannot manage categories, locations, or employees |
| Viewer | Read-only access to listings, stock levels, and transaction history |

---

## Dashboard

The landing page after login shows:

- **Total items** across all countries and classes
- **Classification breakdown** -- counts of Fixed Assets, Materials, Consumables, and Inventory. Click any card to jump to a filtered catalog view.
- **Items by Country** -- summary per operating country
- **Items by Status** -- how many items are Available, CheckedOut, Allocated, etc.
- **Recent Transactions** -- the latest 10 activities in the system

---

## Catalog (Items)

### Browsing

Use the **Catalog** menu in the sidebar to access:

- **All Items** -- unfiltered catalog
- **Fixed Assets** -- vehicles, equipment, installed infrastructure
- **Materials** -- construction/installation inputs (wire, poles, panels)
- **Consumables** -- PPE, office supplies, maintenance items
- **Stock Levels** -- opens the inventory tracking view

Each row shows the item's name, classification badge, category, country, status, and condition. Use the filters at the top of the page to narrow by category, country, or status. Use the search box for name, serial number, asset tag, or QR code.

### Adding an Item

1. Click **Add New Item** (or navigate to Catalog > Add)
2. **Select Classification** -- the radio buttons at the top determine which fields appear:
   - **Fixed Asset** shows: serial number, manufacturer, model, purchase date, purchase price, salvage value, warranty expiry
   - **Material / Consumable / Inventory** shows: quantity, unit of measure, unit cost
3. Fill in the required fields (name, classification, country, condition, status)
4. Choose a category from the dropdown (filtered to your selected class)
5. Click **Save**
6. An `asset_tag` is auto-generated (format: `1PWR-{CLASS}-{COUNTRY}-000001`)

### Viewing an Item

Click any row in the catalog, or scan its QR code. The detail page shows:

- All item properties
- QR code image (if assigned)
- **Allocation History** -- who currently has/had the item
- **Transaction History** -- every check-out, check-in, transfer, etc.

### Editing an Item

From the detail page, click **Edit**. Update fields as needed and save. To change status (e.g., mark an item as `Retired` or `WrittenOff`), use the Status dropdown on the edit form.

---

## Stock Levels

Navigate to **Stock Levels** in the sidebar. This page is designed for Material, Consumable, and Inventory items -- things tracked by quantity rather than individual serial numbers.

The table shows:

- **On Hand** -- total physical quantity
- **Allocated** -- quantity assigned but not yet consumed/deployed
- **Available** -- on hand minus allocated
- **Reorder Level** -- threshold below which a reorder alert triggers

Check **Show only low-stock** to filter to items at or below their reorder level. Use the class and country dropdowns to narrow the view.

---

## Check-Out and Check-In

### Checking Out an Item

1. Navigate to **Check-Out/In**
2. In the **Check Out** section, select the item (only `Available` items appear)
3. Select the employee receiving the item
4. Optionally set an expected return date
5. Click **Check Out**
6. The item's status changes to `CheckedOut` and an allocation record is created

### Checking In an Item

1. In the **Check In** section, select an active allocation from the dropdown
2. Choose the return location
3. Click **Check In**
4. The item's status reverts to `Available`

The **Active Allocations** table at the bottom shows all currently checked-out items.

---

## Transactions

Navigate to **Transactions** in the sidebar to see the full audit trail. Every action that modifies an item's state is logged:

| Type | Meaning |
|---|---|
| CheckOut | Item issued to an employee |
| CheckIn | Item returned |
| StockIngestion | New stock received |
| StockTake | Physical count recorded |
| Transfer | Item moved between locations |
| Allocation | Item reserved for a project |
| Return | Item returned from project allocation |
| WriteOff | Item removed from active inventory |
| QRScan | Item scanned (informational) |
| Consume | Consumable used up |
| Deploy | Item permanently installed |

Filter by transaction type or search by item name.

---

## Requests

Navigate to **Requests** in the sidebar.

### Submitting a Request

1. Click **New Request**
2. Select the **Item Class** you need (Fixed Asset, Material, Consumable, Inventory)
3. Select your **Department** (RET, FAC, O&M, General)
4. Choose the **Country** and optionally a location
5. Set **Priority** (Low, Normal, High, Urgent)
6. Describe what you need and when you need it
7. Click **Submit**
8. A request number is auto-generated (format: `REQ-2026-0001`)

### Managing Requests (Admin/Manager)

The request list shows status summary cards at the top. Click a request to view details. Admins and Managers can:

- **Approve** -- moves to `Approved` status
- **Reject** -- moves to `Rejected` status with a note
- **Fulfill** -- marks as `Fulfilled` when the item has been delivered

---

## Load-out manifests

Use **Load-out manifests** in the sidebar when operations pack goods leaving HQ (or the warehouse) toward a field site. A manifest is a packing list with line items tied to catalog assets where applicable.

- **Draft** -- edit lines, quantities, and notes.
- **Packed / Shipped / Delivered** -- progress the shipment lifecycle as appropriate.
- **Fleet Management:** manifests can be linked to a trip (`trip_id`) so Fleet sees the same load-out from the FM app. Trip linking is a Manager-level action; see internal FM/AM integration notes if you connect trips from `fm.1pwrafrica.com`.

Open a manifest to **view** or **print** the packing list for the warehouse team.

---

## Telecom

The **Telecom** menu covers SIM cards and phone procurement requests. Capabilities are split so Finance can assign numbers to teams and IT can link SIMs to handset assets in the catalog (your account may show only the actions you are allowed to perform).

### SIM registry

Lists **SIM cards** (MSISDN and operational fields such as pool, site label, and assignment). Search and filter to find a number. Edit a SIM to update status, notes, or location context after verification.

### SIM assignments

From a SIM, you can record an **assignment**:

- **Team / function** -- which pool or team uses the line (Finance-oriented workflow).
- **Phone asset** -- link the SIM to a **Fixed Asset** handset in the catalog (IT-oriented workflow). Use the handset’s asset id from the item detail page.

Assignments are time-stamped; use this to keep the registry aligned with physical swaps.

### Phone requests

**Phone requests** are for requesting new handsets or lines through procurement/IT. Submit a justification and country; managers update status (Approved, Fulfilled, etc.) through the list and detail views.

---

## IT Helpdesk

**IT Helpdesk** (under Telecom in the sidebar) is for IT and AM Operations tickets: hardware issues, access, and operational requests. Create a ticket with a title and description, set priority, and track status through resolution. This replaces ad-hoc channels for issues that should stay auditable alongside Asset Management.

---

## QR Codes

### Generating QR Codes

Navigate to **Admin > QR Labels**. The page shows:

- **Coverage stats** -- how many items have QR codes assigned
- **Items without QR** -- click **Generate** next to any item, or use **Generate All** for batch assignment
- **Assigned QR codes** -- preview images of all assigned codes

QR code format: `1PWR-{COUNTRY}-{CLASS_PREFIX}-{SEQUENCE}`

### Scanning QR Codes

Connect the Symcode scanner via USB or Bluetooth. When you scan a QR label:
1. The scanner types the code as keyboard input
2. The system detects the scan and redirects to the item's detail page
3. From there you can check out, edit, or view history

---

## Admin Pages

These pages are available to Admin users only.

### Categories

Manage the category hierarchy. Categories are grouped by item class. Each category has:
- A **code** (e.g., `FA-VEH`, `MAT-ELE`)
- A **department scope** (which department typically uses this category)
- **Depreciation settings** (for Fixed Assets: useful life, method)
- **Reorder tracking** toggle (for Materials/Consumables/Inventory)

### Locations

**Read-only in AM:** site and location data are synced from the **PR Portal**. Use this page to browse sites by country. To add or change a site, update it in the PR Portal at `https://pr.1pwrafrica.com` (locations drive where items are stored, checked out from, and filtered).

### Employees

View and search the employee directory. Employee records come from the shared master data and are used for item allocation and check-out.
