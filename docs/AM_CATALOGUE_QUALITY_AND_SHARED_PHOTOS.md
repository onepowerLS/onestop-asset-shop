# AM catalogue quality and shared reference photographs

Status: implementation specification, 13 September 2026. This document does not deploy
new controls, upload storage or incentives. It follows Matt's request to prevent forecast
mapping delays through normal AM operation and reuse reference photos across countries.

## Outcome

A new site normally selects already verified parts. Forecasting consumes those mappings;
its correction screen handles exceptions. One approved reference photo set serves all
countries using the same verified part variant. Country stock, ownership and receipt
records remain separate. Every UGP part must have an AM counterpart; AM-only items do
not need a UGP mapping.

## Observed gaps

- `web/assets/add.php` creates country-specific items without a required UGP relevance
  decision or a link to a shared product definition.
- `web/assets/ugp-mapping.php` and PR `functions/src/receipts/mapping.ts` implement audited
  approval for a fixed MAS candidate set. This is not a general catalogue stewardship workflow.
- AM item add/edit/view pages have no reference photograph upload/gallery.
- Forecast readiness requires a complete AM catalogue plus compatible active mappings.
  An incomplete capture invalidates coverage even when individual links exist. It must
  be distinguished from genuinely missing or incompatible mappings.
- Existing PR–AM receipt enforcement is a prospective pilot, not a universal closeout rule.

## Shared identity and data model

Introduce an AM part definition independent of country inventory. Use a stable AM ID
for every type, including office supplies. Fields include human-readable name, description,
manufacturer/model when applicable, technical specification, unit, revision, active state,
and classification: UGP-linked / AM-only / needs classification. UGP links require approval,
reviewer, evidence, timestamp and verified specification revision. AM-only classification
requires a reason and review where the purchase or UGP context indicates network use.
Do not let an operator bypass a required UGP link by marking it AM-only.

Country asset records link to this definition after explicit identity review. Do not
merge country asset documents, balances or similarly named records automatically. For
compatibility keep the existing ugp_part_id read contract until consumers migrate; derive
it through an audited, consistent publication path rather than two editable authorities.
A UGP requirement may have several approved manufacturer variants. Photos belong to the
specific variant represented; a generic UGP family photo must be labelled illustrative
and must not imply that every substitute is identical.

Proposed records: am_part_definitions, am_part_media, am_catalogue_tasks and an append-only
review history. These are proposed names, not existing deployed collections.

## Enforce quality where work happens

1. Search and select the shared catalogue first on AM item creation and PR line preparation.
   Show photo, technical specification, unit and mapping status together. Creating a new
   definition is an explicit alternative, with duplicate suggestions but no automatic merge.
2. Allow draft records and record physical arrivals immediately. Missing catalogue metadata
   creates an assigned task; never prevent staff from recording goods that actually arrived.
3. Before a new definition is published as verified/forecast-ready, require specification,
   unit, relevance classification and an approved UGP link when applicable. UGP publication
   should create or request its AM counterpart before that UGP part is released for planning.
4. For orders enrolled in receipt enforcement, retain the receipt coverage checks and require
   applicable catalogue tasks to be resolved before administrative closeout. Roll out to new
   orders explicitly, with audited exceptions and due dates; do not retroactively trap old orders.
   A closeout exception must not certify an unresolved mapping or make unknown stock usable.
5. Material changes to specification, unit, model or mapping invalidate the relevant approval
   and create a new review task. Historical receipts and forecast snapshots retain their versions.
6. Apply validation to server/API/import/sync writes, not just form controls. Cover legacy write
   paths and enforce signed privileges. Operators propose; authorized AM approvers verify their
   permitted records. Global publication needs an explicitly scoped catalogue steward permission;
   country approval must not silently grant authority over all countries.
7. Maintain a named-owner queue with age, due date and site impact: classify item, verify match,
   resolve unit/specification conflict, or capture first reference image. Keep integration failures
   in a separate maintainer queue. Display actionable reasons rather than a single unresolved flag.

## Photographs: capture once, reuse deliberately

Add a Reference photos section to the AM catalogue and item views. Show approved shared
photos automatically when the record has a verified definition/variant link. Label origin,
caption and approval; make clear these identify a type and are not proof of current stock,
condition or a particular receipt. Optional receipt/damage photos remain separate evidence.

