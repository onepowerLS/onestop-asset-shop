<?php
/**
 * What's New primer.
 *
 * Entries are stored in Firestore `am_core_whats_new`. Per-user dismissals
 * are stored in `am_core_whats_new_dismissals`. See docs/SYSTEM_SPECS.md
 * for the policy on when to add an entry.
 */

const AM_WHATS_NEW_COLLECTION = 'am_core_whats_new';
const AM_WHATS_NEW_DISMISSALS_COLLECTION = 'am_core_whats_new_dismissals';

/** @return list<array<string, mixed>> active entries, newest first */
function am_whats_new_entries(bool $activeOnly = true): array {
    $rows = am_firestore_get_collection(AM_WHATS_NEW_COLLECTION, 500);
    $out = [];
    foreach ($rows as $row) {
        if ($activeOnly && (int)($row['active'] ?? 1) !== 1) {
            continue;
        }
        $out[] = $row;
    }
    usort($out, function ($a, $b) {
        $ta = strtotime((string)($a['released_at'] ?? $a['created_at'] ?? '1970-01-01')) ?: 0;
        $tb = strtotime((string)($b['released_at'] ?? $b['created_at'] ?? '1970-01-01')) ?: 0;
        return $tb <=> $ta;
    });
    return $out;
}

/** @return array<string, bool> entry_id => true for this user */
function am_whats_new_dismissed_ids(string $userId): array {
    if ($userId === '') {
        return [];
    }
    $rows = am_firestore_get_collection(AM_WHATS_NEW_DISMISSALS_COLLECTION, 2000);
    $out = [];
    foreach ($rows as $row) {
        if ((string)($row['user_id'] ?? '') !== $userId) {
            continue;
        }
        $eid = (string)($row['entry_id'] ?? '');
        if ($eid !== '') {
            $out[$eid] = true;
        }
    }
    return $out;
}

/**
 * Entries the given user has not yet dismissed.
 * @return list<array<string, mixed>>
 */
function am_whats_new_unseen_for(string $userId): array {
    $dismissed = am_whats_new_dismissed_ids($userId);
    $out = [];
    foreach (am_whats_new_entries(true) as $entry) {
        $eid = (string)($entry['id'] ?? $entry['entry_id'] ?? '');
        if ($eid === '') {
            continue;
        }
        if (isset($dismissed[$eid])) {
            continue;
        }
        $out[] = $entry;
    }
    return $out;
}

/** Dismiss a batch of entry ids for a user (idempotent). */
function am_whats_new_dismiss_entries(string $userId, array $entryIds): array {
    if ($userId === '') {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    $entryIds = array_values(array_unique(array_filter(array_map('strval', $entryIds), fn($s) => $s !== '')));
    if (empty($entryIds)) {
        return ['ok' => true, 'dismissed' => 0];
    }
    $already = am_whats_new_dismissed_ids($userId);
    $created = 0;
    foreach ($entryIds as $eid) {
        if (isset($already[$eid])) {
            continue;
        }
        $r = am_firestore_create_document(AM_WHATS_NEW_DISMISSALS_COLLECTION, [
            'user_id' => $userId,
            'entry_id' => $eid,
            'dismissed_at' => gmdate('c'),
        ]);
        if ($r['ok']) {
            $created++;
        }
    }
    return ['ok' => true, 'dismissed' => $created];
}

/** Normalize a posted entry from the admin form into the Firestore shape. */
function am_whats_new_normalize_entry(array $post): array {
    $title = trim((string)($post['title'] ?? ''));
    $summary = trim((string)($post['summary'] ?? ''));
    $details = trim((string)($post['details'] ?? ''));
    $category = trim((string)($post['category'] ?? 'feature'));
    if (!in_array($category, ['feature', 'improvement', 'fix', 'reconfigure'], true)) {
        $category = 'feature';
    }
    $icon = trim((string)($post['icon'] ?? 'fa-star'));
    $deepLink = trim((string)($post['deep_link'] ?? ''));
    $releasedAt = trim((string)($post['released_at'] ?? ''));
    if ($releasedAt === '') {
        $releasedAt = gmdate('c');
    }
    $active = isset($post['active']) ? 1 : 0;

    return [
        'title' => $title,
        'summary' => $summary,
        'details' => $details,
        'category' => $category,
        'icon' => $icon,
        'deep_link' => $deepLink,
        'released_at' => $releasedAt,
        'active' => $active,
    ];
}

/** Categories for the admin dropdown and the popup badge colors. */
function am_whats_new_category_colors(): array {
    return [
        'feature' => 'success',
        'improvement' => 'primary',
        'fix' => 'warning',
        'reconfigure' => 'info',
    ];
}

function am_whats_new_category_labels(): array {
    return [
        'feature' => 'New feature',
        'improvement' => 'Improvement',
        'fix' => 'Fix',
        'reconfigure' => 'Reconfigured',
    ];
}
