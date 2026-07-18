<?php
/**
 * Generic AM workflow templates (replaces one-off Google Forms).
 * Add new keys here and link from workflow-new.php?type=<key>.
 */
function am_request_workflow_templates(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $sites = [
        'MAT', 'TLH', 'MAS', 'SHG', 'LEB', 'KET', 'SEH', 'TOS', 'SEB', 'RIB',
        'MAK', 'BOB', 'MET', 'NKU', 'TLH_CLINIC', 'HQ',
    ];
    sort($sites);

    $cache = [
        'ready_board' => [
            'label'       => 'Ready board request',
            'description' => 'Request ready boards from Asset Management. Use this instead of the legacy Google Form.',
            'country_code'=> 'LSO',
            'fields'      => [
                [
                    'name'     => 'submitter_name',
                    'type'     => 'text',
                    'label'    => 'Full name of requester',
                    'required' => true,
                ],
                [
                    'name'     => 'submitter_email',
                    'type'     => 'email',
                    'label'    => 'Email',
                    'required' => true,
                ],
                [
                    'name'     => 'quantity',
                    'type'     => 'number',
                    'label'    => 'Quantity of ready boards',
                    'required' => true,
                    'min'      => 1,
                ],
                [
                    'name'     => 'site_code',
                    'type'     => 'select',
                    'label'    => 'Concession / site',
                    'required' => true,
                    'options'  => $sites,
                    'help'     => 'One site per request. For multiple sites, submit separate requests.',
                ],
                [
                    'name'     => 'dispatch_date',
                    'type'     => 'date',
                    'label'    => 'Estimated dispatch to site',
                    'required' => true,
                ],
                [
                    'name'     => 'receiver_name',
                    'type'     => 'text',
                    'label'    => 'Name of assigned receiver on site',
                    'required' => true,
                ],
                [
                    'name'     => 'receiver_email',
                    'type'     => 'email',
                    'label'    => 'Email of assigned receiver on site',
                    'required' => true,
                ],
            ],
        ],
        'inventory_dispatch' => [
            'label'       => 'Dispatch request',
            'description' => 'Request items dispatched from HQ/warehouse to a site within your country. Catalog search follows Request country (same country rules as the main asset list). Select items, quantities, site, and receiver.',
            'country_code' => '',  // resolved dynamically from user scope
            'fields'      => [],  // custom form — see dispatch-new.php
        ],
        'it_equipment_request' => [
            'label'       => 'IT Equipment Request',
            'description' => 'IT productivity asset requisition originated from the IS&T Helpdesk portal.',
            'country_code' => '',  // resolved dynamically from user scope
            'fields'      => [
                [
                    'name'     => 'submitter_name',
                    'type'     => 'text',
                    'label'    => 'Full name of requester',
                    'required' => true,
                ],
                [
                    'name'     => 'submitter_email',
                    'type'     => 'email',
                    'label'    => 'Email',
                    'required' => true,
                ],
                [
                    'name'     => 'equipment_category',
                    'type'     => 'select',
                    'label'    => 'Equipment category',
                    'required' => true,
                    'options'  => ['Tablet', 'Phone', 'Computer', 'Printer', 'Screen', 'Peripheral', 'Modem', 'Router', 'Other'],
                ],
                [
                    'name'     => 'equipment_request_type',
                    'type'     => 'select',
                    'label'    => 'Request type',
                    'required' => true,
                    'options'  => ['New Equipment', 'Replacement', 'Repair', 'Loan'],
                ],
                [
                    'name'     => 'justification',
                    'type'     => 'text',
                    'label'    => 'Justification',
                    'required' => true,
                ],
                [
                    'name'     => 'specifications',
                    'type'     => 'text',
                    'label'    => 'Specifications / preferences',
                    'required' => false,
                ],
                [
                    'name'     => 'site_code',
                    'type'     => 'text',
                    'label'    => 'Destination site code',
                    'required' => true,
                ],
                [
                    'name'     => 'receiver_name',
                    'type'     => 'text',
                    'label'    => 'Receiver name',
                    'required' => true,
                ],
                [
                    'name'     => 'receiver_email',
                    'type'     => 'email',
                    'label'    => 'Receiver email',
                    'required' => true,
                ],
                [
                    'name'     => 'ist_ticket_id',
                    'type'     => 'text',
                    'label'    => 'IS&T ticket ID',
                    'required' => true,
                ],
            ],
        ],
    ];

    return $cache;
}

function am_request_workflow_template(string $type): ?array {
    $all = am_request_workflow_templates();
    return $all[$type] ?? null;
}

/** One-line summary for list views (extend per workflow_type). */
function am_workflow_summary_line(string $type, array $payload): string {
    if ($type === 'ready_board') {
        $q = (int)($payload['quantity'] ?? 0);
        $site = (string)($payload['site_code'] ?? '');
        return 'Ready boards ×' . $q . ' → ' . $site;
    }
    if ($type === 'inventory_dispatch') {
        $items = count($payload['line_items'] ?? []);
        $site = (string)($payload['site_code'] ?? '');
        return $items . ' item(s) → ' . $site;
    }
    if ($type === 'it_equipment_request') {
        $cat = (string)($payload['equipment_category'] ?? '');
        $reqType = (string)($payload['equipment_request_type'] ?? '');
        $site = (string)($payload['site_code'] ?? '');
        return ($reqType ? $reqType . ' — ' : '') . ($cat ? $cat : 'Equipment') . ($site ? ' → ' . $site : '');
    }
    $t = am_request_workflow_template($type);
    return (string)($t['label'] ?? $type);
}
