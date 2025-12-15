# TR2 File Server API

This directory contains the PHP API for the TR2 File Server component of the EduDisplej system.

## Problem Fixed

This API structure fixes the PHP fatal error:
```
Fatal error: Cannot redeclare loadEnv() (previously declared in /var/www/api/heartbeat.php:12) 
in /var/www/api/qbit-password-manager.php on line 10
```

### Solution

The `loadEnv()` function is now defined only once in `config.php`, which is included by all other PHP files. This prevents function redeclaration errors.

## File Structure

```
api/
├── config.php                      # Shared configuration and loadEnv() function
├── heartbeat.php                   # Heartbeat service (file server -> main server)
├── file_server_heartbeat.php      # Heartbeat receiver (main server)
├── diagnostics.php                 # System diagnostics
├── qbit-password-manager.php      # qBittorrent credentials management
├── index.php                       # API status and information
├── .env.example                    # Environment configuration template
└── README.md                       # This file
```

## Configuration

1. Copy `.env.example` to `/var/www/.env`:
   ```bash
   cp api/.env.example /var/www/.env
   ```

2. Edit `/var/www/.env` and configure your settings:
   ```bash
   # Set your pairing ID (generate a unique ID)
   TR2_PAIRING_ID=9dfd7fa79762f513bf03a5f93c570ad9
   
   # Set to false when running in Docker
   TR2_UPNP_ENABLED=false
   ```

3. Restart your containers:
   ```bash
   docker compose restart
   ```

## Docker Deployment

When deploying with Docker, make sure to:

1. Mount the API directory to `/var/www/api/` in your container
2. Set `TR2_UPNP_ENABLED=false` in your `.env` file
3. Configure the `.env` file with your pairing ID and main server URL

Example Docker Compose volume mount:
```yaml
volumes:
  - ./api:/var/www/api:ro
  - ./path/to/.env:/var/www/.env:ro
```

## API Endpoints

### `GET /api/index.php`
Get API status and available endpoints.

**Response:**
```json
{
  "success": true,
  "service": "TR2 File Server API",
  "version": "1.0.0",
  "status": "running",
  "pairing_id": "9dfd7fa79762f513bf03a5f93c570ad9",
  "endpoints": { ... }
}
```

### `POST /api/heartbeat.php`
Send heartbeat to the main server (called automatically by the file server).

**Response:**
```json
{
  "success": true
}
```

### `GET /api/diagnostics.php`
Run system diagnostics and get health status.

**Response:**
```json
{
  "health": "warning",
  "summary": {
    "total_issues": 2,
    "critical": 0,
    "high": 1,
    "medium": 1
  },
  "diagnostics": { ... }
}
```

### `GET /api/qbit-password-manager.php`
Get qBittorrent connection status.

**Response:**
```json
{
  "success": true,
  "username": "admin",
  "password_set": true,
  "connection_ok": true
}
```

### `POST /api/qbit-password-manager.php`
Update qBittorrent credentials.

**Request:**
```json
{
  "username": "admin",
  "password": "newpassword"
}
```

### `POST /api/file_server_heartbeat.php`
Receive heartbeat from file servers (main server endpoint).

**Request:**
```json
{
  "pairing_id": "9dfd7fa79762f513bf03a5f93c570ad9",
  "timestamp": 1234567890,
  "status": "online",
  "ip": "94.27.166.162:29715",
  "upnp_enabled": false
}
```

**Response:**
```json
{
  "success": true,
  "message": "Heartbeat received",
  "server_time": 1234567890,
  "interval": 30,
  "commands": []
}
```

## Troubleshooting

### Heartbeat service keeps restarting

This is usually caused by the function redeclaration error, which has been fixed by using the shared `config.php` file.

### UPnP warnings in Docker

This is expected behavior. Docker containers using bridge networking cannot access the host's router directly. Use API Poll mode instead:

1. Set `TR2_UPNP_ENABLED=false` in `.env` file
2. Restart containers: `docker compose restart`
3. The file server will connect to the main server every 30 seconds

### Cannot reach main server

Check your network configuration and ensure:
- The main server URL is correct in `.env`
- The file server has internet connectivity
- Firewall rules allow outbound HTTPS connections

## Development

The API uses a simple PHP structure with no external dependencies. All files include `config.php` which provides:

- `loadEnv()` - Load environment variables from `.env` file
- `logMessage()` - Log messages with timestamps
- `sendResponse()` - Send JSON responses
- `getJsonInput()` - Parse JSON from request body

## Security Notes

- The `.env` file should never be committed to version control
- Ensure proper file permissions on the `.env` file (600 or 640)
- Use HTTPS for all API communications in production
- Consider implementing API authentication for production use
