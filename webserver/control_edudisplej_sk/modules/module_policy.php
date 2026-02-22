<?php

function edudisplej_module_policy_registry(): array
{
    return [
        'clock' => [
            'duration' => ['min' => 1, 'max' => 3600, 'default' => 10],
            'settings' => [
                'type' => ['type' => 'enum', 'allowed' => ['digital', 'analog'], 'default' => 'digital'],
                'format' => ['type' => 'enum', 'allowed' => ['24h', '12h'], 'default' => '24h'],
                'dateFormat' => ['type' => 'enum', 'allowed' => ['full', 'short', 'numeric', 'none'], 'default' => 'full'],
                'timeColor' => ['type' => 'color', 'default' => '#ffffff'],
                'dateColor' => ['type' => 'color', 'default' => '#ffffff'],
                'bgColor' => ['type' => 'color', 'default' => '#000000'],
                'fontSize' => ['type' => 'int', 'min' => 20, 'max' => 400, 'default' => 120],
                'clockSize' => ['type' => 'int', 'min' => 100, 'max' => 800, 'default' => 300],
                'showSeconds' => ['type' => 'bool', 'default' => true],
                'language' => ['type' => 'enum', 'allowed' => ['hu', 'sk', 'en'], 'default' => 'hu'],
            ],
        ],
        'datetime' => [
            'alias_of' => 'clock',
        ],
        'dateclock' => [
            'alias_of' => 'clock',
        ],
        'default-logo' => [
            'duration' => ['min' => 1, 'max' => 3600, 'default' => 10],
            'settings' => [
                'text' => ['type' => 'string', 'maxLen' => 2000, 'default' => 'EDUDISPLEJ'],
                'fontSize' => ['type' => 'int', 'min' => 20, 'max' => 400, 'default' => 120],
                'textColor' => ['type' => 'color', 'default' => '#ffffff'],
                'bgColor' => ['type' => 'color', 'default' => '#000000'],
                'showVersion' => ['type' => 'bool', 'default' => true],
                'version' => ['type' => 'string', 'maxLen' => 120, 'default' => 'v1.0'],
            ],
        ],
        'text' => [
            'duration' => ['min' => 1, 'max' => 3600, 'default' => 10],
            'settings' => [
                'text' => ['type' => 'string', 'maxLen' => 30000, 'default' => ''],
                'fontFamily' => ['type' => 'enum', 'allowed' => [
                    'Arial, sans-serif',
                    'Verdana, sans-serif',
                    'Tahoma, sans-serif',
                    'Trebuchet MS, sans-serif',
                    'Georgia, serif',
                    'Times New Roman, serif',
                    'Courier New, monospace'
                ], 'default' => 'Arial, sans-serif'],
                'fontSize' => ['type' => 'int', 'min' => 10, 'max' => 260, 'default' => 72],
                'fontWeight' => ['type' => 'enum', 'allowed' => ['400', '600', '700', '800'], 'default' => '700'],
                'fontStyle' => ['type' => 'enum', 'allowed' => ['normal', 'italic'], 'default' => 'normal'],
                'lineHeight' => ['type' => 'float', 'min' => 0.8, 'max' => 2.2, 'default' => 1.2],
                'textAlign' => ['type' => 'enum', 'allowed' => ['left', 'center', 'right'], 'default' => 'left'],
                'textColor' => ['type' => 'color', 'default' => '#ffffff'],
                'bgColor' => ['type' => 'color', 'default' => '#000000'],
                'bgImageData' => ['type' => 'string', 'maxLen' => 8000000, 'default' => ''],
                'scrollMode' => ['type' => 'bool', 'default' => false],
                'scrollStartPauseMs' => ['type' => 'int', 'min' => 0, 'max' => 15000, 'default' => 3000],
                'scrollEndPauseMs' => ['type' => 'int', 'min' => 0, 'max' => 15000, 'default' => 3000],
                'scrollSpeedPxPerSec' => ['type' => 'int', 'min' => 5, 'max' => 400, 'default' => 35],
            ],
        ],
        'unconfigured' => [
            'duration' => ['fixed' => 60],
            'settings' => [
                'text' => ['type' => 'string', 'maxLen' => 2000, 'default' => 'Nincs konfigurálva'],
                'bgColor' => ['type' => 'color', 'default' => '#000000'],
            ],
        ],
        'pdf' => [
            'duration' => ['min' => 1, 'max' => 3600, 'default' => 10],
            'settings' => [
                'pdfDataBase64' => ['type' => 'string', 'maxLen' => 50000000, 'default' => ''],
                'orientation' => ['type' => 'enum', 'allowed' => ['landscape', 'portrait'], 'default' => 'landscape'],
                'zoomLevel' => ['type' => 'int', 'min' => 50, 'max' => 400, 'default' => 100],
                'navigationMode' => ['type' => 'enum', 'allowed' => ['manual', 'auto'], 'default' => 'manual'],
                'displayMode' => ['type' => 'enum', 'allowed' => ['fit-page', 'fit-width', 'fit-height'], 'default' => 'fit-page'],
                'autoScrollSpeedPxPerSec' => ['type' => 'int', 'min' => 5, 'max' => 200, 'default' => 30],
                'autoScrollStartPauseMs' => ['type' => 'int', 'min' => 0, 'max' => 15000, 'default' => 2000],
                'autoScrollEndPauseMs' => ['type' => 'int', 'min' => 0, 'max' => 15000, 'default' => 2000],
                'pausePoints' => ['type' => 'string', 'maxLen' => 10000, 'default' => '[]'],
                'fixedViewMode' => ['type' => 'bool', 'default' => false],
                'fixedPage' => ['type' => 'int', 'min' => 1, 'max' => 9999, 'default' => 1],
                'bgColor' => ['type' => 'color', 'default' => '#ffffff'],
                'showPageNumbers' => ['type' => 'bool', 'default' => true],
            ],
        ],
    ];
}

