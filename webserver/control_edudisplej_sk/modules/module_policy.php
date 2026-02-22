<?php

function edudisplej_module_policy_registry(): array
{
    return [
        'clock' => [
            'duration' => ['min' => 1, 'max' => 3600, 'default' => 10],
            'settings' => [
                'type' => ['type' => 'enum', 'allowed' => ['digital', 'analog'], 'default' => 'digital'],
                'format' => ['type' => 'enum', 'allowed' => ['24h', '12h'], 'default' => '24h'],
                'dateFormat' => ['type' => 'enum', 'allowed' => ['full', 'short', 'dmy', 'numeric', 'none'], 'default' => 'dmy'],
                'timeColor' => ['type' => 'color', 'default' => '#ffffff'],
                'dateColor' => ['type' => 'color', 'default' => '#ffffff'],
                'bgColor' => ['type' => 'color', 'default' => '#000000'],
                'fontSize' => ['type' => 'int', 'min' => 20, 'max' => 400, 'default' => 150],
                'timeFontSize' => ['type' => 'int', 'min' => 20, 'max' => 400, 'default' => 150],
                'dateFontSize' => ['type' => 'int', 'min' => 12, 'max' => 220, 'default' => 48],
                'clockSize' => ['type' => 'int', 'min' => 100, 'max' => 800, 'default' => 300],
                'showSeconds' => ['type' => 'bool', 'default' => true],
                'showDate' => ['type' => 'bool', 'default' => true],
                'language' => ['type' => 'enum', 'allowed' => ['hu', 'sk', 'en'], 'default' => 'sk'],
            ],
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
                'textSourceType' => ['type' => 'enum', 'allowed' => ['manual', 'collection'], 'default' => 'manual'],
                'textCollectionId' => ['type' => 'int', 'min' => 0, 'max' => 2147483647, 'default' => 0],
                'textCollectionLabel' => ['type' => 'string', 'maxLen' => 180, 'default' => ''],
                'textCollectionVersionTs' => ['type' => 'int', 'min' => 0, 'max' => 9999999999999, 'default' => 0],
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
                'pdfDataBase64' => ['type' => 'string', 'maxLen' => 2048, 'default' => ''],
                'pdfAssetUrl' => ['type' => 'string', 'maxLen' => 500, 'default' => ''],
                'pdfAssetId' => ['type' => 'string', 'maxLen' => 64, 'default' => ''],
                'zoomLevel' => ['type' => 'int', 'min' => 50, 'max' => 250, 'default' => 100],
                'autoScrollEnabled' => ['type' => 'bool', 'default' => false],
                'autoScrollSpeedPxPerSec' => ['type' => 'int', 'min' => 5, 'max' => 300, 'default' => 30],
                'autoScrollStartPauseMs' => ['type' => 'int', 'min' => 0, 'max' => 15000, 'default' => 2000],
                'autoScrollEndPauseMs' => ['type' => 'int', 'min' => 0, 'max' => 15000, 'default' => 2000],
                'pauseAtPercent' => ['type' => 'int', 'min' => -1, 'max' => 100, 'default' => -1],
                'pauseDurationMs' => ['type' => 'int', 'min' => 0, 'max' => 15000, 'default' => 2000],
            ],
        ],
        'image-gallery' => [
            'duration' => ['min' => 1, 'max' => 3600, 'default' => 15],
            'settings' => [
                'imageUrlsJson' => ['type' => 'string', 'maxLen' => 8000, 'default' => '[]'],
                'displayMode' => ['type' => 'enum', 'allowed' => ['slideshow', 'collage', 'single'], 'default' => 'slideshow'],
                'fitMode' => ['type' => 'enum', 'allowed' => ['cover', 'contain', 'fill'], 'default' => 'contain'],
                'slideIntervalSec' => ['type' => 'int', 'min' => 1, 'max' => 30, 'default' => 5],
                'transitionEnabled' => ['type' => 'bool', 'default' => true],
                'transitionMs' => ['type' => 'int', 'min' => 100, 'max' => 2000, 'default' => 450],
                'collageColumns' => ['type' => 'int', 'min' => 2, 'max' => 5, 'default' => 3],
                'bgColor' => ['type' => 'color', 'default' => '#000000'],
                'clockOverlayEnabled' => ['type' => 'bool', 'default' => false],
                'clockOverlayPosition' => ['type' => 'enum', 'allowed' => ['top', 'bottom'], 'default' => 'top'],
                'clockOverlayHeightPercent' => ['type' => 'int', 'min' => 20, 'max' => 40, 'default' => 40],
                'clockOverlayTimeColor' => ['type' => 'color', 'default' => '#ffffff'],
                'clockOverlayDateColor' => ['type' => 'color', 'default' => '#ffffff'],
                'textOverlayEnabled' => ['type' => 'bool', 'default' => false],
                'textOverlayPosition' => ['type' => 'enum', 'allowed' => ['top', 'bottom'], 'default' => 'bottom'],
                'textOverlayHeightPercent' => ['type' => 'int', 'min' => 12, 'max' => 40, 'default' => 20],
                'textOverlaySourceType' => ['type' => 'enum', 'allowed' => ['manual', 'collection', 'external'], 'default' => 'manual'],
                'textOverlayText' => ['type' => 'string', 'maxLen' => 3000, 'default' => 'Sem vložte text...'],
                'textOverlayCollectionJson' => ['type' => 'string', 'maxLen' => 12000, 'default' => '[]'],
                'textOverlayExternalUrl' => ['type' => 'string', 'maxLen' => 2000, 'default' => ''],
                'textOverlayFontSize' => ['type' => 'int', 'min' => 18, 'max' => 120, 'default' => 52],
                'textOverlayColor' => ['type' => 'color', 'default' => '#ffffff'],
                'textOverlaySpeedPxPerSec' => ['type' => 'int', 'min' => 40, 'max' => 320, 'default' => 120],
            ],
        ],
        'video' => [
            'duration' => ['min' => 1, 'max' => 86400, 'default' => 10],
            'settings' => [
                'videoAssetUrl' => ['type' => 'string', 'maxLen' => 500, 'default' => ''],
                'videoAssetId' => ['type' => 'string', 'maxLen' => 64, 'default' => ''],
                'videoDurationSec' => ['type' => 'int', 'min' => 1, 'max' => 86400, 'default' => 10],
                'muted' => ['type' => 'bool', 'default' => true],
                'fitMode' => ['type' => 'enum', 'allowed' => ['contain', 'cover', 'fill'], 'default' => 'contain'],
                'bgColor' => ['type' => 'color', 'default' => '#000000'],
            ],
        ],
        'meal-menu' => [
            'duration' => ['min' => 5, 'max' => 3600, 'default' => 60],
            'settings' => [
                'companyId' => ['type' => 'int', 'min' => 0, 'max' => 2147483647, 'default' => 0],
                'siteKey' => ['type' => 'string', 'maxLen' => 80, 'default' => 'jedalen.sk'],
                'institutionId' => ['type' => 'int', 'min' => 0, 'max' => 2147483647, 'default' => 0],
                'language' => ['type' => 'enum', 'allowed' => ['hu', 'sk', 'en'], 'default' => 'hu'],
                'showHeaderTitle' => ['type' => 'bool', 'default' => true],
                'customHeaderTitle' => ['type' => 'string', 'maxLen' => 120, 'default' => ''],
                'showInstitutionName' => ['type' => 'bool', 'default' => true],
                'showBreakfast' => ['type' => 'bool', 'default' => true],
                'showSnackAm' => ['type' => 'bool', 'default' => true],
                'showLunch' => ['type' => 'bool', 'default' => true],
                'showSnackPm' => ['type' => 'bool', 'default' => false],
                'showDinner' => ['type' => 'bool', 'default' => false],
                'showMealTypeEmojis' => ['type' => 'bool', 'default' => false],
                'showMealTypeSvgIcons' => ['type' => 'bool', 'default' => true],
                'showAllergenEmojis' => ['type' => 'bool', 'default' => false],
                'centerAlign' => ['type' => 'bool', 'default' => false],
                'slowScrollOnOverflow' => ['type' => 'bool', 'default' => false],
                'slowScrollSpeedPxPerSec' => ['type' => 'int', 'min' => 8, 'max' => 120, 'default' => 28],
                'scrollStartDelayMs' => ['type' => 'int', 'min' => 0, 'max' => 20000, 'default' => 2000],
                'scrollLoopPauseMs' => ['type' => 'int', 'min' => 0, 'max' => 20000, 'default' => 2000],
                'layoutMode' => ['type' => 'enum', 'allowed' => ['classic', 'fullscreen_scroll', 'square_dual_day'], 'default' => 'classic'],
                'showTomorrowInSquare' => ['type' => 'bool', 'default' => true],
                'fontFamily' => ['type' => 'string', 'maxLen' => 120, 'default' => 'Segoe UI, Tahoma, sans-serif'],
                'mealTitleFontSize' => ['type' => 'float', 'min' => 0.8, 'max' => 4.0, 'default' => 1.5],
                'mealTextFontSize' => ['type' => 'float', 'min' => 0.8, 'max' => 4.0, 'default' => 1.35],
                'textFontWeight' => ['type' => 'int', 'min' => 300, 'max' => 800, 'default' => 600],
                'lineHeight' => ['type' => 'float', 'min' => 1.0, 'max' => 2.2, 'default' => 1.35],
                'wrapText' => ['type' => 'bool', 'default' => true],
                'showAppetiteMessage' => ['type' => 'bool', 'default' => false],
                'appetiteMessageText' => ['type' => 'string', 'maxLen' => 240, 'default' => 'Jó étvágyat kívánunk!'],
                'showSourceUrl' => ['type' => 'bool', 'default' => false],
                'sourceUrl' => ['type' => 'string', 'maxLen' => 500, 'default' => ''],
                'clockOverlayEnabled' => ['type' => 'bool', 'default' => false],
                'clockOverlayPosition' => ['type' => 'enum', 'allowed' => ['top', 'bottom'], 'default' => 'top'],
                'clockOverlayHeightPercent' => ['type' => 'int', 'min' => 20, 'max' => 40, 'default' => 40],
                'clockOverlayTimeColor' => ['type' => 'color', 'default' => '#ffffff'],
                'clockOverlayDateColor' => ['type' => 'color', 'default' => '#ffffff'],
                'textOverlayEnabled' => ['type' => 'bool', 'default' => false],
                'textOverlayPosition' => ['type' => 'enum', 'allowed' => ['top', 'bottom'], 'default' => 'bottom'],
                'textOverlayHeightPercent' => ['type' => 'int', 'min' => 12, 'max' => 40, 'default' => 20],
                'textOverlaySourceType' => ['type' => 'enum', 'allowed' => ['manual', 'collection', 'external'], 'default' => 'manual'],
                'textOverlayText' => ['type' => 'string', 'maxLen' => 4000, 'default' => 'Sem vložte text...'],
                'textOverlayCollectionJson' => ['type' => 'string', 'maxLen' => 12000, 'default' => '[]'],
                'textOverlayExternalUrl' => ['type' => 'string', 'maxLen' => 500, 'default' => ''],
                'textOverlayFontSize' => ['type' => 'int', 'min' => 18, 'max' => 120, 'default' => 52],
                'textOverlayColor' => ['type' => 'color', 'default' => '#ffffff'],
                'textOverlaySpeedPxPerSec' => ['type' => 'int', 'min' => 40, 'max' => 320, 'default' => 120],
                'apiBaseUrl' => ['type' => 'string', 'maxLen' => 300, 'default' => '../../api/meal_plan.php'],
            ],
        ],
        'room-occupancy' => [
            'duration' => ['min' => 5, 'max' => 3600, 'default' => 20],
            'settings' => [
                'companyId' => ['type' => 'int', 'min' => 0, 'max' => 2147483647, 'default' => 0],
                'roomId' => ['type' => 'int', 'min' => 0, 'max' => 2147483647, 'default' => 0],
                'showOnlyCurrent' => ['type' => 'bool', 'default' => false],
                'showNextCount' => ['type' => 'int', 'min' => 1, 'max' => 12, 'default' => 4],
                'apiBaseUrl' => ['type' => 'string', 'maxLen' => 300, 'default' => '../../api/room_occupancy.php'],
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
