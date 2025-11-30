#!/bin/sh
# Egyszerre csak egy update példány fusson — lockfile + PID ellenőrzés.
LOCKFILE="/var/lock/edudisplej-update.lock"
LOCK_DIR="${LOCKFILE%/*}"

# Ha nincs /var/lock írható, használj /tmp helyet
if [ ! -w "$LOCK_DIR" ]; then
  LOCKFILE="/tmp/edudisplej-update.lock"
fi

# Ha létezik lockfile és a PID él -> kilépünk
if [ -f "$LOCKFILE" ]; then
  OLD_PID=$(cat "$LOCKFILE" 2>/dev/null)
  if [ -n "$OLD_PID" ] && kill -0 "$OLD_PID" 2>/dev/null; then
    echo "Update már fut (PID $OLD_PID). Kilépés."
    exit 0
  else
    # elavult lockfile -> töröljük
    rm -f "$LOCKFILE" 2>/dev/null || true
  fi
fi

# Saját PID-et írjuk, és gondoskodunk a lock törléséről a kilépéskor
echo $$ > "$LOCKFILE"
trap 'rm -f "$LOCKFILE"' EXIT INT TERM

# --- ide jön az update logika ---
# pl. git pull && ./install-scripts.sh
# Példa:
# cd /opt/edudisplej || exit 1
# git pull origin main || exit 1
# ./install.sh || exit 1

# Végén a trap törli a lockfile
exit 0
