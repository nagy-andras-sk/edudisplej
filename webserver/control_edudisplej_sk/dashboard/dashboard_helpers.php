<?php
/**
 * Dashboard Helper Functions
 * Extracted from index.php for better organization
 */

/**
 * Normalize screenshot URL
 */
function normalize_screenshot_url($raw_url) {
    if ($raw_url === null) {
        return null;
    }

    $path = trim((string)$raw_url);
    if ($path === '') {
        return null;
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('/^[^A-Za-z0-9\/._-]+/u', '', $path);
    if ($path === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    if (strpos($path, 'screenshots/') === 0) {
        return '../' . $path;
    }

    if (preg_match('#(^|/)screenshots/([^/]+)$#i', $path, $matches)) {
        return '../screenshots/' . $matches[2];
    }

    if (preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g|webp|gif)$/i', $path)) {
        return '../screenshots/' . $path;
    }

    return '../' . ltrim($path, '/');
}

/**
 * Check if a time is in a given range
 */
function edudisplej_time_in_range($now, $start, $end) {
    if ($start <= $end) {
        return $now >= $start && $now < $end;
    }
    return $now >= $start || $now < $end;
}

/**
 * Get localized text for dashboard helpers
 */
function edudisplej_dashboard_t(string $key, string $default, array $vars = []): string {
    if (function_exists('t_def')) {
        return t_def($key, $default, $vars);
    }

    $translated = $default;
    foreach ($vars as $name => $value) {
        if ($name === '' || $name === null) {
            continue;
        }
        $translated = str_replace('{' . $name . '}', (string)$value, $translated);
    }
    return $translated;
}

/**
 * Get localized short day name
 */
function edudisplej_day_short(int $day): string {
    $lang = function_exists('edudisplej_get_lang') ? (string)edudisplej_get_lang() : 'sk';
    $maps = [
        'hu' => [1 => 'H', 2 => 'K', 3 => 'Sze', 4 => 'Cs', 5 => 'P', 6 => 'Szo', 7 => 'V'],
        'en' => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'],
        'sk' => [1 => 'Po', 2 => 'Ut', 3 => 'St', 4 => 'Št', 5 => 'Pi', 6 => 'So', 7 => 'Ne'],
    ];

    $map = $maps[$lang] ?? $maps['sk'];
    return $map[$day] ?? '?';
}

/**
 * Build style map from loop styles
 */
function edudisplej_build_style_map(array $styles): array {
    $style_map = [];
    foreach ($styles as $style) {
        if (!is_array($style)) {
            continue;
        }
        $sid = (int)($style['id'] ?? 0);
        if ($sid === 0) {
            continue;
        }
        $style_map[$sid] = trim((string)($style['name'] ?? '')) ?: ('Loop #' . $sid);
    }
    return $style_map;
}

/**
 * Filter schedule blocks that match current time
 */
function edudisplej_filter_matching_blocks(array $schedule_blocks, DateTimeImmutable $now): array {
    $now_date = $now->format('Y-m-d');
    $now_time = $now->format('H:i:s');
    $weekday = (int)$now->format('N');
    $matches = [];

    foreach ($schedule_blocks as $block) {
        if (!is_array($block)) {
            continue;
        }

        if ((int)($block['is_active'] ?? 1) === 0) {
            continue;
        }

        $type = strtolower(trim((string)($block['block_type'] ?? 'weekly')));

        if ($type === 'datetime_range') {
            $start_dt = strtotime(str_replace('T', ' ', (string)($block['start_datetime'] ?? '')));
            $end_dt = strtotime(str_replace('T', ' ', (string)($block['end_datetime'] ?? '')));
            $now_ts = $now->getTimestamp();
            if ($start_dt === false || $end_dt === false) {
                continue;
            }
            if (!($now_ts >= $start_dt && $now_ts < $end_dt)) {
                continue;
            }
            $matches[] = $block;
            continue;
        }

        $start = (string)($block['start_time'] ?? '00:00:00');
        $end = (string)($block['end_time'] ?? '00:00:00');
        
        // Normalize time format
        if (strlen($start) === 5) {
            $start .= ':00';
        }
        if (strlen($end) === 5) {
            $end .= ':00';
        }

        // Check date/day match
        if ($type === 'date') {
            $specific_date = (string)($block['specific_date'] ?? '');
            if ($specific_date !== $now_date) {
                continue;
            }
        } else {
            $days = array_filter(array_map('intval', explode(',', (string)($block['days_mask'] ?? ''))));
            if (!in_array($weekday, $days, true)) {
                continue;
            }
        }

        // Check time range
        if (!edudisplej_time_in_range($now_time, $start, $end)) {
            continue;
        }

        $matches[] = $block;
    }

    return $matches;
}

/**
 * Sort schedule blocks by priority
 */
function edudisplej_sort_schedule_matches(array &$matches): void {
    usort($matches, static function ($a, $b) {
        $type_weight = static function (string $type): int {
            if ($type === 'datetime_range') {
                return 3;
            }
            if ($type === 'date') {
                return 2;
            }
            return 1;
        };

        $ta = $type_weight(strtolower((string)($a['block_type'] ?? 'weekly')));
        $tb = $type_weight(strtolower((string)($b['block_type'] ?? 'weekly')));
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }
        $pa = (int)($a['priority'] ?? 0);
        $pb = (int)($b['priority'] ?? 0);
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });
}

