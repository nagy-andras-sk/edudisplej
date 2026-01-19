#!/bin/bash
# install-kiosk.sh - ARMv6 Kiosk Installer for Raspberry Pi 1 / Zero (not Zero 2)
# Target: Raspbian 13 (Debian Trixie) or compatible
# Uses: Epiphany browser (ARMv6 compatible), Xorg, Openbox, xterm
# Auto-login on tty1 without Display Manager
set -euo pipefail

# Configuration
KIOSK_USER="${SUDO_USER:-}"
DEFAULT_USER="edudisplej"
DEFAULT_URL="https://example.com"

# Colors and formatting
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $*"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $*"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $*"
}

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    log_error "This script must be run as root (use sudo)"
    exit 1
fi

log_info "EduDisplej ARMv6 Kiosk Installer"
log_info "Target: Raspberry Pi 1 / Zero with Epiphany browser"
echo ""

# Determine kiosk user
if [ -z "$KIOSK_USER" ]; then
    # Try to find user with UID 1000
    KIOSK_USER="$(awk -F: '$3==1000{print $1}' /etc/passwd | head -n1 || true)"
fi

if [ -z "$KIOSK_USER" ]; then
    KIOSK_USER="$DEFAULT_USER"
    log_warn "No user found, will create: $KIOSK_USER"
fi

log_info "Using kiosk user: $KIOSK_USER"

# Create user if doesn't exist
if ! id "$KIOSK_USER" &>/dev/null; then
    log_info "Creating user: $KIOSK_USER"
    useradd -m -s /bin/bash "$KIOSK_USER"
    log_info "User created: $KIOSK_USER"
fi

# Get user home directory
USER_HOME="$(getent passwd "$KIOSK_USER" | cut -d: -f6)"
if [ -z "$USER_HOME" ]; then
    log_error "Could not determine home directory for user: $KIOSK_USER"
    exit 1
fi
log_info "User home: $USER_HOME"

# Step 1: Install required packages
log_info "Step 1/7: Installing required packages..."

PACKAGES=(
    "xserver-xorg"
    "xinit"
    "openbox"
    "xterm"
    "epiphany-browser"
    "unclutter"
    "xdotool"
    "figlet"
    "dbus-x11"
    "x11-xserver-utils"
)

log_info "Updating package lists..."
apt-get update -qq || log_warn "Package update had warnings, continuing..."

log_info "Installing packages: ${PACKAGES[*]}"
DEBIAN_FRONTEND=noninteractive apt-get install -y "${PACKAGES[@]}" || {
    log_error "Failed to install some packages"
    exit 1
}

log_info "Packages installed successfully"

# Step 2: Disable/remove display managers
log_info "Step 2/7: Disabling display managers..."

DISPLAY_MANAGERS=("lightdm" "lxdm" "sddm" "gdm3" "gdm" "xdm" "plymouth")

for dm in "${DISPLAY_MANAGERS[@]}"; do
    # Check if service exists
    if systemctl list-unit-files | grep -q "^${dm}.service"; then
        log_info "Disabling $dm..."
        systemctl disable --now "${dm}.service" 2>/dev/null || true
        systemctl mask "${dm}.service" 2>/dev/null || true
    fi
    
    # Check if package is installed
    if dpkg -l | grep -q "^ii  $dm "; then
        log_info "Removing package $dm..."
        DEBIAN_FRONTEND=noninteractive apt-get purge -y "$dm" 2>/dev/null || true
    fi
done

log_info "Display managers disabled"

# Step 3: Set up autologin on tty1
log_info "Step 3/7: Configuring autologin on tty1..."

# Create systemd drop-in directory
GETTY_DIR="/etc/systemd/system/getty@tty1.service.d"
mkdir -p "$GETTY_DIR"

# Create autologin configuration
cat > "$GETTY_DIR/autologin.conf" <<EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $KIOSK_USER --noclear %I 38400 linux
EOF

log_info "Autologin configured for $KIOSK_USER on tty1"

# Step 4: Configure auto-start X on tty1
log_info "Step 4/7: Configuring auto-start X on tty1..."

# Create/update .profile
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
    # Check if snippet already exists
    if ! grep -q "Auto-start X/Openbox on tty1" "$USER_HOME/.profile"; then
        echo "$PROFILE_SNIPPET" >> "$USER_HOME/.profile"
        log_info "Added X auto-start to .profile"
    else
        log_info ".profile already configured"
    fi
else
    echo "$PROFILE_SNIPPET" > "$USER_HOME/.profile"
    log_info "Created .profile with X auto-start"
fi

chown "$KIOSK_USER:$KIOSK_USER" "$USER_HOME/.profile"

# Step 5: Create .xinitrc
log_info "Step 5/7: Creating .xinitrc..."

cat > "$USER_HOME/.xinitrc" <<'EOF'
#!/bin/bash
# Start Openbox session
exec openbox-session
EOF

chmod +x "$USER_HOME/.xinitrc"
chown "$KIOSK_USER:$KIOSK_USER" "$USER_HOME/.xinitrc"

log_info ".xinitrc created"

# Step 6: Create Openbox autostart
log_info "Step 6/7: Creating Openbox autostart configuration..."

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

chown -R "$KIOSK_USER:$KIOSK_USER" "$USER_HOME/.config"

log_info "Openbox autostart configured"

# Step 7: Create kiosk-launcher.sh
log_info "Step 7/7: Creating kiosk-launcher.sh..."

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
  (unclutter -idle 1 -root >/dev/null 2>&1 & ) || true
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
chown "$KIOSK_USER:$KIOSK_USER" "$USER_HOME/kiosk-launcher.sh"

log_info "kiosk-launcher.sh created"

# Step 8: Add alias to .bashrc
log_info "Adding xrestart alias to .bashrc..."

BASHRC="$USER_HOME/.bashrc"
ALIAS_LINE='alias xrestart="for pid in \$(pgrep Xorg); do kill -TERM \$pid 2>/dev/null || true; done; sleep 2; for pid in \$(pgrep Xorg); do kill -KILL \$pid 2>/dev/null || true; done; sleep 1; startx -- :0 vt1"'

if [ -f "$BASHRC" ]; then
    if ! grep -q "alias xrestart=" "$BASHRC"; then
        echo "# X restart alias" >> "$BASHRC"
        echo "$ALIAS_LINE" >> "$BASHRC"
        log_info "Added xrestart alias"
    else
        log_info "xrestart alias already exists"
    fi
else
    echo "# X restart alias" > "$BASHRC"
    echo "$ALIAS_LINE" >> "$BASHRC"
    log_info "Created .bashrc with xrestart alias"
fi

chown "$KIOSK_USER:$KIOSK_USER" "$BASHRC"

# Reload systemd
log_info "Reloading systemd daemon..."
systemctl daemon-reload

echo ""
log_info "=========================================="
log_info "Installation complete!"
log_info "=========================================="
echo ""
log_info "Configuration:"
log_info "  User: $KIOSK_USER"
log_info "  Home: $USER_HOME"
log_info "  Default URL: $DEFAULT_URL"
echo ""
log_info "To test without reboot:"
log_info "  sudo -u $KIOSK_USER DISPLAY=:0 XDG_VTNR=1 startx -- :0 vt1"
echo ""
log_info "To reboot and start kiosk:"
log_info "  sudo reboot"
echo ""
log_info "After boot:"
log_info "  - Auto-login on tty1 as $KIOSK_USER"
log_info "  - X server starts automatically"
log_info "  - xterm opens with kiosk-launcher.sh"
log_info "  - Epiphany browser launches in fullscreen"
echo ""
