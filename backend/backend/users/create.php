<?php
/**
 * SiteTracker - API Create User Account
 */
require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=UTF-8');

if (!is_logged_in()) {
    send_json(['success' => false, 'message' => 'Unauthorized. Please log in.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

$data = get_request_data();

$fullName = trim($data['full_name'] ?? '');
$username = strtolower(trim($data['username'] ?? ''));
$email    = strtolower(trim($data['email'] ?? ''));
$password = $data['password'] ?? '';
$role     = trim($data['role'] ?? 'Site Supervisor');
$status   = in_array($data['status'] ?? '', ['active', 'inactive']) ? $data['status'] : 'active';

// Validation
if (empty($fullName)) {
    send_json(['success' => false, 'message' => 'Full Name is required.'], 422);
}
if (empty($username) || strlen($username) < 3) {
    send_json(['success' => false, 'message' => 'Username must be at least 3 characters.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    send_json(['success' => false, 'message' => 'Please provide a valid email address.'], 422);
}
if (empty($password) || strlen($password) < 6) {
    send_json(['success' => false, 'message' => 'Password must be at least 6 characters.'], 422);
}

try {
    // Check duplicate username
    $checkUser = $pdo->prepare("SELECT `id` FROM `users` WHERE `username` = ? LIMIT 1");
    $checkUser->execute([$username]);
    if ($checkUser->fetch()) {
        send_json(['success' => false, 'message' => "The username '{$username}' is already taken."], 409);
    }

    // Check duplicate email
    $checkEmail = $pdo->prepare("SELECT `id` FROM `users` WHERE `email` = ? LIMIT 1");
    $checkEmail->execute([$email]);
    if ($checkEmail->fetch()) {
        send_json(['success' => false, 'message' => "The email '{$email}' is already registered."], 409);
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $insert = $pdo->prepare("
        INSERT INTO `users` (`username`, `email`, `password`, `plain_password`, `full_name`, `role`, `status`)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([$username, $email, $hashedPassword, $password, $fullName, $role, $status]);
    $newId = (int)$pdo->lastInsertId();

    send_json([
        'success' => true,
        'message' => "User account '{$fullName}' ({$username}) created and saved to database successfully!",
        'user' => [
            'id'             => $newId,
            'username'       => $username,
            'email'          => $email,
            'full_name'      => $fullName,
            'plain_password' => $password,
            'role'           => $role,
            'status'         => $status
        ]
    ], 201);
} catch (PDOException $e) {
    send_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