/**
 * Format schedule block information
 */
function edudisplej_format_schedule_info(array $block, array $style_map, DateTimeImmutable $now, int $default_style_id): array {
    $style_id = (int)($block['loop_style_id'] ?? $default_style_id);
    $loop_name = trim((string)($style_map[$style_id] ?? '')) ?: 'DEFAULT';

    if (strtolower((string)($block['block_type'] ?? 'weekly')) === 'datetime_range') {
        $start_dt = str_replace('T', ' ', (string)($block['start_datetime'] ?? ''));
        $end_dt = str_replace('T', ' ', (string)($block['end_datetime'] ?? ''));
        return [
            'loop_name' => $loop_name,
            'schedule_text' => edudisplej_dashboard_t(
                'dashboard.schedule.datetime_range',
                'Special interval {start} → {end}',
                ['start' => $start_dt !== '' ? $start_dt : '—', 'end' => $end_dt !== '' ? $end_dt : '—']
            ),
        ];
    }

    $start = substr((string)($block['start_time'] ?? '00:00:00'), 0, 5);
    $end = substr((string)($block['end_time'] ?? '00:00:00'), 0, 5);
    $until = edudisplej_dashboard_t('dashboard.schedule.until', 'Until: {end}', ['end' => $end]);

    if (strtolower((string)($block['block_type'] ?? 'weekly')) === 'date') {
        $date = (string)($block['specific_date'] ?? $now->format('Y-m-d'));
        return [
            'loop_name' => $loop_name,
            'schedule_text' => edudisplej_dashboard_t(
                'dashboard.schedule.date',
                'Special {date} {start}-{end} • {until}',
                ['date' => $date, 'start' => $start, 'end' => $end, 'until' => $until]
            ),
        ];
    }

    $days = array_filter(array_map('intval', explode(',', (string)($block['days_mask'] ?? ''))));
    $days_label = implode(',', array_map('edudisplej_day_short', $days));
    return [
        'loop_name' => $loop_name,
        'schedule_text' => edudisplej_dashboard_t(
            'dashboard.schedule.weekly',
            'Plan {days} {start}-{end} • {until}',
            ['days' => $days_label, 'start' => $start, 'end' => $end, 'until' => $until]
        ),
    ];
}

/**
 * Resolve current content for a group (main function)
 */
function edudisplej_resolve_group_current_content($plan, $now) {
    if (!is_array($plan)) {
        return [
            'loop_name' => 'DEFAULT',
            'schedule_text' => edudisplej_dashboard_t('dashboard.schedule.none', 'No plan'),
        ];
    }

    $styles = is_array($plan['loop_styles'] ?? null) ? $plan['loop_styles'] : [];
    $style_map = edudisplej_build_style_map($styles);

    $default_style_id = (int)($plan['default_loop_style_id'] ?? 0);
    $schedule_blocks = is_array($plan['schedule_blocks'] ?? null)
        ? $plan['schedule_blocks']
        : (is_array($plan['time_blocks'] ?? null) ? $plan['time_blocks'] : []);

    $matches = edudisplej_filter_matching_blocks($schedule_blocks, $now);

    if (!empty($matches)) {
        edudisplej_sort_schedule_matches($matches);
        return edudisplej_format_schedule_info($matches[0], $style_map, $now, $default_style_id);
    }

    $default_name = trim((string)($style_map[$default_style_id] ?? ''));
    $normalized_default = strtolower($default_name);
    if (
        $default_name === ''
        || in_array($normalized_default, ['default', 'alap loop', 'default loop', 'predvolený loop', 'predvoleny loop'], true)
    ) {
        $default_name = edudisplej_dashboard_t('dashboard.loop.default_name', 'Default loop');
    }
    return [
        'loop_name' => $default_name,
        'schedule_text' => edudisplej_dashboard_t('dashboard.schedule.no_active', 'No active time block'),
    ];
}

