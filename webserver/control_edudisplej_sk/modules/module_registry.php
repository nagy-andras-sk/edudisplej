<?php
/**
 * Central module registry for admin tooling and future extensibility.
 *
 * Schema goals:
 * - every module has normalized metadata
 * - config path and default settings are discoverable
 * - shared renderers/folders are explicit
 */

function edudisplej_module_registry(): array
{
    return [
        'schema_version' => '1.0',
        'required_files' => [
            'module.json',
            'config/default_settings.json',
        ],
        'modules' => [
            'clock' => [
                'folder_key' => 'clock',
                'folder' => 'clock',
                'renderer' => 'modules/clock/m_clock.html',
                'config_dir' => 'modules/clock/config',
                'default_settings_file' => 'modules/clock/config/default_settings.json',
                'functions' => ['digital', 'analog', 'dateDisplay', 'language', 'colorTheme'],
                'settings_schema' => ['type', 'format', 'dateFormat', 'timeColor', 'dateColor', 'bgColor', 'fontSize', 'timeFontSize', 'dateFontSize', 'clockSize', 'showSeconds', 'showDate', 'language'],
            ],
            'default-logo' => [
                'folder_key' => 'default',
                'folder' => 'default',
                'renderer' => 'modules/default/m_default.html',
                'config_dir' => 'modules/default/config',
                'default_settings_file' => 'modules/default/config/default_settings.json',
                'functions' => ['textDisplay', 'versionBadge', 'colorTheme'],
                'settings_schema' => ['text', 'fontSize', 'textColor', 'bgColor', 'showVersion', 'version'],
            ],
            'text' => [
                'folder_key' => 'text',
                'folder' => 'text',
                'renderer' => 'modules/text/m_text.html',
                'config_dir' => 'modules/text/config',
                'default_settings_file' => 'modules/text/config/default_settings.json',
                'functions' => ['formattedText', 'scrollMode', 'backgroundImage'],
                'settings_schema' => ['textSourceType', 'textCollectionId', 'textCollectionLabel', 'textCollectionVersionTs', 'text', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'textAlign', 'textColor', 'bgColor', 'bgImageData', 'scrollMode', 'scrollStartPauseMs', 'scrollEndPauseMs', 'scrollSpeedPxPerSec'],
            ],
            'unconfigured' => [
                'folder_key' => 'default',
                'folder' => 'default',
                'renderer' => 'modules/default/m_default.html',
                'config_dir' => 'modules/default/config',
                'default_settings_file' => 'modules/default/config/default_settings.json',
                'functions' => ['fallback'],
                'settings_schema' => ['text', 'bgColor'],
            ],
            'pdf' => [
                'folder_key' => 'pdf',
                'folder' => 'pdf',
                'renderer' => 'modules/pdf/m_pdf.html',
                'config_dir' => 'modules/pdf/config',
                'default_settings_file' => 'modules/pdf/config/default_settings.json',
                'functions' => ['pdfViewer', 'zoom', 'autoScroll'],
                'settings_schema' => ['pdfDataBase64', 'pdfAssetUrl', 'pdfAssetId', 'zoomLevel', 'autoScrollEnabled', 'autoScrollSpeedPxPerSec', 'autoScrollStartPauseMs', 'autoScrollEndPauseMs', 'pauseAtPercent', 'pauseDurationMs'],
            ],
            'image-gallery' => [
                'folder_key' => 'gallery',
                'folder' => 'gallery',
                'renderer' => 'modules/gallery/m_gallery.html',
                'config_dir' => 'modules/gallery/config',
                'default_settings_file' => 'modules/gallery/config/default_settings.json',
                'functions' => ['slideshow', 'collage', 'fitMode', 'imageOptimization'],
                'settings_schema' => ['imageUrlsJson', 'displayMode', 'fitMode', 'slideIntervalSec', 'transitionEnabled', 'transitionMs', 'collageColumns', 'bgColor', 'clockOverlayEnabled', 'clockOverlayPosition', 'clockOverlayHeightPercent', 'clockOverlayTimeColor', 'clockOverlayDateColor', 'textOverlayEnabled', 'textOverlayPosition', 'textOverlayHeightPercent', 'textOverlaySourceType', 'textOverlayText', 'textOverlayCollectionJson', 'textOverlayExternalUrl', 'textOverlayFontSize', 'textOverlayColor', 'textOverlaySpeedPxPerSec'],
            ],
            'video' => [
                'folder_key' => 'video',
                'folder' => 'video',
                'renderer' => 'modules/video/m_video.html',
                'config_dir' => 'modules/video/config',
                'default_settings_file' => 'modules/video/config/default_settings.json',
                'functions' => ['mp4Playback', 'hardwareFriendly', 'autoDuration'],
                'settings_schema' => ['videoAssetUrl', 'videoAssetId', 'videoDurationSec', 'muted', 'fitMode', 'bgColor'],
            ],
            'meal-menu' => [
                'folder_key' => 'meal-menu',
                'folder' => 'meal-menu',
                'renderer' => 'modules/meal-menu/m_meal_menu.html',
                'config_dir' => 'modules/meal-menu/config',
                'default_settings_file' => 'modules/meal-menu/config/default_settings.json',
                'functions' => ['remoteMealFetch', 'offlineFallback', 'mealTypeFiltering'],
                'settings_schema' => ['companyId', 'siteKey', 'institutionId', 'language', 'showHeaderTitle', 'customHeaderTitle', 'showInstitutionName', 'showBreakfast', 'showSnackAm', 'showLunch', 'showSnackPm', 'showDinner', 'showMealTypeEmojis', 'showMealTypeSvgIcons', 'showAllergenEmojis', 'centerAlign', 'slowScrollOnOverflow', 'slowScrollSpeedPxPerSec', 'scrollStartDelayMs', 'scrollLoopPauseMs', 'layoutMode', 'showTomorrowInSquare', 'fontFamily', 'mealTitleFontSize', 'mealTextFontSize', 'textFontWeight', 'lineHeight', 'wrapText', 'showAppetiteMessage', 'appetiteMessageText', 'showSourceUrl', 'sourceUrl', 'clockOverlayEnabled', 'clockOverlayPosition', 'clockOverlayHeightPercent', 'clockOverlayTimeColor', 'clockOverlayDateColor', 'textOverlayEnabled', 'textOverlayPosition', 'textOverlayHeightPercent', 'textOverlaySourceType', 'textOverlayText', 'textOverlayCollectionJson', 'textOverlayExternalUrl', 'textOverlayFontSize', 'textOverlayColor', 'textOverlaySpeedPxPerSec', 'apiBaseUrl'],
            ],
            'room-occupancy' => [
                'folder_key' => 'room-occupancy',
                'folder' => 'room-occupancy',
                'renderer' => 'modules/room-occupancy/m_room_occupancy.html',
                'config_dir' => 'modules/room-occupancy/config',
                'default_settings_file' => 'modules/room-occupancy/config/default_settings.json',
                'functions' => ['roomScheduleFetch', 'manualAndExternalData', 'currentOccupancyState'],
                'settings_schema' => ['companyId', 'roomId', 'showOnlyCurrent', 'showNextCount', 'apiBaseUrl'],
            ],
        ],
    ];
}

function edudisplej_module_meta(string $moduleKey): ?array
{
    $registry = edudisplej_module_registry();
    return $registry['modules'][$moduleKey] ?? null;
}
