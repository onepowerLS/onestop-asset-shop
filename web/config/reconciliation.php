<?php
/** Pure display/ranking helpers. Suggestions are never approvals. */
function am_workshop_identity(array $a): array {
    return ['name' => $a['name'] ?? '', 'unit' => $a['unit_of_measure'] ?? '', 'ugpPartId' => $a['ugp_part_id'] ?? '', 'definitionId' => $a['definition_id'] ?? '', 'manufacturer' => $a['manufacturer'] ?? '', 'model' => $a['model'] ?? '', 'description' => $a['description'] ?? ''];
}
function am_workshop_rank(array $a, string $id, array $part): int {
    if (($a['ugp_part_id'] ?? '') === $id) return 1000;
    $e = am_workshop_evidence($a, $id);
    if ($e) return ['strong'=>800, 'candidate'=>650, 'difference'=>100][$e['band']] ?? 100;
    if (in_array($a['id'] ?? $a['asset_id'] ?? '', $part['candidateIds'] ?? [], true)) return 500;
    $words = preg_split('/[^a-z0-9]+/', strtolower($part['name']));
    $text = strtolower(($a['name'] ?? '') . ' ' . ($a['description'] ?? ''));
    return count(array_filter(array_unique($words), fn($w) => strlen($w) > 2 && str_contains($text, $w)));
}
function am_workshop_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

/** Apply captured evidence only to the exact catalogue identity that was reviewed. */
function am_workshop_evidence(array $a, string $partId): ?array {
    static $data = null;
    $data ??= json_decode(file_get_contents(__DIR__.'/../data/mas-specification-evidence.json'), true)['entries'];
    foreach ($data as $e) {
        if ($e['partId'] === $partId && $e['assetId'] === ($a['id'] ?? $a['asset_id'] ?? '') && $e['identity'] === am_workshop_identity($a)) return $e;
    }
    return null;
}

/** Stable pages keep every accessible candidate reachable without rendering thousands of tiles. */
function am_workshop_page(array $items, int $requested): array {
    $pages = max(1, (int)ceil(count($items) / 12));
    $page = max(1, min($pages, $requested));
    return ['page'=>$page, 'pages'=>$pages, 'offset'=>($page-1)*12, 'items'=>array_slice($items, ($page-1)*12, 12)];
}
function am_workshop_url(string $part, string $query = '', int $page = 1): string {
    return 'reconciliation.php?' . http_build_query(['part'=>$part, 'q'=>$query, 'page'=>max(1,$page)], '', '&', PHP_QUERY_RFC3986);
}

/** Guidance is a review queue, not a stock assertion or automatic approval. */
function am_workshop_exceptions(): array {
    static $data; return $data ??= json_decode(file_get_contents(__DIR__.'/../data/mas-reconciliation-exceptions.json'), true);
}
function am_workshop_block(string $partId, string $assetId): ?string {
    foreach (am_workshop_exceptions()['blockedPairs'] as $row) if ($row['partId'] === $partId && $row['assetId'] === $assetId) return $row['reason'];
    return null;
}
function am_workshop_linked_parts(array $assets, array $definitions): array {
    $valid=[]; foreach($definitions as $d) if(!empty($d['canonical_approved']) && !empty($d['active']) && !empty($d['ugp_part_id'])) $valid[$d['id']]=$d['ugp_part_id'];
    $parts=[]; foreach($assets as $a) if(!empty($a['ugp_part_id']) && ($valid[$a['definition_id']??'']??null)===$a['ugp_part_id'] && !am_workshop_block($a['ugp_part_id'],$a['id']??$a['asset_id']??'')) $parts[]=$a['ugp_part_id'];
    return array_values(array_unique($parts));
}
function am_workshop_original(array $asset, array $reviews): array {
    if(!empty($asset['original_catalogue_identity'])) return $asset['original_catalogue_identity'];
    usort($reviews,fn($a,$b)=>strcmp($a['created_at']??$a['at']??'', $b['created_at']??$b['at']??''));
    foreach($reviews as $review) foreach(is_array($review['before']??null)?$review['before']:[] as $before) if(is_array($before) && ($before['assetId']??'')===($asset['id']??$asset['asset_id']??'')) return $before;
    return $asset;
}
