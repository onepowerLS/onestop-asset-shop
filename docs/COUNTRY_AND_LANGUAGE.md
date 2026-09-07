# Country scope and language (AM)

## Country access (`amCountryAccess`)

Asset Management enforces **which countries a user may manage** using the signed Nexus privilege claim's **`scopeCountries`** (ISO-2 codes: `LS`, `ZM`, `BJ`; `BN` is accepted as a Benin alias). ISO-3 codes (`LSO`, `ZMB`, `BEN`) are accepted and normalized to AM's internal ISO-3 form.

- **Empty scope list = GLOBAL grant.** An unscoped assignment (or protected superadmin) signs an empty `scopeCountries`, which AM resolves to all org countries (`LSO`, `ZMB`, `BEN`).
- **Scoped assignments** restrict viewing and mutation to the listed countries.
- Sessions predating the claim (emergency `?fallback=1` login) fall back to the legacy Firestore profile field **`amCountryAccess`** on `users/{uid}` for viewing; such sessions are read-only regardless of scope.

Operations staff in Lesotho carry e.g. `["LS"]`; Zambia `["ZM"]`; Benin `["BJ"]`. Regional leads may have multiple entries.

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
