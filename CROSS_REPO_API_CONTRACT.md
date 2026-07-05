# Cross-Repo API Contract — Asset Management (AM)

**Owner:** 1PWR AM (`am.1pwrafrica.com`, EC2 `16.28.64.221`, PHP + Firestore, repo `onepowerLS/onestop-asset-shop`)
**Canonical data owned:** Assets, allocations, inventory, loadout manifests, mutations, SIM cards.
**Source of truth for:** asset inventory + field-camp provisioning.

Master ownership map: `nexus-portal/docs/CANONICAL_DATA_OWNERSHIP.md`.

## Authentication

| Mechanism | Where | Notes |
|-----------|-------|-------|
| Firebase ID token (session) | All user-facing endpoints | Standard AM login. |
| `X-API-Key` | `/api/vehicles/index.php`, `/api/loadout-manifests/index.php`, `/api/mutations/index.php` | Server-to-server; resolves to `FIREBASE_ADMIN_BEARER_TOKEN` (minted). |
| `FIREBASE_ADMIN_BEARER_TOKEN` / `am_firestore_admin_access_token()` | Canonical sync cache writes | Service-account OAuth2 access token (admin, rules bypassed). |

## Exposed APIs

| Method | Path | Purpose | Auth | Consumers |
|--------|------|---------|------|-----------|
| GET | `/api/vehicles` | Vehicle asset registry | X-API-Key | FM, PR, Nexus |
| GET | `/api/loadout-manifests/index.php` | Loadout manifests | Bearer / api_key | FM |
| GET | `/api/mutations/index.php` | Mutation log | X-API-Key | Nexus, reporting |

## Canonical data sync (consumer)

AM pulls canonical reference data from PR/HR/FM into AM-owned
`am_reference_*` Firestore cache collections (see `docs/CANONICAL_DATA_SYNC_PLAN.md`):

| Type | Source | Upstream API | Cache collection |
|------|--------|--------------|------------------|
| sites | PR | fanout → `/api/sync/site-ingest.php` | `am_reference_sites` |
| organizations | PR | `prCatalogApi/api/organizations` | `am_reference_organizations` |
| countries | PR | `prCatalogApi/api/countries` | `am_reference_countries` |
| employees | HR | `/api/employees/directory` | `am_reference_employees` |
| departments | HR | `/api/departments` | `am_reference_departments` |
| vehicles | FM | `/api/integrations/v1/vehicles` | `am_reference_vehicles` |

Sync runs: systemd timer `am-canonical-sync.timer` (incremental every 15 min) +
`am-canonical-sync-full.timer` (daily 03:00 UTC). Admin UI: `/admin/canonical-sync.php`.

Runtime loaders (`am_get_pr_sites()`, `am_employee_directory_load()`) read the
cache with the minted admin token — so UI dropdowns populate even when the user's
Firebase session has expired.

## Change management

- AM does not write to PR/HR/FM-owned collections (item categories excepted —
  see master ownership map row 8, being retired).
- New cache types: add a pull client in `web/config/canonical_sync.php` + a
  `match` block in `nexus-portal/firestore.rules`.
- AM-owned collections: `am_core_*`, `am_reference_*`, `am_canonical_sync_*`.

## Nexus SSO (centralized auth)

Nexus (`nexus.1pwrafrica.com`) is the IdP. `require_login()`
(`web/config/app.php`) redirects unauthenticated users to
`/sso/authorize?tool=am&redirect_uri=https://am.1pwrafrica.com/sso.php?return=<path>`;
`web/sso.php` exchanges the custom token client-side (`signInWithCustomToken`)
and reuses `POST /auth/firebase-login.php` to create the PHP session.
**Emergency fallback:** `/login.php?fallback=1` (Firebase email/password).
Full flow + outage procedure: `nexus-portal/docs/NEXUS_AUTH_RUNBOOK.md`.
