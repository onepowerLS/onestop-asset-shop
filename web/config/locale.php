<?php
/**
 * UI language (session + cookie). v1: EN / FR for chrome + key screens.
 */
require_once __DIR__ . '/app.php';

function am_locale_bootstrap(): void {
    if (!empty($_SESSION['ui_lang']) && in_array($_SESSION['ui_lang'], ['en', 'fr'], true)) {
        return;
    }
    $c = strtolower(trim((string)($_COOKIE['am_lang'] ?? '')));
    if ($c === 'fr' || $c === 'en') {
        $_SESSION['ui_lang'] = $c;
        return;
    }
    $_SESSION['ui_lang'] = 'en';
}

function am_session_lang(): string {
    $l = strtolower((string)($_SESSION['ui_lang'] ?? 'en'));
    return $l === 'fr' ? 'fr' : 'en';
}

/** @return array<string, string> */
function am_ui_strings(): array {
    $lang = am_session_lang();
    $path = __DIR__ . '/i18n/ui_' . $lang . '.php';
    if (!is_readable($path)) {
        $path = __DIR__ . '/i18n/ui_en.php';
    }
    /** @var array<string, string> $a */
    $a = require $path;
    return is_array($a) ? $a : [];
}

function am_ui(string $key, string $fallback = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = am_ui_strings();
    }
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    return $fallback !== '' ? $fallback : $key;
}
