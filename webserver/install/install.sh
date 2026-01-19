#!/bin/bash
set -euo pipefail

TARGET_DIR="/opt/edudisplej"
INIT_DIR="${TARGET_DIR}/init"
LOCAL_WEB_DIR="${TARGET_DIR}/localweb"
INIT_BASE="https://install.edudisplej.sk/init"

echo "[*] Kontrola opravneni root..."
if [ "$(id -u)" -ne 0 ]; then
  echo "[!] Spusti skript s sudo!"
  exit 1
fi

# Detect architecture
ARCH="$(uname -m)"
echo "[*] Detected architecture: $ARCH"

# Determine kiosk mode based on architecture
if [ "$ARCH" = "armv6l" ]; then
  KIOSK_MODE="epiphany"
  echo "[*] ARMv6 detected - using Epiphany browser kiosk mode"
else
  KIOSK_MODE="chromium"
  echo "[*] Using Chromium browser kiosk mode"
fi

# Kontrola: curl nainstalovany?
if ! command -v curl >/dev/null 2>&1; then
  echo "[*] Instalacia curl..."
  apt-get update && apt-get install -y curl
fi

# Kontrola GUI (len info)
echo "[*] Kontrola GUI prostredia..."
if pgrep -x "Xorg" >/dev/null 2>&1; then
    echo "[*] GUI bezi."
    GUI_AVAILABLE=true
else
    echo "[!] GUI sa nenasiel. Pokracujeme bez grafiky."
    GUI_AVAILABLE=false
fi

# Ak existuje cielovy priecinok, vytvorit zalohu
if [ -d "$TARGET_DIR" ]; then
  BACKUP="${TARGET_DIR}.bak.$(date +%s)"
  echo "[*] Zalohovanie: $TARGET_DIR -> $BACKUP"
  mv "$TARGET_DIR" "$BACKUP"
fi

# Vytvorenie init a localweb priecinkov
mkdir -p "$INIT_DIR" "$LOCAL_WEB_DIR"

echo "[*] Nacitavame zoznam suborov : ${INIT_BASE}/download.php?getfiles"
FILES_LIST="$(curl -s "${INIT_BASE}/download.php?getfiles" | tr -d '\r')"

if [ -z "$FILES_LIST" ]; then
  echo "[!] Chyba: Nepodarilo sa nacitat zoznam suborov."
  exit 1
fi

echo "[DEBUG] Zoznam suborov:"
echo "$FILES_LIST"

# Stiahnutie jednotlivo + CRLF oprava + kontrola shebang
# DÔLEŽITÉ: while bez pipe (aby exit vo vnútri ukončil skript)
while IFS=";" read -r NAME SIZE MODIFIED; do
    [ -z "${NAME:-}" ] && continue

    echo "[*] Stahovanie: $NAME ($SIZE bajtov)"
    curl -sL "${INIT_BASE}/download.php?streamfile=${NAME}" -o "${INIT_DIR}/${NAME}" || {
        echo "[!] Chyba: Stahovanie $NAME zlyhalo."
        exit 1
    }

    # Oprava konca riadkov (CRLF -> LF)
    sed -i 's/\r$//' "${INIT_DIR}/${NAME}"

    # Ha .sh fajl, ellenorizzuk a shebang-et
    if [[ "${NAME}" == *.sh ]]; then
        chmod +x "${INIT_DIR}/${NAME}"
        FIRST_LINE="$(head -n1 "${INIT_DIR}/${NAME}" || true)"
        if [[ "${FIRST_LINE}" != "#!"* ]]; then
            echo "[!] Chyba shebang, pridavam: #!/bin/bash"
            sed -i '1i #!/bin/bash' "${INIT_DIR}/${NAME}"
        fi
    elif [[ "${NAME}" == *.html ]]; then
      # HTML subory presun do localweb priecinka
      cp -f "${INIT_DIR}/${NAME}" "${LOCAL_WEB_DIR}/${NAME}"
    fi
done <<< "$FILES_LIST"

# Kontrola: edudisplej-init.sh existuje?
if [ ! -f "${INIT_DIR}/edudisplej-init.sh" ]; then
    echo "[!] Chyba: edudisplej-init.sh sa nenachadza medzi stiahnutymi subormi."
    exit 1
fi

# Nastavenie opravneni
chmod -R 755 "$TARGET_DIR"

