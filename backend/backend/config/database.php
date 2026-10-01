<?php
/**
 * Force Asia/Kolkata (IST) timezone for all date/time operations.
 * This ensures punch-in/out times stored and returned by the API
 * reflect Indian Standard Time (UTC+5:30).
 */
date_default_timezone_set('Asia/Kolkata');

/**
 * SiteTracker - Database Configuration & Core Helpers
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'sitetracker');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    try {
        $dsn = "mysql:unix_socket=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock;dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e2) {
        die("Database Connection Error: " . htmlspecialchars($e2->getMessage()));
    }
}

// Ensure the tables exist and seed demo records
function init_database($pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `email` VARCHAR(100) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `plain_password` VARCHAR(255) NULL,
            `full_name` VARCHAR(100) NOT NULL,
            `role` VARCHAR(50) NOT NULL DEFAULT 'Super Admin',
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `last_login` DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `sites` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(150) NOT NULL,
            `client_name` VARCHAR(150) DEFAULT 'Standard Project',
            `address` VARCHAR(255) NOT NULL,
            `latitude` DECIMAL(10, 8) NOT NULL,
            `longitude` DECIMAL(11, 8) NOT NULL,
            `radius_meters` INT NOT NULL DEFAULT 300,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `attendance` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `supervisor_name` VARCHAR(100) NOT NULL,
            `employee_id` VARCHAR(50) NOT NULL,
            `site_name` VARCHAR(150) NOT NULL,
            `punch_in_time` DATETIME NOT NULL,
            `punch_out_time` DATETIME NULL,
            `punch_in_latitude` DECIMAL(10, 8) NOT NULL,
            `punch_in_longitude` DECIMAL(11, 8) NOT NULL,
            `distance_meters` INT NOT NULL DEFAULT 0,
            `is_within_geofence` TINYINT(1) NOT NULL DEFAULT 1,
            `photo_url` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql);

    // Auto-migrate `plain_password` column if not present in existing users table
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'plain_password'")->fetch();
        if (!$checkCol) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `plain_password` VARCHAR(255) NULL AFTER `password`");
        }
    } catch (PDOException $e) {
        // Ignored if column already exists
    }

    // Seed default admin if missing
    $stmt = $pdo->query("SELECT COUNT(*) FROM `users`");
    if ($stmt->fetchColumn() == 0) {
        $insert = $pdo->prepare("
            INSERT INTO `users` (`username`, `email`, `password`, `plain_password`, `full_name`, `role`, `status`) 
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        $insert->execute([
            'admin',
            'admin@sitetracker.com',
            password_hash('admin123', PASSWORD_DEFAULT),
            'admin123',
            'Chief Operations Admin',
            'Super Admin'
        ]);
    } else {
        // Backfill admin plain_password if null
        $pdo->exec("UPDATE `users` SET `plain_password` = 'admin123' WHERE `username` = 'admin' AND (`plain_password` IS NULL OR `plain_password` = '')");
        // Backfill any other existing accounts without plain_password with a default known password
        $pdo->exec("UPDATE `users` SET `plain_password` = 'pass123' WHERE `plain_password` IS NULL OR `plain_password` = ''");
    }

    // One-time initialization guard using system_meta
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_meta` (
            `meta_key` VARCHAR(50) PRIMARY KEY,
            `meta_value` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $isSeeded = $pdo->query("SELECT `meta_value` FROM `system_meta` WHERE `meta_key` = 'initial_seed_done'")->fetchColumn();

    if (!$isSeeded) {
        $pdo->exec("INSERT INTO `system_meta` (`meta_key`, `meta_value`) VALUES ('initial_seed_done', '1') ON DUPLICATE KEY UPDATE `meta_value` = '1'");

        // Seed initial sites ONLY ONCE on very first clean setup
        $stmtSites = $pdo->query("SELECT COUNT(*) FROM `sites`");
        if ($stmtSites->fetchColumn() == 0) {
            $insertSite = $pdo->prepare("
                INSERT IGNORE INTO `sites` (`code`, `name`, `client_name`, `address`, `latitude`, `longitude`, `radius_meters`, `status`)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $insertSite->execute(['GVA-KCH-01', 'Green Valley Apartment', 'Green Valley Developers', 'Marine Drive, Kochi, Kerala', 9.9700, 76.2800, 300]);
            $insertSite->execute(['PR-KKN-02', 'Palm Residency', 'Palm Infra Group', 'Infopark Road, Kakkanad, Kochi', 10.0159, 76.3419, 400]);
            $insertSite->execute(['LV-TRP-03', 'Lakeview Villas', 'Lakeview Habitats', 'Hill Palace Area, Thrippunithura, Kochi', 9.9536, 76.3486, 350]);
        }

        // Seed initial attendance logs ONLY ONCE on very first clean setup
        $stmtAtt = $pdo->query("SELECT COUNT(*) FROM `attendance`");
        if ($stmtAtt->fetchColumn() == 0) {
            $insertAtt = $pdo->prepare("
                INSERT INTO `attendance` (`supervisor_name`, `employee_id`, `site_name`, `punch_in_time`, `punch_out_time`, `punch_in_latitude`, `punch_in_longitude`, `distance_meters`, `is_within_geofence`, `photo_url`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertAtt->execute([
                'Vikram Sharma', 'SUP-4089', 'Green Valley Apartment',
                date('Y-m-d 09:00:00'), null,
                9.9705, 76.2802, 42, 1,
                'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=300&fit=crop&q=80'
            ]);
            $insertAtt->execute([
                'Rahul Nair', 'SUP-5021', 'Palm Residency',
                date('Y-m-d 08:30:00', strtotime('-1 day')), date('Y-m-d 17:45:00', strtotime('-1 day')),
                10.0158, 76.3420, 22, 1,
                'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=300&fit=crop&q=80'
            ]);
        }
    }
}

init_database($pdo);

// CORS headers for API requests
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if current user is logged in
 */
