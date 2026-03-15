#!/bin/bash

# Uptime Monitor Setup Script for Raspberry Pi (Run from monitor directory)
# This script sets up the uptime monitor system with fresh data

set -e  # Exit on any error

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration - we're already in the monitor directory
MONITOR_DIR=$(pwd)
PI_IP=$(hostname -I | awk '{print $1}')  # Get first IP address

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Uptime Monitor Setup Script${NC}"
echo -e "${GREEN}(In-Place Configuration)${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check if running as root/sudo
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Please run this script with sudo${NC}"
    exit 1
fi

# Verify we're in the right directory
if [[ "$MONITOR_DIR" != *"/monitor"* ]]; then
    echo -e "${YELLOW}Warning: Current directory doesn't contain 'monitor' in path${NC}"
    echo -e "${YELLOW}Current directory: $MONITOR_DIR${NC}"
    read -p "Are you sure this is the correct directory? (y/n): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

echo -e "${YELLOW}Working directory: ${MONITOR_DIR}${NC}"
echo -e "${YELLOW}Detected Pi IP: ${PI_IP}${NC}"
echo ""
read -p "Continue with setup? (y/n): " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    exit 1
fi

# Step 1: Check for required files
echo -e "${GREEN}[1/9] Checking for required files...${NC}"
REQUIRED_FILES=("api.php" "index.html" "status.php" "data.html" "config.html")
MISSING_FILES=()

for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        MISSING_FILES+=("$file")
    fi
done

