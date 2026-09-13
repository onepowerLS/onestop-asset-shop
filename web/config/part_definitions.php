<?php
/**
 * Shared AM part definitions and catalogue stewardship tasks.
 *
 * Country stock remains on am_core_assets; definitions are cross-country identity.
 * UGP authoritative links still go through MAS confirmAmUgpMapping until general
 * candidate selection exists — this module stores classification and task state only.
 */

const AM_PART_DEFINITIONS_COLLECTION = 'am_part_definitions';
const AM_PART_DEFINITION_REVIEWS_COLLECTION = 'am_part_definition_reviews';
const AM_CATALOGUE_TASKS_COLLECTION = 'am_catalogue_tasks';

/** @return list<string> */
function am_part_definition_classifications(): array {
    return ['needs_classification', 'ugp_linked', 'am_only'];
}

/** @return list<string> */
function am_catalogue_task_types(): array {
    return [
        'classify_item',
        'verify_ugp_match',
        'resolve_unit_spec_conflict',
        'capture_reference_image', // reserved; photos deferred
    ];
}

/** @return list<string> */
function am_catalogue_task_statuses(): array {
    return ['open', 'in_progress', 'resolved', 'cancelled'];
}

/**
 * @param array<string, mixed> $input
 * @return array{ok:bool, error:?string, data:array<string,mixed>}
 */
function am_part_definition_normalize(array $input): array {
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'error' => 'Definition name is required.', 'data' => []];
    }
    $classification = trim((string)($input['classification'] ?? 'needs_classification'));
    if (!in_array($classification, am_part_definition_classifications(), true)) {
        return ['ok' => false, 'error' => 'Invalid classification.', 'data' => []];
    }
    $ugp = trim((string)($input['ugp_part_id'] ?? ''));
    $amOnlyReason = trim((string)($input['am_only_reason'] ?? ''));
    if ($classification === 'ugp_linked' && $ugp === '') {
        return ['ok' => false, 'error' => 'UGP-linked definitions require ugp_part_id (set via audited mapping).', 'data' => []];
    }
    if ($classification === 'am_only' && $amOnlyReason === '') {
        return ['ok' => false, 'error' => 'AM-only classification requires a reason.', 'data' => []];
    }
    // Operators must not bypass a required UGP link by marking AM-only when network context is forced.
    if (!empty($input['requires_ugp']) && $classification === 'am_only') {
        return ['ok' => false, 'error' => 'This part requires a UGP link; AM-only is not allowed.', 'data' => []];
    }

    $unit = trim((string)($input['unit_of_measure'] ?? $input['unit'] ?? 'EA'));
    $active = !isset($input['active']) || (bool)$input['active'];
    $forecastReady = !empty($input['forecast_ready']);
    if ($forecastReady) {
        if ($classification === 'needs_classification') {
            return ['ok' => false, 'error' => 'Forecast-ready requires classification.', 'data' => []];
        }
        if ($unit === '') {
            return ['ok' => false, 'error' => 'Forecast-ready requires a unit of measure.', 'data' => []];
        }
        if ($classification === 'ugp_linked' && $ugp === '') {
            return ['ok' => false, 'error' => 'Forecast-ready UGP-linked definition needs ugp_part_id.', 'data' => []];
        }
    }

    $data = [
        'name' => $name,
        'description' => trim((string)($input['description'] ?? '')),
        'manufacturer' => trim((string)($input['manufacturer'] ?? '')),
        'model' => trim((string)($input['model'] ?? '')),
        'technical_specification' => trim((string)($input['technical_specification'] ?? '')),
        'unit_of_measure' => $unit !== '' ? $unit : 'EA',
        'revision' => max(1, (int)($input['revision'] ?? 1)),
        'active' => $active,
        'classification' => $classification,
        'ugp_part_id' => $ugp !== '' ? $ugp : null,
        'am_only_reason' => $amOnlyReason !== '' ? $amOnlyReason : null,
        'forecast_ready' => $forecastReady,
        'verified_spec_revision' => trim((string)($input['verified_spec_revision'] ?? '')),
        'updated_at' => date('c'),
    ];
    return ['ok' => true, 'error' => null, 'data' => $data];
}

