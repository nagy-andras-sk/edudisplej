# Core Update And Versioning

This document describes how EduDisplej core updates are detected, triggered, and verified, and where version data is stored.

## 1. Version Sources

### 1.1 Server-side target version
- File: webserver/install/init/versions.json
- Main key: system_version
- Service keys: services.*
- Example:
```json
{
  "system_version": "1.1.0",
  "services": {
    "edudisplej_sync_service.sh": "1.1.0"
  }
}
```

### 1.2 Device-side current version
- File on kiosk: /opt/edudisplej/VERSION
- Value is sent by kiosk and compared with server target version.

## 2. API Fields Used By Kiosk

### 2.1 Unified sync API
- Endpoint: /api/v1/device/sync.php
- Relevant response fields:
  - current_system_version
  - latest_system_version
  - core_update_required
  - core_update.required
  - core_update.target_version

### 2.2 Version fallback endpoint
- Endpoint: /api/check_versions.php
- Fallback latest version if reading versions.json fails is aligned with current release.

## 3. Core Update Trigger Flow On Device

### 3.1 Main service
- File: /opt/edudisplej/init/edudisplej_sync_service.sh
- Reads sync response and sets:
  - CORE_UPDATE_REQUIRED
  - CORE_UPDATE_TARGET_VERSION
  - CORE_UPDATE_CURRENT_VERSION

### 3.2 Trigger function
- Function: trigger_core_update_if_needed
- Executes when CORE_UPDATE_REQUIRED=true
- Runs:
```bash
/opt/edudisplej/init/update.sh --core-only --source=auto-sync --target-version=<latest>
```

### 3.3 Safety controls
- Lock directory: /tmp/edudisplej_core_update.lock
- Last attempt marker: /tmp/edudisplej_core_update.last_attempt
- Cooldown: EDUDISPLEJ_CORE_UPDATE_RETRY_COOLDOWN (default 1800 seconds)

## 4. Core Update Script

### 4.1 Script and args
- Script: /opt/edudisplej/init/update.sh
- Important args:
  - --core-only
  - --source=<source>
  - --target-version=<version>

### 4.2 Progress reporting
- Endpoint: /api/install/progress.php
- Kiosk can send progress phase, state, percent, message.

### 4.3 Success finalization
- On successful completion, target version is written to:
  - /opt/edudisplej/VERSION

## 5. Runtime Verification Checklist

Run on kiosk:
```bash
cat /opt/edudisplej/VERSION
cat /opt/edudisplej/last_sync_response.json | jq '{core_update_required, latest_system_version, current_system_version, needs_update}'
systemctl is-active edudisplej-sync.service
```

Expected healthy state after rollout:
- VERSION equals server versions.json system_version
- core_update_required=false
- latest_system_version == current_system_version
- sync service is active

## 6. Common Failure Mode: Broken Structure Response

If update.sh logs JSON parse errors while reading structure payload, core update may fail repeatedly.

Symptoms:
- core_update_required remains true
- /opt/edudisplej/logs/core_update.log contains jq parse errors

Mitigation:
- Fix install endpoint payload integrity (structure response must be valid JSON)
- Keep cooldown enabled to avoid rapid retry loops
- As emergency workaround, deploy required init files and VERSION marker manually, then restart services

## 7. Related Files

- webserver/install/init/versions.json
- webserver/control_edudisplej_sk/api/v1/device/sync.php
- webserver/control_edudisplej_sk/api/check_versions.php
- webserver/install/init/edudisplej_sync_service.sh
- webserver/install/init/update.sh
- webserver/control_edudisplej_sk/api/install/progress.php
- webserver/control_edudisplej_sk/admin/kiosk_details.php
