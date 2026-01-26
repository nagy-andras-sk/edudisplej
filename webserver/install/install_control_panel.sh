#!/bin/bash
# EduDisplej Control Panel Installation Script
# =============================================================================

set -e

echo "======================================"
echo "EduDisplej Control Panel Installation"
echo "======================================"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "Please run as root (use sudo)"
    exit 1
fi

# Variables
DB_NAME="edudisplej_sk"
DB_USER="edudisplej_sk"
DB_PASS="Pab)tB/g/PulNs)2"
WEB_ROOT="/var/www/html"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "Configuration:"
echo "  Database Name: $DB_NAME"
echo "  Database User: $DB_USER"
echo "  Web Root: $WEB_ROOT"
echo ""

read -p "Continue with installation? (y/n) " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Installation cancelled."
    exit 1
fi

# Install dependencies
echo ""
echo "[1/5] Installing dependencies..."
apt-get update -qq
apt-get install -y -qq php php-mysql mysql-server apache2 curl scrot > /dev/null 2>&1
echo "✓ Dependencies installed"

# Setup database
echo ""
echo "[2/5] Setting up database..."

# Check if MySQL is running
if ! systemctl is-active --quiet mysql; then
    systemctl start mysql
fi

# Create database and user
mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci;" 2>/dev/null || true
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';" 2>/dev/null || true
mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';" 2>/dev/null || true
mysql -e "FLUSH PRIVILEGES;" 2>/dev/null || true

# Import schema
if [ -f "$SCRIPT_DIR/../control_edudisplej_sk/database_schema.sql" ]; then
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SCRIPT_DIR/../control_edudisplej_sk/database_schema.sql" 2>/dev/null || true
    echo "✓ Database created and schema imported"
else
    echo "⚠ Schema file not found, skipping schema import"
fi

# Copy web files
echo ""
echo "[3/5] Installing web files..."
mkdir -p "$WEB_ROOT"

# Copy control panel
if [ -d "$SCRIPT_DIR/../control_edudisplej_sk" ]; then
    cp -r "$SCRIPT_DIR/../control_edudisplej_sk" "$WEB_ROOT/" 2>/dev/null || true
    echo "✓ Control panel installed"
fi

# Copy website
if [ -d "$SCRIPT_DIR/../www_edudisplej_sk" ]; then
    cp -r "$SCRIPT_DIR/../www_edudisplej_sk" "$WEB_ROOT/" 2>/dev/null || true
    echo "✓ Website installed"
fi

# Copy dashboard redirect
if [ -d "$SCRIPT_DIR/../dashboard_edudisplej_sk" ]; then
    cp -r "$SCRIPT_DIR/../dashboard_edudisplej_sk" "$WEB_ROOT/" 2>/dev/null || true
    echo "✓ Dashboard redirect installed"
fi

# Set permissions
chown -R www-data:www-data "$WEB_ROOT"
chmod -R 755 "$WEB_ROOT"
mkdir -p "$WEB_ROOT/control_edudisplej_sk/screenshots"
chmod 775 "$WEB_ROOT/control_edudisplej_sk/screenshots"
echo "✓ Permissions set"

# Setup sync service
echo ""
echo "[4/5] Installing sync service..."
mkdir -p /opt/edudisplej

if [ -f "$SCRIPT_DIR/init/edudisplej_sync_service.sh" ]; then
    cp "$SCRIPT_DIR/init/edudisplej_sync_service.sh" /opt/edudisplej/
    chmod +x /opt/edudisplej/edudisplej_sync_service.sh
    echo "✓ Sync service script installed"
fi

if [ -f "$SCRIPT_DIR/init/edudisplej-sync.service" ]; then
    cp "$SCRIPT_DIR/init/edudisplej-sync.service" /etc/systemd/system/
    systemctl daemon-reload
    echo "✓ Systemd service installed"
fi

# Configure Apache
echo ""
echo "[5/5] Configuring web server..."
a2enmod rewrite > /dev/null 2>&1 || true
systemctl restart apache2 > /dev/null 2>&1 || true
echo "✓ Apache configured and restarted"

# Print summary
echo ""
echo "======================================"
echo "Installation Complete!"
echo "======================================"
echo ""
echo "Access points:"
echo "  Control Panel: http://localhost/control_edudisplej_sk/admin.php"
echo "  Website: http://localhost/www_edudisplej_sk/"
echo "  Dashboard: http://localhost/dashboard_edudisplej_sk/"
echo ""
echo "Default admin credentials:"
echo "  Username: admin"
echo "  Password: admin123"
echo "  ⚠️  CHANGE THIS PASSWORD IMMEDIATELY!"
echo ""
echo "To enable sync service on this machine:"
echo "  sudo systemctl enable edudisplej-sync.service"
echo "  sudo systemctl start edudisplej-sync.service"
echo ""
echo "For detailed information, see:"
echo "  $WEB_ROOT/control_edudisplej_sk/README.md"
echo ""
