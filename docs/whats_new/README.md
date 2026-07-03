# What's New entries

This directory is the in-repo record of entries added to the in-app **What's New** primer at login (see `docs/SYSTEM_SPECS.md` §1).

## Why this exists

The live What's New entries are stored in Firestore (`am_core_whats_new`), not in this repo, so CI can't inspect them directly. The marker files under `entries/` are the repo-side signal that an entry was added in the deployed app. They also serve as a readable changelog.

## Policy

Per `docs/SYSTEM_SPECS.md` §1, any commit that adds or reconfigures a user-facing feature MUST be accompanied by a What's New entry. Enforcement:

- `.github/workflows/whats-new-check.yml` — blocks PR merge if the policy is violated.
- `scripts/check-whats-new.sh` — local pre-push hook (install below).

A commit counts as **feature-bearing** if its subject starts with `feat:`, `feature:`, `improve:`, or `enhance:` (Conventional Commits style).

Pure fixes, chores, refactors, docs, tests, style, or perf changes (`fix:`, `chore:`, `refactor:`, `docs:`, `test:`, `style:`, `perf:`) do NOT require a marker — unless the change materially changes a user-visible workflow, in which case use `feat:`/`improve:` for the commit subject.

## Workflow when shipping a feature

1. Build the feature.
2. Add the entry in the deployed app at **Admin → What's New** (`/admin/whats-new.php`) — this is what users see.
3. In the same commit (or same PR), add a marker file `docs/whats_new/entries/YYYY-MM-DD-slug.md` using the template below.
4. Push / open PR. CI runs `scripts/check-whats-new.sh` and blocks if a feature commit has no marker.

## Marker file template

```md
---
title: <short title shown in the modal>
category: feature | improvement | fix | announcement
released_at: YYYY-MM-DD
deep_link: /path/in/app
---

<one-line summary>

<1–3 sentences explaining what changed and why the user should care>
```

`category` should match one of the values in `web/config/whats_new.php` (`am_whats_new_category_labels`).

## Install the local pre-push hook (optional but recommended)

The CI check runs on PRs. For fast local feedback before you push, install the hook:

```bash
cat > .git/hooks/pre-push <<'EOF'
#!/bin/bash
exec bash scripts/check-whats-new.sh
EOF
chmod +x .git/hooks/pre-push
```

To bypass in an emergency (NOT RECOMMENDED): `git push --no-verify`. Bypasses are visible in CI because CI runs on the PR regardless.

## Examples

See `entries/2026-07-01-bulk-ready-board-allocation.md` for a real example.
