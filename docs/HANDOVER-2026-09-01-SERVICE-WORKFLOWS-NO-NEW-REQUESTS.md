# Handover: Service Workflows tab shows no requests created after 2026-08-27

| | |
|---|---|
| **Date prepared** | 2026-09-01 |
| **System** | OneStop Asset Shop (AM) — https://am.1pwrafrica.com |
| **Repo** | `onepowerLS/onestop-asset-shop` (production deploys from `main`) |
| **Status** | Diagnosed. Root cause is on the **Nexus portal side** (user provisioning), not in AM code. No AM deploy required to fix. |
| **Severity** | High — effectively no user can create new service workflow requests (dispatch / ready board / IT equipment), and non-privileged users cannot fulfil existing ones. |

---

## 1. Reported symptom

> "Completed requests in the AM system do not show up in the Service Workflows tab. The last items that can be seen are AMW-2026-00131, Dispatch Request made in 2026-08-27. Nothing afterwards is shown."

The Service Workflows tab is `web/requests/workflow-index.php` (on `main`). It lists every document in the Firestore collection `am_core_requests` (shared Firebase project `pr-system-4ea55`).

## 2. Key finding

**The list is not broken — the requests were never created.** Production data confirms the last request document in `am_core_requests` is AMW-2026-00131 (created 2026-08-27 08:03 UTC, fulfilled 08:38 UTC). Nothing exists after that. New submissions are being blocked in the AM app *before* any Firestore write happens, by the authorization layer.

## 3. Evidence (production Firestore, queried 2026-09-01 ~07:45 UTC)

All checks below were performed read-only via the Firestore REST API with the test admin account (see `context.md`), plus one create/delete-permission probe (see §8 Housekeeping).

| Check | Result |
|---|---|
| `am_core_requests` document count | 131 docs; newest is AMW-2026-00131 (2026-08-27 08:03 UTC). **Zero docs created after that.** |
| Request docs *updated* after 2026-08-27 | Only AMW-2026-00131 itself (fulfilled 08:38 UTC same day). |
| Writes to other AM collections after 2026-08-27 | **Working.** `am_core_mutation_logs` shows successful user writes through 2026-08-31: `am_core_assets` update (molibeli@, 14:36), `am_core_inventory_levels` update (mofokeng@, 10:38), `am_core_loadout_manifests` create (2026-08-27), whats-new dismissals. |
| Firestore security rules | **Not the blocker.** A direct REST `create` into `am_core_requests` with a fresh user ID token succeeded. (Delete is correctly denied — 403 — for a non-admin claim set.) |
| Firebase refresh-token exchange (`securetoken.googleapis.com`) | Works — the app's token-refresh path is not globally broken. |
| `am_core_phone_requests` | Empty (phone requests are a separate collection and not the issue). |
| `nexus_users` audit (all 169 docs) | **Only 1 of 169 users — molibeli@1pwrafrica.com — has a `systemAccess.am` entry.** All others (incl. the main dispatch requester monoane@1pwrafrica.com, seshemane@1pwrafrica.com, and even testadmin@1pwrafrica.com) have none. |
| `nexus_users` batch updates | 98 user docs were rewritten by a batch job at **2026-09-01 00:00:45 UTC** (plus smaller batches 2026-08-31 00:00 and 04:30). Suspected nightly Nexus sync — needs review (see §6). |

## 4. Root cause

Since the **claim-only authorization migration (deployed 2026-08-10 → 2026-08-12**, commits `1e26f7f`, `b6bbe2a`, `5a1a5c1`), AM derives a user's privileges **exclusively from the signed Nexus `effectivePrivilege` claim** in their Firebase ID token:

1. At login, `web/auth/firebase-login.php` reads the claim via `am_nexus_privilege_from_verified_id_token()` (`web/config/firebase.php`) and snapshots it into the PHP session (`$_SESSION['privilege_actions']`, `privilege_level`, …).
2. Nexus signs that claim from the user's `nexus_users.systemAccess.am` entry. **168 of 169 users have no such entry**, so freshly minted tokens carry no AM grant.
3. Request submission is gated on Level D (`request_assets`) via `am_require_can_request()` in `web/config/authz.php` (added by `1a38af4`, 2026-08-26). Without the claim, the user falls back to their PR-derived role (e.g. PR permissionLevel 5 → AM "Viewer"), which is not sufficient. The POST is redirected with a privilege-denial flash and **no Firestore write is ever attempted**. Fulfilment (Level C, `am_require_can_mutate()`) is blocked the same way.
4. Because privileges are snapshotted into the session at login, users with pre-migration sessions kept working until their session expired — which is why the die-off was gradual rather than instantaneous. monoane@'s last successful submissions were 2026-08-26/27 (right after the Level D fix deployed); his next login (2026-09-01 07:04 UTC) came back with no AM grant.
5. The read path was separately fixed on 2026-08-31 (`169bf1b`, admin-bearer fallback + error logging in `am_firestore_get_collection` / `am_firestore_get_document`), so **pages render normally** and the list shows all historical requests — masking the fact that nothing new can be written.

### Why it presents as "completed requests don't show up"

Reads work fine (admin-bearer fallback), so the Service Workflows tab looks healthy with all 131 historical requests. Meanwhile every new submission is silently blocked at the app layer. The list simply never grows.

## 5. Affected functionality

