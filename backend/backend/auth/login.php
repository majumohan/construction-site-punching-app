<?php
/**
 * SiteTracker - API Authentication Endpoint
 * Supports both Web Admin session and Flutter Mobile App (JSON / Bearer Token)
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Handle logout action
if ($action === 'logout' || ($method === 'POST' && isset($_GET['logout']))) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    send_json(['success' => true, 'message' => 'Logged out successfully.']);
}

// Handle login verification (POST)
if ($method === 'POST') {
    $data = get_request_data();
    // Accept identifier (mobile app), username, email, or phone
    $loginInput = trim($data['identifier'] ?? $data['username'] ?? $data['email'] ?? $data['phone'] ?? '');
    $password = $data['password'] ?? '';

    if (empty($loginInput) || empty($password)) {
        send_json(['success' => false, 'message' => 'Username/Email/Phone and Password are required.'], 422);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM `users` 
            WHERE `username` = ? OR `email` = ? OR (`phone` IS NOT NULL AND `phone` = ?) 
            LIMIT 1
        ");
        $stmt->execute([$loginInput, $loginInput, $loginInput]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] !== 'active') {
                send_json(['success' => false, 'message' => 'Account is inactive. Please contact system admin.'], 403);
            }

            // Update last login
            $update = $pdo->prepare("UPDATE `users` SET `last_login` = NOW() WHERE `id` = ?");
            $update->execute([$user['id']]);

            // Form initials
            $parts = explode(' ', trim($user['full_name']));
            $initials = count($parts) >= 2 
                ? strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1))
                : strtoupper(substr($user['full_name'], 0, 2));

            // Set session for web admin
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_initials'] = $initials ?: 'AD';

            // Generate token for mobile app
            $tokenPayload = base64_encode(json_encode([
                'user_id' => $user['id'],
                'email'   => $user['email'],
                'role'    => $user['role'],
                'time'    => time(),
                'exp'     => time() + (86400 * 30) // 30 days
            ]));
            $tokenSig = hash_hmac('sha256', $tokenPayload, 'sitetracker_secret_jwt_2026');
            $authToken = "{$tokenPayload}.{$tokenSig}";

            $normalizedRole = (stripos($user['role'], 'admin') !== false) ? 'admin' : 'supervisor';

            send_json([
                'success' => true,
                'message' => 'Authentication successful.',
                'token'   => $authToken,
                'user'    => [
                    'id'          => (string)$user['id'],
                    'name'        => $user['full_name'],
                    'full_name'   => $user['full_name'],
                    'username'    => $user['username'],
                    'email'       => $user['email'],
                    'phone'       => $user['phone'] ?? '',
                    'role'        => $normalizedRole,
                    'employeeId'  => $user['employee_id'] ?? ('SUP-' . str_pad($user['id'], 4, '0', STR_PAD_LEFT)),
                    'department'  => $user['department'] ?? 'Site Operations',
                    'initials'    => $initials
                ]
            ]);
        } else {
            send_json(['success' => false, 'message' => 'Invalid credentials. Please check your username/email and password.'], 401);
        }
    } catch (PDOException $e) {
        send_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// Check current auth status if GET
if ($method === 'GET') {
    if (is_logged_in()) {
        send_json(['success' => true, 'authenticated' => true, 'user' => current_user()]);
    } else {
        send_json(['success' => true, 'authenticated' => false], 401);
    }
}

send_json(['success' => false, 'message' => 'Method not allowed.'], 405);