# Urcenie konzoloveho pouzivatela (zvycajne pi)
CONSOLE_USER="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1 || true)"
[ -z "${CONSOLE_USER}" ] && CONSOLE_USER="pi"

# Get user home directory
USER_HOME="$(getent passwd "$CONSOLE_USER" | cut -d: -f6)"
if [ -z "$USER_HOME" ]; then
    echo "[!] Could not determine home directory for user: $CONSOLE_USER"
    exit 1
fi
echo "[*] User: $CONSOLE_USER, Home: $USER_HOME"

# Install additional packages based on kiosk mode
if [ "$KIOSK_MODE" = "epiphany" ]; then
    echo "[*] Installing ARMv6-specific packages (Epiphany)..."
    ADDITIONAL_PACKAGES=(
        "epiphany-browser"
        "xterm"
        "xdotool"
        "figlet"
        "dbus-x11"
    )
    apt-get update -qq || true
    DEBIAN_FRONTEND=noninteractive apt-get install -y "${ADDITIONAL_PACKAGES[@]}" || {
        echo "[!] Warning: Some packages failed to install"
    }
fi

# --- KIOSK CONFIGURATION ---
# Both modes now use terminal launcher approach
echo "[*] Installing common packages for terminal-based kiosk..."
COMMON_PACKAGES=(
    "xterm"
    "xdotool"
    "figlet"
    "dbus-x11"
)
apt-get update -qq || true
DEBIAN_FRONTEND=noninteractive apt-get install -y "${COMMON_PACKAGES[@]}" || {
    echo "[!] Warning: Some packages failed to install"
}

if [ "$KIOSK_MODE" = "chromium" ]; then
    # Chromium-based kiosk setup with terminal launcher
    echo "[*] Setting up Chromium kiosk mode (with terminal launcher)..."
    
    # Disable/remove display managers
    echo "[*] Disabling display managers..."
    DISPLAY_MANAGERS=("lightdm" "lxdm" "sddm" "gdm3" "gdm" "xdm" "plymouth")
    for dm in "${DISPLAY_MANAGERS[@]}"; do
        if systemctl list-unit-files | grep -q "^${dm}.service"; then
            echo "[*] Disabling $dm..."
            systemctl disable --now "${dm}.service" 2>/dev/null || true
            systemctl mask "${dm}.service" 2>/dev/null || true
        fi
        if dpkg -l | grep -q "^ii  $dm "; then
            echo "[*] Removing package $dm..."
            DEBIAN_FRONTEND=noninteractive apt-get purge -y "$dm" 2>/dev/null || true
        fi
    done
    
    # Set up autologin on tty1
    echo "[*] Configuring autologin on tty1..."
    GETTY_DIR="/etc/systemd/system/getty@tty1.service.d"
    mkdir -p "$GETTY_DIR"
    cat > "$GETTY_DIR/autologin.conf" <<EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $CONSOLE_USER --noclear %I 38400 linux
EOF
    
    # Configure auto-start X on tty1
    echo "[*] Configuring auto-start X on tty1..."
    PROFILE_SNIPPET='
# Auto-start X/Openbox on tty1
if [ -z "$DISPLAY" ] && [ "$(tty)" = "/dev/tty1" ]; then
  # Safely terminate any existing X server
  if pgrep Xorg >/dev/null 2>&1; then
    XORG_PIDS=$(pgrep Xorg)
    for pid in $XORG_PIDS; do
      kill -TERM "$pid" 2>/dev/null || true
    done
    sleep 2
    # Force kill if still running
    for pid in $XORG_PIDS; do
      if kill -0 "$pid" 2>/dev/null; then
        kill -KILL "$pid" 2>/dev/null || true
      fi
    done
  fi
  sleep 1
  startx -- :0 vt1
fi'
    
    if [ -f "$USER_HOME/.profile" ]; then
        if ! grep -q "Auto-start X/Openbox on tty1" "$USER_HOME/.profile"; then
            echo "$PROFILE_SNIPPET" >> "$USER_HOME/.profile"
            echo "[*] Added X auto-start to .profile"
        else
            echo "[*] .profile already configured"
        fi
    else
        echo "$PROFILE_SNIPPET" > "$USER_HOME/.profile"
        echo "[*] Created .profile with X auto-start"
    fi
    chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.profile"
    
    # Create .xinitrc
    echo "[*] Creating .xinitrc..."
    cat > "$USER_HOME/.xinitrc" <<'EOF'
