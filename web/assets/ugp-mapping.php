<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/pr_receipts.php';
require_login();
if (!am_is_manager_role()) { http_response_code(403); exit('AM approver access is required.'); }
$assetId = (string)($_GET['id'] ?? '');
$asset = am_firestore_get_document('am_core_assets', $assetId);
if (!$asset) { http_response_code(404); exit('Item not found.'); }
am_require_asset_visible($asset, am_get_countries());
$catalog = json_decode(file_get_contents(__DIR__ . '/../data/mas-mapping-review.json'), true);
$choices = array_filter($catalog['parts'], fn($p) => $p['category'] === 'candidate' && in_array($assetId, $p['candidateIds'], true));
$_SESSION['am_receipt_csrf'] ??= bin2hex(random_bytes(32));
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!am_receipt_csrf_valid()) $error = 'Session changed; reload this page.';
    else {
        $result = am_call_receipt_function('confirmAmUgpMapping', [
            'assetId' => $assetId, 'ugpPartId' => (string)($_POST['ugp_part_id'] ?? ''),
            'expectedUgpPartId' => $asset['ugp_part_id'] ?? null,
            'canonicalPartVerified' => isset($_POST['canonical_verified']), 'specificationVerified' => isset($_POST['specification_verified']),
            'evidence' => (string)($_POST['evidence'] ?? ''),
        ]);
        if ($result['ok']) $success = 'Authoritative mapping saved with an audit record. Stock quantities were not changed. Capture new forecasting inputs to update readiness.';
        else $error = $result['message'];
    }
}
$page_title = 'Verify UGP part mapping';
include __DIR__ . '/../includes/header.php';
?>
<div class="card p-4"><h1>Verify UGP part mapping</h1>
<p>AM item: <strong><?= htmlspecialchars($asset['name'] ?? $assetId) ?></strong> · <?= htmlspecialchars($asset['unit_of_measure'] ?? '') ?></p>
<p>Every UGP part needs an AM mapping. Office and other non-UGP items do not. These candidates were captured on 8 September 2026: verify the current canonical UGP specification before confirming.</p>
<?php if ($error): ?><div role="alert" class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif ?>
<?php if ($success): ?><div role="status" class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif ?>
<?php if (!$choices): ?><p>No eligible candidate for this item. Component-only and conflicting matches require source-owner resolution.</p><?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['am_receipt_csrf']) ?>">
<label class="form-label" for="part">UGP part</label><select class="form-select mb-3" id="part" name="ugp_part_id" required>
<?php foreach ($choices as $id => $part): ?><option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($part['name'] . ' — ' . $part['specification'] . ' (' . $part['unit'] . ')') ?></option><?php endforeach ?></select>
<label class="d-block mb-2"><input type="checkbox" name="canonical_verified" required> I checked this ID and specification against the current canonical UGP part.</label>
<label class="d-block mb-2"><input type="checkbox" name="specification_verified" required> I verified this AM item’s dimensions, rating, construction and unit; it is an equivalent item, not merely a component or similarly named item.</label>
<label for="evidence" class="form-label">Specification evidence and reason</label><textarea id="evidence" class="form-control mb-3" name="evidence" minlength="20" maxlength="2000" required><?= htmlspecialchars($_POST['evidence'] ?? '') ?></textarea>
<button class="btn btn-primary" type="submit">Confirm authoritative mapping</button></form>
<?php endif ?></div>
<p><a href="/help.php#pr-am-receipts">Receipt and mapping guide / Guide des réceptions</a></p>
<?php include __DIR__ . '/../includes/footer.php'; ?>
