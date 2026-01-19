#!/bin/bash
# GLPI Customization Deployer

# Paths (change for you)
GLPI_ROOT="/var/www/html/glpi"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "Starting deployment of custom mods..."

# 1. Check availability file Computer.php
if [ -f "$REPO_ROOT/core-mods/src/Computer.php" ]; then
    echo " -> Replacing Computer.php..."
    # Backup file if not exists
    if [ ! -f "$GLPI_ROOT/src/Computer.php.bak" ]; then
        cp "$GLPI_ROOT/src/Computer.php" "$GLPI_ROOT/src/Computer.php.bak"
        echo "    (Original backed up to Computer.php.bak)"
    fi
    
    # Copy custom file
    cp "$REPO_ROOT/core-mods/src/Computer.php" "$GLPI_ROOT/src/Computer.php"
    
    # Paste correct ownership
    chown www-data:www-data "$GLPI_ROOT/src/Computer.php"
else
    echo " Error: Custom Computer.php not found in repo!"
fi

# 2. Clear Cache (Critical Step)
echo " -> Clearing GLPI Cache..."
sudo -u www-data php "$GLPI_ROOT/bin/console" cache:clear

echo "Deployment finished successfully!"