#!/bin/bash
# Start Openbox session
exec openbox-session
EOF
    chmod +x "$USER_HOME/.xinitrc"
    chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.xinitrc"
    
    # Create Openbox autostart
    echo "[*] Creating Openbox autostart configuration..."
    mkdir -p "$USER_HOME/.config/openbox"
    cat > "$USER_HOME/.config/openbox/autostart" <<EOF
# Disable DPMS/screensaver
xset -dpms
xset s off
xset s noblank

# Hide mouse after inactivity
unclutter -idle 1 &

# Start TERMINAL with launcher for CHROMIUM
xterm -fa Monospace -fs 14 -geometry 120x36+20+20 -e "\$HOME/kiosk-launcher.sh" &
EOF
    chown -R "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.config"
    
    # Create kiosk-launcher.sh for Chromium
    echo "[*] Creating kiosk-launcher.sh for Chromium..."
    cat > "$USER_HOME/kiosk-launcher.sh" <<'EOF'
#!/bin/bash
# kiosk-launcher.sh - Terminal launcher for Chromium browser kiosk mode
set -euo pipefail

# Configuration
URL="${1:-https://www.time.is}"
COUNT_FROM=5

# Function to ensure fullscreen with F11
ensure_fullscreen() {
  if command -v xdotool >/dev/null 2>&1; then
    xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
  fi
}

# Terminal appearance: hide cursor, clear screen
tput civis || true
clear

# ASCII banner (figlet)
if command -v figlet >/dev/null 2>&1; then
  figlet -w 120 "EDUDISPLEJ"
else
  echo "==== EDUDISPLEJ ===="
fi
echo

# Brief description
echo "Starting... Browser will launch in ${COUNT_FROM} seconds."
echo "URL: ${URL}"
echo

# Countdown
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rStarting in %2d..." "$i"
  sleep 1
done
echo -e "\rStarting now!     "
sleep 0.3

# Disable screensaver/power management (if running under X)
if command -v xset >/dev/null 2>&1; then
  xset -dpms
  xset s off
  xset s noblank
fi

# Hide mouse cursor (background)
if command -v unclutter >/dev/null 2>&1; then
  unclutter -idle 1 -root >/dev/null 2>&1 &
fi

# Restore cursor if interrupted (Ctrl+C)
trap 'tput cnorm || true' EXIT

# Launch browser in fullscreen (Chromium for standard platforms)
chromium-browser --kiosk --no-sandbox --disable-gpu --disable-infobars \
  --no-first-run --incognito --noerrdialogs --disable-translate \
  --disable-features=TranslateUI --disable-session-crashed-bubble \
  --check-for-update-interval=31536000 "${URL}" &

# Optional: ensure fullscreen is active with F11
sleep 3
ensure_fullscreen

# Optional watchdog: restart Chromium if it closes
while true; do
  sleep 2
  if ! pgrep -x "chromium-browser" >/dev/null; then
    chromium-browser --kiosk --no-sandbox --disable-gpu --disable-infobars \
      --no-first-run --incognito --noerrdialogs --disable-translate \
      --disable-features=TranslateUI --disable-session-crashed-bubble \
      --check-for-update-interval=31536000 "${URL}" &
    sleep 3
    ensure_fullscreen
  fi
done
EOF
    chmod +x "$USER_HOME/kiosk-launcher.sh"
    chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/kiosk-launcher.sh"
    
    # Add xrestart function to .bashrc
    echo "[*] Adding xrestart function to .bashrc..."
    BASHRC="$USER_HOME/.bashrc"
    XRESTART_FUNC='# X restart function
xrestart() {
  # Terminate X server safely
  for pid in $(pgrep Xorg 2>/dev/null || true); do
    kill -TERM "$pid" 2>/dev/null || true
  done
  sleep 2
  # Force kill if still running
  for pid in $(pgrep Xorg 2>/dev/null || true); do
    if kill -0 "$pid" 2>/dev/null; then
      kill -KILL "$pid" 2>/dev/null || true
    fi
  done
  sleep 1
  # Start X
  startx -- :0 vt1
}'
    
    if [ -f "$BASHRC" ]; then
        if ! grep -q "xrestart()" "$BASHRC"; then
            echo "$XRESTART_FUNC" >> "$BASHRC"
            echo "[*] Added xrestart function"
        else
            echo "[*] xrestart function already exists"
        fi
    else
        echo "$XRESTART_FUNC" > "$BASHRC"
        echo "[*] Created .bashrc with xrestart function"
    fi
    chown "$CONSOLE_USER:$CONSOLE_USER" "$BASHRC"
    
    # Reload systemd
    systemctl daemon-reload
    
