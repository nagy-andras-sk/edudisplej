#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "${SCRIPT_DIR}/common.sh" ] && source "${SCRIPT_DIR}/common.sh" || true

stop_kiosk_mode() {
    for proc in surf openbox unclutter Xorg xinit; do
        local pids
        pids=$(pgrep -x "$proc" 2>/dev/null || true)
        for pid in $pids; do
            kill -TERM "$pid" 2>/dev/null || true
        done
    done
    sleep 1
    for proc in surf openbox Xorg xinit; do
        local pids
        pids=$(pgrep -x "$proc" 2>/dev/null || true)
        for pid in $pids; do
            kill -0 "$pid" 2>/dev/null && kill -KILL "$pid" 2>/dev/null || true
        done
    done
    rm -f /tmp/.X0-lock 2>/dev/null
    rm -f /tmp/.X11-unix/X0 2>/dev/null
}
