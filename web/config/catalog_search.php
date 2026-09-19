<?php
/**
 * Catalog search: every word must match somewhere, with plurals and a small
 * related-word list (drone / UAV). Results are scored so name hits rank first.
 */

/** @return list<list<string>> */
function am_catalog_search_synonym_groups(): array {
    return [
        ['drone', 'uav', 'quadcopter', 'unmanned'],
        ['laptop', 'notebook'],
        ['phone', 'handset', 'mobile'],
        ['vehicle', 'truck', 'car'],
        ['cable', 'wire'],
        ['generator', 'genset'],
        ['solar', 'pv', 'panel'],
    ];
}

function am_catalog_search_stem(string $word): string {
    $w = $word;
    if (strlen($w) > 4 && str_ends_with($w, 'ies')) {
        return substr($w, 0, -3) . 'y';
    }
    if (strlen($w) > 4 && str_ends_with($w, 'es')) {
        $base = substr($w, 0, -2);
        if (preg_match('/(?:s|x|z|ch|sh)$/', $base)) {
            return $base;
        }
    }
    if (strlen($w) > 3 && str_ends_with($w, 's') && !str_ends_with($w, 'ss')) {
        return substr($w, 0, -1);
    }
    return $w;
}

/** @return list<string> */
function am_catalog_search_words(string $text): array {
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/i', ' ', $text) ?? '';
    $parts = preg_split('/\s+/', trim($text)) ?: [];
    $out = [];
    foreach ($parts as $part) {
        if ($part === '' || strlen($part) < 2) {
            continue;
        }
        $out[] = am_catalog_search_stem($part);
    }
    return $out;
}

/** @return list<string> */
function am_catalog_search_expand(string $stem): array {
    $set = [$stem => true];
    foreach (am_catalog_search_synonym_groups() as $group) {
        $stems = array_map('am_catalog_search_stem', $group);
        if (in_array($stem, $stems, true)) {
            foreach ($stems as $alt) {
                $set[$alt] = true;
            }
        }
    }
    return array_keys($set);
}

/**
 * @return array{tokens: list<string>, expansions: list<list<string>>, related: list<string>}
 */
function am_catalog_search_parse(string $raw): array {
    $tokens = am_catalog_search_words($raw);
    $expansions = [];
    $related = [];
    foreach ($tokens as $token) {
        $alts = am_catalog_search_expand($token);
        $expansions[] = $alts;
        foreach ($alts as $alt) {
            if ($alt !== $token) {
                $related[$alt] = true;
            }
        }
    }
    return ['tokens' => $tokens, 'expansions' => $expansions, 'related' => array_values(array_keys($related))];
}

/** @return array<string, int> */
function am_catalog_search_field_weights(): array {
    return [
        'name' => 100,
        'alias' => 80,
        'tag' => 70,
        'manufacturer' => 60,
        'model' => 60,
        'serial' => 50,
        'category' => 40,
        'ugp' => 40,
        'description' => 25,
        'notes' => 25,
        'location' => 15,
    ];
}

/** @return array<string, string> */
function am_catalog_search_field_labels(): array {
    return [
        'name' => 'name',
        'alias' => 'alias',
        'tag' => 'tag',
        'manufacturer' => 'manufacturer',
        'model' => 'model',
        'serial' => 'serial',
        'category' => 'category',
        'ugp' => 'UGP id',
        'description' => 'description',
        'notes' => 'notes',
        'location' => 'location',
    ];
}

/**
 * @param array<string, string> $fields
 * @return array{score: int, reasons: list<string>}|null
 */
function am_catalog_search_match(array $fields, string $query): ?array {
    $parsed = am_catalog_search_parse($query);
    if ($parsed['tokens'] === []) {
        return null;
    }
    $indexed = [];
    foreach ($fields as $key => $value) {
        $indexed[$key] = am_catalog_search_words((string)$value);
    }
    $weights = am_catalog_search_field_weights();
    $score = 0;
    $hitKeys = [];
    foreach ($parsed['expansions'] as $alts) {
        $bestKey = null;
        $bestWeight = 0;
        foreach ($indexed as $key => $words) {
            $weight = $weights[$key] ?? 10;
            if ($weight <= $bestWeight) {
                continue;
            }
            foreach ($words as $word) {
                foreach ($alts as $alt) {
                    if ($word === $alt || (strlen($alt) >= 3 && str_starts_with($word, $alt))) {
                        $bestKey = $key;
                        $bestWeight = $weight;
                        break 2;
                    }
                }
            }
        }
        if ($bestKey === null) {
            return null;
        }
        $score += $bestWeight;
        $hitKeys[$bestKey] = true;
    }
    $labels = am_catalog_search_field_labels();
    $reasons = [];
    foreach (array_keys($hitKeys) as $key) {
        $reasons[] = $labels[$key] ?? $key;
    }
    return ['score' => $score, 'reasons' => $reasons];
}

/**
 * @param array<string, mixed> $asset
 * @param array<string, string> $extra
 * @return array<string, string>
 */
function am_catalog_search_fields(array $asset, array $extra = []): array {
    $aliases = $asset['catalogue_aliases'] ?? [];
    if (!is_array($aliases)) {
        $aliases = [$aliases];
    }
    return [
        'name' => (string)($asset['name'] ?? ''),
        'alias' => trim(implode(' ', array_merge($aliases, [(string)($asset['canonical_part_number'] ?? '')]))),
        'tag' => trim(implode(' ', [
            (string)($asset['asset_tag'] ?? ''),
            (string)($asset['legacy_tag'] ?? ''),
            (string)($asset['qr_code_id'] ?? ''),
        ])),
        'manufacturer' => (string)($asset['manufacturer'] ?? ''),
        'model' => (string)($asset['model'] ?? ''),
        'serial' => trim(implode(' ', [
            (string)($asset['serial_number'] ?? ''),
            (string)($asset['engine_number'] ?? ''),
        ])),
        'category' => (string)($extra['category_name'] ?? ''),
        'ugp' => trim(implode(' ', [
            (string)($asset['ugp_part_id'] ?? ''),
            (string)($asset['vehicle_type'] ?? ''),
            (string)($asset['fuel_type'] ?? ''),
        ])),
        'description' => (string)($asset['description'] ?? ''),
        'notes' => (string)($asset['notes'] ?? ''),
        'location' => trim(implode(' ', [
            (string)($extra['location_name'] ?? ''),
            (string)($extra['location_code'] ?? ''),
        ])),
    ];
}