else
    # ARMv6 Epiphany-based kiosk setup
    echo "[*] Setting up Epiphany kiosk mode (ARMv6)..."
    
    # Disable/remove display managers
    echo "[*] Disabling display managers..."
    DISPLAY_MANAGERS=("lightdm" "lxdm" "sddm" "gdm3" "gdm" "xdm" "plymouth")
    for dm in "${DISPLAY_MANAGERS[@]}"; do
        if systemctl list-unit-files | grep -q "^${dm}.service"; then
            echo "[*] Disabling $dm..."
            systemctl disable --now "${dm}.service" 2>/dev/null || true
            systemctl mask "${dm}.service" 2>/dev/null || true
        fi
        if dpkg -l | grep -q "^ii  $dm "; then
            echo "[*] Removing package $dm..."
            DEBIAN_FRONTEND=noninteractive apt-get purge -y "$dm" 2>/dev/null || true
        fi
    done
    
    # Set up autologin on tty1
    echo "[*] Configuring autologin on tty1..."
    GETTY_DIR="/etc/systemd/system/getty@tty1.service.d"
    mkdir -p "$GETTY_DIR"
    cat > "$GETTY_DIR/autologin.conf" <<EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $CONSOLE_USER --noclear %I 38400 linux
EOF
    
    # Configure auto-start X on tty1
    echo "[*] Configuring auto-start X on tty1..."
    PROFILE_SNIPPET='
# Auto-start X/Openbox on tty1
if [ -z "$DISPLAY" ] && [ "$(tty)" = "/dev/tty1" ]; then
  # Safely terminate any existing X server
  if pgrep Xorg >/dev/null 2>&1; then
    XORG_PIDS=$(pgrep Xorg)
    for pid in $XORG_PIDS; do
      kill -TERM "$pid" 2>/dev/null || true
    done
    sleep 2
    # Force kill if still running
    for pid in $XORG_PIDS; do
      if kill -0 "$pid" 2>/dev/null; then
        kill -KILL "$pid" 2>/dev/null || true
      fi
    done
  fi
  sleep 1
  startx -- :0 vt1
fi'
    
    if [ -f "$USER_HOME/.profile" ]; then
        if ! grep -q "Auto-start X/Openbox on tty1" "$USER_HOME/.profile"; then
            echo "$PROFILE_SNIPPET" >> "$USER_HOME/.profile"
            echo "[*] Added X auto-start to .profile"
        else
            echo "[*] .profile already configured"
        fi
    else
        echo "$PROFILE_SNIPPET" > "$USER_HOME/.profile"
        echo "[*] Created .profile with X auto-start"
    fi
    chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.profile"
    
    # Create .xinitrc
    echo "[*] Creating .xinitrc..."
    cat > "$USER_HOME/.xinitrc" <<'EOF'
#!/bin/bash
# Start Openbox session
exec openbox-session
EOF
    chmod +x "$USER_HOME/.xinitrc"
    chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.xinitrc"
    
    # Create Openbox autostart
    echo "[*] Creating Openbox autostart configuration..."
    mkdir -p "$USER_HOME/.config/openbox"
    cat > "$USER_HOME/.config/openbox/autostart" <<EOF
# Disable DPMS/screensaver
xset -dpms
xset s off
xset s noblank

# Hide mouse after inactivity
unclutter -idle 1 &

# Start TERMINAL with launcher
xterm -fa Monospace -fs 14 -geometry 120x36+20+20 -e "\$HOME/kiosk-launcher.sh" &
EOF
    chown -R "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/.config"
    
    # Create kiosk-launcher.sh
    echo "[*] Creating kiosk-launcher.sh..."
    cat > "$USER_HOME/kiosk-launcher.sh" <<'EOF'
#!/bin/bash
# kiosk-launcher.sh - Terminal launcher for Epiphany browser kiosk mode
set -euo pipefail

# Configuration
URL="${1:-https://example.com}"
COUNT_FROM=5

# Function to ensure fullscreen with F11
ensure_fullscreen() {
  if command -v xdotool >/dev/null 2>&1; then
    xdotool key --window "$(xdotool getactivewindow 2>/dev/null || true)" F11 || true
  fi
}

