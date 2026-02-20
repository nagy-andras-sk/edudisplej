# EduDisplej – Architecture & API Reference

> **Version:** 2025 Q2 | **Status:** Active development

---

## Table of Contents

1. [Repository Structure](#1-repository-structure)
2. [Auth Model](#2-auth-model)
3. [API Specification](#3-api-specification)
4. [Request Signing (HMAC-SHA256)](#4-request-signing-hmac-sha256)
5. [Screenshot Policy (TTL-based)](#5-screenshot-policy-ttl-based)
6. [Threat Model & Security Notes](#6-threat-model--security-notes)
7. [Kiosk Service Architecture](#7-kiosk-service-architecture)
8. [UI Templating Rules](#8-ui-templating-rules)
9. [Database Schema Notes](#9-database-schema-notes)
10. [Migration Plan](#10-migration-plan)
11. [Manual Test Steps](#11-manual-test-steps)

---

## 1. Repository Structure

```
edudisplej/
├── docs/
│   └── ARCHITECTURE.md          ← this file
├── webserver/
│   ├── control_edudisplej_sk/   ← Control panel (PHP)
│   │   ├── api/                 ← Device & admin API endpoints
│   │   │   ├── auth.php         ← Shared auth middleware
│   │   │   ├── v1/device/
│   │   │   │   └── sync.php     ← ★ Unified device sync endpoint (NEW)
│   │   │   ├── hw_data_sync.php ← Legacy (deprecated)
│   │   │   ├── screenshot_sync.php ← Legacy (deprecated)
│   │   │   ├── log_sync.php     ← Legacy (deprecated)
│   │   │   ├── registration.php
│   │   │   ├── modules_sync.php
│   │   │   ├── get_module_file.php ← Now auth-protected
│   │   │   └── health/
│   │   │       ├── report.php   ← Kiosk health report
│   │   │       ├── status.php   ← Now auth-protected
│   │   │       └── list.php     ← Now auth-protected
│   │   ├── admin/               ← Admin panel pages + shared CSS/templates
│   │   │   ├── header.php       ← Shared HTML header/nav
│   │   │   ├── footer.php       ← Shared HTML footer
│   │   │   └── style.css        ← Single shared stylesheet (dashboard styles added)
│   │   ├── dashboard/           ← Company user dashboard
│   │   │   └── index.php        ← Inline CSS removed; uses admin/style.css
│   │   ├── api.php              ← Legacy router (deprecated, routes to v1)
│   │   └── dbkonfiguracia.php
│   ├── install/
│   │   └── init/                ← Kiosk systemd services + scripts
│   │       ├── edudisplej_sync_service.sh ← Main sync (now uses v1 endpoint)
│   │       ├── edudisplej-screenshot-service.sh
│   │       ├── edudisplej-health.sh
│   │       └── common.sh
│   └── www_edudisplej_sk/       ← Public landing page
```

---

## 2. Auth Model

| Endpoint | Auth | Notes |
|---|---|---|
| `api/hw_data_sync.php` | ✅ Bearer token | Deprecated → use v1/device/sync |
| `api/screenshot_sync.php` | ✅ Bearer token | Deprecated → use v1/device/sync |
| `api/log_sync.php` | ✅ Bearer token | Deprecated → use v1/device/sync |
| `api/v1/device/sync.php` | ✅ Bearer token | Unified endpoint |
| `api/get_module_file.php` | ✅ Bearer token | Fixed (was unauthenticated) |
| `api/health/report.php` | ✅ Bearer token | – |
| `api/health/status.php` | ✅ Bearer token | Fixed (was unauthenticated) |
| `api/health/list.php` | ✅ Bearer token | Fixed (was unauthenticated) |
| `api/check_versions.php` | ✅ Bearer token | – |
| `api/download_module.php` | ✅ Bearer token | – |
| `api/registration.php` | ✅ Bearer token | – |
| `api/geolocation.php` | ✅ Session | Fixed (was unauthenticated) |
| `api/screenshot_request.php` | ✅ Session or Bearer | New TTL endpoint |
| Dashboard pages | ✅ Session-based | – |
| Admin pages | ✅ Session + `isadmin` | – |

**Token sources:**
- `Authorization: Bearer <token>` header ✅ (preferred)
- `X-API-Token` header ✅
- `?token=` query parameter ⚠️ (deprecated with warning, will be removed)

---

## 3. New / Improved Architecture

### 3.1 Auth Policy

- **Device / kiosk endpoints:** `Authorization: Bearer <token>` header only (preferred).
  - `?token=` query parameter: still accepted for backward compatibility but emits
    `X-EDU-Deprecation-Warning` response header and a server-side error_log entry.
    Will be **removed** in a future version.
- **Admin / dashboard:** PHP session + `$_SESSION['isadmin']` check.
- **Optional request signing:** `X-EDU-Timestamp` + `X-EDU-Nonce` + `X-EDU-Signature`
  headers verified via HMAC-SHA256 (see Section 5).

### 3.2 Unified Device Sync Endpoint

`POST /api/v1/device/sync.php`

Replaces **three** separate round-trips:

| Old endpoint | New field in sync payload |
|---|---|
| `hw_data_sync.php` | Top-level `mac`, `hostname`, `hw_info`, `version`, … |
| `screenshot_sync.php` | `screenshot` + `screenshot_filename` |
| `log_sync.php` | `logs` array |

The response includes all configuration the kiosk needs:
- `sync_interval`
- `screenshot_requested` / `screenshot_enabled`
- `needs_update` + `update_action`
- `company_id` / `company_name`

### 3.3 Deprecation Headers

All legacy endpoints now emit:
```
X-EDU-Deprecated: true
X-EDU-Successor: /api/v1/device/sync.php
```

---

## 4. API Specification

### 4.1 `POST /api/v1/device/sync.php` ★

**Headers (required):**
```
Authorization: Bearer <company_api_token>
Content-Type: application/json
```

**Headers (optional – request signing):**
```
X-EDU-Timestamp: <unix_epoch>
X-EDU-Nonce: <random_alphanumeric_8..128_chars>
X-EDU-Signature: <hex_hmac_sha256>
```

**Request body:**
```json
{
  "mac": "aabbccddeeff",
  "hostname": "kiosk-01",
  "hw_info": { "cpu": "...", "memory": "...", "os": "..." },
  "version": "1.2.3",
  "screen_resolution": "1920x1080",
  "screen_status": "on",
  "last_update": "2025-01-15 10:00:00",

  // Optional: screenshot upload
  "screenshot": "data:image/png;base64,iVBOR...",
  "screenshot_filename": "scrn_edudisplaabbccddeeff_20250115100000.png",

  // Optional: log batch
  "logs": [
    {
      "type": "sync",
      "level": "info",
      "message": "Sync completed",
      "details": { "duration_ms": 120 }
    }
  ]
}
```

**Response (success):**
```json
{
  "success": true,
  "kiosk_id": 42,
  "device_id": "edu-abc123",
  "sync_interval": 300,
  "screenshot_requested": false,
  "screenshot_enabled": true,
  "company_id": 7,
  "company_name": "Példa Iskola",
  "needs_update": false,
  "api_version": "v1"
}
```

**Response (update needed):**
```json
{
  "success": true,
  "needs_update": true,
  "update_reason": "Server loop updated",
  "update_action": "restart",
  ...
}
```

**Error responses:**

| HTTP | Condition |
|---|---|
| 401 | Missing / invalid token |
| 401 | Invalid request signature |
| 401 | Nonce already used |
| 401 | Timestamp out of range (drift > 300 s) |
| 403 | Token belongs to a different company than the kiosk |
| 500 | Server / DB error |

---

### 4.2 `POST /api/registration.php`

Registers a new kiosk. Requires a valid company Bearer token.

**Request:**
```json
{
  "mac": "aabbccddeeff",
  "hostname": "kiosk-01",
  "device_id": "edu-abc123"
}
```

### 4.3 `POST /api/modules_sync.php`

Returns the current module loop for the kiosk. Authenticated.

### 4.4 `GET /api/get_module_file.php`

Returns module HTML / JSON file content. **Now requires auth** (Bearer token or session).

Query params: `module_key`, `file` (`live.html` | `configure.json`), `kiosk_id` (optional).

### 4.5 `POST /api/health/report.php`

Kiosk posts its system health data. Authenticated.

### 4.6 `GET /api/health/status.php`

Returns latest health record for a kiosk. **Now requires auth**.

Query param: `kiosk_id`.

### 4.7 `GET /api/health/list.php`

Lists health status for all kiosks of the authenticated company.
**Now requires auth.** Non-admin users can only see their own company's kiosks.

---

## 5. Request Signing (HMAC-SHA256)

### 5.1 Schema

```
canonical_string = METHOD + "\n"
                 + URI_PATH + "\n"
                 + TIMESTAMP + "\n"
                 + NONCE + "\n"
                 + hex(SHA256(request_body))

signature = hex(HMAC-SHA256(canonical_string, signing_secret))
```

`signing_secret` is stored in `companies.signing_secret` (server-side only).

### 5.2 Example (PHP)

```php
$timestamp = time();
$nonce     = bin2hex(random_bytes(16));
$body      = json_encode($payload);
$uri_path  = '/api/v1/device/sync.php';
$canonical = implode("\n", ['POST', $uri_path, $timestamp, $nonce, hash('sha256', $body)]);
$signature = hash_hmac('sha256', $canonical, $signing_secret);

// Headers to send:
// X-EDU-Timestamp: $timestamp
// X-EDU-Nonce: $nonce
// X-EDU-Signature: $signature
```

### 5.3 Example (Bash / curl)

```bash
TIMESTAMP=$(date +%s)
NONCE=$(cat /proc/sys/kernel/random/uuid | tr -d '-')
BODY='{"mac":"aabbccddeeff",...}'
URI='/api/v1/device/sync.php'
CANONICAL="POST\n${URI}\n${TIMESTAMP}\n${NONCE}\n$(echo -n "$BODY" | sha256sum | awk '{print $1}')"
SIGNATURE=$(echo -e "$CANONICAL" | openssl dgst -sha256 -hmac "$SIGNING_SECRET" | awk '{print $2}')

curl -X POST "https://control.edudisplej.sk${URI}" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-EDU-Timestamp: $TIMESTAMP" \
  -H "X-EDU-Nonce: $NONCE" \
  -H "X-EDU-Signature: $SIGNATURE" \
  -H "Content-Type: application/json" \
  -d "$BODY"
```

### 5.4 Replay Protection

- Server checks timestamp is within **±300 seconds** of server time.
- Server stores used nonces in `api_nonces` table with a 10-minute TTL.
- Nonce must be **alphanumeric + `_-`**, 8–128 characters.

### 5.5 Signing Secret Provisioning

`signing_secret` is stored only on the server in `companies.signing_secret`.
For kiosk-side signing, provision the secret via a secure out-of-band channel
(e.g., during on-site setup from the control panel).

> **Note:** If `signing_secret` is not configured for a company, the signature
> check is skipped (soft enforcement). Pass `$required = true` in
> `validate_request_signature()` to make it mandatory.

---

## 5. Screenshot Policy (TTL-based)

Screenshots are **only sent by the kiosk when someone is actively watching** – not continuously.

### 5.1 How it works

```
Control Panel                    Server DB               Kiosk
     │                               │                     │
     │  Open kiosk detail modal      │                     │
     │──POST /api/screenshot_request.php──────────────────►│
     │       { kiosk_id, ttl=60 }    │                     │
     │                     ┌─────────▼──────────┐          │
     │                     │ screenshot_         │          │
     │                     │ requested_until =   │          │
     │                     │ NOW() + 60s         │          │
     │                     └─────────────────────┘          │
     │                               │                     │
     │  (keepalive every 45 s)       │                     │
     │──POST screenshot_request ────►│                     │
     │                               │                     │
     │                               │  POST /api/v1/device/sync.php
     │                               │◄────────────────────│
     │                     ┌─────────▼──────────┐          │
     │                     │ screenshot_         │          │
     │                     │ requested = TRUE    │──────────►
     │                     │ (TTL not expired)   │ send screenshot
     │                     └─────────────────────┘          │
     │                               │                     │
     │  Close modal                  │                     │
     │──POST action=stop ───────────►│                     │
     │                               │                     │
     │  (or TTL expires after 60s)   │                     │
     │                               │  POST sync          │
     │                               │◄────────────────────│
     │                     screenshot_requested = FALSE ────►
     │                               │  kiosk stops sending│
```

### 5.2 Server-side API

#### `POST /api/screenshot_request.php`

Requires session auth (or Bearer token for same company).

**Start / extend TTL:**
```json
{ "kiosk_id": 42, "ttl_seconds": 60 }
```
Response: `{ "success": true, "screenshot_requested_until": "2025-01-01 12:01:00", "ttl_seconds": 60 }`

**Stop immediately:**
```json
{ "kiosk_id": 42, "action": "stop" }
```

#### `POST /api/v1/device/sync.php` – screenshot fields in response

```json
{
  "screenshot_requested": true,
  "screenshot_enabled": false,
  "screenshot_interval_seconds": 3
}
```

- `screenshot_requested`: `true` when `screenshot_requested_until > NOW()` (TTL active)
- `screenshot_enabled`: persistent per-kiosk toggle
- `screenshot_interval_seconds`: how often the kiosk should send screenshots (default 3 s)

### 5.3 Kiosk-side behaviour

`edudisplej-screenshot-service.sh` checks on every iteration:
1. Reads `screenshot_requested` from `/opt/edudisplej/last_sync_response.json` (written by the sync service after each successful v1 sync)
2. If `true` → captures and uploads screenshot, then sleeps `screenshot_interval_seconds`
3. If `false` and `screenshot_enabled` (config) is also false → skips, sleeps default interval

The sync service writes the full v1 sync response to `/opt/edudisplej/last_sync_response.json` after every successful call. This lets the screenshot service read the latest policy without an extra API call.

### 5.4 Dashboard integration

When `openKioskDetail(kioskId, hostname)` is called:
- `requestScreenshotTTL(kioskId)` is called immediately (TTL = 60 s)
- A keepalive runs every 45 s to extend TTL
- When the modal is closed (click-outside or X button), `stopScreenshotTTL(kioskId)` clears the TTL

---

## 6. Threat Model & Security Notes

| Threat | Mitigation |
|---|---|
| Unauthenticated data access | All endpoints require Bearer token or session |
| Token in URL (logging exposure) | Deprecated with warning; Bearer header preferred |
| Replay attacks | Timestamp drift check + nonce deduplication in DB |
| MITM / eavesdropping | TLS (HTTPS) mandatory at infrastructure level |
| Cross-company data access | `api_require_company_match()` on every kiosk lookup |
| Path traversal in module file delivery | Allowlist: only `live.html` / `configure.json` |
| SQL injection | All queries use `mysqli` prepared statements |
| Sensitive info in debug output | `DEBUG_MODE` constant in `registration.php` – **set to `false` in production** |

---

## 7. Kiosk Service Architecture

### 7.1 Systemd Services

| Service file | Purpose |
|---|---|
| `edudisplej-sync.service` | Runs `edudisplej_sync_service.sh` – main sync loop |
| `edudisplej-screenshot-service.service` | Runs `edudisplej-screenshot-service.sh` |
| `edudisplej-health.service` | Runs `edudisplej-health.sh` |
| `edudisplej-kiosk.service` | Starts the Chromium kiosk browser |
| `edudisplej-command-executor.service` | Executes remote commands from control panel |

### 7.2 Main Sync Loop (`edudisplej_sync_service.sh`)

1. Read Bearer token from `/opt/edudisplej/lic/token`
2. Collect HW info, screen resolution/status, version
3. `POST /api/v1/device/sync.php` (unified endpoint)
4. Parse response: update `sync_interval`, `screenshot_enabled`, `company_id`
5. If `needs_update == true`: trigger module download & browser restart
6. Sleep `sync_interval` seconds, repeat

### 7.3 Screenshot Service

- `edudisplej-screenshot-service.sh` runs independently, controlled by
  `screenshot_enabled` field in `/opt/edudisplej/data/config.json`.
- Screenshot interval: configurable via `SCREENSHOT_INTERVAL` env var (default 15 s).
- **Only sends screenshots when `screenshot_enabled == true`** (server can toggle via sync response).

### 7.4 Config File

`/opt/edudisplej/data/config.json` – central configuration:
```json
{
  "company_name": "",
  "company_id": null,
  "kiosk_id": null,
  "device_id": "",
  "token": "",
  "sync_interval": 300,
  "last_update": "",
  "last_sync": "",
  "screenshot_mode": "sync",
  "screenshot_enabled": false,
  "last_screenshot": "",
  "module_versions": {},
  "service_versions": {}
}
```

### 7.5 Token Storage

`/opt/edudisplej/lic/token` – plain text file containing the company Bearer token.
Permissions should be `640` (readable by the service user only).

---

## 8. UI Templating Rules

- **Shared layout:** All admin and dashboard pages must `include '../admin/header.php'`
  and `include '../admin/footer.php'` (or the equivalent relative path).
- **Single stylesheet:** `webserver/control_edudisplej_sk/admin/style.css`
  is the single source of truth for all styles.
  - Dashboard-specific component classes (`.minimal-table`, `.preview-card`, etc.)
    are defined in the `/* Dashboard */` section at the bottom of `style.css`.
- **No inline `<style>` blocks** in page files. Extract to `style.css`.
- **Inline `style="..."` attributes:** Allowed only for truly one-off layout nudges
  that cannot reasonably be a class; minimise their use.
- Language switcher and logout are rendered by `header.php`.

---

## 9. Database Schema Notes

### New table: `api_nonces`

Required for request signing replay protection. Create with:

```sql
CREATE TABLE IF NOT EXISTS api_nonces (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    nonce      VARCHAR(128) NOT NULL,
    company_id INT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nonce_company (nonce, company_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### New column: `companies.signing_secret`

Required for request signing:

```sql
ALTER TABLE companies
    ADD COLUMN signing_secret VARCHAR(256) NULL
    COMMENT 'HMAC-SHA256 signing secret for request signature validation';
```

> Both additions are optional and backward-compatible.
> Signing is only enforced when `signing_secret` is populated and
> `validate_request_signature()` is called with `$required = true`.

---

## 10. Migration Plan

### Phase 1 – Auth hardening (this PR)
- ✅ `get_module_file.php` requires auth
- ✅ `health/status.php` requires auth + company match
- ✅ `health/list.php` requires auth + company scoping
- ✅ `?token=` query parameter deprecated with warning header

### Phase 2 – Unified endpoint rollout
- ✅ `api/v1/device/sync.php` created
- ✅ `edudisplej_sync_service.sh` updated to use v1 endpoint
- ✅ Legacy endpoints emit `X-EDU-Deprecated` response headers
- [ ] Update `edudisplej-screenshot-service.sh` to pass screenshot in v1 sync payload
- [ ] Update `edudisplej-health.sh` to pass health data in v1 sync payload
- [ ] Remove legacy `hw_data_sync.php`, `screenshot_sync.php`, `log_sync.php` (future)

### Phase 3 – Request signing rollout
- ✅ `validate_request_signature()` function implemented in `auth.php`
- ✅ `api/v1/device/sync.php` calls it (soft, not required)
- [ ] Provision `signing_secret` per company in the admin panel
- [ ] Enable hard enforcement (`$required = true`) for production kiosks
- [ ] Create `api_nonces` table in production DB (see Section 9)

### Phase 4 – Query param removal
- After all kiosks have been verified to use Bearer header only
- Remove `$token_from_query` fallback from `auth.php`

---

## 11. Manual Test Steps

### 11.1 Verify auth on previously unprotected endpoints

```bash
# Should return 401 (no token)
curl -s https://control.edudisplej.sk/api/get_module_file.php?module_key=clock
curl -s https://control.edudisplej.sk/api/health/status.php?kiosk_id=1
curl -s https://control.edudisplej.sk/api/health/list.php

# Should return data (valid token)
TOKEN="your_company_api_token"
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://control.edudisplej.sk/api/health/list.php"
```

### 11.2 Test deprecation warning for query param token

```bash
curl -sv "https://control.edudisplej.sk/api/hw_data_sync.php?token=$TOKEN" \
  -X POST -H "Content-Type: application/json" -d '{"mac":"aabbccddeeff"}'
# Expect: X-EDU-Deprecation-Warning header in response
```

### 11.3 Test unified v1 sync endpoint

```bash
curl -s -X POST "https://control.edudisplej.sk/api/v1/device/sync.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "mac": "aabbccddeeff",
    "hostname": "test-kiosk",
    "hw_info": {"os": "Raspbian"},
    "version": "1.0.0"
  }'
# Expect: {"success":true, "api_version":"v1", ...}
```

### 11.4 Test request signing

```bash
TS=$(date +%s)
NONCE=$(openssl rand -hex 16)
BODY='{"mac":"aabbccddeeff","hostname":"test-kiosk"}'
CANONICAL="POST\n/api/v1/device/sync.php\n${TS}\n${NONCE}\n$(echo -n "$BODY" | sha256sum | awk '{print $1}')"
SIG=$(printf '%s' "$(printf "$CANONICAL")" | openssl dgst -sha256 -hmac "$SIGNING_SECRET" | awk '{print $2}')

curl -s -X POST "https://control.edudisplej.sk/api/v1/device/sync.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-EDU-Timestamp: $TS" \
  -H "X-EDU-Nonce: $NONCE" \
  -H "X-EDU-Signature: $SIG" \
  -H "Content-Type: application/json" \
  -d "$BODY"
```

### 11.5 Verify deprecated endpoints still work (backward compat)

```bash
curl -s -X POST "https://control.edudisplej.sk/api/hw_data_sync.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"mac":"aabbccddeeff","hostname":"test-kiosk"}'
# Expect: X-EDU-Deprecated: true header, success response
```
