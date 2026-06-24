<?php
/**
 * Assemble — consume inventory items and produce FixedAsset(s).
 * Records lineage (built_from) on the resulting asset.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/inventory_levels.php';
require_login();
am_ensure_country_scope_from_session();
am_require_can_mutate();

$page_title = 'Assemble / produce';
$errors = [];

// ── Reference data ──────────────────────────────────────────────
$countries = am_firestore_get_collection('pr_master_countries', 500);
$countries = array_values(array_filter($countries, fn($c) => (int)($c['active'] ?? 1) === 1));
$countries = am_countries_for_user_select($countries);

$allCategories = am_firestore_get_collection('pr_master_categories', 1000);
$allCategories = array_values(array_filter($allCategories, fn($c) => (int)($c['active'] ?? 1) === 1));
// Only FixedAsset categories for fixed-asset results; stockable for inventory/material output.
$faCategories = array_values(array_filter($allCategories, fn($c) => ($c['item_class'] ?? '') === 'FixedAsset'));
$stockableCategories = array_values(array_filter($allCategories, fn($c) => in_array($c['item_class'] ?? '', ['Material', 'Consumable', 'Inventory'], true)));

$locations = am_get_pr_sites();
$allowCodes = am_country_allow_codes();
$locations = array_values(array_filter($locations, fn($l) => in_array((string)($l['country_code'] ?? ''), $allowCodes, true)));

$allAssets = am_firestore_get_collection('am_core_assets', 4000);
$allInvLevels = am_firestore_get_collection('am_core_inventory_levels', 4000);

// Index assets and inventory levels
$assetById = [];
foreach ($allAssets as $a) {
    $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
    if ($aid !== '') $assetById[$aid] = $a;
}

// Stockable items for source selection (Material, Consumable, Inventory)
$sourceItems = [];
foreach ($allAssets as $a) {
    $ic = (string)($a['item_class'] ?? '');
    if (!in_array($ic, ['Material', 'Consumable', 'Inventory'], true)) continue;
    $status = (string)($a['status'] ?? '');
    if (in_array($status, ['WrittenOff', 'Retired', 'Consumed'], true)) continue;
    $sourceItems[] = $a;
}

$locByAnyKey = am_build_location_index($locations);

// Inventory levels indexed by asset_id|canonical location|country_id
$invByKey = [];
foreach (am_inventory_dedupe_all_levels($allInvLevels, $locByAnyKey, $assetById) as $inv) {
    $iaid = (string)($inv['asset_id'] ?? '');
    $iloc = am_canonical_location_code((string)($inv['location_id'] ?? ''), $locByAnyKey);
    $icid = (string)($inv['country_id'] ?? '');
    if ($iaid !== '' && $iloc !== '') {
        $invByKey[$iaid . '|' . $iloc . '|' . $icid] = $inv;
    }
}

// Country → code map for tag generation
$countryById = [];
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cid !== '') {
        $countryById[$cid] = $c;
    }
}

// Default country from session scope
$defaultCountryId = '';
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    $cc = strtoupper((string)($c['country_code'] ?? ''));
    if ($cc !== '' && $cid !== '' && in_array($cc, $allowCodes, true)) {
        $defaultCountryId = $cid;
        break;
    }
}

// ── POST processing ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assemblyCountryId = trim($_POST['country_id'] ?? '');
    $assemblyLocationCode = trim($_POST['location_code'] ?? '');
    $resultType = trim($_POST['result_type'] ?? 'FixedAsset');
    $resultName = trim($_POST['name'] ?? '');
    $resultCategoryId = trim($_POST['category_id'] ?? '');
    $resultItemClass = trim($_POST['result_item_class'] ?? 'Inventory');
    $existingResultAssetId = trim($_POST['existing_result_asset_id'] ?? '');
    $resultQty = (int)($_POST['result_qty'] ?? 1);
    $serialNumber = trim($_POST['serial_number'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $conditionStatus = trim($_POST['condition_status'] ?? 'New');
    $notes = trim($_POST['notes'] ?? '');

    $isStockableResult = $resultType === 'Stockable';
    if (!in_array($resultType, ['FixedAsset', 'Stockable'], true)) {
        $errors[] = 'Please select a valid output type.';
    }
    if ($isStockableResult && !in_array($resultItemClass, ['Material', 'Consumable', 'Inventory'], true)) {
        $errors[] = 'Please select a valid stockable item class.';
    }

    // Parse source items JSON
    $rawSourceItems = trim($_POST['source_items_json'] ?? '');
    $sourceLineItems = [];
    if ($rawSourceItems !== '') {
        $decoded = json_decode($rawSourceItems, true);
        if (is_array($decoded)) $sourceLineItems = $decoded;
    }

    // Validate result fields
    if ($resultName === '' && ($existingResultAssetId === '' || !$isStockableResult)) {
        $errors[] = 'Resulting item name is required.';
    }
    if (!$isStockableResult && $resultCategoryId === '') {
        $errors[] = 'Category is required for Fixed Assets.';
    }
    if ($isStockableResult && $existingResultAssetId === '' && $resultCategoryId === '') {
        $errors[] = 'Category is required when creating a new stockable item.';
    }
    if ($assemblyCountryId === '') $errors[] = 'Country is required.';
    if ($assemblyLocationCode === '') $errors[] = 'Assembly location is required.';
    if ($resultQty < 1) $errors[] = 'Quantity to produce must be at least 1.';

    if ($isStockableResult && $existingResultAssetId !== '') {
        $existingResult = $assetById[$existingResultAssetId] ?? [];
        if ($existingResult === []) {
            $errors[] = 'Selected stockable catalog item was not found.';
        } elseif (!in_array((string)($existingResult['item_class'] ?? ''), ['Material', 'Consumable', 'Inventory'], true)) {
            $errors[] = 'Selected catalog item is not a stockable class.';
        }
    }

    // Validate source items
    $validSources = [];
    if (empty($sourceLineItems)) {
        $errors[] = 'At least one source material is required.';
    } else {
        foreach ($sourceLineItems as $idx => $src) {
            $saId = trim((string)($src['asset_id'] ?? ''));
            $sqty = (int)($src['quantity'] ?? 0);
            if ($saId === '') {
                $errors[] = 'Source item #' . ($idx + 1) . ' is missing an asset reference.';
                continue;
            }
            if ($sqty < 1) {
                $errors[] = 'Source item #' . ($idx + 1) . ' must have quantity ≥ 1.';
                continue;
            }
            $sAsset = $assetById[$saId] ?? [];
            if (!$sAsset) {
                $errors[] = 'Source item #' . ($idx + 1) . ' not found in catalog.';
                continue;
            }
            $validSources[] = [
                'asset_id' => $saId,
                'name' => trim((string)($src['name'] ?? $sAsset['name'] ?? '')),
                'asset_tag' => trim((string)($src['asset_tag'] ?? $sAsset['asset_tag'] ?? '')),
                'quantity' => $sqty,
                'unit' => trim((string)($src['unit'] ?? $sAsset['unit_of_measure'] ?? 'EA')),
            ];
        }
    }

    // Check inventory sufficiency
    if (empty($errors)) {
        $checkLoc = am_canonical_location_code($assemblyLocationCode, $locByAnyKey);
        foreach ($validSources as $src) {
            $skey = $src['asset_id'] . '|' . $checkLoc . '|' . $assemblyCountryId;
            $sinv = $invByKey[$skey] ?? null;
            $available = $sinv ? (int)($sinv['quantity_on_hand'] ?? 0) - (int)($sinv['quantity_allocated'] ?? 0) : 0;
            if ($available < $src['quantity']) {
                $errors[] = 'Insufficient stock for ' . $src['name'] . ': '
                    . max(0, $available) . ' available, ' . $src['quantity'] . ' requested.';
            }
        }
    }

    if (empty($errors)) {
        // Country code for tag generation
        $countryCode = (string)($countryById[$assemblyCountryId]['country_code'] ?? 'UNK');
        $asmLoc = $locByAnyKey[$assemblyLocationCode] ?? [];
        $asmLocName = (string)($asmLoc['location_name'] ?? $assemblyLocationCode);
        $assemblyLocationCode = am_canonical_location_code($assemblyLocationCode, $locByAnyKey);

        // Consume source inventory
        foreach ($validSources as $src) {
            $skey = $src['asset_id'] . '|' . $assemblyLocationCode . '|' . $assemblyCountryId;
            $sinv = $invByKey[$skey] ?? null;
            if ($sinv) {
                $newQoh = max(0, (int)($sinv['quantity_on_hand'] ?? 0) - $src['quantity']);
                am_firestore_update_document('am_core_inventory_levels', (string)$sinv['id'], [
                    'quantity_on_hand' => $newQoh,
                    'updated_at' => date('c'),
                ]);
                $invByKey[$skey]['quantity_on_hand'] = $newQoh;
            }
        }

        $lineage = [
            'built_from' => $validSources,
            'assembled_at' => date('c'),
            'assembled_by' => $_SESSION['user_id'] ?? '',
        ];

        if ($isStockableResult) {
            $targetAsset = null;
            $targetAssetId = '';
            if ($existingResultAssetId !== '') {
                $targetAsset = $assetById[$existingResultAssetId] ?? [];
                $targetAssetId = $existingResultAssetId;
                $resultName = (string)($targetAsset['name'] ?? $resultName);
            }

            if ($targetAssetId === '') {
                $assetTag = am_generate_asset_tag($resultItemClass, $countryCode, $allAssets);
                $data = array_merge([
                    'name' => $resultName,
                    'description' => 'Produced from ' . count($validSources) . ' material type(s)',
                    'item_class' => $resultItemClass,
                    'category_id' => $resultCategoryId,
                    'country_id' => $assemblyCountryId,
                    'location_id' => $assemblyLocationCode,
                    'location_name' => $asmLocName,
                    'condition_status' => $conditionStatus,
                    'status' => 'Available',
                    'quantity' => $resultQty,
                    'unit_of_measure' => 'EA',
                    'asset_tag' => $assetTag,
                    'qr_code_id' => '',
                    'notes' => $notes,
                    'created_at' => date('c'),
                    'updated_at' => date('c'),
                    'created_by' => $_SESSION['user_id'] ?? '',
                ], $lineage);

                $result = am_firestore_create_document('am_core_assets', $data);
                if (!$result['ok']) {
                    $errors[] = 'Failed to create stockable item: ' . ($result['error'] ?? 'Unknown');
                } else {
                    $targetAssetId = (string)($result['id'] ?? '');
                    am_firestore_create_document('am_core_inventory_levels', [
                        'asset_id' => $targetAssetId,
                        'location_id' => $assemblyLocationCode,
                        'country_id' => $assemblyCountryId,
                        'quantity_on_hand' => $resultQty,
                        'quantity_allocated' => 0,
                        'created_at' => date('c'),
                        'updated_at' => date('c'),
                    ]);
                    $_SESSION['flash_success'] = 'Produced ' . $resultQty . ' × ' . $resultName
                        . ' (' . $assetTag . ') from ' . count($validSources) . ' material type(s).';
                    header('Location: ' . base_url('assets/view.php?id=' . urlencode($targetAssetId)));
                    exit;
                }
            } else {
                $invKey = $targetAssetId . '|' . $assemblyLocationCode . '|' . $assemblyCountryId;
                $tinv = $invByKey[$invKey] ?? null;
                $prevQty = (int)($targetAsset['quantity'] ?? 0);
                $newQty = $prevQty + $resultQty;
                am_firestore_update_document('am_core_assets', $targetAssetId, array_merge([
                    'quantity' => $newQty,
                    'location_id' => $assemblyLocationCode,
                    'location_name' => $asmLocName,
                    'updated_at' => date('c'),
                ], $lineage));

                if ($tinv) {
                    am_firestore_update_document('am_core_inventory_levels', (string)$tinv['id'], [
                        'location_id' => $assemblyLocationCode,
                        'quantity_on_hand' => (int)($tinv['quantity_on_hand'] ?? 0) + $resultQty,
                        'updated_at' => date('c'),
                    ]);
                } else {
                    am_firestore_create_document('am_core_inventory_levels', [
                        'asset_id' => $targetAssetId,
                        'location_id' => $assemblyLocationCode,
                        'country_id' => $assemblyCountryId,
                        'quantity_on_hand' => $resultQty,
                        'quantity_allocated' => 0,
                        'created_at' => date('c'),
                        'updated_at' => date('c'),
                    ]);
                }

                $_SESSION['flash_success'] = 'Produced ' . $resultQty . ' × ' . $resultName
                    . ' (added to ' . (string)($targetAsset['asset_tag'] ?? $targetAssetId) . ') from '
                    . count($validSources) . ' material type(s).';
                header('Location: ' . base_url('assets/view.php?id=' . urlencode($targetAssetId)));
                exit;
            }
        } else {
        // Create FixedAsset(s)
        $createdTags = [];
        $existingAssets = $allAssets;
        for ($i = 0; $i < $resultQty; $i++) {
            $assetTag = am_generate_asset_tag('FixedAsset', $countryCode, $existingAssets);
            $createdTags[] = $assetTag;

            $data = [
                'name' => $resultName,
                'description' => 'Assembled from ' . count($validSources) . ' material type(s)',
                'item_class' => 'FixedAsset',
                'category_id' => $resultCategoryId,
                'country_id' => $assemblyCountryId,
                'location_id' => $assemblyLocationCode,
                'location_name' => $asmLocName,
                'serial_number' => $serialNumber,
                'manufacturer' => $manufacturer,
                'model' => $model,
                'condition_status' => $conditionStatus,
                'status' => 'Available',
                'quantity' => 1,
                'unit_of_measure' => 'EA',
                'asset_tag' => $assetTag,
                'qr_code_id' => '',
                'notes' => $notes,
                'built_from' => $validSources,
                'assembled_at' => date('c'),
                'assembled_by' => $_SESSION['user_id'] ?? '',
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'created_by' => $_SESSION['user_id'] ?? '',
            ];

            $result = am_firestore_create_document('am_core_assets', $data);
            if (!$result['ok']) {
                $errors[] = 'Failed to create asset #' . ($i + 1) . ': ' . ($result['error'] ?? 'Unknown');
                break;
            }
            // Add newly created asset to lookup so next tag auto-increments
            $existingAssets[] = $data;
        }

        if (empty($errors)) {
            $tagList = implode(', ', $createdTags);
            $_SESSION['flash_success'] = 'Assembled ' . $resultQty . ' × ' . $resultName
                . ' (' . $tagList . ') from ' . count($validSources) . ' material type(s).';
            header('Location: ' . base_url('assets/index.php?item_class=FixedAsset'));
            exit;
        }
        }
    }
}

// Source materials for JS — keyed by location_code
$srcByLoc = [];
foreach ($sourceItems as $si) {
    $slid = (string)($si['location_id'] ?? '');
    $sloc = $locByAnyKey[$slid] ?? [];
    $slcode = (string)($sloc['location_code'] ?? $slid);
    if ($slcode === '') continue;
    if (!isset($srcByLoc[$slcode])) $srcByLoc[$slcode] = [];
    $srcByLoc[$slcode][] = [
        'asset_id' => (string)($si['asset_id'] ?? $si['id'] ?? ''),
        'name' => (string)($si['name'] ?? ''),
        'asset_tag' => (string)($si['asset_tag'] ?? ''),
        'item_class' => (string)($si['item_class'] ?? ''),
        'unit' => (string)($si['unit_of_measure'] ?? 'EA'),
    ];
}

$classColors = ['FixedAsset' => 'primary', 'Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];

$stockableResultItems = [];
foreach ($allAssets as $a) {
    $ic = (string)($a['item_class'] ?? '');
    if (!in_array($ic, ['Material', 'Consumable', 'Inventory'], true)) {
        continue;
    }
    $status = (string)($a['status'] ?? '');
    if (in_array($status, ['WrittenOff', 'Retired', 'Consumed'], true)) {
        continue;
    }
    $stockableResultItems[] = [
        'asset_id' => (string)($a['asset_id'] ?? $a['id'] ?? ''),
        'name' => (string)($a['name'] ?? ''),
        'asset_tag' => (string)($a['asset_tag'] ?? ''),
        'item_class' => $ic,
    ];
}
usort($stockableResultItems, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$postResultType = (string)($_POST['result_type'] ?? 'FixedAsset');

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('assets/index.php'); ?>">Catalog</a></li>
                    <li class="breadcrumb-item active">Assemble / produce</li>
                </ol>
            </nav>
            <h1 class="h2 mt-2">Assemble / produce</h1>
            <p class="mb-0 text-gray-600">Consume inventory materials at a location to produce fixed assets or stockable items (e.g. pole boxes, ready boards).</p>
        </div>
        <a href="<?php echo base_url('assets/index.php?item_class=FixedAsset'); ?>" class="btn btn-outline-secondary btn-sm">Back to catalog</a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
            <li><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (am_is_auditor_readonly()): ?>
    <div class="alert alert-warning">Read-only accounts cannot assemble assets.</div>
    <?php else: ?>
    <form method="post" action="" id="assembleForm">
        <input type="hidden" name="source_items_json" id="sourceItemsJson" value="">

        <!-- Card A: Source Materials -->
        <div class="card border-0 shadow mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="fs-5 fw-bold mb-0">Source materials</h2>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addSourceRowBtn">
                    <i class="fas fa-plus me-1"></i> Add material
                </button>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Assembly location</label>
                        <select class="form-select" id="asmLocation" name="location_code" required>
                            <option value="">Select location…</option>
                            <?php foreach ($locations as $l):
                                $lcode = (string)($l['location_code'] ?? '');
                                $lname = (string)($l['location_name'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($lcode); ?>" <?php echo ($_POST['location_code'] ?? '') === $lcode ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lname . ' (' . ($l['country_code'] ?? '') . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Country</label>
                        <select class="form-select" name="country_id" id="asmCountryId" required>
                            <option value="">Select country…</option>
                            <?php foreach ($countries as $c):
                                $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($cid); ?>" <?php echo $defaultCountryId === $cid ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($c['country_name'] ?? '') . ' (' . ($c['country_code'] ?? '') . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="sourceItemsTable">
                        <thead>
                            <tr><th>Material</th><th>Tag</th><th>Class</th><th style="width:100px">Qty</th><th style="width:80px">Unit</th><th style="width:40px"></th></tr>
                        </thead>
                        <tbody id="sourceItemsBody">
                            <tr id="noSourceRow">
                                <td colspan="6" class="text-center text-gray-500 py-4">No materials added yet. Click <strong>Add material</strong> to select items from this location's stock.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Card B: Result -->
        <div class="card border-0 shadow mb-4">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Result</h2></div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Output type</label>
                        <select class="form-select" name="result_type" id="resultType">
                            <option value="FixedAsset" <?php echo $postResultType === 'FixedAsset' ? 'selected' : ''; ?>>Fixed Asset</option>
                            <option value="Stockable" <?php echo $postResultType === 'Stockable' ? 'selected' : ''; ?>>Stockable item (inventory / material)</option>
                        </select>
                        <div class="form-text">Use stockable output for pole boxes, ready boards, and other produced inventory.</div>
                    </div>
                    <div class="col-md-6 stockable-only">
                        <label class="form-label">Stockable class</label>
                        <select class="form-select" name="result_item_class" id="resultItemClass">
                            <?php foreach (['Inventory' => 'Inventory', 'Material' => 'Material', 'Consumable' => 'Consumable'] as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo (($_POST['result_item_class'] ?? 'Inventory') === $val) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-3 stockable-only mb-3">
                    <div class="col-12">
                        <label class="form-label">Add to existing catalog item</label>
                        <select class="form-select" name="existing_result_asset_id" id="existingResultAsset">
                            <option value="">Create new item instead…</option>
                            <?php foreach ($stockableResultItems as $item): ?>
                            <option value="<?php echo htmlspecialchars($item['asset_id']); ?>" <?php echo (($_POST['existing_result_asset_id'] ?? '') === $item['asset_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($item['name'] . ' (' . $item['asset_tag'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-8 new-result-fields">
                        <label class="form-label">Item name <span class="text-danger result-name-required">*</span></label>
                        <input type="text" name="name" id="resultName" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                            placeholder="e.g. Pole box 3-channel, Ready board 20A">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Qty to produce</label>
                        <input type="number" name="result_qty" class="form-control" min="1" value="<?php echo (int)($_POST['result_qty'] ?? 1); ?>">
                        <div class="form-text">How many identical units to create or add.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Category <span class="text-danger category-required">*</span></label>
                        <select name="category_id" class="form-select" id="resultCategoryId">
                            <option value="">Select…</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Condition</label>
                        <select name="condition_status" class="form-select">
                            <?php foreach (['New', 'Good', 'Fair', 'Refurbished'] as $cnd): ?>
                            <option value="<?php echo $cnd; ?>" <?php echo ($_POST['condition_status'] ?? 'New') === $cnd ? 'selected' : ''; ?>><?php echo $cnd; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 fixed-only">
                        <label class="form-label">Serial number</label>
                        <input type="text" name="serial_number" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['serial_number'] ?? ''); ?>"
                            placeholder="Optional">
                    </div>
                    <div class="col-md-4 fixed-only">
                        <label class="form-label">Manufacturer</label>
                        <input type="text" name="manufacturer" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['manufacturer'] ?? ''); ?>"
                            placeholder="Optional">
                    </div>
                    <div class="col-md-4 fixed-only">
                        <label class="form-label">Model</label>
                        <input type="text" name="model" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['model'] ?? ''); ?>"
                            placeholder="Optional">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"
                            placeholder="Assembly notes…"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-wrench me-2"></i><span id="submitBtnLabel">Assemble</span>
            </button>
            <a href="<?php echo base_url('assets/index.php'); ?>" class="btn btn-gray-200">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
// Source items state
var sourceItems = [];
// Stock data per location
var srcByLoc = <?php echo json_encode($srcByLoc, JSON_UNESCAPED_SLASHES); ?>;
var classColors = <?php echo json_encode($classColors); ?>;
var faCategories = <?php echo json_encode(array_map(fn($c) => ['id' => (string)($c['category_id'] ?? $c['id'] ?? ''), 'name' => (string)($c['category_name'] ?? '')], $faCategories), JSON_UNESCAPED_SLASHES); ?>;
var stockableCategories = <?php echo json_encode(array_map(fn($c) => ['id' => (string)($c['category_id'] ?? $c['id'] ?? ''), 'name' => (string)($c['category_name'] ?? '')], $stockableCategories), JSON_UNESCAPED_SLASHES); ?>;
var selectedCategoryId = <?php echo json_encode((string)($_POST['category_id'] ?? '')); ?>;

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function syncHidden() {
    document.getElementById('sourceItemsJson').value = JSON.stringify(sourceItems);
}

function rebuildItemSelect() {
    var locCode = document.getElementById('asmLocation').value;
    var items = srcByLoc[locCode] || [];
    // Build select options HTML
    var opts = '<option value="">Select material…</option>';
    items.forEach(function(it) {
        var already = sourceItems.some(function(si) { return si.asset_id === it.asset_id; });
        if (already) return;
        opts += '<option value="' + it.asset_id + '"'
            + ' data-name="' + escHtml(it.name) + '"'
            + ' data-tag="' + escHtml(it.asset_tag) + '"'
            + ' data-class="' + escHtml(it.item_class) + '"'
            + ' data-unit="' + escHtml(it.unit || 'EA') + '"'
            + '>' + it.name + ' (' + it.asset_tag + ')</option>';
    });
    // Update all existing select rows
    document.querySelectorAll('.source-item-select').forEach(function(sel) {
        var curVal = sel.value;
        sel.innerHTML = opts;
        sel.value = curVal;
    });
    // Also store for new rows
    window._sourceItemOptions = opts;
}

document.getElementById('asmLocation').addEventListener('change', function() {
    rebuildItemSelect();
});

function renderSourceItems() {
    var tbody = document.getElementById('sourceItemsBody');
    var noRow = document.getElementById('noSourceRow');
    tbody.innerHTML = '';
    if (sourceItems.length === 0) {
        noRow = document.createElement('tr');
        noRow.id = 'noSourceRow';
        noRow.innerHTML = '<td colspan="6" class="text-center text-gray-500 py-4">No materials added yet. Click <strong>Add material</strong> to select items from this location\'s stock.</td>';
        tbody.appendChild(noRow);
    } else {
        sourceItems.forEach(function(item, idx) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><strong>' + escHtml(item.name) + '</strong></td>' +
                '<td><code class="text-muted">' + escHtml(item.asset_tag || '—') + '</code></td>' +
                '<td><span class="badge bg-' + (classColors[item.item_class] || 'secondary') + '">' + escHtml(item.item_class) + '</span></td>' +
                '<td><input type="number" class="form-control form-control-sm src-qty" value="' + item.quantity + '" min="1" data-idx="' + idx + '" style="width:80px"></td>' +
                '<td><span class="text-muted">' + escHtml(item.unit || 'EA') + '</span></td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger src-remove" data-idx="' + idx + '" title="Remove"><i class="fas fa-trash"></i></button></td>';
            tbody.appendChild(tr);
        });

        tbody.querySelectorAll('.src-qty').forEach(function(inp) {
            inp.addEventListener('change', function() {
                var idx = parseInt(this.dataset.idx);
                var val = parseInt(this.value) || 1;
                if (val < 1) val = 1;
                this.value = val;
                sourceItems[idx].quantity = val;
                syncHidden();
            });
        });

        tbody.querySelectorAll('.src-remove').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var idx = parseInt(this.dataset.idx);
                sourceItems.splice(idx, 1);
                renderSourceItems();
                syncHidden();
                rebuildItemSelect();
            });
        });
    }
}

function addSourceRow() {
    var locCode = document.getElementById('asmLocation').value;
    if (!locCode) {
        alert('Please select an assembly location first.');
        return;
    }
    var items = srcByLoc[locCode] || [];
    var available = items.filter(function(it) {
        return !sourceItems.some(function(si) { return si.asset_id === it.asset_id; });
    });
    if (available.length === 0) {
        alert('No more materials available at this location.');
        return;
    }
    // Pick first available
    var pick = available[0];
    sourceItems.push({
        asset_id: pick.asset_id,
        name: pick.name,
        asset_tag: pick.asset_tag,
        item_class: pick.item_class,
        quantity: 1,
        unit: pick.unit || 'EA'
    });
    renderSourceItems();
    syncHidden();
    rebuildItemSelect();
}

document.getElementById('addSourceRowBtn').addEventListener('click', addSourceRow);

// Validate on submit
document.getElementById('assembleForm').addEventListener('submit', function(e) {
    syncHidden();
    if (sourceItems.length === 0) {
        e.preventDefault();
        alert('Please add at least one source material.');
        return false;
    }
    return true;
});

// Init
renderSourceItems();
syncHidden();
rebuildItemSelect();

function rebuildCategoryOptions() {
    var type = document.getElementById('resultType').value;
    var isStockable = type === 'Stockable';
    var cats = isStockable ? stockableCategories : faCategories;
    var sel = document.getElementById('resultCategoryId');
    var cur = sel.value || selectedCategoryId;
    sel.innerHTML = '<option value="">Select…</option>';
    cats.forEach(function(cat) {
        var opt = document.createElement('option');
        opt.value = cat.id;
        opt.textContent = cat.name;
        if (cat.id === cur) opt.selected = true;
        sel.appendChild(opt);
    });
}

function updateResultForm() {
    var type = document.getElementById('resultType').value;
    var isStockable = type === 'Stockable';
    var existing = document.getElementById('existingResultAsset').value;
    document.querySelectorAll('.stockable-only').forEach(function(el) {
        el.style.display = isStockable ? '' : 'none';
    });
    document.querySelectorAll('.fixed-only').forEach(function(el) {
        el.style.display = isStockable ? 'none' : '';
    });
    document.querySelectorAll('.new-result-fields').forEach(function(el) {
        el.style.display = (isStockable && existing) ? 'none' : '';
    });
    document.getElementById('submitBtnLabel').textContent = isStockable ? 'Produce' : 'Assemble';
    document.getElementById('resultName').required = !isStockable || !existing;
    rebuildCategoryOptions();
}

document.getElementById('resultType').addEventListener('change', updateResultForm);
document.getElementById('existingResultAsset').addEventListener('change', updateResultForm);
updateResultForm();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
