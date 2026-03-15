#!/bin/bash

# Uptime Monitor Setup Script - Preserves existing config
# Run this from /var/www/html/monitor/

set -e

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Get current directory and IP
CURRENT_DIR=$(pwd)
PI_IP=$(hostname -I | awk '{print $1}')

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Uptime Monitor Setup (Preserve Config)${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Please run with sudo${NC}"
    exit 1
fi

echo -e "${YELLOW}Current directory: ${CURRENT_DIR}${NC}"
echo -e "${YELLOW}Pi IP: ${PI_IP}${NC}"
echo ""

# Step 1: Create directories
echo -e "${GREEN}[1/7] Creating directories...${NC}"
mkdir -p uptime_data/checks

# Step 2: Move config.ini if needed
echo -e "${GREEN}[2/7] Setting up config.ini...${NC}"
if [ -f "config.ini" ]; then
    echo "  Moving config.ini to uptime_data/"
    mv config.ini uptime_data/config.ini
elif [ ! -f "uptime_data/config.ini" ]; then
    echo -e "${RED}  No config.ini found!${NC}"
    exit 1
else
    echo "  config.ini already in uptime_data/"
fi

# Step 3: Clean old data (but keep config!)
echo -e "${GREEN}[3/7] Cleaning old data (keeping config)...${NC}"
find uptime_data -name "*.json" -delete 2>/dev/null || true
find uptime_data -name "*.log" -delete 2>/dev/null || true
find uptime_data/checks -name "*.json" -delete 2>/dev/null || true
find uptime_data/checks -name "*.txt" -delete 2>/dev/null || true
echo "  Data cleaned"

# Step 4: Update status.php
echo -e "${GREEN}[4/7] Updating status.php...${NC}"
sed -i "s|https://openxtalk.net/monitor/api.php|./api.php|g" status.php
echo "  API URL updated"

# Step 5: Fix dashboard URL in config
echo -e "${GREEN}[5/7] Fixing dashboard URL in config...${NC}"
# Remove /index.html from the dashboard URL if present
sed -i "s|/monitor/index.html|/monitor|g" uptime_data/config.ini
echo "  Dashboard URL fixed"

# Step 6: Set permissions
echo -e "${GREEN}[6/7] Setting permissions...${NC}"
chown -R www-data:www-data .
chmod 755 .
chmod 755 uptime_data/
chmod 755 uptime_data/checks/
chmod 644 *.php *.html 2>/dev/null || true
chmod 666 uptime_data/config.ini
echo "  Permissions set"

# Step 7: Setup cron
echo -e "${GREEN}[7/7] Setting up cron job...${NC}"
CRON_JOB="*/5 * * * * /usr/bin/curl -s http://localhost/monitor/api.php?path=check-all -X POST > /dev/null 2>&1"
(crontab -u www-data -l 2>/dev/null | grep -v "monitor/api.php" ; echo "$CRON_JOB") | crontab -u www-data -
echo "  Cron job added"

# Enable ping
setcap cap_net_raw+ep /bin/ping 2>/dev/null || echo -e "${YELLOW}  Warning: Ping capability not set${NC}"

# Create .htaccess
cat > .htaccess << 'EOF'
<Files "config.ini">
    Require all denied
</Files>
<FilesMatch "\.(json|log)$">
    Require all denied
</FilesMatch>
EOF
chmod 644 .htaccess

# Restart Apache
systemctl restart apache2

# Test
echo ""
echo -e "${GREEN}Testing...${NC}"
if curl -s -f http://localhost/monitor/api.php?path=health > /dev/null 2>&1; then
    echo -e "${GREEN}✓ API working${NC}"
else
    echo -e "${RED}✗ API test failed${NC}"
fi

if [ -f uptime_data/config.ini ]; then
    echo -e "${GREEN}✓ config.ini in place${NC}"
    
    # Show current config status
    echo ""
    echo -e "${GREEN}Configuration Status:${NC}"
    if grep -q "xoxb-" uptime_data/config.ini; then
        echo "  ✓ Slack configured"
    fi
    if grep -q 'enabled = true' uptime_data/config.ini; then
        echo "  ✓ TextBee SMS enabled"
    fi
fi

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Setup Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "Monitor URL: ${YELLOW}http://${PI_IP}/monitor/${NC}"
echo -e "Status Page: ${YELLOW}http://${PI_IP}/monitor/status.php${NC}"
echo -e "Config Page: ${YELLOW}http://${PI_IP}/monitor/config.html${NC}"
echo ""
echo -e "${YELLOW}Your Slack and TextBee settings have been preserved.${NC}"

