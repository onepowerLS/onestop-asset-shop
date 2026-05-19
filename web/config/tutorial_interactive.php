<?php
/**
 * Fleet Hub–style interactive tutorial: steps reference [data-tutorial] targets.
 */
declare(strict_types=1);

require_once __DIR__ . '/locale.php';

/** Map step path to sidebar nav target when the page is not yet loaded (FM pathToNavTarget). */
/** First-step URL with ?tutorial= for launching a track (Fleet-style deep link). */
function am_tutorial_start_url(string $trackId): string
{
    $lang = am_session_lang();
    $path = __DIR__ . '/i18n/tutorial_interactive_' . ($lang === 'fr' ? 'fr' : 'en') . '.php';
    if (!is_readable($path)) {
        $path = __DIR__ . '/i18n/tutorial_interactive_en.php';
    }
    /** @var array $data */
    $data = require $path;
    $steps = $data['tracks'][$trackId]['steps'] ?? [];
    if ($steps === []) {
        return base_url('index.php?tutorial=1');
    }
    $p = (string) ($steps[0]['path'] ?? 'index.php');
    $q = $trackId === 'overview' ? '1' : $trackId;

    return base_url($p . '?tutorial=' . rawurlencode($q));
}

function am_tutorial_nav_target_for_path(string $stepPath): string
{
    $stepPath = str_replace('\\', '/', $stepPath);
    $map = [
        'index.php' => 'nav-dashboard',
        'assets/index.php' => 'nav-assets-all',
        'assets/add.php' => 'nav-assets-all',
        'inventory/index.php' => 'nav-inventory',
        'checkout/index.php' => 'nav-checkout',
        'requests/index.php' => 'nav-requests-procurement',
        'requests/workflow-index.php' => 'nav-requests-workflows',
        'requests/dispatch-new.php' => 'nav-requests-workflows',
        'requests/dispatch-view.php' => 'nav-requests-workflows',
        'loadout/index.php' => 'nav-loadout',
        'reports/index.php' => 'nav-reports',
        'tablet/index.php' => 'nav-tablet',
        'help.php' => 'nav-help',
        'tutorial.php' => 'nav-tutorial',
        'transactions/index.php' => 'nav-transactions',
    ];
    return $map[$stepPath] ?? 'nav-dashboard';
}

/** @return array{tracks: array<string, array>, track_order: array<int, string>, query_map: array<string, string>, strings: array<string, string>, base_url: string, nav_map: array<string, string>} */
function am_tutorial_client_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $lang = am_session_lang();
    $path = __DIR__ . '/i18n/tutorial_interactive_' . ($lang === 'fr' ? 'fr' : 'en') . '.php';
    if (!is_readable($path)) {
        $path = __DIR__ . '/i18n/tutorial_interactive_en.php';
    }
    /** @var array $data */
    $data = require $path;

    $strings = [
        'back' => am_ui('tutorial_back', 'Back'),
        'next' => am_ui('tutorial_next', 'Next'),
        'finish' => am_ui('tutorial_finish', 'Finish tutorial'),
        'exit' => am_ui('tutorial_exit', 'Exit'),
        'stepOf' => am_ui('tutorial_step_of', 'Step %1$s of %2$s'),
        'missing' => am_ui('tutorial_missing', 'Open the highlighted navigation item or tap Next — the page may still be loading.'),
        'mode' => am_ui('tutorial_mode', 'Tutorials'),
        'chooseTrack' => am_ui('tutorial_choose', 'Choose a tutorial…'),
    ];

    $navMap = [
        'index.php' => 'nav-dashboard',
        'assets/index.php' => 'nav-assets-all',
        'assets/add.php' => 'nav-assets-all',
        'inventory/index.php' => 'nav-inventory',
        'checkout/index.php' => 'nav-checkout',
        'requests/index.php' => 'nav-requests-procurement',
        'requests/workflow-index.php' => 'nav-requests-workflows',
        'requests/dispatch-new.php' => 'nav-requests-workflows',
        'requests/dispatch-view.php' => 'nav-requests-workflows',
        'loadout/index.php' => 'nav-loadout',
        'reports/index.php' => 'nav-reports',
        'tablet/index.php' => 'nav-tablet',
        'help.php' => 'nav-help',
        'tutorial.php' => 'nav-tutorial',
    ];

    $cache = [
        'tracks' => $data['tracks'] ?? [],
        'track_order' => $data['track_order'] ?? ['overview'],
        'query_map' => $data['query_map'] ?? [],
        'strings' => $strings,
        'base_url' => rtrim(base_url(''), '/'),
        'nav_map' => $navMap,
    ];

    return $cache;
}