function edudisplej_module_policy_resolve(string $moduleKey): ?array
{
    $key = strtolower(trim($moduleKey));
    $registry = edudisplej_module_policy_registry();
    if (!isset($registry[$key])) {
        return null;
    }

    $policy = $registry[$key];
    if (!empty($policy['alias_of'])) {
        $base = strtolower(trim((string)$policy['alias_of']));
        if (isset($registry[$base])) {
            $basePolicy = $registry[$base];
            return [
                'duration' => $basePolicy['duration'] ?? ['min' => 1, 'max' => 3600, 'default' => 10],
                'settings' => $basePolicy['settings'] ?? [],
            ];
        }
    }

    return [
        'duration' => $policy['duration'] ?? ['min' => 1, 'max' => 3600, 'default' => 10],
        'settings' => $policy['settings'] ?? [],
    ];
}

function edudisplej_module_policy_normalize_color($value, string $default): string
{
    $candidate = strtolower(trim((string)$value));
    if (preg_match('/^#[0-9a-f]{6}$/', $candidate)) {
        return $candidate;
    }
    return strtolower($default);
}

function edudisplej_module_policy_normalize_bool($value, bool $default): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return $default;
    }

    $normalized = strtolower(trim((string)$value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

function edudisplej_module_policy_normalize_field($value, array $rule)
{
    $type = strtolower((string)($rule['type'] ?? 'string'));
    $default = $rule['default'] ?? null;

    if ($type === 'enum') {
        $allowed = array_map('strval', (array)($rule['allowed'] ?? []));
        $candidate = (string)$value;
        if (in_array($candidate, $allowed, true)) {
            return $candidate;
        }
        return (string)$default;
    }

    if ($type === 'color') {
        return edudisplej_module_policy_normalize_color($value, (string)$default);
    }

    if ($type === 'int') {
        $min = isset($rule['min']) ? (int)$rule['min'] : PHP_INT_MIN;
        $max = isset($rule['max']) ? (int)$rule['max'] : PHP_INT_MAX;
        $normalized = is_numeric($value) ? (int)$value : (int)$default;
        if ($normalized < $min) {
            $normalized = $min;
        }
        if ($normalized > $max) {
            $normalized = $max;
        }
        return $normalized;
    }

    if ($type === 'float') {
        $min = isset($rule['min']) ? (float)$rule['min'] : -INF;
        $max = isset($rule['max']) ? (float)$rule['max'] : INF;
        $normalized = is_numeric($value) ? (float)$value : (float)$default;
        if ($normalized < $min) {
            $normalized = $min;
        }
        if ($normalized > $max) {
            $normalized = $max;
        }
        return $normalized;
    }

    if ($type === 'bool') {
        return edudisplej_module_policy_normalize_bool($value, (bool)$default);
    }

    $maxLen = isset($rule['maxLen']) ? (int)$rule['maxLen'] : 0;
    $normalized = (string)($value ?? $default ?? '');
    if ($maxLen > 0 && mb_strlen($normalized, 'UTF-8') > $maxLen) {
        $normalized = mb_substr($normalized, 0, $maxLen, 'UTF-8');
    }
    return $normalized;
}

function edudisplej_sanitize_module_settings(string $moduleKey, $settings): array
{
    $policy = edudisplej_module_policy_resolve($moduleKey);
    if ($policy === null) {
        return is_array($settings) ? $settings : [];
    }

    $rules = (array)($policy['settings'] ?? []);
    $input = is_array($settings) ? $settings : [];
    $output = [];

    foreach ($rules as $field => $rule) {
        $value = array_key_exists($field, $input) ? $input[$field] : ($rule['default'] ?? null);
        $output[$field] = edudisplej_module_policy_normalize_field($value, (array)$rule);
    }

    return $output;
}

function edudisplej_clamp_module_duration(string $moduleKey, $duration): int
{
    $policy = edudisplej_module_policy_resolve($moduleKey);
    if ($policy === null) {
        $value = is_numeric($duration) ? (int)$duration : 10;
        return max(1, min(3600, $value));
    }

    $durationPolicy = (array)($policy['duration'] ?? []);
    if (isset($durationPolicy['fixed'])) {
        return (int)$durationPolicy['fixed'];
    }

    $min = isset($durationPolicy['min']) ? (int)$durationPolicy['min'] : 1;
    $max = isset($durationPolicy['max']) ? (int)$durationPolicy['max'] : 3600;
    $default = isset($durationPolicy['default']) ? (int)$durationPolicy['default'] : 10;
    $value = is_numeric($duration) ? (int)$duration : $default;

    if ($value < $min) {
        $value = $min;
    }
    if ($value > $max) {
        $value = $max;
    }

    return $value;
}
