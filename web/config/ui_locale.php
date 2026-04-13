<?php
/**
 * UI locale (EN/FR) for Help and Tutorial pages.
 * Persists in session; ?lang=en|fr switches language.
 */
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function am_locale_bootstrap(): void
{
    if (isset($_GET['lang'])) {
        $l = strtolower((string) $_GET['lang']);
        if ($l === 'fr' || $l === 'en') {
            $_SESSION['am_lang'] = $l;
        }
    }
    if (empty($_SESSION['am_lang']) || !in_array($_SESSION['am_lang'], ['en', 'fr'], true)) {
        $_SESSION['am_lang'] = 'en';
    }
}

function am_locale(): string
{
    return $_SESSION['am_lang'] ?? 'en';
}

/** Append lang= to a relative path for navigation preserving locale */
function am_localized_url(string $path): string
{
    $base = base_url($path);
    $sep = str_contains($base, '?') ? '&' : '?';
    return $base . $sep . 'lang=' . am_locale();
}
