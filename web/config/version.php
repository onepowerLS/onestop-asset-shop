<?php
/**
 * Application version / build identification.
 *
 * Exposes the deployed git commit and build timestamp so the browser console
 * (and /health.php) can confirm which revision is actually running on EC2.
 *
 * Resolution order (first one that works wins):
 *   1. VERSION file at repo root (written by the deploy workflow)
 *   2. .git/HEAD + .git/refs/heads/<branch> (works because EC2 has a full clone)
 *   3. Latest file mtime among this file and the repo-root composer.json
 */

if (!function_exists('am_app_version')) {
    function am_app_version(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $root = dirname(__DIR__, 2);
        $commit = '';
        $builtAt = '';
        $source = 'unknown';
        $branch = 'main';

        $versionFile = $root . '/VERSION';
        if (is_readable($versionFile)) {
            $raw = @file_get_contents($versionFile);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode(trim($raw), true);
                if (is_array($decoded)) {
                    $commit = (string)($decoded['commit'] ?? '');
                    $builtAt = (string)($decoded['built_at'] ?? '');
                    $branch = (string)($decoded['branch'] ?? $branch);
                    $source = 'VERSION';
                } else {
                    $commit = trim((string)$raw);
                    $source = 'VERSION';
                    $builtAt = date('c', (int)@filemtime($versionFile));
                }
            }
        }

        if ($commit === '') {
            $headFile = $root . '/.git/HEAD';
            if (is_readable($headFile)) {
                $head = trim((string)@file_get_contents($headFile));
                if (str_starts_with($head, 'ref:')) {
                    $ref = trim(substr($head, 4));
                    $refFile = $root . '/.git/' . $ref;
                    if (is_readable($refFile)) {
                        $commit = trim((string)@file_get_contents($refFile));
                        $branch = basename($ref);
                        $source = 'git';
                        $builtAt = date('c', (int)@filemtime($refFile));
                    }
                } elseif (preg_match('/^[0-9a-f]{7,40}$/i', $head)) {
                    $commit = $head;
                    $source = 'git';
                    $builtAt = date('c', (int)@filemtime($headFile));
                }
            }
        }

        if ($commit === '') {
            $candidates = [__FILE__, $root . '/composer.json', $root . '/web/index.php'];
            $mtime = 0;
            foreach ($candidates as $c) {
                if (is_readable($c)) {
                    $t = (int)@filemtime($c);
                    if ($t > $mtime) {
                        $mtime = $t;
                    }
                }
            }
            $commit = $mtime > 0 ? ('mtime-' . $mtime) : 'unknown';
            $source = 'mtime';
            $builtAt = $mtime > 0 ? date('c', $mtime) : date('c');
        }

        $short = preg_match('/^[0-9a-f]{7,40}$/i', $commit) ? substr($commit, 0, 7) : $commit;
        if ($builtAt === '') {
            $builtAt = date('c');
        }

        $cache = [
            'commit' => $commit,
            'short' => $short,
            'branch' => $branch,
            'built_at' => $builtAt,
            'source' => $source,
        ];
        return $cache;
    }
}

if (!function_exists('am_app_version_tag')) {
    function am_app_version_tag(): string
    {
        $v = am_app_version();
        return sprintf('%s @ %s', $v['short'], $v['built_at']);
    }
}
