# API Security and Optimization Documentation

## Overview
This document describes the security enhancements and optimizations made to the EduDisplej API system.

## 1. Authentication & Authorization

### License Key Authentication
All API endpoints now require a valid license key from the company:

- **License Key**: Stored in `companies.license_key` field
- **API Token**: Generated token stored in `companies.api_token` field
- **Active Status**: Company must have `is_active = 1`

### Authentication Flow
1. Client sends request with `Authorization: Bearer <token>` header or `?token=<token>` query parameter
2. Server validates token against `companies.api_token`
3. Server checks company `is_active` status
4. Server verifies `license_key` exists
5. Request is processed if all checks pass

### Device Authentication
Devices can authenticate using their MAC address:
- MAC address is looked up in `kiosks` table
- Associated company's API token is retrieved
- Same validation process as above

## 2. Two-Factor Authentication (OTP/2FA)

### Setup Process
1. User requests OTP setup via `/api/otp_setup.php?action=generate`
2. Server generates a random 32-character Base32 secret
3. Server returns QR code data for scanning with authenticator app
4. User scans QR code and enters verification code
5. Server verifies code via `/api/otp_setup.php?action=verify&code=123456`
6. OTP is enabled for user account

### Login Process with OTP
1. User enters username and password
2. If OTP is enabled, server prompts for 6-digit code
3. Server verifies code using TOTP algorithm (RFC 6238)
4. Accepts codes from ±1 time window (30 seconds) for clock drift
5. Login completes if code is valid

### OTP Implementation
- **Algorithm**: Time-based One-Time Password (TOTP)
- **Time Step**: 30 seconds
- **Code Length**: 6 digits
- **Hash Function**: HMAC-SHA1
- **Window**: ±1 step (allows 30s clock drift)

## 3. Sync Logic Optimization

### Timestamp-Based Sync
The sync system now uses timestamps to minimize unnecessary data transfers:

```php
// Client sends last known update timestamp
{
  "mac": "AA:BB:CC:DD:EE:FF",
  "last_loop_update": "2024-01-15 10:30:00"
}

// Server compares with latest update
// Only sends data if server timestamp is newer
{
  "success": true,
  "needs_update": false,
  "server_timestamp": "2024-01-15 10:30:00",
  "modules": []  // Empty if no update needed
}
```

### Optimization Benefits
- **Reduced Bandwidth**: Only changed data is transmitted
- **Lower Server Load**: Database queries skip when no updates
- **Faster Response**: Empty response for unchanged data
- **Better Scalability**: Supports more concurrent devices

### Implementation Details
1. `kiosk_group_modules.updated_at` tracks last modification
2. Client sends `last_loop_update` timestamp
3. Server queries `MAX(updated_at)` for group modules
4. Server compares timestamps
5. If server timestamp ≤ client timestamp: no update needed
6. Returns `needs_update: false` and empty `modules` array

## 4. Data Encryption

### Session Security
- `session.cookie_httponly = 1`: Prevents JavaScript access
- `session.cookie_secure = 1`: HTTPS only (production)
- `session.use_only_cookies = 1`: No URL session IDs
- `session.cookie_samesite = Strict`: CSRF protection

### Sensitive Data Encryption
Use `security_config.php` functions:

```php
require_once 'security_config.php';

// Encrypt sensitive data
$encrypted = encrypt_data($sensitive_string);

// Decrypt when needed
$decrypted = decrypt_data($encrypted);
```

### Password Hashing
- Uses PHP's `password_hash()` with bcrypt
- Automatic salt generation
- Configurable cost factor

## 5. Security Headers