function get_auth_token_payload() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        $parts = explode('.', $m[1]);
        if (!empty($parts[0])) {
            $json = json_decode(base64_decode($parts[0]), true);
            if (is_array($json) && !empty($json['user_id'])) {
                return $json;
            }
        }
    }
    return null;
}

function is_logged_in() {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        return true;
    }
    return get_auth_token_payload() !== null;
}

/**
 * Protect admin pages
 */
function require_login($redirectUrl = 'login.php') {
    if (!is_logged_in()) {
        header("Location: " . $redirectUrl);
        exit;
    }
}

/**
 * Get logged in user details
 */
function current_user() {
    global $pdo;
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        return [
            'id'          => $_SESSION['user_id'] ?? null,
            'username'    => $_SESSION['username'] ?? '',
            'email'       => $_SESSION['email'] ?? '',
            'full_name'   => $_SESSION['full_name'] ?? 'Operations Admin',
            'role'        => $_SESSION['role'] ?? 'Super Admin',
            'employeeId'  => 'ADM-' . ($_SESSION['user_id'] ?? 1),
            'department'  => 'Operations HQ',
            'initials'    => $_SESSION['user_initials'] ?? 'OA'
        ];
    }
    $token = get_auth_token_payload();
    if ($token && !empty($token['user_id'])) {
        if ($pdo) {
            $stmt = $pdo->prepare('SELECT * FROM `users` WHERE `id` = ? LIMIT 1');
            $stmt->execute([$token['user_id']]);
            $u = $stmt->fetch();
            if ($u) {
                return [
                    'id'          => (string)$u['id'],
                    'username'    => $u['username'],
                    'email'       => $u['email'],
                    'full_name'   => $u['full_name'],
                    'role'        => $u['role'],
                    'phone'       => $u['phone'] ?? '',
                    'employeeId'  => $u['employee_id'] ?? ('SUP-' . $u['id']),
                    'department'  => $u['department'] ?? 'Site Operations',
                    'initials'    => strtoupper(substr($u['full_name'], 0, 2))
                ];
            }
        }
        return [
            'id'          => (string)$token['user_id'],
            'username'    => 'supervisor',
            'email'       => $token['email'] ?? '',
            'full_name'   => 'Vikram Sharma',
            'role'        => $token['role'] ?? 'supervisor',
            'employeeId'  => 'SUP-4089',
            'department'  => 'Structural Operations',
            'initials'    => 'VS'
        ];
    }
    return null;
}

/**
 * Send JSON response
 */
function send_json($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

/**
 * Parse input JSON or POST parameters
 */
function get_request_data() {
    $raw = file_get_contents('php://input');
    $json = [];
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }
    return array_merge($_REQUEST, $json);
}
