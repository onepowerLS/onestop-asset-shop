---
title: Access now follows your signed Nexus privileges exactly
category: improvement
released_at: 2026-08-11
deep_link: /index.php
---

Authorization now comes solely from your signed Nexus privilege.

Asset Management no longer consults legacy role labels or per-user
capability flags: viewing, operating, approving, and administering follow
your signed Nexus level (view → operate → approve → administer). Role
changes in Nexus now take effect on the next automatic session refresh —
no more waiting for a full sign-out. The emergency fallback login (when
Nexus is unavailable) is read-only. Country scope is enforced from the
signed claim, including Benin (`BJ`) assignments, which previously could
be widened to all countries by mistake. If you are denied an action, the
app explains what access is required and who can grant it.
