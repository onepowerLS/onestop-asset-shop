# Country scope and language (AM)

## Country access (`amCountryAccess`)

Asset Management enforces **which countries a user may manage** using Firestore field **`amCountryAccess`** on `users/{uid}` (array of `LSO`, `ZMB`, `BEN`), or Nexus layout **`systemAccess.am.countryAccess`**.

- **Managers / Viewers / Auditors:** must have at least one code or they see no assets and cannot mutate data.
- **Admins (AM role mapped from PR):** if `amCountryAccess` is empty, AM treats them as allowed for **all** org countries (`LSO`, `ZMB`, `BEN`) for backwards compatibility. Prefer setting explicit codes for clarity.

Operations staff in Lesotho should have e.g. `["LSO"]` only; Zambia `["ZMB"]`; Benin `["BEN"]`. Regional leads may have multiple entries.

## Session filter (UI)

After login, users with **more than one** allowed country see a **Country scope** control in the top bar:

- **All my countries** — lists and dashboards use the union of allowed codes.
- **One country only** — narrows listings to that country (does not grant extra rights; it only filters).

Single-country users see a read-only label instead of the dropdown.

## Language

**English** and **Français** are available via the **Language** control in the top bar. Choice is stored in session and a long-lived cookie (`am_lang`). UI strings use `web/config/locale.php` and `web/config/i18n/ui_*.php`; extend those files as more pages are translated.

## Enforcement

- **PHP session:** Listing and mutation pages check country access (see `web/config/country_scope.php`).
- **Firestore rules:** Still rely on existing `permissionLevel` / role checks. Row-level country enforcement in rules would require custom claims or mirroring `country_id` in rules — coordinate before changing shared rules.

## Procurement (PR)

Purchase Request / procurement behaviour is implemented in **pr.1pwrafrica.com**, not in this repo. Apply the **same organisational policy** there (per-country procurement staff) via PR’s own profile fields and UI filters.
