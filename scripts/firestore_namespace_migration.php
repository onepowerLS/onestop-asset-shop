<?php
/**
 * One-time migration tool:
 * 1) AM MySQL tables -> Firestore am_core_* collections
 * 2) PR live collections -> Firestore pr_master_* collections
 *
 * Usage:
 *   php firestore_namespace_migration.php
 *
 * Required env vars in project .env:
 *   FIREBASE_PROJECT_ID=pr-system-4ea55
 *   FIREBASE_ADMIN_BEARER_TOKEN=<OAuth2 access token with Firestore scope>
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 */

date_default_timezone_set('UTC');

$root = dirname(__DIR__);
$envPath = $root . '/.env';
if (!file_exists($envPath)) {
    fwrite(STDERR, "Missing .env at project root: {$envPath}\n");
    exit(1);
}

$env = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
if (!is_array($env)) {
    fwrite(STDERR, "Unable to parse .env\n");
    exit(1);
}

$projectId = trim((string)($env['FIREBASE_PROJECT_ID'] ?? 'pr-system-4ea55'));
$bearer = trim((string)($env['FIREBASE_ADMIN_BEARER_TOKEN'] ?? ''));
if ($projectId === '' || $bearer === '') {
    fwrite(STDERR, "Missing FIREBASE_PROJECT_ID or FIREBASE_ADMIN_BEARER_TOKEN in .env\n");
    exit(1);
}

$dbHost = (string)($env['DB_HOST'] ?? 'localhost');
$dbName = (string)($env['DB_NAME'] ?? '');
$dbUser = (string)($env['DB_USER'] ?? 'root');
$dbPass = (string)($env['DB_PASS'] ?? '');

if ($dbName === '') {
    fwrite(STDERR, "Missing DB_NAME in .env\n");
    exit(1);
}