if [ ${#MISSING_FILES[@]} -ne 0 ]; then
    echo -e "${RED}Missing required files:${NC}"
    printf '%s\n' "${MISSING_FILES[@]}"
    echo -e "${YELLOW}Please ensure all files are in this directory before running this script.${NC}"
    exit 1
fi
echo "  All required files present"

# Step 2: Create directory structure
echo -e "${GREEN}[2/9] Creating directory structure...${NC}"
mkdir -p uptime_data/checks
echo "  Directories created"

# Step 3: Create or move config.ini
echo -e "${GREEN}[3/9] Setting up config.ini...${NC}"
if [ -f "config.ini" ] && [ ! -f "uptime_data/config.ini" ]; then
    echo "  Moving config.ini to uptime_data/"
    mv config.ini uptime_data/
elif [ ! -f "uptime_data/config.ini" ]; then
    echo "  Creating default config.ini in uptime_data/"
    cat > uptime_data/config.ini << 'EOF'
[general]
alerts_enabled    = "true"
alert_cooldown    = "30"
alert_hours_start = ""
alert_hours_end   = ""

[security]
api_key     = ""
allowed_ips = ""

[slack_bot]
bot_token         = ""
channel           = "#monitoring"
dashboard_url     = ""
alert_on_down     = "true"
alert_on_recovery = "true"

[slack]
webhook_url = ""

[custom_webhook]
url               = ""
method            = "POST"
content_type      = "json"
body_template     = ""
headers           = ""
timeout           = "5"
retry_count       = "2"
basic_auth_user   = ""
basic_auth_pass   = ""
alert_on_down     = "true"
alert_on_recovery = "true"

[email]
enabled           = "false"
recipients        = ""
from_email        = ""
from_name         = "Uptime Monitor"
alert_on_down     = "true"
alert_on_recovery = "true"

[textbee]
enabled            = "false"
api_key            = ""
device_id          = ""
recipients         = ""
high_priority_only = "false"
include_url        = "true"
alert_on_down      = "true"
alert_on_recovery  = "true"
EOF
else
    echo "  config.ini already exists in uptime_data/"
fi

# Step 4: Clean old data
echo -e "${GREEN}[4/9] Cleaning old data files...${NC}"
rm -f uptime_data/*.json 2>/dev/null || true
rm -f uptime_data/*.log 2>/dev/null || true
rm -f uptime_data/checks/*.json 2>/dev/null || true
rm -f uptime_data/checks/*.txt 2>/dev/null || true
echo "  Old data files removed"

# Step 5: Update status.php with correct API URL
echo -e "${GREEN}[5/9] Updating status.php with correct API URL...${NC}"
sed -i "s|https://openxtalk.net/monitor/api.php|./api.php|g" status.php
echo "  Updated API URL in status.php"

# Step 6: Update config.ini with Pi's dashboard URL
echo -e "${GREEN}[6/9] Updating config.ini with dashboard URL...${NC}"
sed -i "s|dashboard_url.*=.*\"\"|dashboard_url = \"http://${PI_IP}/monitor\"|g" uptime_data/config.ini
echo "  Updated dashboard URL to: http://${PI_IP}/monitor"

# Step 7: Set permissions
echo -e "${GREEN}[7/9] Setting file permissions...${NC}"
chown -R www-data:www-data .
chmod 755 .
chmod 755 uptime_data/
chmod 755 uptime_data/checks/
chmod 644 *.php
chmod 644 *.html
chmod 666 uptime_data/config.ini
echo "  Permissions set"

# Step 8: Install PHP dependencies
echo -e "${GREEN}[8/9] Checking PHP dependencies...${NC}"
PACKAGES_TO_INSTALL=""

for pkg in php-curl php-json php-mbstring; do
    if ! dpkg -l | grep -q "^ii  $pkg "; then
        PACKAGES_TO_INSTALL="$PACKAGES_TO_INSTALL $pkg"
    fi
done

if [ -n "$PACKAGES_TO_INSTALL" ]; then
    echo "  Installing required PHP packages:$PACKAGES_TO_INSTALL"
    apt-get update > /dev/null 2>&1
    apt-get install -y $PACKAGES_TO_INSTALL > /dev/null 2>&1
else
    echo "  All required PHP packages are already installed"
fi

# Enable ping for www-data user
echo -e "${GREEN}Enabling ping capability for www-data...${NC}"
setcap cap_net_raw+ep /bin/ping 2>/dev/null || {
    echo -e "${YELLOW}  Warning: Could not set ping capability. PING monitoring might not work.${NC}"
}

# Step 9: Setup cron job
echo -e "${GREEN}[9/9] Setting up cron job for automatic monitoring...${NC}"
CRON_JOB="*/5 * * * * /usr/bin/curl -s http://localhost/monitor/api.php?path=check-all -X POST > /dev/null 2>&1"
(crontab -u www-data -l 2>/dev/null | grep -v "monitor/api.php" ; echo "$CRON_JOB") | crontab -u www-data -
echo "  Cron job added (runs every 5 minutes)"

# Create .htaccess for security
echo -e "${GREEN}Creating .htaccess for security...${NC}"
cat > .htaccess << 'EOF'
# Protect config files
<Files "config.ini">
    Require all denied
</Files>

# Protect data files
<FilesMatch "\.(json|log)$">
    Require all denied
</FilesMatch>

# Protect the uptime_data directory from direct access
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^uptime_data/ - [F,L]
</IfModule>
EOF
chmod 644 .htaccess

# Also create .htaccess in uptime_data
cat > uptime_data/.htaccess << 'EOF'
# Deny all direct access to this directory
Require all denied
EOF
chmod 644 uptime_data/.htaccess

# Restart Apache
echo -e "${GREEN}Restarting Apache...${NC}"
systemctl restart apache2

# Test the installation
echo ""
echo -e "${GREEN}Testing installation...${NC}"
sleep 2
if curl -s -f http://localhost/monitor/api.php?path=health > /dev/null 2>&1; then
    echo -e "${GREEN}✓ API is responding correctly${NC}"
    
    if [ -f uptime_data/config.ini ]; then
        echo -e "${GREEN}✓ config.ini is in the correct location${NC}"
    else
        echo -e "${RED}✗ config.ini not found in uptime_data/${NC}"
    fi
else
    echo -e "${RED}✗ API test failed. Check Apache error logs: sudo tail -f /var/log/apache2/error.log${NC}"
fi

# Display file structure
echo ""
echo -e "${GREEN}File structure:${NC}"
echo "  ${MONITOR_DIR}/"
echo "  ├── api.php"
echo "  ├── index.html"
echo "  ├── status.php"
echo "  ├── data.html"
echo "  ├── config.html"
echo "  ├── .htaccess"
echo "  └── uptime_data/"
echo "      ├── config.ini ${YELLOW}← Configuration file${NC}"
echo "      ├── .htaccess"
echo "      └── checks/"

# Final message
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Installation Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "Access your uptime monitor at:"
echo -e "  ${YELLOW}http://${PI_IP}/monitor/${NC}"
echo ""
echo -e "Other pages:"
echo -e "  Status Page: ${YELLOW}http://${PI_IP}/monitor/status.php${NC}"
echo -e "  Config Page: ${YELLOW}http://${PI_IP}/monitor/config.html${NC}"
echo -e "  Data Management: ${YELLOW}http://${PI_IP}/monitor/data.html${NC}"
echo ""
echo -e "To configure alerts (Slack, Email, SMS, etc.):"
echo -e "  Go to: ${YELLOW}http://${PI_IP}/monitor/config.html${NC}"
echo ""
echo -e "${YELLOW}Note: The system will start monitoring after you add monitors via the dashboard.${NC}"
echo -e "${YELLOW}Automatic checks will run every 5 minutes via cron.${NC}"
