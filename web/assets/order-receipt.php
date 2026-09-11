<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/pr_receipts.php';
require_login();
if (!am_is_manager_role()) { http_response_code(403); exit('AM approver access through Nexus is required.'); }
$prId = (string)($_GET['pr'] ?? '');
$policy = am_firestore_get_document('prReceiptOrders', $prId);
$_SESSION['am_receipt_csrf'] ??= bin2hex(random_bytes(32));
$eventId = (string)($_POST['event_id'] ?? bin2hex(random_bytes(16)));
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $policy) {
    if (!am_receipt_csrf_valid()) $error = 'Session changed; reload before submitting.';
    elseif (!preg_match('/^[1-9][0-9]{0,8}$/', (string)($_POST['quantity'] ?? ''))) $error = 'Whole positive units only in this pilot; fractional metres must not be rounded.';
    else {
        $result = am_call_receipt_function('recordAmOrderReceipt', [
            'prId' => $prId, 'eventId' => $eventId, 'revision' => $policy['revision'],
            'lineId' => (string)($_POST['line_id'] ?? ''), 'quantity' => (int)$_POST['quantity'],
            'evidence' => (string)($_POST['evidence'] ?? ''), 'acceptedAndLogged' => isset($_POST['accepted']),
        ]);
        if ($result['ok']) {
            $success = 'Receipt ' . $eventId . ' and its AM stock entry are recorded. A repeated submission with this ID will not add stock twice.';
            $policy = am_firestore_get_document('prReceiptOrders', $prId);
            $eventId = bin2hex(random_bytes(16));
        } else $error = $result['message'];
    }
}
$page_title = 'Receive approved PR goods'; include __DIR__ . '/../includes/header.php';
?>
<p><a href="reverse-order-receipt.php">Reverse a receipt / record a full return</a></p>
<div class="card p-4"><h1>Receive approved PR goods</h1><p>Only accepted goods count toward closeout. Do not use this screen for quarantine, historical opening balances, or goods already entered through another AM stock screen.</p>
<?php if (!$policy): ?><p>This order is not enrolled in the receipt pilot. Ask Procurement to review its approved lines, owner and destination.</p><?php else: ?>
<p>Order <?= htmlspecialchars($prId) ?> · owner <?= htmlspecialchars($policy['ownerId']) ?> · site <?= htmlspecialchars($policy['canonicalSiteCode']) ?></p>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div><?php endif ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($success) ?></div><?php endif ?>
<?php if (!empty($policy['exception'])): ?><div class="alert alert-danger"><?= htmlspecialchars($policy['exception']) ?></div><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['am_receipt_csrf']) ?>"><input type="hidden" name="event_id" value="<?= htmlspecialchars($eventId) ?>">
<label for="line" class="form-label">Approved line and AM destination</label><select id="line" class="form-select mb-3" name="line_id" required>
<?php foreach ($policy['lines'] as $line): ?><option value="<?= htmlspecialchars($line['lineId']) ?>"><?= htmlspecialchars($line['description'] . ': ' . $line['received'] . '/' . $line['ordered'] . ' ' . $line['unit'] . ' recorded — ' . $line['locationId']) ?></option><?php endforeach ?></select>
<label for="quantity">Accepted quantity to record now</label><input id="quantity" class="form-control mb-3" type="number" step="1" min="1" max="999999999" name="quantity" required>
<label for="evidence">Delivery-note reference and receipt evidence</label><textarea id="evidence" class="form-control mb-3" name="evidence" minlength="10" maxlength="2000" required><?= htmlspecialchars($_POST['evidence'] ?? '') ?></textarea>
<label class="d-block mb-3"><input type="checkbox" name="accepted" required> I confirm these accepted goods are being entered into AM by this receipt, and have not already been entered elsewhere.</label><button class="btn btn-primary">Record receipt and AM stock</button>
</form><?php endif ?></div>
<p><a href="/help.php#pr-am-receipts">Receipt and mapping guide / Guide des réceptions</a></p>
<?php include __DIR__ . '/../includes/footer.php'; ?>
