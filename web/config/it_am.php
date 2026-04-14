<?php
/**
 * IT desk + telecom (SIM / phone requests) — collection names and enums.
 */
require_once __DIR__ . '/app.php';

const AM_IT_TICKETS_COLLECTION = 'it_support_tickets';
const AM_SIM_CARDS_COLLECTION = 'am_core_sim_cards';
const AM_SIM_ASSIGNMENTS_COLLECTION = 'am_core_sim_assignments';
const AM_PHONE_REQUESTS_COLLECTION = 'am_core_phone_requests';

/** @return list<string> */
function am_it_ticket_statuses(): array {
    return ['Open', 'InProgress', 'Resolved', 'Closed', 'Cancelled'];
}

/** @return list<string> */
function am_it_ticket_priorities(): array {
    return ['Low', 'Normal', 'High', 'Urgent'];
}

/** @return list<string> */
function am_it_ticket_queues(): array {
    return ['it', 'am_operations'];
}

/** @return list<string> */
function am_sim_statuses(): array {
    return ['Active', 'Suspended', 'Deactivated', 'Lost', 'Unknown'];
}

/** @return list<string> */
function am_sim_assignment_types(): array {
    return ['team', 'phone_asset', 'vehicle_tracker', 'site_gateway', 'other'];
}

/** @return list<string> */
function am_phone_request_statuses(): array {
    return ['Submitted', 'Approved', 'Rejected', 'Fulfilled', 'Cancelled'];
}

/**
 * @param array<int, array<string, mixed>> $tickets
 */
function am_it_next_ticket_number(array $tickets): string {
    $y = date('Y');
    $max = 0;
    foreach ($tickets as $t) {
        $n = (string)($t['ticket_number'] ?? '');
        if (preg_match('/^IT-' . preg_quote($y, '/') . '-(\d+)$/', $n, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return 'IT-' . $y . '-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function am_phone_request_next_number(array $rows): string {
    $y = date('Y');
    $max = 0;
    foreach ($rows as $r) {
        $n = (string)($r['request_number'] ?? '');
        if (preg_match('/^PHR-' . preg_quote($y, '/') . '-(\d+)$/', $n, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return 'PHR-' . $y . '-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function am_is_it_portal_host(): bool {
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return str_contains($h, 'it.1pwrafrica.com');
}

/**
 * IT app URLs: under /it/ when docroot is web/, or at root when docroot is web/it/.
 */
function am_it_path(string $file): string {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = dirname($script);
    if (preg_match('#/it$#', str_replace('\\', '/', $dir))) {
        return 'it/' . ltrim($file, '/');
    }
    return ltrim($file, '/');
}

function am_it_url(string $file): string {
    return base_url(am_it_path($file));
}

/** Digits-only MSISDN for matching (display can stay in msisdn_raw). */
function am_normalize_msisdn(string $raw): string {
    $d = preg_replace('/\D+/', '', $raw);
    return $d;
}
