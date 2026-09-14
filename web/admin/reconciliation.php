<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/pr_receipts.php';
require_once __DIR__ . '/../config/reconciliation.php';
require_login();
am_ensure_country_scope_from_session();
if (!am_is_manager_role()) { http_response_code(403); exit('An AM approver must host the joint RET–AM workshop. Relaunch AM from Nexus with your own authorized account.'); }
$catalog = json_decode(file_get_contents(__DIR__ . '/../data/mas-mapping-review.json'), true);
$parts = $catalog['parts']; $ids = array_keys($parts);
$partId = (string)($_GET['part'] ?? $ids[0]);
if (!isset($parts[$partId])) { http_response_code(404); exit('Part is not in this MAS pilot.'); }
$part = $parts[$partId]; $countries = am_get_countries();
$assets = array_values(array_filter(am_firestore_get_collection('am_core_assets', 1000), fn($a) =>
    am_asset_passes_country_scope($a, $countries) && am_asset_passes_org_scope($a, $countries) &&
    in_array(am_country_code_for_id((string)($a['country_id'] ?? ''), $countries), ['LSO','LS'], true) &&
    in_array($a['item_class'] ?? '', ['Material', 'Consumable', 'Inventory'], true) &&
    !in_array($a['status'] ?? '', ['Retired', 'WrittenOff', 'Missing'], true)));
// Some country masters use ISO2 as country_code. Normalize through the existing scope resolver.
$allById = []; foreach ($assets as $a) $allById[$a['id'] ?? $a['asset_id']] = $a;
$_SESSION['am_receipt_csrf'] ??= bin2hex(random_bytes(32));
$error = ''; $message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = (string)($_POST['event_id'] ?? '');
    $sessionReview = $_SESSION['am_workshop_forms'][$eventId] ?? null;
    if (!am_receipt_csrf_valid() || !$sessionReview || $sessionReview['part'] !== $partId || time() - $sessionReview['at'] > 3600) $error = 'This review form expired. Reload and review the current items.';
    else {
        $selected = $_POST['asset_ids'] ?? [];
        if (!is_array($selected) || count($selected) > 10 || array_diff($selected, array_keys($sessionReview['identities']))) $error = 'Select only items displayed in this review.';
        else {
            $expected = array_intersect_key($sessionReview['identities'], array_flip($selected));
            $result = am_call_receipt_function('saveAmReconciliation', [
                'eventId' => $eventId, 'ugpPartId' => $partId, 'assetIds' => $selected, 'expectedAssets' => (object)$expected,
                'decision' => (string)($_POST['decision'] ?? ''), 'evidence' => (string)($_POST['evidence'] ?? ''),
                'retParticipant' => (string)($_POST['ret_participant'] ?? ''), 'ownerName' => (string)($_POST['owner_name'] ?? ''),
                'dueDate' => (string)($_POST['due_date'] ?? ''), 'retVerified' => isset($_POST['ret_verified']),
                'amVerified' => isset($_POST['am_verified']), 'currentSpecificationVerified' => isset($_POST['current_specification_verified'])]);
            if ($result['ok']) {
                $_SESSION['am_workshop_ret'] = trim((string)($_POST['ret_participant'] ?? ''));
                $_SESSION['flash_workshop'] = !empty($result['result']['published']) ? 'Published: selected AM items now use the shared UGP number and description. Old names remain aliases. Stock quantities were not changed.' : 'Decision saved. Follow-up tasks appear in Catalogue tasks. No notification was sent; agree the assignment with the named owner.';
                header('Location: reconciliation.php?part=' . rawurlencode($partId)); exit;
            } else $error = $result['message'];
        }
    }
}
$message = $_SESSION['flash_workshop'] ?? ''; unset($_SESSION['flash_workshop']);
$q = trim((string)($_GET['q'] ?? ''));
$filtered = array_values(array_filter($assets, fn($a) => $q === '' || str_contains(strtolower(implode(' ', [$a['name'] ?? '', $a['description'] ?? '', $a['asset_tag'] ?? '', $a['manufacturer'] ?? '', $a['model'] ?? '', implode(' ',(array)($a['catalogue_aliases']??[])), $a['canonical_part_number']??''])), strtolower($q))));
usort($filtered, fn($a, $b) => am_workshop_rank($b, $partId, $part) <=> am_workshop_rank($a, $partId, $part));
$shown = array_slice($filtered, 0, $q === '' ? 12 : 60); $eventId = $error !== '' && !empty($sessionReview) ? (string)$_POST['event_id'] : bin2hex(random_bytes(16)); $identities = [];
foreach ($shown as $a) $identities[$a['id'] ?? $a['asset_id']] = am_workshop_identity($a);
if (!isset($_SESSION['am_workshop_forms'][$eventId])) $_SESSION['am_workshop_forms'][$eventId] = ['at' => time(), 'part' => $partId, 'identities' => $identities];
while (count($_SESSION['am_workshop_forms']) > 8) array_shift($_SESSION['am_workshop_forms']);
$media = am_firestore_get_collection('am_part_media', 1000);
$definitions = am_firestore_get_collection('am_part_definitions', 1000);
$approved = array_column(array_filter($definitions, fn($d) => !empty($d['canonical_approved'])), 'ugp_part_id');
$history = array_values(array_filter(am_firestore_get_collection('am_core_mapping_reviews', 1000), fn($r) => ($r['partId'] ?? '') === $partId));
usort($history, fn($a,$b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
$page_title = 'RET–AM reconciliation workshop';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/reconciliation_view.php';
include __DIR__ . '/../includes/footer.php';