/**
 * @return array{ok:bool, error:?string, id:string}
 */
function am_part_definition_create(array $input, ?string $idTokenOverride = null): array {
    $norm = am_part_definition_normalize($input);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => $norm['error'], 'id' => ''];
    }
    $data = $norm['data'];
    $data['created_at'] = date('c');
    $data['created_by'] = (string)($_SESSION['user_id'] ?? '');
    $result = am_firestore_create_document(AM_PART_DEFINITIONS_COLLECTION, $data, null, $idTokenOverride);
    if (!empty($result['ok'])) {
        am_part_definition_review_append((string)$result['id'], 'created', $data, $idTokenOverride);
    }
    return [
        'ok' => (bool)($result['ok'] ?? false),
        'error' => $result['error'] ?? null,
        'id' => (string)($result['id'] ?? ''),
    ];
}

/**
 * Append-only review history.
 *
 * @param array<string, mixed> $snapshot
 */
function am_part_definition_review_append(
    string $definitionId,
    string $action,
    array $snapshot,
    ?string $idTokenOverride = null
): void {
    if ($definitionId === '') {
        return;
    }
    try {
        am_firestore_create_document(AM_PART_DEFINITION_REVIEWS_COLLECTION, [
            'definition_id' => $definitionId,
            'action' => $action,
            'snapshot' => $snapshot,
            'actor_uid' => (string)($_SESSION['user_id'] ?? ''),
            'created_at' => date('c'),
        ], null, $idTokenOverride);
    } catch (Throwable $e) {
        error_log('[am_part_definition_review_append] ' . $e->getMessage());
    }
}

/**
 * Search active definitions by name/manufacturer/model (case-insensitive substring).
 *
 * @param list<array<string, mixed>> $definitions
 * @return list<array<string, mixed>>
 */
