#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

chmod +x ./prepare-live-build.sh ./auto/config

./prepare-live-build.sh
sudo lb clean --purge
bash ./auto/config

# live-build often needs root privileges for final build steps
sudo lb build

echo "[✓] Build complete"
ls -lah *.iso 2>/dev/null || true