Mobile flow: open item (or scan its existing tag), see whether usable shared photos already
exist, choose Take photo or Upload, add a caption, preview and submit. Request a clear overall
view and a close-up of markings/model/rating when needed to distinguish the item. A dimension
reference is useful for ambiguous fittings. Explain the reason for any rejected image.
Existing approved photos remove the capture task everywhere that exact variant is used.
Do not require a fresh photo at every delivery or in each country. A verified correction or
new variant may need an additional photo. Support non-UGP AM items with the same facility.

Store image objects separately from database metadata, behind authenticated access. Approved
reference media may be read by authorized AM users across countries without exposing the
source asset, local balances, invoices or other country-restricted records. Pending images
are limited to uploader and authorized reviewers. Forecast users may see approved reference
media only through their own explicit authorized read route; PR approval access does not
implicitly grant unrestricted AM access.

Validate actual image content and size, decode/re-encode supported formats, strip GPS/EXIF,
create thumbnails, reject executable/SVG payloads, and use generated storage keys rather than
user paths. Upload authorization, CSRF protection, quotas and orphan cleanup are required.
Confirm production storage, backup and authenticated delivery before release; do not assume
an existing bucket is configured. Handle mobile format conversion or give a clear supported
format error. Retrying submission must not create duplicate records or contributions.

Approval records capture contributor, reviewer, canonical variant and content hash. Detect
identical images and allow visual duplicate review. Never use image similarity alone to
approve technical equivalence. Replacement is versioned; removal/rejection updates catalogue
coverage and records an audit event.

## Motivation and operating routine

Start with assigned, achievable work during receiving and stock counts: for example, five
missing high-impact part types per store each week, adjusted to the types actually present.
This is a proposed pilot target, not an imposed quota. Give staff capture time, a phone and
connectivity; preserve form state and allow retry after a connection failure.

Credit the first accepted, useful photo set for a unique variant, not every uploaded image.
Show the contributor on the reference card and recognize team progress at the existing
operations meeting. Recognize reviewers and specification corrections too. Cross-country
reuse counts as organizational benefit, not a missed target for the receiving country.
Do not penalize teams with an already complete catalogue or reward duplicate uploads.
Any cash/bonus scheme needs a separately approved budget and HR process; none is authorized here.

Pilot measures: approved unique photo coverage; active UGP mapping coverage; median time to
resolve catalogue tasks; review turnaround; rejection/duplicate rate; and forecasts delayed
by mapping versus source failures. Use high-impact active parts as the initial denominator,
then report full UGP catalogue coverage separately. Missing photos should create work, not
block a technically valid forecast: image completeness and mapping readiness are different.

## Implementation order and acceptance

1. Generalize mapping stewardship and introduce shared definitions with conservative migration.
   Prove a verified definition can be reused across countries without moving or exposing stock.
   Retire the fixed candidate restriction only when canonical selection and equivalent evidence
   validation are available. Do not replace it with unrestricted arbitrary identifiers.
2. Add authenticated upload, review and shared gallery, English/French instructions and camera
   examples. Test unsupported/oversized images, permissions, metadata stripping, retry/dedup,
   withdrawal, and approved-photo reuse without access to another country's asset record.
3. Integrate selection/classification and assigned tasks into AM creation and PR receipt closeout.
   Test UI and alternate write paths, AM-only items, unit changes, exceptions and first receipt
   capture with incomplete metadata. Existing double-count protection remains mandatory.
4. Extend read APIs and forecasting with definition/approval versions, actionable mapping reasons,
   and optional approved thumbnails. Successful refresh creates a new snapshot; never rewrite
   an existing pack. Handle complete pagination independently from AM staff's mapping decisions.
5. Pilot with the 36 MAS BOM parts and actual staff ownership, then expand to Benin and Zambia.
   Validate that the same approved variant/photo appears in all three countries while local
   balances remain distinct. Confirm a missing photo alone does not block forecast calculation.

Release is not complete until storage/access rules, server enforcement, read API compatibility,
browser/mobile upload and cross-country isolation tests, guides and rollback are verified.
