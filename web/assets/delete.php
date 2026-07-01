<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/asset_delete.php';

require_login();
am_ensure_country_scope_from_session();
am_require_can_mutate();

if (!am_is_manager_role()) {
    $_SESSION['flash_error'] = 'Only Managers/Admins can delete items.';
    header('Location: ' . base_url('assets/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . base_url('assets/index.php'));
    exit;
}

$assetId = trim((string)($_POST['asset_id'] ?? ''));
$reason = trim((string)($_POST['delete_reason'] ?? ''));

if ($assetId === '') {
    $_SESSION['flash_error'] = 'Missing item id.';
    header('Location: ' . base_url('assets/index.php'));
    exit;
}

$countries = am_firestore_get_collection('pr_master_countries', 500);
$asset = am_firestore_get_document('am_core_assets', $assetId);
if (!$asset || !is_array($asset)) {
    $_SESSION['flash_error'] = 'Item not found or already deleted.';
    header('Location: ' . base_url('assets/index.php'));
    exit;
}

am_require_asset_visible($asset, $countries);

$res = am_asset_archive_and_delete($assetId, $reason);
if (!empty($res['ok'])) {
    $_SESSION['flash_success'] = 'Item deleted and archived.';
    header('Location: ' . base_url('assets/index.php'));
    exit;
}

$_SESSION['flash_error'] = (string)($res['error'] ?? 'Delete failed.');
header('Location: ' . base_url('assets/view.php?id=' . urlencode($assetId)));
exit;
