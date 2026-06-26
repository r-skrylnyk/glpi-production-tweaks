<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ============================================
// CORS HEADERS
// ============================================
// Allow from any origin
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400'); // cache for 1 day
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

    exit(0); // <-- Attention: Exit, without check token
}

header("Content-Type: application/json; charset=UTF-8");
// Generate a secure token with: openssl rand -hex 32
// Or use: bin2hex(random_bytes(32))
define('API_SECRET_TOKEN', 'YOUR_SECURE_TOKEN_HERE');

// Check for Authorization header or token parameter
$providedToken = null;

// Method 1: Authorization header (recommended)
$headers = getallheaders();
if (isset($headers['Authorization'])) {
    $providedToken = str_replace('Bearer ', '', $headers['Authorization']);
} elseif (isset($headers['authorization'])) {
    $providedToken = str_replace('Bearer ', '', $headers['authorization']);
}

// Method 2: Query parameter (fallback)
if (!$providedToken) {
    $providedToken = $_GET['token'] ?? '';
}

// Verify token
if (!hash_equals(API_SECRET_TOKEN, $providedToken)) {
    http_response_code(401);
    echo json_encode([
        "error" => "Unauthorized",
        "message" => "Invalid or missing API token"
    ]);
    exit;
}

// Rate limiting: Track requests per IP (basic protection)
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitFile = sys_get_temp_dir() . '/glpi_api_rate_' . md5($clientIp) . '.txt';
$maxRequestsPerMinute = 60;

if (file_exists($rateLimitFile)) {
    $lastRequests = json_decode(file_get_contents($rateLimitFile), true) ?? [];
    $recentRequests = array_filter($lastRequests, function($time) {
        return $time > (time() - 60);
    });

    if (count($recentRequests) >= $maxRequestsPerMinute) {
        http_response_code(429);
        echo json_encode([
            "error" => "Rate limit exceeded",
            "message" => "Too many requests. Please try again later."
        ]);
        exit;
    }

    $recentRequests[] = time();
    file_put_contents($rateLimitFile, json_encode($recentRequests));
} else {
    file_put_contents($rateLimitFile, json_encode([time()]));
}

// ============================================
// Database Connection
// ============================================
$host = '127.0.0.1';
$db   = 'your_glpi_database';
$user = 'your_db_user';
$pass = 'your_db_password';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

// ============================================
// Input Validation
// ============================================
$userEmail = $_GET['email'] ?? '';

// Validate email format
if (empty($userEmail)) {
    http_response_code(400);
    echo json_encode(["error" => "Email parameter is required"]);
    exit;
}

if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid email format"]);
    exit;
}

// Optional: Restrict to specific domain
$allowedDomains = ['example.com', 'example.dev'];
$emailDomain = substr(strrchr($userEmail, "@"), 1);
if (!in_array($emailDomain, $allowedDomains)) {
    http_response_code(403);
    echo json_encode(["error" => "Access denied for this domain"]);
    exit;
}

// Debug mode (only if explicitly enabled)
$debug = $_GET['debug'] ?? false;

if ($debug) {
    $sqlDebug = "SELECT id, firstname, realname, name
                 FROM your_glpi_users
                 WHERE name LIKE ?";
    $stmtDebug = $pdo->prepare($sqlDebug);
    $stmtDebug->execute(['%' . $userEmail . '%']);
    echo json_encode(["debug_users" => $stmtDebug->fetchAll()], JSON_PRETTY_PRINT);
    exit;
}

// ============================================
// Fetch User Data
// ============================================
$sqlUser = "
    SELECT id, firstname, realname, name
    FROM your_glpi_users
    WHERE name = ?
    LIMIT 1
";

$stmt = $pdo->prepare($sqlUser);
$stmt->execute([$userEmail]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode([
        "error" => "User not found",
        "email" => $userEmail
    ]);
    exit;
}

$userId = $user['id'];

// Initialize response
$response = [
    "firstName" => $user['firstname'] ?? '',
    "lastName"  => $user['realname'] ?? '',
    "company"   => "YOUR_COMPANY",
    "avatarUrl" => null,
    "items"     => []
];

// Icon mapping for different asset types
$iconMapping = [
    'PowerStation' => 'battery-charging',
    'Chairs' => 'armchair',
    'Tables' => 'picnic-table',
    'Computer' => 'laptop',
    'Monitor' => 'monitor',
    'Phone' => 'smartphone',
    'Peripheral' => 'usb'
];

// Type name mapping
$typeMapping = [
    'PowerStation' => 'Power station',
    'Chairs' => 'Chair',
    'Tables' => 'Table'
];

