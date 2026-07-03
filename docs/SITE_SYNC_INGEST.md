# AM Site Sync Ingest

AM receives PR site fanout events at `web/api/sync/site-ingest.php`.

## Authentication

Either of:

- `X-API-Key: <SITE_SYNC_FANOUT_API_KEY>`
- `Authorization: Bearer <Firebase ID token>`

If API-key mode is used, the endpoint resolves Firestore auth through `FIREBASE_ADMIN_BEARER_TOKEN`.

## Behavior

- Validates canonical payload and coordinate bounds.
- Upserts site cache into `am_reference_sites` by document ID:
  - `<organizationId>_<siteCodeLower>`
- Stores idempotency receipts in `am_site_sync_events` using `idempotencyKey`.
- Duplicate deliveries return success with `idempotent: true`.

## Environment Variables

- `SITE_SYNC_FANOUT_API_KEY`
- `FIREBASE_ADMIN_BEARER_TOKEN`

## Runtime Consumption

`am_get_pr_sites()` now checks `am_reference_sites` first, then falls back to PR collections (`sites` and `referenceData_sites`). This keeps location dropdowns current even when PR fanout arrives before periodic pull-sync cycles.
