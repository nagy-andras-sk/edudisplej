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
                'folder_key' => 'datetime',
                'folder' => 'datetime',
                'renderer' => 'modules/datetime/m_datetime.html',
                'config_dir' => 'modules/datetime/config',
                'default_settings_file' => 'modules/datetime/config/default_settings.json',
                'functions' => ['digital', 'analog', 'dateDisplay', 'language', 'colorTheme'],
                'settings_schema' => ['type', 'format', 'dateFormat', 'timeColor', 'dateColor', 'bgColor', 'fontSize', 'clockSize', 'showSeconds', 'language'],
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
                'settings_schema' => ['text', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'textAlign', 'textColor', 'bgColor', 'bgImageData', 'scrollMode', 'scrollStartPauseMs', 'scrollEndPauseMs', 'scrollSpeedPxPerSec'],
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
                'functions' => ['pdfViewer', 'navigation', 'zoom', 'autoScroll'],
                'settings_schema' => ['pdfDataBase64', 'orientation', 'zoomLevel', 'navigationMode', 'displayMode', 'autoScrollSpeedPxPerSec', 'autoScrollStartPauseMs', 'autoScrollEndPauseMs', 'pausePoints', 'fixedViewMode', 'fixedPage', 'bgColor', 'showPageNumbers'],
            ],
        ],
    ];
}

function edudisplej_module_meta(string $moduleKey): ?array
{
    $registry = edudisplej_module_registry();
    return $registry['modules'][$moduleKey] ?? null;
}
