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
    $t = am_request_workflow_template($type);
    return (string)($t['label'] ?? $type);
}