# Terminal appearance: hide cursor, clear screen
tput civis || true
clear

# ASCII banner (figlet)
if command -v figlet >/dev/null 2>&1; then
  figlet -w 120 "EDUDISPLEJ"
else
  echo "==== EDUDISPLEJ ===="
fi
echo

# Brief description
echo "Starting... Browser will launch in ${COUNT_FROM} seconds."
echo "URL: ${URL}"
echo

# Countdown
for ((i=COUNT_FROM; i>=1; i--)); do
  printf "\rStarting in %2d..." "$i"
  sleep 1
done
echo -e "\rStarting now!     "
sleep 0.3

# Disable screensaver/power management (if running under X)
if command -v xset >/dev/null 2>&1; then
  xset -dpms
  xset s off
  xset s noblank
fi

# Hide mouse cursor (background)
if command -v unclutter >/dev/null 2>&1; then
  unclutter -idle 1 -root >/dev/null 2>&1 &
fi

# Restore cursor if interrupted (Ctrl+C)
trap 'tput cnorm || true' EXIT

# Launch browser in fullscreen (Epiphany is ARMv6-compatible)
epiphany-browser --fullscreen "${URL}" &

# Optional: ensure fullscreen is active with F11
sleep 3
ensure_fullscreen

# Optional watchdog: restart Epiphany if it closes
while true; do
  sleep 2
  if ! pgrep -x "epiphany-browser" >/dev/null; then
    epiphany-browser --fullscreen "${URL}" &
    sleep 3
    ensure_fullscreen
  fi
done
EOF
    chmod +x "$USER_HOME/kiosk-launcher.sh"
    chown "$CONSOLE_USER:$CONSOLE_USER" "$USER_HOME/kiosk-launcher.sh"
    
    # Add xrestart function to .bashrc
    echo "[*] Adding xrestart function to .bashrc..."
    BASHRC="$USER_HOME/.bashrc"
    XRESTART_FUNC='# X restart function
xrestart() {
  # Terminate X server safely
  for pid in $(pgrep Xorg 2>/dev/null || true); do
    kill -TERM "$pid" 2>/dev/null || true
  done
  sleep 2
  # Force kill if still running
  for pid in $(pgrep Xorg 2>/dev/null || true); do
    if kill -0 "$pid" 2>/dev/null; then
      kill -KILL "$pid" 2>/dev/null || true
    fi
  done
  sleep 1
  # Start X
  startx -- :0 vt1
}'
    
    if [ -f "$BASHRC" ]; then
        if ! grep -q "xrestart()" "$BASHRC"; then
            echo "$XRESTART_FUNC" >> "$BASHRC"
            echo "[*] Added xrestart function"
        else
            echo "[*] xrestart function already exists"
        fi
    else
        echo "$XRESTART_FUNC" > "$BASHRC"
        echo "[*] Created .bashrc with xrestart function"
    fi
    chown "$CONSOLE_USER:$CONSOLE_USER" "$BASHRC"
    
    # Reload systemd
    systemctl daemon-reload
fi

echo ""
echo "=========================================="
echo "Telepítés kész! / Installation Complete!"
echo "=========================================="
echo ""

if [ "$KIOSK_MODE" = "chromium" ]; then
    echo "Kiosk mode: Chromium (standard)"
    echo "Újraindításhoz / To reboot: sudo reboot"
    echo "Szolgáltatás indítása / Start service: sudo systemctl start chromiumkiosk-minimal"
    echo "Logok / Logs: /opt/edudisplej/kiosk.log, /opt/edudisplej/service.log"
else
    echo "Kiosk mode: Epiphany (ARMv6)"
    echo "User: $CONSOLE_USER"
    echo "After reboot:"
    echo "  - Auto-login on tty1 as $CONSOLE_USER"
    echo "  - X server starts automatically"
    echo "  - xterm opens with kiosk-launcher.sh"
    echo "  - Epiphany browser launches in fullscreen"
    echo ""
    echo "To test without reboot:"
    echo "  sudo -u $CONSOLE_USER DISPLAY=:0 XDG_VTNR=1 startx -- :0 vt1"
    echo ""
    echo "Manual control:"
    echo "  xrestart  # Restart X server (as $CONSOLE_USER)"
fi

echo ""
echo "[✓] Instalacia dokoncena. Restart za 10 sekund..."
sleep 10
reboot
