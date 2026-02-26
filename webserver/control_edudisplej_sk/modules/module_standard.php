<?php
require_once __DIR__ . '/module_registry.php';

function edudisplej_canonical_module_key(string $moduleKey): string
{
    $key = strtolower(trim($moduleKey));

    static $aliases = [
        'meal_menu' => 'meal-menu',
        'room_occupancy' => 'room-occupancy',
        'default_logo' => 'default-logo',
        'image_gallery' => 'image-gallery',
    ];

    return $aliases[$key] ?? $key;
}

function edudisplej_modules_root(): string
{
    return realpath(__DIR__) ?: __DIR__;
}

function edudisplej_module_key_is_valid(string $moduleKey): bool
{
    return (bool)preg_match('/^[a-z0-9_.-]+$/', strtolower(trim($moduleKey)));
}

function edudisplej_read_json_file_safe(string $absPath): ?array
{
    if (!is_file($absPath)) {
        return null;
    }

    $raw = @file_get_contents($absPath);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function edudisplej_resolve_module_runtime(string $moduleKey): array
{
    $moduleKey = edudisplej_canonical_module_key($moduleKey);
    $meta = edudisplej_module_meta($moduleKey);

    $folder = $meta['folder'] ?? $moduleKey;
    $folder = trim(str_replace('\\', '/', (string)$folder), '/');

    $modulesRoot = edudisplej_modules_root();
    $folderAbs = $modulesRoot . '/' . $folder;

    $rendererRel = $meta['renderer'] ?? ('modules/' . $folder . '/m_' . $moduleKey . '.html');
    $defaultSettingsRel = $meta['default_settings_file'] ?? ('modules/' . $folder . '/config/default_settings.json');
    $manifestAbs = $folderAbs . '/module.json';

    return [
        'module_key' => $moduleKey,
        'meta' => $meta,
        'folder' => $folder,
        'folder_abs' => $folderAbs,
        'manifest_abs' => $manifestAbs,
        'manifest' => edudisplej_read_json_file_safe($manifestAbs),
        'renderer_rel' => $rendererRel,
        'default_settings_rel' => $defaultSettingsRel,
    ];
}

function edudisplej_validate_manifest_payload(array $manifest): array
{
    $errors = [];

    $moduleKey = strtolower(trim((string)($manifest['module_key'] ?? '')));
    $name = trim((string)($manifest['name'] ?? ''));
    $renderer = trim((string)($manifest['renderer'] ?? ''));
    $defaults = trim((string)($manifest['config']['defaults'] ?? ''));

    if ($moduleKey === '') {
        $errors[] = 'manifest.module_key is required';
    } elseif (!edudisplej_module_key_is_valid($moduleKey)) {
        $errors[] = 'manifest.module_key has invalid format';
    }

    if ($name === '') {
        $errors[] = 'manifest.name is required';
    }

    if ($renderer === '') {
        $errors[] = 'manifest.renderer is required';
    }

    if ($defaults === '') {
        $errors[] = 'manifest.config.defaults is required';
    }

    return $errors;
}

function edudisplej_validate_module_folder(string $moduleKey): array
{
    $runtime = edudisplej_resolve_module_runtime($moduleKey);
    $errors = [];

    if (!is_dir($runtime['folder_abs'])) {
        $errors[] = 'Module folder missing';
        return ['ok' => false, 'errors' => $errors, 'runtime' => $runtime];
    }

    $registry = edudisplej_module_registry();
    $requiredFiles = $registry['required_files'] ?? [];
    foreach ($requiredFiles as $required) {
        $requiredAbs = $runtime['folder_abs'] . '/' . ltrim((string)$required, '/');
        if (!is_file($requiredAbs)) {
            $errors[] = 'Missing required file: ' . $required;
        }
    }

    $manifest = $runtime['manifest'];
    if (!$manifest) {
        $errors[] = 'Invalid or missing module.json';
    } else {
        $manifestErrors = edudisplej_validate_manifest_payload($manifest);
        foreach ($manifestErrors as $manifestError) {
            $errors[] = $manifestError;
        }
    }

    return ['ok' => empty($errors), 'errors' => $errors, 'runtime' => $runtime];
}

function edudisplej_detect_package_root(string $extractDir): ?string
{
    if (is_file($extractDir . '/module.json')) {
        return $extractDir;
    }

    $entries = scandir($extractDir);
    if ($entries === false) {
        return null;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $candidate = $extractDir . '/' . $entry;
        if (is_dir($candidate) && is_file($candidate . '/module.json')) {
            return $candidate;
        }
    }

    return null;
}

function edudisplej_safe_recursive_copy(string $sourceDir, string $targetDir): bool
{
    if (!is_dir($sourceDir)) {
        return false;
    }

    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return false;
    }

    $items = scandir($sourceDir);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $src = $sourceDir . '/' . $item;
        $dst = $targetDir . '/' . $item;

        if (is_dir($src)) {
            if (!edudisplej_safe_recursive_copy($src, $dst)) {
                return false;
            }
            continue;
        }

        if (!@copy($src, $dst)) {
            return false;
        }
    }

    return true;
}
