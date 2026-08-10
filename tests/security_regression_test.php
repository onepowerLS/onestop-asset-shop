#!/usr/bin/env php
<?php
declare(strict_types=1);

function security_expect(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$deleteApi = $read('web/api/assets/delete.php');
security_expect(str_contains($deleteApi, 'am_require_admin_json();'), 'destructive legacy asset DELETE requires Level A');
security_expect(!str_contains($deleteApi, "Access-Control-Allow-Origin: *"), 'destructive DELETE is not cross-origin');

$bootstrap = $read('web/create-admin.php');
security_expect(str_contains($bootstrap, "http_response_code(404)"), 'bootstrap admin endpoint is retired');
security_expect(!str_contains($bootstrap, 'Welcome123'), 'bootstrap credentials are absent');

$dbTest = $read('web/test-db.php');
security_expect(str_contains($dbTest, "http_response_code(404)"), 'database diagnostic endpoint is retired');
security_expect(!str_contains($dbTest, 'DB_HOST'), 'database endpoint exposes no connection metadata');

foreach ([
    'web/api/loadout-manifests/index.php' => 'am-loadout-manifest-dev-2026',
    'web/api/ugp/parts-sync.php' => 'ugp-parts-sync-dev-2026',
    'web/api/vehicles/sync.php' => 'onestop-vehicle-sync-2026',
] as $file => $legacyDefault) {
    security_expect(!str_contains($read($file), $legacyDefault), "{$file} has no built-in production API key");
}

fwrite(STDOUT, "security_regression_test: OK\n");
