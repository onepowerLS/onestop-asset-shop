<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/pr_receipts.php';
require_login();
if (!am_is_manager_role()) { http_response_code(403); exit('AM approver access is required.'); }
$_SESSION['am_receipt_csrf'] ??= bin2hex(random_bytes(32));
$message = ''; $ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!am_receipt_csrf_valid()) $message = 'Session changed; reload this page.';
    else {
        $r = am_call_receipt_function('reverseAmOrderReceipt', ['receiptId' => (string)($_POST['receipt_id'] ?? ''), 'reason' => (string)($_POST['reason'] ?? '')]);
        $ok = $r['ok'];
        $message = $ok ? 'Full receipt reversed once, stock reduced, and PR coverage updated. A previously completed order is flagged for closeout review.' : $r['message'];
    }
}
$page_title = 'Reverse an AM order receipt'; include __DIR__ . '/../includes/header.php';
?>
<div class="card p-4"><h1>Reverse an AM order receipt</h1><p>This reverses the full receipt. Its goods must still be unallocated at the recorded location. For partial returns or goods already issued/transferred, request reconciliation first. No original audit record is deleted.</p>
<?php if ($message): ?><div role="alert" class="alert <?= $ok ? 'alert-success' : 'alert-danger' ?>"><?= htmlspecialchars($message) ?></div><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['am_receipt_csrf']) ?>">
<label for="receipt">Receipt ID</label><input class="form-control mb-3" id="receipt" name="receipt_id" required value="<?= htmlspecialchars($_POST['receipt_id'] ?? '') ?>">
<label for="reason">Return / correction evidence and reason</label><textarea class="form-control mb-3" id="reason" name="reason" minlength="10" maxlength="2000" required></textarea>
<label class="d-block mb-3"><input type="checkbox" required> I confirm that the full receipt should be reversed and its stock removed.</label><button class="btn btn-warning">Reverse full receipt</button></form></div>
<p><a href="/help.php#pr-am-receipts">Receipt and mapping guide / Guide des réceptions</a></p>
<?php include __DIR__ . '/../includes/footer.php'; ?>