// ============================================
// Fetch Custom Assets (Power Stations, Chairs, etc.)
// ============================================
$sqlAssets = "
    SELECT
        a.name,
        a.serial,
        a.otherserial,
        ad.system_name,
        ad.label,
        i.warranty_date
    FROM glpi_assets_assets a
    LEFT JOIN glpi_assets_assetdefinitions ad ON a.assets_assetdefinitions_id = ad.id
    LEFT JOIN glpi_infocoms i ON i.items_id = a.id AND i.itemtype = 'Glpi\\\\Asset\\\\Asset'
    WHERE a.users_id = ? AND a.is_deleted = 0
    ORDER BY ad.label, a.name
";

$stmtAssets = $pdo->prepare($sqlAssets);
$stmtAssets->execute([$userId]);
$assets = $stmtAssets->fetchAll();

foreach ($assets as $asset) {
    $systemName = $asset['system_name'] ?? 'Unknown';
    $displayName = $asset['name'];

    if (!empty($asset['otherserial'])) {
        $displayName .= ' (' . $asset['otherserial'] . ')';
    } elseif (!empty($asset['serial'])) {
        $displayName .= ' (' . $asset['serial'] . ')';
    }

    $response['items'][] = [
        "name" => $displayName,
        "type" => $typeMapping[$systemName] ?? $asset['label'] ?? $systemName,
        "iconSvg" => $iconMapping[$systemName] ?? 'package',
        "guaranteeExpire" => $asset['warranty_date'] ?? "",
        "subItems" => []
    ];
}

// ============================================
// Fetch Monitors
// ============================================
$sqlMonitors = "
    SELECT
        m.name,
        m.serial,
        i.warranty_date
    FROM glpi_monitors m
    LEFT JOIN glpi_infocoms i ON i.items_id = m.id AND i.itemtype = 'Monitor'
    WHERE m.users_id = ? AND m.is_deleted = 0
    ORDER BY m.name
";

$stmtMon = $pdo->prepare($sqlMonitors);
$stmtMon->execute([$userId]);
$monitors = $stmtMon->fetchAll();

foreach ($monitors as $mon) {
    $response['items'][] = [
        "name" => $mon['name'],
        "type" => "Monitor",
        "iconSvg" => "monitor",
        "guaranteeExpire" => $mon['warranty_date'] ?? "",
        "subItems" => []
    ];
}

// ============================================
// Fetch Computers
// ============================================
$sqlComputers = "
    SELECT
        c.name,
        c.serial,
        i.warranty_date
    FROM glpi_computers c
    LEFT JOIN glpi_infocoms i ON i.items_id = c.id AND i.itemtype = 'Computer'
    WHERE c.users_id = ? AND c.is_deleted = 0
    ORDER BY c.name
";

$stmtComp = $pdo->prepare($sqlComputers);
$stmtComp->execute([$userId]);
$computers = $stmtComp->fetchAll();

foreach ($computers as $comp) {
    $response['items'][] = [
        "name" => $comp['name'],
        "type" => "Laptop",
        "iconSvg" => "laptop",
        "guaranteeExpire" => $comp['warranty_date'] ?? "",
        "subItems" => []
    ];
}

// ============================================
// Fetch Phones
// ============================================
$sqlPhones = "
    SELECT
        p.name,
        p.serial,
        i.warranty_date
    FROM glpi_phones p
    LEFT JOIN glpi_infocoms i ON i.items_id = p.id AND i.itemtype = 'Phone'
    WHERE p.users_id = ? AND p.is_deleted = 0
    ORDER BY p.name
";

$stmtPhone = $pdo->prepare($sqlPhones);
$stmtPhone->execute([$userId]);
$phones = $stmtPhone->fetchAll();

foreach ($phones as $phone) {
    $response['items'][] = [
        "name" => $phone['name'],
        "type" => "Phone",
        "iconSvg" => "smartphone",
        "guaranteeExpire" => $phone['warranty_date'] ?? "",
        "subItems" => []
    ];
}

// ============================================
// Fetch Peripherals
// ============================================
$sqlPeripherals = "
    SELECT
        p.name,
        p.serial,
        i.warranty_date
    FROM glpi_peripherals p
    LEFT JOIN glpi_infocoms i ON i.items_id = p.id AND i.itemtype = 'Peripheral'
    WHERE p.users_id = ? AND p.is_deleted = 0
    ORDER BY p.name
";

$stmtPeriph = $pdo->prepare($sqlPeripherals);
$stmtPeriph->execute([$userId]);
$peripherals = $stmtPeriph->fetchAll();

foreach ($peripherals as $periph) {
    $response['items'][] = [
        "name" => $periph['name'],
        "type" => "Peripheral",
        "iconSvg" => "usb",
        "guaranteeExpire" => $periph['warranty_date'] ?? "",
        "subItems" => []
    ];
}

// ============================================
// Output Response
// ============================================
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);