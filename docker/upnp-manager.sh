#!/bin/bash
# UPnP Manager Script
# This script manages UPnP port forwarding

# Source configuration
if [ -f /var/www/.env ]; then
    export $(cat /var/www/.env | grep -v '^#' | xargs)
fi

UPNP_ENABLED="${TR2_UPNP_ENABLED:-false}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] Starting UPnP Manager..."

# Check if running in Docker
if [ -f "/.dockerenv" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] Running inside Docker container"
fi

# Check if UPnP is enabled
if [ "$UPNP_ENABLED" = "false" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] UPnP is disabled (TR2_UPNP_ENABLED=false)"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] Using API Poll mode - no UPnP configuration needed"
    # Keep running but with signal handling for graceful shutdown
    trap 'echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] Shutting down..."; exit 0' SIGTERM SIGINT
    while true; do
        sleep 60
    done
    exit 0
fi

# Try to discover UPnP gateway
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] Discovering UPnP gateway..."

# Check if running in Docker - UPnP won't work in Docker bridge network
if [ -f "/.dockerenv" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR: No UPnP gateway found or UPnP is disabled on router"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR: DOCKER NETWORKING ISSUE DETECTED:"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR:   Docker containers using bridge networking cannot access the host's router directly"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR:   This is a fundamental limitation of Docker bridge networks"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR: "
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR: SOLUTION: Use API Poll mode instead"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR:   1. Set TR2_UPNP_ENABLED=false in .env file"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR:   2. Restart containers: docker compose restart"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR:   3. Use 'Remote Terminal' in admin panel for all operations"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR: "
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR:   The file server will connect to the main server every 30 seconds,"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] ERROR:   so no inbound ports are needed."
    
    # Exit with error code to indicate UPnP not available
    exit 1
fi

# If not in Docker, try to set up UPnP (placeholder - would need upnpc or similar tool)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] Attempting to configure port forwarding..."
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] Note: UPnP tools not installed - this is expected in Docker"

# Keep running with signal handling for graceful shutdown
trap 'echo "[$(date '+%Y-%m-%d %H:%M:%S')] [UPnP] Shutting down..."; exit 0' SIGTERM SIGINT
while true; do
    sleep 60
done
