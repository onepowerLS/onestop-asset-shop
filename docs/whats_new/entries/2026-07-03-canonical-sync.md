---
title: Canonical data sync (sites, people, vehicles from PR/HR/FM)
category: improvement
released_at: 2026-07-03
deep_link: /admin/canonical-sync.php
---

Reference data (sites, organizations, countries, employees, departments, vehicles) now comes from an AM-owned cache that pulls the canonical source systems (PR, HR, FM) via their APIs on a 15-minute cycle.

This fixes the empty site/people dropdowns that appeared when your Firebase session expired — dropdowns now read the cache with a service token, so they always populate regardless of your login state. Admins can refresh any type on demand from Admin → Canonical sync.