Automatically applied to all responses:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Strict-Transport-Security: max-age=31536000` (HTTPS only)

## 6. Rate Limiting

Basic rate limiting implemented in `security_config.php`:
- Default: 100 requests per 60 seconds
- Per-identifier (IP, user ID, MAC address)
- Can be customized per endpoint

Usage:
```php
if (!check_rate_limit($_SERVER['REMOTE_ADDR'], 60, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests']);
    exit;
}
```

## 7. Security Best Practices

### Input Validation
- Always validate and sanitize user input
- Use prepared statements for SQL queries
- Validate data types and ranges
- Use `sanitize_input()` for display

### Output Encoding
- Use `htmlspecialchars()` for HTML output
- Use `json_encode()` for JSON output
- Escape data based on context

### Error Handling
- Log errors securely (no sensitive data)
- Show generic errors to users
- Use `log_security_event()` for security events
- Monitor logs regularly

### API Token Management
- Generate tokens with `generate_secure_token()`
- Store hashed tokens in database
- Rotate tokens regularly
- Revoke compromised tokens immediately

## 8. Database Schema Updates

### Companies Table
```sql
ALTER TABLE companies ADD COLUMN license_key VARCHAR(255) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN api_token VARCHAR(255) DEFAULT NULL;
ALTER TABLE companies ADD COLUMN token_created_at TIMESTAMP NULL;
ALTER TABLE companies ADD COLUMN is_active TINYINT(1) DEFAULT 1;
ALTER TABLE companies ADD UNIQUE KEY (license_key);
ALTER TABLE companies ADD UNIQUE KEY (api_token);
```

### Users Table
```sql
ALTER TABLE users ADD COLUMN otp_enabled TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN otp_secret VARCHAR(255) DEFAULT NULL;
ALTER TABLE users ADD COLUMN otp_verified TINYINT(1) DEFAULT 0;
```

### Kiosks Table
```sql
ALTER TABLE kiosks ADD COLUMN version VARCHAR(50) DEFAULT NULL;
ALTER TABLE kiosks ADD COLUMN screen_resolution VARCHAR(50) DEFAULT NULL;
ALTER TABLE kiosks ADD COLUMN screen_status VARCHAR(20) DEFAULT NULL;
ALTER TABLE kiosks ADD COLUMN loop_last_update DATETIME DEFAULT NULL;
ALTER TABLE kiosks ADD COLUMN last_sync DATETIME DEFAULT NULL;
```

### Kiosk Group Modules Table
```sql
ALTER TABLE kiosk_group_modules 
  ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP 
  ON UPDATE CURRENT_TIMESTAMP;
```

## 9. Migration Instructions

### Running Database Updates
```bash
# Navigate to admin panel
https://control.edudisplej.sk/dbjavito.php

# Or run directly
php webserver/control_edudisplej_sk/dbjavito.php
```

### Generating License Keys
```php
// Generate a new license key for a company
$license_key = generate_secure_token(16);

// Store in database
UPDATE companies SET license_key = '$license_key' WHERE id = 1;
```

### Enabling 2FA for User
1. User logs in to dashboard
2. Navigate to profile/security settings
3. Click "Enable Two-Factor Authentication"
4. Scan QR code with authenticator app
5. Enter verification code
6. 2FA is now enabled

## 10. Testing

### Test API Authentication
```bash
# Without token (should fail)
curl https://control.edudisplej.sk/api/modules_sync.php \
  -H "Content-Type: application/json" \
  -d '{"mac":"AA:BB:CC:DD:EE:FF"}'

# With token (should succeed)
curl https://control.edudisplej.sk/api/modules_sync.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -d '{"mac":"AA:BB:CC:DD:EE:FF"}'
```

### Test Sync Optimization
```bash
# First sync (should return modules)
curl -X POST https://control.edudisplej.sk/api/modules_sync.php \
  -H "Authorization: Bearer TOKEN" \
  -d '{"mac":"AA:BB:CC:DD:EE:FF"}'

# Second sync with timestamp (should return empty if no changes)
curl -X POST https://control.edudisplej.sk/api/modules_sync.php \
  -H "Authorization: Bearer TOKEN" \
  -d '{"mac":"AA:BB:CC:DD:EE:FF","last_loop_update":"2024-01-15 10:30:00"}'
```

## 11. Troubleshooting

### Authentication Failures
- Verify API token exists in database
- Check company `is_active` status
- Verify license key is set
- Check token format (Bearer prefix)

### OTP Issues
- Verify time synchronization on server and device
- Check secret is stored correctly
- Verify authenticator app is time-based (not counter-based)
- Try codes from multiple time windows

### Sync Issues
- Check kiosk `is_configured` status
- Verify group assignment
- Check module configuration
- Review sync logs in database

## 12. Security Checklist

- [ ] All API endpoints require authentication
- [ ] License keys generated for all companies
- [ ] API tokens rotated regularly
- [ ] OTP enabled for admin accounts
- [ ] HTTPS enforced in production
- [ ] Security headers configured
- [ ] Rate limiting active
- [ ] Error logging configured
- [ ] Database credentials secured
- [ ] Encryption key configured
- [ ] Session security enabled
- [ ] Input validation implemented
- [ ] SQL injection prevention (prepared statements)
- [ ] XSS prevention (output encoding)
- [ ] CSRF tokens implemented (where needed)

## Support

For security issues, contact: security@edudisplej.sk
For general support: support@edudisplej.sk
