#!/bin/bash
# edudisplej_terminal_script.sh - Terminal with ASCII logo
# =============================================================================

# Terminal appearance -- Vzhľad terminalu
tput civis || true
clear

# ASCII banner
if command -v figlet >/dev/null 2>&1; then
    figlet -w 120 "EDUDISPLEJ"
else
    cat << 'EOF'
 _____    _         ____  _           _            _ 
| ____|__| |_   _  |  _ \(_)___ _ __ | | ___  ___ (_)
|  _| / _` | | | | | | | | / __| '_ \| |/ _ \/ _ \| |
| |__| (_| | |_| | | |_| | \__ \ |_) | |  __/  __/| |
|_____\__,_|\__,_| |____/|_|___/ .__/|_|\___|\___// |
                                |_|              |__/ 
EOF
fi
echo
echo "System je pripraveny / System is ready"
echo "=========================================="
echo

# Keep terminal open
exec bash