| Action | Gate | Blocked for users without AM grant? |
|---|---|---|
| Submit dispatch request (`requests/dispatch-new.php`) | Level D `request_assets` | Yes — POST redirects with denial; form shows "Read-only accounts cannot submit dispatch requests." |
| Submit workflow / ready-board request (`requests/workflow-new.php`, `requests/index.php`) | Level D | Yes |
| Submit phone request (`phone-requests/index.php`) | Level D | Yes |
| Approve / reject / fulfil requests (`requests/workflow-index.php`, `requests/dispatch-view.php`, `requests/workflow-view.php`) | Level C `operate_assets` | Yes |
| View lists and detail pages | read (with admin-bearer fallback since 2026-08-31) | No — this is why the UI looks normal |

## 6. Remediation (Nexus portal side — owner: Nexus/IS&T)

1. **Provision `systemAccess.am` for all AM users** in the Nexus portal (`nexus_users` collection). Suggested mapping per the privilege ladder in `web/config/authz.php`:
   - Requesters (e.g. monoane@, seshemane@): Level D — actions `view_assets`, `request_assets`.
   - Warehouse / fulfilment staff: Level C — `operate_assets`.
   - Managers / admins: Level B / A respectively.
   - Include `scopeCountries` / `scopeOrganizations` per user (LSO/ZMB/BEN; note the new `kuwala` org for ZM asset-co, see commit `7761a88`).
2. **Review the nightly Nexus sync job** that batch-rewrites `nexus_users` docs (98 users at 2026-09-01 00:00:45 UTC). Confirm it creates/preserves `systemAccess.am` and isn't stripping AM grants when it recomputes access.
3. **Have users sign out and back in via Nexus SSO** after provisioning — privileges are only refreshed at login (session snapshot).
4. Optional AM-side hardening (separate PR, not required for the fix): surface a persistent "you don't have Asset Management access — contact IS&T" state instead of an easily-missed flash redirect, and consider logging denied `am_require_can_request()` attempts so gaps like this are visible in `error_log`.

## 7. Verification steps (after remediation)

1. Before the fix, ask monoane@ to open `requests/dispatch-new.php` — he should currently see the read-only banner. This confirms the diagnosis.
2. Provision his `systemAccess.am` (Level D), have him sign out/in via Nexus, and submit a test dispatch.
3. Confirm the new document appears in `am_core_requests` and in the Service Workflows list.
4. Confirm a Level C user can approve/fulfil it from `requests/dispatch-view.php`.
5. Check `/var/log/php-fpm/www-error.log` on the EC2 host for `[am_firestore_get_collection]` / `[am_firestore_get_document]` lines — these indicate how often the read fallback is being hit (a symptom of expired session tokens).

## 8. Housekeeping left in production

- **`DIAG-DELETE-ME`** (doc id `LBBdkHNCk9n4Uvh711AL` in `am_core_requests`): created during this investigation to prove Firestore rules accept writes. Already neutralized (status `Cancelled`, `requested_date` 2020-01-01 so it sorts to the bottom, description marks it for deletion). **Please delete it with an AM admin account** — the test admin token gets a 403 on delete, which itself confirms the rules ladder is enforced at the database level.
- Two **empty documents** in `am_core_requests` created 2026-08-12 ~19:49–19:52 UTC by `amtest@1pwrafrica.com` (no fields at all) — likely artifacts of claim-migration testing. Safe to delete.

## 9. Reference: relevant commits and files

| When | Commit | Relevance |
|---|---|---|
| 2026-08-10 | `1e26f7f` | Enforce signed Nexus AM privileges (claim-only authz begins) |
| 2026-08-11/12 | `b6bbe2a`, `5a1a5c1` | Claim-only authorization + claim refresh; read AM grant from per-system claims map on refreshed tokens |
| 2026-08-26 | `1a38af4` | Allow Level D requesters to submit dispatch/phone/workflow requests (`am_require_can_request`) |
| 2026-08-28 | `7761a88` | Kuwala (ZM asset-co) added to org→country map — unrelated to this issue, but note `am_country_to_org_map()` `array_flip` is now ambiguous for ZMB (last entry `kuwala` wins) |
| 2026-08-31 | `169bf1b` | Admin-bearer fallback + error logging for Firestore reads (fixed the *read*-side symptom of expired tokens) |

Key files (on `main`):

- `web/requests/workflow-index.php` — Service Workflows list (reads `am_core_requests`)
- `web/requests/dispatch-new.php`, `web/requests/workflow-new.php` — submission paths, gated by `am_require_can_request()`
- `web/config/authz.php` — privilege ladder and gates
- `web/config/firebase.php` — `am_nexus_privilege_from_verified_id_token()` (claim → session)
- `web/auth/firebase-login.php` — login; snapshots claims into the session
- `web/config/firestore.php` — Firestore helpers; read-path admin-bearer fallback (2026-08-31)

## 10. Open questions for the assignee

- Which Nexus job rewrote 98 `nexus_users` docs at 2026-09-01 00:00:45 UTC, and does it manage `systemAccess.*` entries?
- Was there ever a bulk provisioning of `systemAccess.am`, or did pre-migration AM access rely solely on the legacy PR-role fallback (which claim-only authz no longer honors)?
- Should AM keep supporting the PR-role fallback as a safety net while Nexus provisioning is completed?
