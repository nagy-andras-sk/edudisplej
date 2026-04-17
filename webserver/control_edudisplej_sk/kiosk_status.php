<?php

declare(strict_types=1);

const KIOSK_OFFLINE_TIMEOUT_SECONDS = 1800;
const KIOSK_UPGRADE_ERROR_TIMEOUT_SECONDS = 1800;

function kiosk_status_reference_time(array $kiosk): ?string
{
    $candidates = [
        $kiosk['last_sync'] ?? null,
        $kiosk['last_seen'] ?? null,
        $kiosk['last_heartbeat'] ?? null,
        $kiosk['heartbeat_at'] ?? null,
        $kiosk['health_timestamp'] ?? null,
        $kiosk['screenshot_timestamp'] ?? null,
        $kiosk['timestamp'] ?? null,
    ];

    $latestRaw = null;
    $latestTs = null;

    foreach ($candidates as $candidate) {
        if ($candidate === null || $candidate === '') {
            continue;
        }

        $candidateRaw = (string)$candidate;
        $candidateTs = strtotime($candidateRaw);
        if ($candidateTs === false) {
            continue;
        }

        if ($latestTs === null || $candidateTs > $latestTs) {
            $latestTs = $candidateTs;
            $latestRaw = $candidateRaw;
        }
    }

    return $latestRaw;
}

function kiosk_upgrade_timed_out(array $kiosk, int $timeoutSeconds = KIOSK_UPGRADE_ERROR_TIMEOUT_SECONDS): bool
{
    $upgradeStartedAt = $kiosk['upgrade_started_at'] ?? null;
    if (!$upgradeStartedAt) {
        return false;
    }

    $upgradeTimestamp = strtotime((string)$upgradeStartedAt);
    if ($upgradeTimestamp === false) {
        return false;
    }

    return (time() - $upgradeTimestamp) > $timeoutSeconds;
}

function kiosk_is_timed_out(array $kiosk, int $timeoutSeconds = KIOSK_OFFLINE_TIMEOUT_SECONDS): bool
{
    $referenceTime = kiosk_status_reference_time($kiosk);

    if (!$referenceTime) {
        return false;
    }

    $referenceTimestamp = strtotime((string)$referenceTime);
    if ($referenceTimestamp === false) {
        return false;
    }

    return (time() - $referenceTimestamp) > $timeoutSeconds;
}

function kiosk_effective_status(array $kiosk, int $timeoutSeconds = KIOSK_OFFLINE_TIMEOUT_SECONDS): string
{
    $status = (string)($kiosk['status'] ?? 'offline');

    if ($status === 'upgrading') {
        if (kiosk_upgrade_timed_out($kiosk)) {
            return 'error';
        }
        return 'upgrading';
    }

    if ($status === 'error') {
        return 'error';
    }

    $isTimedOut = kiosk_is_timed_out($kiosk, $timeoutSeconds);
    if ($isTimedOut) {
        return 'offline';
    }

    // If database status is stale offline but we have fresh activity evidence,
    // prefer reporting online to avoid false offline state in dashboard/UI.
    if ($status === 'offline' && kiosk_status_reference_time($kiosk) !== null) {
        return 'online';
    }

    return $status;
}

function kiosk_apply_effective_status(array &$kiosk, int $timeoutSeconds = KIOSK_OFFLINE_TIMEOUT_SECONDS): void
{
    $kiosk['status'] = kiosk_effective_status($kiosk, $timeoutSeconds);
}
