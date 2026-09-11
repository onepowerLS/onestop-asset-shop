# PR–AM receipt and authoritative mapping pilot — 10 September 2026

Implemented locally, not deployed. No live orders, mappings or inventory were changed.
This advances the separate receipt-closeout brief; it is not a declaration that every
edge case in that brief is complete.

## What is implemented

AM and PR share Firestore. A trusted callable transaction records the AM receipt,
stock increment, stock transaction, normalized movement, and PR line coverage together.
There is no asynchronous acknowledgment window or separate integration key.
The caller must have a signed Nexus AM approver action and matching country/owner scope.

- `enrollPrReceiptPilot`: PR administrator explicitly enrolls an unchanged ORDERED PO,
  freezes its source lines/revision, and verifies each AM item and canonical destination.
- `recordAmOrderReceipt`: accepts a receipt once under an idempotency key, atomically
  increments stock and coverage, rejects mismatched/stale revisions and overdelivery.
- `completeReceiptControlledPr`: trusted transaction checks current order, evidence and
  full line coverage. Existing delivery-document requirements remain.
- `reverseAmOrderReceipt`: reverses a complete receipt only while that stock remains
  unallocated at its location. It appends reversal records and reduces coverage once.
  A completed order gets a visible exception rather than silently retaining a clean receipt status.
- `confirmAmUgpMapping`: an AM approver confirms a curated eligible MAS candidate after
  checking current UGP specification, item specification and units. The mapping and its
  audit record commit atomically. Existing different mappings cannot be overwritten.

PR shows AM coverage on Ordered and Completed views. AM item pages link to mapping
verification. PR links enrolled orders to the AM receipt screen; that screen links to
full receipt reversal. Mapping proposals remain distinct from confirmed source records.

## Pilot boundaries

Only whole-unit Material/Consumable/Inventory goods, all lines from one explicitly
selected source, at one verified canonical site and asset owner are supported initially.
Stable source line IDs and explicit country metadata are required. Scope fields come
from signed Nexus privileges, not a client-selected organization.

AM's legacy stock read/dispatch paths still use integer quantities. Fractional metres
are rejected, never rounded. Decimal support needs a coordinated AM balance/dispatch
migration before those orders can join this pilot. Fixed assets, mixed goods/services,
unit conversions, kits, substitutions, cancellations, split destinations, partial
returns and amendments after enrollment need their additional workflows. A changed
order is blocked for reconciliation; it cannot silently re-enroll and discard receipts.

Historical orders are not mass-enrolled or reopened. Do not receipt goods already
recorded through another stock screen: historical reconciliation must link evidence
without adding stock a second time. This pilot is prospective receiving only.

MAS is the canonical site and SMP owns its assets. Country/owner/site metadata must
be explicitly consistent; the possible plain `mashai` orphan is not aliased.
Unrelated AM items need no UGP link. The initial stock-goods pilot does not yet cover
serialized office equipment, which still belongs in AM through its owning workflow.

## Operator steps after release

1. Procurement administrator selects a small prospective SMP goods order, verifies
   every source line, exact AM item, existing inventory level, owner and site, then
   enrolls it with `enrollPrReceiptPilot`. Enrollment is currently an administrator/API
   setup step, not a self-service requestor control.
2. AM approver opens **Open AM receipt screen** from the order, selects a frozen line,
   enters newly accepted quantity and delivery-note evidence, and confirms it is not
   already recorded elsewhere. Keep the receipt ID when retrying an uncertain response.
3. PR shows recorded / ordered quantities. At 60 of 100, closeout is denied. At 100,
   the server rechecks the live revision before completing.
4. For a full return, use the AM reversal screen with the original receipt ID and reason.
   Already issued/transferred/allocated stock must be reconciled first.
5. For mappings, open the AM asset and **Verify UGP mapping**. Check the current canonical
   UGP ID/specification and AM manufacturer's specification. Record supporting evidence.
   Component-only and conflicting candidates remain blocked. Capture fresh forecasting
   inputs after an authoritative mapping is saved.

The curated candidate reference is dated 8 September 2026. It is not a refreshed UGP
master catalogue and never substitutes for the approver's current specification check.

## Release sequence

1. Isolate reviewed changes from unrelated local work, especially Nexus rules.
2. Deploy canonical Nexus rules together with the new trusted server functions.
   PR functions use only `npm run deploy:functions` / explicit-selector script;
   never bare Firebase functions deploy or `--force`. Rebuild compiled functions first.
3. Deploy PR and AM interfaces, then publish the prepared AM What's New announcement.
   AM main-branch pushes trigger production deployment: do not push it prematurely.
4. Smoke authenticated scopes and one controlled pilot order, inspect stock/ledger/receipt
   references, retry the same receipt ID, and verify shared Nexus SSO functions remain.
5. Only then enroll actual prospective orders. No global closeout cutover has been enabled.

Nexus rules deny client writes to policies, receipts, reversals and mapping audit records;
they protect AM mapping fields and enrolled-order completion. Unenrolled orders retain
their existing workflow. An AM delivery-document override cannot satisfy receipt coverage.

## Tests and limitations

- Firestore emulator: atomic stock/evidence writes; concurrent duplicate receipts;
  partial/overdelivery denial; AM vs PR role and organization scope; completion;
  reversal after completion; verified mapping; direct-client completion, disabling
  enforcement, forged policy and mapping writes rejected; ordinary notes edits allowed.
- 40 PR function/catalog tests pass. AM mapping, inventory-read, authorization, security,
  transaction and dispatch regression scripts pass. Functions compile; PR Vite builds.
- PR-wide frontend TypeScript has 259 existing errors in both baseline and changed trees;
  it is not a clean type-check. Normalized comparison against the unchanged baseline shows no new diagnostics.
- Browser interaction and live production smoke checks remain release tasks.

To run the integration test, compile functions and launch the Firestore emulator with
project `demo-pr-am-receipts`, loopback `127.0.0.1:8187`, and canonical Nexus rules.
Run `node functions/test/receipt-emulator.cjs` inside `firebase emulators:exec`.
The test refuses a non-loopback emulator and uses no production credentials.

Forecast access decision: MSO confirmed Matt plus all PR approvers. The sibling
uGridPREDICT service now supports current signed PR approval grants and an explicit
owner UID override; no separate Finance/Procurement staff list is required.


## 11 September release check: stock reconciliation gate

The new AM inventory double-count brief revealed that receipt/return writes must preserve
both the asset total and its location balances. The receipt service now updates
`am_core_assets.quantity` and the selected position in the same transaction, and records
before/after totals in the transaction ledger. Enrollment, new receipts and returns read
all positions for that asset within their transaction and reject mismatched totals,
duplicate location codes, malformed location codes, explicit unverified reconciliation
states, or allocations above stock. Exact retries still replay without changing stock.
The code-format check is not proof that a location exists in the canonical registry;
enrollment separately checks the selected destination against the PR site and AM country.

Emulator regression checks pass for those blocks, concurrent retries and the paired
asset/location updates. Existing discrepant inventory has not been repaired or relocated.
The physical count and canonical inventory write-path work in AM's
`docs/BRIEF_INVENTORY_LEVELS_DOUBLE_COUNT_20260910.md` remain necessary. The receipt
pilot remains undeployed and no real orders have been enrolled.
