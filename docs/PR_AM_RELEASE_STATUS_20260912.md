# Coordinated forecast and PR–AM pilot release — 12 September 2026

## What is live

- Nexus `/forecast`: deployed portal bundle `e398009`, with the existing production TDL
  fixes preserved. Includes source snapshots, evidence review, draft report packs,
  comparisons, charts, XLSX/CSV/HTML/JSON exports and an in-app tutorial.
- Forecast runtime `62d693b`: dedicated systemd service on Nexus EC2, persistent SQLite,
  protected loopback proxy, Firebase session/revocation checks. Matt's verified account
  is configured explicitly; other users require current signed PR approval grants.
- PR evidence API `a938350`: purchasing lines and paginated historical archives, deployed
  and verified 11 September.
- Receipt functions: all five callables deployed; enrollment and mapping include the
  explicit legacy AM country resolver from `4972eb7`.
- Canonical Nexus rules `fdad40c`: protect receipt evidence, AM mapping fields and enrolled
  order closeout. Comparison against live rules excluded unrelated working-tree changes.
- PR frontend `4a38619`: guided administrator enrollment, receipt coverage/status, trusted
  closeout call, and English/French user-manual instructions. Live bundle matches the
  isolated release build and contains the source commit stamp.
- AM `b327405`: receipt entry, full returns, verified mapping review, name-match proposals,
  zero-stock catalogue sync, and English/French help/tutorial sections. Production health
  reports this commit. Release announcement `2026-09-11-receipts-and-mappings` is active.

## Authoritative metadata repair

Each MAS site row (`mashai`, `mashai_smp`, `mashai_neo1`, `mashai_pueco_lesotho`) now has
`countryCode: LS`, based on its existing organisation’s `countryCode` and canonical PR
country `LS` (Lesotho). The existing AM `pr_master_countries/LSO` row now has `iso2: LS`.
Writes used document-version preconditions and provenance fields. IDs and owners remain
unchanged; the plain `mashai` row was not merged with SMP.

## Verification

- Consumer suite: 251 passing tests before hosting; 16 workbench/export/auth tests also
  passed on production's Python 3.10 runtime using a separate temporary database.
- PR catalog/policy: 40 tests pass; functions compile. Emulator tests pass atomic totals,
  duplicate retry handling, partial/overdelivery checks, stock reconciliation gates,
  returns, scope/role checks, exact country resolution, ambiguous-country rejection,
  mapping audit and direct-client bypass protection.
- PR build passes. Global frontend typecheck retains 259 pre-existing errors, with no
  new diagnostics compared with the previously verified baseline.
- AM syntax and mapping, inventory API, authorisation, security, transaction and dispatch
  tests pass; key checks were repeated on the AM server.
- Live API rejects anonymous forecast requests (401) and unsigned receipt/mapping calls
  (403). All 79 shared functions are present, including Nexus sign-in and account creation.
- A real signed-in browser walkthrough remains unverified: the browser reached Nexus's
  normal login page. No user was impersonated and no production test receipt was created.

## Using the pilot

Open https://nexus.1pwrafrica.com/forecast and sign in. In PR, an administrator opens a
prospective ORDERED whole-unit goods order and selects **Set up AM receipt checks**.
Match every approved line to the exact AM item and destination; verify the specification
before enabling. AM approvers then use the receipt link. See the PR manual and AM help
for returns, mapping confirmation and closeout requirements.

No actual orders have been enrolled and no stock or authoritative part mappings have
been changed. Stock discrepancies still need physical reconciliation by AM. This pilot
does not handle services, fractional quantities, partial returns, kit conversions,
historical backfill, or amendment/cancellation reconciliation. Forecast quantities still
use fixtures and remain labelled as drafts; live cash, crew, commissioned-connection and
material inputs must be validated before operational or lender use.

## Deployment isolation and recovery

Changes were released from isolated source snapshots/branches. AM used a targeted
fast-forward release, preserving server configuration and avoiding the unrelated vehicle
import in its main-branch deployment workflow. Nexus retains the previous frontend and
proxy configuration; forecast database and settings are outside the release directory.
Do not roll back receipt rules to permit bypasses on enrolled orders. Release source is
preserved on named release branches; main-branch automation was not triggered.
