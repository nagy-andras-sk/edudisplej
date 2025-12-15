# TR2 File Server Deployment Guide

This guide will help you deploy the TR2 File Server with the fixed API that resolves the `loadEnv()` redeclaration error.

## Prerequisites

- Docker and Docker Compose installed
- Access to your server via SSH
- Basic knowledge of Docker and command line

## Quick Start

### 1. Clone the Repository

```bash
git clone https://github.com/03Andras/edudisplej.git
cd edudisplej
```

### 2. Configure Environment

Copy the example environment file and edit it:

```bash
cp api/.env.example .env
nano .env
```

**Important settings to configure:**

```bash
# Generate a unique pairing ID (you can use: echo -n "$(date +%s)$(hostname)" | md5sum | cut -d' ' -f1)
TR2_PAIRING_ID=your-unique-pairing-id-here

# Main server URL (default is fine if using tr.nagyandras.sk)
TR2_MAIN_SERVER=https://tr.nagyandras.sk

# MUST be false when running in Docker
TR2_UPNP_ENABLED=false

# Optional: Set your public IP and port if known
# TR2_PUBLIC_IP=your.ip.address:port
```

### 3. Build and Start Services

```bash
# Build the Docker image
docker compose build

# Start services in detached mode
docker compose up -d

# Check logs to verify everything is working
docker compose logs -f tr2-server
```

### 4. Verify Installation

You should see output similar to:

```
tr2-server       | TR2 Heartbeat Service Starting...
tr2-server       | Pairing ID: 9dfd7fa79762f513bf03a5f93c570ad9
tr2-server       | Main Server: https://tr.nagyandras.sk
tr2-server       | Heartbeat Interval: 30 seconds (dynamic)
tr2-server       | [2025-12-15 12:00:00] Running network diagnostics...
tr2-server       | [2025-12-15 12:00:00] Heartbeat sent successfully
```

**No more fatal errors!** The `loadEnv()` redeclaration error is fixed.

## Troubleshooting

### Issue: Still seeing "Cannot redeclare loadEnv()" error

**Solution:**
- Make sure you're using the latest code from this repository
- Rebuild the Docker image: `docker compose build --no-cache`
- Restart containers: `docker compose down && docker compose up -d`

### Issue: UPnP warnings in Docker logs

**This is expected!** Docker containers using bridge networking cannot access the host's router directly.

**Solution:** Already configured! When `TR2_UPNP_ENABLED=false`, the file server uses API Poll mode:
- File server connects to main server every 30 seconds
- No inbound ports needed
- Use 'Remote Terminal' in admin panel for all operations

### Issue: Container keeps restarting

**Check logs:**
```bash
docker compose logs tr2-server
```

Common causes:
1. Missing or invalid `.env` file → Copy from `.env.example` and configure
2. Invalid pairing ID → Set `TR2_PAIRING_ID` in `.env`
3. Network connectivity → Check internet connection

### Issue: Cannot reach main server

**Check configuration:**
```bash
# Verify .env file
cat .env | grep TR2_MAIN_SERVER

# Test connectivity
docker compose exec tr2-server curl -I https://tr.nagyandras.sk
```

**Solutions:**
- Verify `TR2_MAIN_SERVER` URL is correct
- Check firewall rules allow outbound HTTPS
- Ensure DNS resolution works

## API Endpoints

Once running, you can access these endpoints:

```bash
# API Status
curl http://localhost:29715/api/index.php

# Diagnostics
curl http://localhost:29715/api/diagnostics.php

# Manual heartbeat (for testing)
curl -X POST http://localhost:29715/api/heartbeat.php
```

## Updating

To update to the latest version:

```bash
cd edudisplej
git pull
docker compose down
docker compose build --no-cache
docker compose up -d
```

## Production Deployment

For production use, consider:

1. **Use HTTPS** - Configure SSL/TLS certificates
2. **Add authentication** - Protect API endpoints
3. **Set up monitoring** - Use tools like Prometheus/Grafana
4. **Configure backups** - Backup your `.env` and data
5. **Review security** - See Security Recommendations section

## Security Recommendations

For production deployments:

1. **API Authentication**
   - Implement API key authentication
   - Use OAuth for web-based access

2. **Encrypt Sensitive Data**
   - Encrypt qBittorrent passwords
   - Use secrets management (Docker secrets, Vault)

3. **Network Security**
   - Use HTTPS for all communications
   - Implement rate limiting
   - Configure firewall rules

4. **Access Control**
   - Limit container privileges
   - Use read-only volumes where possible
   - Run as non-root user

## Support

If you encounter issues:

1. Check the logs: `docker compose logs -f tr2-server`
2. Verify configuration: `cat .env`
3. Test connectivity: `docker compose exec tr2-server ping -c 3 google.com`
4. Review diagnostics: `curl http://localhost:29715/api/diagnostics.php`

For more information, see:
- [API Documentation](api/README.md)
- [Main README](README.md)

## What Was Fixed

The original error:
```
Fatal error: Cannot redeclare loadEnv() (previously declared in /var/www/api/heartbeat.php:12) 
in /var/www/api/qbit-password-manager.php on line 10
```

Was caused by multiple PHP files defining the same `loadEnv()` function.

**Solution implemented:**
- Created shared `config.php` with single `loadEnv()` definition
- Added `!function_exists()` guard to prevent redeclaration
- All PHP files now include `config.php` instead of defining their own functions
- Result: No more fatal errors, heartbeat service runs continuously

## File Structure

```
edudisplej/
├── api/                           # PHP API files
│   ├── config.php                 # Shared config (defines loadEnv once)
│   ├── heartbeat.php              # Heartbeat service
│   ├── qbit-password-manager.php  # qBittorrent manager
│   ├── diagnostics.php            # System diagnostics
│   ├── index.php                  # API status
│   ├── file_server_heartbeat.php  # Heartbeat receiver
│   ├── .env.example               # Config template
│   └── README.md                  # API documentation
├── docker/                        # Docker configuration
│   ├── supervisord.conf           # Supervisor config
│   └── upnp-manager.sh            # UPnP manager script
├── Dockerfile                     # Docker image definition
├── docker-compose.yml             # Docker Compose config
├── .env                           # Your configuration (not in git)
└── README.md                      # Main documentation
```
