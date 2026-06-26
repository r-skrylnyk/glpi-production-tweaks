#!/bin/bash
set -e

GLPI_DIR="/var/www/html/glpi"

echo "==> Generating database configuration..."
php /docker/generate-config.php
chown www-data:www-data "${GLPI_DIR}/config/config_db.php"
chmod 640 "${GLPI_DIR}/config/config_db.php"

# If the production crypto key is provided, restore it.
# This is required to decrypt encrypted fields from an existing database.
# Set the GLPI_CRYPT_KEY secret to the contents of your production glpicrypt.key file.
if [ -n "${GLPI_CRYPT_KEY}" ]; then
    echo "==> Restoring crypto key..."
    printf '%s' "${GLPI_CRYPT_KEY}" > "${GLPI_DIR}/config/glpicrypt.key"
    chown www-data:www-data "${GLPI_DIR}/config/glpicrypt.key"
    chmod 640 "${GLPI_DIR}/config/glpicrypt.key"
fi

echo "==> Ensuring GLPI data directories exist with correct permissions..."
for dir in \
    files \
    files/_dumps \
    files/_graphs \
    files/_lock \
    files/_log \
    files/_pictures \
    files/_plugins \
    files/_rss \
    files/_sessions \
    files/_tmp \
    files/_uploads \
    files/_cache \
    files/_inventories \
    plugins \
    config \
    marketplace; do
    mkdir -p "${GLPI_DIR}/${dir}"
    chown -R www-data:www-data "${GLPI_DIR}/${dir}"
done

echo "==> Starting Apache..."
exec apache2-foreground
