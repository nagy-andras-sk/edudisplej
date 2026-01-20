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
 _____ ____  _   _ ____ ___ ____  ____  _     _____ ___ 
| ____|  _ \| | | |  _ \_ _/ ___||  _ \| |   | ____| _ |
|  _| | | | | | | | | | | |\___ \| |_) | |   |  _| |  _ \
| |___| |_| | |_| | |_| | | ___) |  __/| |___| |___|_| |_|
|_____|____/ \___/|____/___|____/|_|   |_____|_____|_____/
EOF
fi
echo
echo "System je pripraveny / System is ready"
echo "=========================================="
echo

# Keep terminal open
exec bash
