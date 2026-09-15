# RET–AM reconciliation workshop — MAS pilot

Entry: `/admin/reconciliation.php`, also linked from AM's sidebar, help/tutorial,
part definitions, and Nexus Forecast's selected-part review. An AM approver hosts
with a named RET participant. The participant name is an attestation recorded by
that signed-in approver, not a separate authenticated RET signature.

## What users can complete

Review the 36 MAS requirements against live accessible Lesotho AM stock records.
The dated candidate set ranks likely choices but does not limit inventory search.
Select up to ten identical stock records; check the current UGP specification and
both teams' evidence before publishing. The trusted `saveAmReconciliation` callable
atomically creates/reuses `am_part_definitions/ugp-{UGP ID}`, publishes the UGP name,
description and stable number on selected assets, preserves old names as searchable
aliases, attaches approved photos to the shared definition, and writes an immutable
event. Quantities, locations, ownership, manufacturers and models are not modified.
UGP already uses these identifiers/descriptions, so no UGP catalogue rewrite is needed.

A different existing mapping or definition is refused. Technical alternatives,
assembly components, missing photographs/specifications and missing items create
named follow-up tasks. These do not certify an alternative or define an assembly
conversion automatically. Rejections record evidence without changing inventory.
No notifications or financial incentives are sent. The follow-up owner must agree
with the team. Later engineering changes require steward-led reconciliation; this
pilot cannot overwrite approved canonical identities through an ordinary asset edit.

## Reference photographs

`/assets/reference-photo.php?asset=...` supports JPEG, PNG and WebP up to 5 MB
(after browser-side compression). An AM approver uploads and approves the reference
in one action. All authorized AM viewers may view approved reference images; stock
records retain their country scope. `?definition=...` provides a shared gallery
without exposing another country's stock. Photos submitted during the workshop
attach to the canonical definition on publication. Photos submitted after
publication use that definition immediately. Max five useful reference views per
item/definition; identical normalized images are rejected.

Phone cameras often produce files larger than PHP's default `upload_max_filesize`
(2M). Production must use `deployment/99-am-uploads.ini` (`10M` / `12M`) under
`/etc/php.d/` and **restart php-fpm** (httpd reload alone is not enough). The
upload form compresses to JPEG ≤1600px before POST so typical phone photos stay
under the app's 5 MB cap.

File data is re-encoded to JPEG at max 1600 pixels, strips EXIF/GPS, and is stored
outside the webroot in `AM_REFERENCE_PHOTO_DIR` (default `/var/lib/am-reference-photos`).
The PHP worker must own that directory, mode 0700; files mode 0600. Images are delivered
through authenticated PHP with no-store and nosniff headers. Metadata is in am_part_media.
No executable/SVG upload, arbitrary path or publicly accessible file URL is accepted.
If storage or metadata save fails, report the failure and clean up the new file.

Operations: include the photo directory AND Firestore media metadata in the AM backup
policy. Keep the photo directory outside release checkouts, retain it across code rollbacks,
and never delete production photos as part of deployment. The current implementation
provides no self-service deletion/replacement; a steward must review corrections. For
phone HEIC files, choose/convert JPEG before upload. Contributor credit is captured on
media records. Weekly recognition should count useful first reference sets, not file volume.

## Validation and release scope

Local PHP checks cover ranking, identity fields, image decoding/re-encoding and existing
AM authorization/inventory regressions. The isolated browser harness checks tiled selection,
publication versus follow-up controls and narrow screens without live data writes.
Firestore emulator tests cover joint attestations, stale evidence, scope, unit conflict,
atomic publication/aliases/photos, idempotent retries, dimension-bearing IDs, assigned
exceptions and direct-client bypass rejection. Existing receipt emulator tests also pass.

Deployment: restore canonical Nexus rules first, deploy only the new PR callable through
`npm run deploy:functions -- --functions=saveAmReconciliation`, then publish AM and the
Nexus link. Do not use a broad functions deployment or trigger the legacy vehicle import.
No actual canonical decisions or photographs are fabricated as a deployment check.

## Deployed 14 September 2026

AM frontend/server source: `70c6dff`; Nexus frontend: `df9f6c0`; new PR callable source:
`f80e7e3`. All are pushed (AM/Nexus main; PR release/pr-am-pilot-20260912). AM deployment
was a clean fast-forward with the legacy import deliberately not executed. Photo storage
is provisioned as 0700 apache:apache and the PHP worker has write access. Production
PHP image validation/re-encoding and AM security/read API regression checks passed.
Nexus bundle hash matches the local build and live rules exactly match the canonical source.
There are 80 functions; sign-in and receipt functions remain present. Anonymous workshop
access redirects to Nexus preserving the selected part; anonymous callable invocation is
403. The AM What's New entry is active. No real photos, mappings or catalogue approvals
were submitted during validation. Authenticated write paths were tested in the emulator,
not by impersonating a production approver.


## Specification-assisted review — 14 September 2026

The workshop now ranks and explains 13 captured candidate assessments across nine UGP requirements. One is a strong specification candidate (galvanized stay thimble); six are possible matches needing a targeted check; six expose differences or ambiguity. These are evidence bands, not calibrated probabilities. The remaining requirements retain their existing candidate search.

Each assessment in `web/data/mas-specification-evidence.json` records the exact AM identity examined, reasoning, outstanding question, and a source reference. Supplier quotations provide purchasing corroboration only; they are not linked receipts. No attachment download tokens, prices, banking information or contact details are included. Changed AM identity fields automatically invalidate the assessment. A future refresh must review the new identity and source documents before replacing it.

“Prepare review of this item” selects one displayed item and drafts its decision and evidence. It asks before replacing an existing note, clears all attestations, and moves focus to the decision step. It does not save or publish. Strong candidates still require current UGP, RET and AM confirmation. Possible matches and discrepancies default to specification follow-up.

The read-only history assessment found 14 StockIngestion events, all explicitly opening stock on catalogue creation, and no structured order receipts. Normalized receipt movements can include opening stock or stocktakes; those dates cannot substantiate procurement delivery. No automatic timing or current-balance match was asserted. Supplier quotations revealed richer model and dimension information, including a cable-tie mismatch and conflicting UGP stay-wire strand counts.

Validation: PHP identity/evidence regression checks cover all 13 entries and reject changed descriptions; browser tests cover draft selection, discrepancy decision, unchecked attestations and mobile overflow. No production review, stock update or canonical approval was performed by these tests.


## Candidate and requirement navigation — 15 September 2026

All accessible AM candidates can now be browsed in stable pages of 12, including
search results. Page links retain the requirement and search term. Previous/next
UGP requirement controls are separate and display the position in the 36-part
pilot. Navigating away from modified selections/notes asks before discarding them;
page changes are not saves. Save each agreed review before changing pages.

Photo links retain a validated pilot part ID, search term and candidate page.
Successful upload confirms that the photo was saved and provides a return link.
The page explains that returning to the existing tab preserves unsaved selections,
and that photo approval does not establish an approved canonical mapping.

Lefa's supplied workbook is reference evidence, not an approval import. Its local
names do not identify AM records uniquely; complete-kit/component ambiguities
still require RET review. No workbook quantities or proposed names were written
to inventory or the canonical definitions by this navigation change.

Validation: PHP paging checks cover first, final, empty and out-of-range pages;
browser tests traverse 25 synthetic candidates over three pages, retain filters,
exercise unsaved-change cancellation, and verify separate requirement navigation
and mobile layout. Existing workshop smoke tests also pass. No test writes to live data.
