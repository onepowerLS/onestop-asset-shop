<?php
/**
 * Load localized Help / Tutorial copy from web/config/i18n/*.php
 */
declare(strict_types=1);

require_once __DIR__ . '/ui_locale.php';

function am_i18n_help(): array
{
    $lang = am_locale();
    $path = __DIR__ . '/i18n/help_' . ($lang === 'fr' ? 'fr' : 'en') . '.php';
    return require $path;
}

function am_i18n_tutorial(): array
{
    $lang = am_locale();
    $path = __DIR__ . '/i18n/tutorial_' . ($lang === 'fr' ? 'fr' : 'en') . '.php';
    return require $path;
}
