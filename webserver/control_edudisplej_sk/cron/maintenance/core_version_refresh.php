<?php

function edudisplej_refresh_core_version_manifest(string $webserverRoot): array {
    $versionsFile = rtrim($webserverRoot, '/\\') . '/install/init/versions.json';
    $controlRoot = rtrim($webserverRoot, '/\\') . '/control_edudisplej_sk';
    $initRoot = rtrim($webserverRoot, '/\\') . '/install/init';
    $scanRoots = [$controlRoot, $initRoot];
    $includeExtensions = ['php', 'sh', 'json', 'service'];
    $skipFragments = [
        '/.git/',
        '/.runtime/',
        '/cache/',
        '/docs/',
        '/logs/',
        '/node_modules/',
        '/runtime/',
        '/screenshots/',
        '/storage/',
        '/tests/',
        '/tmp/',
        '/uploads/',
        '/vendor/',
    ];

    if (!is_file($versionsFile)) {
        return [
            'changed' => false,
            'error' => true,
            'message' => '[ERROR] Core version refresh skipped: versions.json not found',
        ];
    }

    $trackedFiles = [];
    foreach ($scanRoots as $root) {
        if (!is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $absolutePath = $fileInfo->getPathname();
            $normalizedPath = str_replace('\\', '/', $absolutePath);
            $normalizedRoot = str_replace('\\', '/', rtrim($webserverRoot, '/\\'));

            if (strpos($normalizedPath, $normalizedRoot . '/') !== 0) {
                continue;
            }

            $relativePath = ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
            if ($relativePath === '' || $relativePath === 'install/init/versions.json') {
                continue;
            }

            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            if (!in_array($extension, $includeExtensions, true)) {
                continue;
            }

            foreach ($skipFragments as $fragment) {
                $needle = trim($fragment, '/');
                if ($needle !== '' && strpos($relativePath, $needle) !== false) {
                    continue 2;
                }
            }

            $trackedFiles[$relativePath] = $absolutePath;
        }
    }

    ksort($trackedFiles);

    $signatureContext = hash_init('sha256');
    foreach ($trackedFiles as $relativePath => $absolutePath) {
        $fileHash = @hash_file('sha256', $absolutePath);
        if ($fileHash === false) {
            $fileHash = 'missing';
        }
        hash_update($signatureContext, $relativePath . "\n" . $fileHash . "\n");
    }

    $signature = hash_final($signatureContext);
    $versionsData = json_decode((string)file_get_contents($versionsFile), true);
    if (!is_array($versionsData)) {
        $versionsData = [];
    }

    $currentSignature = trim((string)($versionsData['core_checksum'] ?? ''));
    $currentVersion = trim((string)($versionsData['system_version'] ?? ''));
    $fileCount = count($trackedFiles);

    if ($currentSignature === $signature) {
        if (!isset($versionsData['core_checksum_files']) || (int)$versionsData['core_checksum_files'] !== $fileCount) {
            $versionsData['core_checksum'] = $signature;
            $versionsData['core_checksum_files'] = $fileCount;
            $versionsData['core_checksum_scanned_at'] = gmdate('Y-m-d\TH:i:s\Z');

            $tmpFile = tempnam(dirname($versionsFile), 'versions_');
            if ($tmpFile !== false) {
                $json = json_encode($versionsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json !== false) {
                    file_put_contents($tmpFile, $json . "\n");
                    rename($tmpFile, $versionsFile);
                } else {
                    @unlink($tmpFile);
                }
            }

            return [
                'changed' => false,
                'message' => sprintf('[INFO] Core version checksum refreshed without version bump (%s, %d file(s))', substr($signature, 0, 12), $fileCount),
                'version' => $currentVersion,
                'checksum' => $signature,
                'files' => $fileCount,
            ];
        }

        return [
            'changed' => false,
            'message' => sprintf('[INFO] Core version unchanged (%s, %d file(s))', substr($signature, 0, 12), $fileCount),
            'version' => $currentVersion,
            'checksum' => $signature,
            'files' => $fileCount,
        ];
    }

    $newVersion = 'v' . date('YmdHis');
    $versionsData['system_version'] = $newVersion;
    $versionsData['core_checksum'] = $signature;
    $versionsData['core_checksum_files'] = $fileCount;
    $versionsData['core_checksum_scanned_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $versionsData['last_updated'] = gmdate('Y-m-d\TH:i:s\Z');

    $tmpFile = tempnam(dirname($versionsFile), 'versions_');
    if ($tmpFile === false) {
        return [
            'changed' => false,
            'error' => true,
            'message' => '[ERROR] Core version refresh failed: could not create temporary file',
            'checksum' => $signature,
            'files' => $fileCount,
        ];
    }

    $json = json_encode($versionsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        @unlink($tmpFile);
        return [
            'changed' => false,
            'error' => true,
            'message' => '[ERROR] Core version refresh failed: could not encode versions.json',
            'checksum' => $signature,
            'files' => $fileCount,
        ];
    }

    file_put_contents($tmpFile, $json . "\n");
    if (!@rename($tmpFile, $versionsFile)) {
        @unlink($tmpFile);
        return [
            'changed' => false,
            'error' => true,
            'message' => '[ERROR] Core version refresh failed: could not update versions.json',
            'checksum' => $signature,
            'files' => $fileCount,
        ];
    }

    return [
        'changed' => true,
        'message' => sprintf(
            '[SUCCESS] Core version bumped %s -> %s (%s, %d file(s))',
            $currentVersion !== '' ? $currentVersion : 'unknown',
            $newVersion,
            substr($signature, 0, 12),
            $fileCount
        ),
        'version' => $newVersion,
        'previous_version' => $currentVersion,
        'checksum' => $signature,
        'files' => $fileCount,
    ];
}