function am_part_definition_search(array $definitions, string $needle, int $limit = 25): array {
    $needle = strtolower(trim($needle));
    if ($needle === '' || strlen($needle) < 2) {
        return [];
    }
    $out = [];
    foreach ($definitions as $d) {
        if (isset($d['active']) && !$d['active']) {
            continue;
        }
        $blob = strtolower(implode(' ', [
            (string)($d['name'] ?? ''),
            (string)($d['manufacturer'] ?? ''),
            (string)($d['model'] ?? ''),
            (string)($d['ugp_part_id'] ?? ''),
        ]));
        if (!str_contains($blob, $needle)) {
            continue;
        }
        $out[] = $d;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array{ok:bool, error:?string, id:string}
 */
function am_catalogue_task_create(array $input, ?string $idTokenOverride = null): array {
    $type = trim((string)($input['task_type'] ?? ''));
    if (!in_array($type, am_catalogue_task_types(), true)) {
        return ['ok' => false, 'error' => 'Invalid task type.', 'id' => ''];
    }
    $reason = trim((string)($input['reason'] ?? ''));
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Task reason is required.', 'id' => ''];
    }
    $data = [
        'task_type' => $type,
        'status' => 'open',
        'reason' => $reason,
        'asset_id' => trim((string)($input['asset_id'] ?? '')) ?: null,
        'definition_id' => trim((string)($input['definition_id'] ?? '')) ?: null,
        'country_id' => trim((string)($input['country_id'] ?? '')) ?: null,
        'owner_uid' => trim((string)($input['owner_uid'] ?? '')) ?: null,
        'owner_name' => trim((string)($input['owner_name'] ?? '')) ?: null,
        'due_at' => trim((string)($input['due_at'] ?? '')) ?: null,
        'site_impact' => trim((string)($input['site_impact'] ?? '')) ?: null,
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'created_by' => (string)($_SESSION['user_id'] ?? ''),
    ];
    $result = am_firestore_create_document(AM_CATALOGUE_TASKS_COLLECTION, $data, null, $idTokenOverride);
    return [
        'ok' => (bool)($result['ok'] ?? false),
        'error' => $result['error'] ?? null,
        'id' => (string)($result['id'] ?? ''),
    ];
}

/**
 * Ensure a classify task exists when a new country asset lacks classification metadata.
 *
 * @param array<string, mixed> $asset
 */
function am_catalogue_maybe_create_classify_task(array $asset, ?string $idTokenOverride = null): void {
    $defId = trim((string)($asset['definition_id'] ?? ''));
    $ugp = trim((string)($asset['ugp_part_id'] ?? ''));
    if ($defId !== '' || $ugp !== '') {
        return;
    }
    $cls = (string)($asset['item_class'] ?? '');
    if (!in_array($cls, ['Material', 'Consumable', 'Inventory'], true)) {
        return;
    }
    am_catalogue_task_create([
        'task_type' => 'classify_item',
        'reason' => 'New stockable item has no shared definition or UGP mapping — classify for forecast readiness.',
        'asset_id' => (string)($asset['id'] ?? $asset['asset_id'] ?? ''),
        'country_id' => (string)($asset['country_id'] ?? ''),
        'site_impact' => (string)($asset['location_id'] ?? ''),
    ], $idTokenOverride);
}

/**
 * Publish ugp_part_id from a verified definition onto a country asset (read-compatible path).
 * Does not call PR confirmAmUgpMapping — only copies an already-approved definition link.
 *
 * @return array{ok:bool, error:?string}
 */
function am_part_definition_link_to_asset(
    string $assetId,
    string $definitionId,
    array $definition,
    ?string $idTokenOverride = null
): array {
    if ($assetId === '' || $definitionId === '') {
        return ['ok' => false, 'error' => 'Missing asset or definition id.'];
    }
    $patch = [
        'definition_id' => $definitionId,
        'updated_at' => date('c'),
    ];
    $ugp = trim((string)($definition['ugp_part_id'] ?? ''));
    if ($ugp !== '' && ($definition['classification'] ?? '') === 'ugp_linked') {
        // Keep ugp_part_id in sync for API consumers; authoritative approval remains MAS mapping.
        $patch['ugp_part_id'] = $ugp;
    }
    $result = am_firestore_update_document('am_core_assets', $assetId, $patch, $idTokenOverride);
    return ['ok' => (bool)($result['ok'] ?? false), 'error' => $result['error'] ?? null];
}

/**
 * Actionable catalogue status for forecast parts API.
 *
 * @param array<string, mixed> $asset
 * @param array<string, mixed>|null $definition
 * @param list<array<string, mixed>> $openTasks
 * @return array{definition_id:?string, classification:?string, forecast_ready:bool, catalogue_status:string, catalogue_reason:?string}
 */
function am_catalogue_status_for_asset(array $asset, ?array $definition, array $openTasks = []): array {
    $defId = trim((string)($asset['definition_id'] ?? $definition['id'] ?? ''));
    $classification = $definition
        ? (string)($definition['classification'] ?? 'needs_classification')
        : null;
    $forecastReady = $definition ? !empty($definition['forecast_ready']) : false;
    $ugp = trim((string)($asset['ugp_part_id'] ?? $definition['ugp_part_id'] ?? ''));

    $reason = null;
    $status = 'complete';
    if ($openTasks !== []) {
        $status = 'task_open';
        $reason = (string)($openTasks[0]['reason'] ?? 'Catalogue task open');
    } elseif ($defId === '' && $ugp === '') {
        $status = 'incomplete_catalogue';
        $reason = 'No shared definition or UGP mapping on this country item';
    } elseif ($classification === 'needs_classification') {
        $status = 'needs_classification';
        $reason = 'Definition exists but is not classified';
    } elseif ($classification === 'ugp_linked' && $ugp === '') {
        $status = 'missing_ugp_link';
        $reason = 'Classified UGP-linked but ugp_part_id not published';
    } elseif (!$forecastReady && $defId !== '') {
        $status = 'not_forecast_ready';
        $reason = 'Definition not marked forecast-ready';
    }

    return [
        'definition_id' => $defId !== '' ? $defId : null,
        'classification' => $classification,
        'forecast_ready' => $forecastReady,
        'catalogue_status' => $status,
        'catalogue_reason' => $reason,
    ];
}
