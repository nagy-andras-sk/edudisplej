#!/bin/bash
set -euo pipefail

printf '%s\n' 'edudisplej' | sudo -S install -m 755 /tmp/edudisplej_terminal_script.sh /opt/edudisplej/init/edudisplej_terminal_script.sh

printf '%s\n' 'edudisplej' | sudo -S bash -c '
mkdir -p /home/edudisplej/localweb
if [ ! -f /home/edudisplej/localweb/offline_status.json ]; then
  printf "%s\n" "{\"active\":false,\"message\":\"\"}" > /home/edudisplej/localweb/offline_status.json
fi
chown edudisplej:edudisplej /home/edudisplej/localweb/offline_status.json
chmod 644 /home/edudisplej/localweb/offline_status.json
'

bash -n /opt/edudisplej/init/edudisplej_terminal_script.sh
printf '%s\n' 'edudisplej' | sudo -S systemctl restart edudisplej-kiosk.service
sleep 3
systemctl is-active edudisplej-kiosk.service

echo '--- offline_status endpoint ---'
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8765/offline_status.json

echo '--- runtime modules sample ---'
find /home/edudisplej/localweb/modules -maxdepth 2 -type f | sort | head -n 20

echo '--- http log tail ---'
tail -n 40 /tmp/edudisplej-runtime-http.log 2>/dev/null || true
