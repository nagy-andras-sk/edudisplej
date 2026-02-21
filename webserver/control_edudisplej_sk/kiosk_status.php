<?php

declare(strict_types=1);

const KIOSK_OFFLINE_TIMEOUT_SECONDS = 1800;

function kiosk_is_timed_out(array $kiosk, int $timeoutSeconds = KIOSK_OFFLINE_TIMEOUT_SECONDS): bool
{
    $referenceTime = $kiosk['last_sync'] ?? null;
    if (!$referenceTime) {
        $referenceTime = $kiosk['last_seen'] ?? null;
    }

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

    if (kiosk_is_timed_out($kiosk, $timeoutSeconds)) {
        return 'offline';
    }

    return $status;
}

function kiosk_apply_effective_status(array &$kiosk, int $timeoutSeconds = KIOSK_OFFLINE_TIMEOUT_SECONDS): void
{
    $kiosk['status'] = kiosk_effective_status($kiosk, $timeoutSeconds);
}
