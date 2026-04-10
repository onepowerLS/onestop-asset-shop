---
description: CRITICAL - Firebase is shared with other production systems
globs: ["firestore.rules", "firebase.json", ".firebaserc", "**/*.rules"]
alwaysApply: false
---

# Shared Firebase Project - Production Safety

This project uses Firebase project `pr-system-4ea55` which is SHARED with:

- **PR System** (pr.1pwrafrica.com) - production purchase request system used daily
- **Job Card System** - production job tracking
- Other 1PWR internal tools

## Rules for Firestore Security Rules

1. The `firestore.rules` file in this repo MUST include rules for ALL systems, not just AM collections.
2. The catch-all wildcard MUST be `allow read, write: if request.auth != null;` -- NEVER `allow read, write: if false;`.
3. Before modifying `firestore.rules`, read the current file completely to understand all collection rules.
4. NEVER deploy firestore rules (`firebase deploy --only firestore:rules`) without verifying the rules cover: `referenceData_*`, `purchaseRequests`, `archivePRs`, `jobCards`, `departments`, `vendors`, `currencies`, and all `am_core_*` / `pr_master_*` collections.
5. If unsure, do NOT deploy rules. Use the Firebase Emulator Suite for testing.

## Rules for Firebase Auth / Config

1. Do NOT modify Firebase Authentication settings without coordinating with other projects.
2. Do NOT change Firebase project settings, hosting config, or storage rules without checking dependencies.
3. The PR System repo (`PR 25 NOV`) maintains the canonical rules file. Keep both in sync.

## What Went Wrong Before

On 2026-03-19, deploying AM-only firestore rules with a `deny-all` catch-all (`if false`) to this shared project broke the entire PR system in production, blocking all Firestore access for PR users across all countries.
