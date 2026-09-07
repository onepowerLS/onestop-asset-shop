---
title: Inventory read API for forecast and reporting
category: feature
released_at: 2026-09-07
deep_link: /api/v1/health
---

Machine consumers can now read stock on hand, allocations, movements and the part master via `/api/v1/*` using an API key.

This does not change any AM screen. It is the feed uGridPREDICT uses to constrain connection targets by material actually in the store.
