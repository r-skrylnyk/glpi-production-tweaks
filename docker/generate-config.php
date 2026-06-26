<?php
/**
 * Generates GLPI config/config_db.php from environment variables.
 * Called by docker/entrypoint.sh on every container startup.
 * Uses var_export() to safely handle passwords with special characters.
 */

$host = getenv('GLPI_DB_HOST') ?: 'mariadb';
$user = getenv('GLPI_DB_USER') ?: 'glpi';
$pass = getenv('GLPI_DB_PASSWORD') ?: '';
$name = getenv('GLPI_DB_NAME') ?: 'glpi';

if (empty($pass)) {
    fwrite(STDERR, "ERROR: GLPI_DB_PASSWORD environment variable is not set.\n");
    exit(1);
}

$config = sprintf(
    "<?php\nclass DB extends DBmysql {\n" .
    "   public \$dbhost            = %s;\n" .
    "   public \$dbuser            = %s;\n" .
    "   public \$dbpassword        = %s;\n" .
    "   public \$dbdefault         = %s;\n" .
    "   public \$use_utf8mb4       = true;\n" .
    "   public \$allow_myisam      = false;\n" .
    "   public \$allow_datetime    = false;\n" .
    "   public \$allow_signed_keys = false;\n" .
    "}\n",
    var_export($host, true),
    var_export($user, true),
    var_export($pass, true),
    var_export($name, true)
);

$configFile = '/var/www/html/glpi/config/config_db.php';
if (file_put_contents($configFile, $config) === false) {
    fwrite(STDERR, "ERROR: Could not write {$configFile}\n");
    exit(1);
}

echo "config_db.php generated (host={$host}, db={$name})\n";
