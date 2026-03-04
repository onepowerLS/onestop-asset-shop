<?php
/**
 * Firestore read helpers for AM/PR source-of-truth split.
 *
 * Collections (single Firebase project, split by namespace):
 * - AM source: am_core_*
 * - PR source: pr_master_*
 */
require_once __DIR__ . '/firebase.php';

function am_firestore_project_id(): string {
    $cfg = am_firebase_config();
    return (string)($cfg['project_id'] ?? 'pr-system-4ea55');
}

function am_firestore_id_token(): string {
    return (string)($_SESSION['firebase_id_token'] ?? '');
}

function am_firestore_field_value(array $value): mixed {
    if (isset($value['stringValue'])) {
        return (string)$value['stringValue'];
    }
    if (isset($value['integerValue'])) {
        return (int)$value['integerValue'];
    }
    if (isset($value['doubleValue'])) {
        return (float)$value['doubleValue'];
    }
    if (isset($value['booleanValue'])) {
        return (bool)$value['booleanValue'];
    }
    if (isset($value['timestampValue'])) {
        return (string)$value['timestampValue'];
    }
    if (isset($value['nullValue'])) {
        return null;
    }
    if (isset($value['arrayValue'])) {
        $values = $value['arrayValue']['values'] ?? [];
        $out = [];
        foreach ($values as $v) {
            if (is_array($v)) {
                $out[] = am_firestore_field_value($v);
            }
        }
        return $out;
    }
    if (isset($value['mapValue'])) {
        $fields = $value['mapValue']['fields'] ?? [];
        $out = [];
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                $out[$k] = am_firestore_field_value($v);
            }
        }
        return $out;
    }
    return null;
}

function am_firestore_document_to_array(array $doc): array {
    $data = [];
    $fields = $doc['fields'] ?? [];
    foreach ($fields as $key => $value) {
        if (is_array($value)) {
            $data[$key] = am_firestore_field_value($value);
        }
    }
    if (!empty($doc['name'])) {
        $parts = explode('/', (string)$doc['name']);
        $data['id'] = end($parts);
    }
    return $data;
}

function am_firestore_get_collection(string $collectionName, int $pageSize = 1000): array {
    $token = am_firestore_id_token();
    if ($token === '') {
        return [];
    }

    $project = am_firestore_project_id();
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($project) .
        '/databases/(default)/documents/' . rawurlencode($collectionName) .
        '?pageSize=' . max(1, min(1000, $pageSize));

    $result = am_http_get_json($url, ['Authorization: Bearer ' . $token]);
    if (!$result['ok']) {
        return [];
    }

    $docs = $result['json']['documents'] ?? [];
    if (!is_array($docs)) {
        return [];
    }

    $out = [];
    foreach ($docs as $doc) {
        if (is_array($doc)) {
            $out[] = am_firestore_document_to_array($doc);
        }
    }
    return $out;
}