/**
 * Resolve next content for a group (after current time)
 */
function edudisplej_resolve_group_next_content($plan, $now) {
    if (!is_array($plan)) {
        return null;
    }

    $styles = is_array($plan['loop_styles'] ?? null) ? $plan['loop_styles'] : [];
    $style_map = edudisplej_build_style_map($styles);

    $default_style_id = (int)($plan['default_loop_style_id'] ?? 0);
    $schedule_blocks = is_array($plan['schedule_blocks'] ?? null)
        ? $plan['schedule_blocks']
        : (is_array($plan['time_blocks'] ?? null) ? $plan['time_blocks'] : []);

    // Find next blocks that start after current time
    $now_ts = $now->getTimestamp();
    $now_date = $now->format('Y-m-d');
    $now_time = $now->format('H:i:s');
    $weekday = (int)$now->format('N');
    
    $next_candidates = [];

    foreach ($schedule_blocks as $block) {
        if (!is_array($block)) {
            continue;
        }

        if ((int)($block['is_active'] ?? 1) === 0) {
            continue;
        }

        $type = strtolower(trim((string)($block['block_type'] ?? 'weekly')));
        $start_ts = null;

        if ($type === 'datetime_range') {
            $start_dt = strtotime(str_replace('T', ' ', (string)($block['start_datetime'] ?? '')));
            if ($start_dt === false) {
                continue;
            }
            // Only consider datetime_range blocks that start after now
            if ($start_dt <= $now_ts) {
                continue;
            }
            $start_ts = $start_dt;
            $next_candidates[] = ['block' => $block, 'start_ts' => $start_ts, 'type' => $type];
            continue;
        }

        $start = (string)($block['start_time'] ?? '00:00:00');
        $end = (string)($block['end_time'] ?? '00:00:00');
        
        // Normalize time format
        if (strlen($start) === 5) {
            $start .= ':00';
        }
        if (strlen($end) === 5) {
            $end .= ':00';
        }

        $check_date = $now_date;
        $check_weekday = $weekday;

        // Check date/day match
        if ($type === 'date') {
            $specific_date = (string)($block['specific_date'] ?? '');
            // For date blocks, check if block is today and time is after now, or if block is in future
            if ($specific_date === $now_date) {
                // Today - check if start time is after now
                if ($start <= $now_time) {
                    continue;
                }
            } elseif ($specific_date < $now_date) {
                // Past date
                continue;
            }
            $start_ts = strtotime($specific_date . ' ' . $start);
        } else {
            // Weekly blocks - find next occurrence
            // Check if current weekday matches and time is after now
            $days = array_filter(array_map('intval', explode(',', (string)($block['days_mask'] ?? ''))));
            
            if (in_array($weekday, $days, true) && $start > $now_time) {
                // Today's block is still in future
                $start_ts = strtotime('today ' . $start);
            } else {
                // Find next matching weekday
                for ($i = 1; $i <= 7; $i++) {
                    $future_date = date('Y-m-d', strtotime("+$i days", $now_ts));
                    $future_weekday = (int)date('N', strtotime($future_date));
                    
                    if (in_array($future_weekday, $days, true)) {
                        $start_ts = strtotime($future_date . ' ' . $start);
                        break;
                    }
                }
            }
        }

        if ($start_ts !== null) {
            $next_candidates[] = ['block' => $block, 'start_ts' => $start_ts, 'type' => $type];
        }
    }

    // Sort candidates by start time (earliest first)
    if (!empty($next_candidates)) {
        usort($next_candidates, static function ($a, $b) {
            if ($a['start_ts'] !== $b['start_ts']) {
                return $a['start_ts'] <=> $b['start_ts'];
            }
            // If same time, prioritize by type and priority
            $ta = $a['type'] === 'datetime_range' ? 3 : ($a['type'] === 'date' ? 2 : 1);
            $tb = $b['type'] === 'datetime_range' ? 3 : ($b['type'] === 'date' ? 2 : 1);
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }
            $pa = (int)($a['block']['priority'] ?? 0);
            $pb = (int)($b['block']['priority'] ?? 0);
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            return ((int)($a['block']['id'] ?? 0)) <=> ((int)($b['block']['id'] ?? 0));
        });

        $next_block = $next_candidates[0]['block'];
        $next_start_ts = $next_candidates[0]['start_ts'];
        
        // Create a DateTimeImmutable from the next start timestamp
        $next_start_dt = (new DateTimeImmutable())->setTimestamp($next_start_ts);
        return edudisplej_format_schedule_info($next_block, $style_map, $next_start_dt, $default_style_id);
    }

    return null;
}