$mysql = null;
try {
    $mysql = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "MySQL connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

function fs_req(string $method, string $url, string $bearer, ?array $payload = null): array {
    $headers = [
        'Authorization: Bearer ' . $bearer,
        'Content-Type: application/json',
    ];
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $json = is_string($response) ? json_decode($response, true) : null;
    return [
        'ok' => empty($error) && $status >= 200 && $status < 300,
        'status' => $status,
        'error' => $error,
        'json' => is_array($json) ? $json : [],
        'raw' => $response,
    ];
}

function fs_php_to_field(mixed $v): array {
    if ($v === null) return ['nullValue' => null];
    if (is_bool($v)) return ['booleanValue' => $v];
    if (is_int($v)) return ['integerValue' => (string)$v];
    if (is_float($v)) return ['doubleValue' => $v];
    if (is_array($v)) {
        $isList = array_keys($v) === range(0, count($v) - 1);
        if ($isList) {
            return ['arrayValue' => ['values' => array_map('fs_php_to_field', $v)]];
        }
        $fields = [];
        foreach ($v as $k => $val) $fields[(string)$k] = fs_php_to_field($val);
        return ['mapValue' => ['fields' => $fields]];
    }
    return ['stringValue' => (string)$v];
}

function fs_row_to_doc_fields(array $row): array {
    $fields = [];
    foreach ($row as $k => $v) {
        $fields[(string)$k] = fs_php_to_field($v);
    }
    return $fields;
}

function fs_upsert_doc(string $projectId, string $collection, string $docId, array $row, string $bearer): bool {
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($projectId) .
        '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($docId);
    $payload = ['fields' => fs_row_to_doc_fields($row)];
    $res = fs_req('PATCH', $url, $bearer, $payload);
    return $res['ok'];
}

function fs_list_collection(string $projectId, string $collection, string $bearer): array {
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($projectId) .
        '/databases/(default)/documents/' . rawurlencode($collection) . '?pageSize=1000';
    $res = fs_req('GET', $url, $bearer, null);
    if (!$res['ok']) return [];
    return $res['json']['documents'] ?? [];
}

function fs_doc_to_php(array $doc): array {
    $fields = $doc['fields'] ?? [];
    $out = [];
    $decode = function ($v) use (&$decode) {
        if (isset($v['stringValue'])) return (string)$v['stringValue'];
        if (isset($v['integerValue'])) return (int)$v['integerValue'];
        if (isset($v['doubleValue'])) return (float)$v['doubleValue'];
        if (isset($v['booleanValue'])) return (bool)$v['booleanValue'];
        if (isset($v['timestampValue'])) return (string)$v['timestampValue'];
        if (isset($v['nullValue'])) return null;
        if (isset($v['arrayValue']['values'])) return array_map($decode, $v['arrayValue']['values']);
        if (isset($v['mapValue']['fields'])) {
            $m = [];
            foreach ($v['mapValue']['fields'] as $k => $mv) $m[$k] = $decode($mv);
            return $m;
        }
        return null;
    };
    foreach ($fields as $k => $v) $out[$k] = $decode($v);
    $parts = explode('/', (string)($doc['name'] ?? ''));
    $out['id'] = end($parts);
    return $out;
}

function choose_doc_id(array $row): string {
    foreach (['id', 'asset_id', 'transaction_id', 'request_id', 'location_id', 'category_id', 'country_id', 'employee_id', 'user_id'] as $k) {
        if (isset($row[$k]) && (string)$row[$k] !== '') return (string)$row[$k];
    }
    return bin2hex(random_bytes(8));
}

echo "== Migration start ==\n";
echo "Project: {$projectId}\n";

// 1) AM source-of-truth tables -> am_core_*
$amSourceTables = [
    'assets',
    'inventory_levels',
    'transactions',
    'allocations',
    'requests',
    'request_items',
    'qr_labels',
    'stock_takes',
    'stock_take_items',
];

$existingTables = $mysql->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$existingSet = array_fill_keys($existingTables, true);

foreach ($amSourceTables as $table) {
    if (!isset($existingSet[$table])) {
        echo "[skip] Missing MySQL table: {$table}\n";
        continue;
    }
    $rows = $mysql->query("SELECT * FROM `{$table}`")->fetchAll();
    $collection = 'am_core_' . $table;
    $ok = 0;
    $total = count($rows);
    foreach ($rows as $row) {
        $docId = choose_doc_id($row);
        if (fs_upsert_doc($projectId, $collection, $docId, $row, $bearer)) {
            $ok++;
        }
    }
    echo "[am_core] {$table} -> {$collection}: {$ok}/{$total}\n";
}

// 2) PR live source collections -> pr_master_*
$prSourceCollections = [
    // Source collection => destination collection
    'users' => 'pr_master_users',
    'organizations' => 'pr_master_organizations',
    'countries' => 'pr_master_countries',
    'locations' => 'pr_master_locations',
    'employees' => 'pr_master_employees',
    'departments' => 'pr_master_departments',
    'projectCategories' => 'pr_master_project_categories',
    'sites' => 'pr_master_sites',
    'expenseTypes' => 'pr_master_expense_types',
    'vendors' => 'pr_master_vendors',
    'currencies' => 'pr_master_currencies',
    'unitsOfMeasure' => 'pr_master_units_of_measure',
    'permissions' => 'pr_master_permissions',
    'rules' => 'pr_master_rules',
    'paymentTypes' => 'pr_master_payment_types',
];

foreach ($prSourceCollections as $source => $dest) {
    $docs = fs_list_collection($projectId, $source, $bearer);
    $ok = 0;
    $total = count($docs);
    foreach ($docs as $doc) {
        $row = fs_doc_to_php($doc);
        $docId = (string)($row['id'] ?? choose_doc_id($row));
        unset($row['id']);
        if (fs_upsert_doc($projectId, $dest, $docId, $row, $bearer)) {
            $ok++;
        }
    }
    echo "[pr_master] {$source} -> {$dest}: {$ok}/{$total}\n";
}

echo "== Migration complete ==\n";
