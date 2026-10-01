<?php
/**
 * SiteTracker - API Update / Delete User Account
 */
require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=UTF-8');

if (!is_logged_in()) {
    send_json(['success' => false, 'message' => 'Unauthorized. Please log in.'], 401);
}

$data = get_request_data();
$id = filter_var($data['id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT);

if (!$id) {
    send_json(['success' => false, 'message' => 'Valid User ID is required.'], 422);
}

// Check existing user
$stmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = ? LIMIT 1");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) {
    send_json(['success' => false, 'message' => 'User not found.'], 404);
}

// Handle delete
$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim($data['action'] ?? $_GET['action'] ?? ''));
if ($method === 'DELETE' || $action === 'delete') {
    $curr = current_user();
    if (!$curr || stripos($curr['role'], 'admin') === false) {
        send_json(['success' => false, 'message' => 'Permission denied. Only Super Admin can delete user accounts.'], 403);
    }

    $currentUserId = (int)($curr['id'] ?? $_SESSION['user_id'] ?? 0);
    if ((int)$id === $currentUserId) {
        send_json(['success' => false, 'message' => 'You cannot delete your own active administrator account.'], 403);
    }

    try {
        // Optional: clean up attendance records for this supervisor/user
        $empId = !empty($user['employee_id']) ? $user['employee_id'] : ('SUP-' . $user['id']);
        $delAtt = $pdo->prepare("DELETE FROM `attendance` WHERE `employee_id` = ? OR `supervisor_name` = ?");
        $delAtt->execute([$empId, $user['full_name']]);

        // Delete user account
        $del = $pdo->prepare("DELETE FROM `users` WHERE `id` = ?");
        $del->execute([$id]);

        send_json([
            'success' => true,
            'message' => "User account '{$user['full_name']}' (@{$user['username']}) deleted successfully by Super Admin."
        ]);
    } catch (PDOException $e) {
        send_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// Handle update
$fullName = isset($data['full_name']) ? trim($data['full_name']) : $user['full_name'];
$email    = isset($data['email']) ? strtolower(trim($data['email'])) : $user['email'];
$role     = isset($data['role']) ? trim($data['role']) : $user['role'];
$status   = in_array($data['status'] ?? '', ['active', 'inactive']) ? $data['status'] : $user['status'];
$password = trim($data['password'] ?? '');

if (empty($fullName)) {
    send_json(['success' => false, 'message' => 'Full Name cannot be empty.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    send_json(['success' => false, 'message' => 'Please provide a valid email.'], 422);
}

try {
    // Check email uniqueness
    if ($email !== $user['email']) {
        $check = $pdo->prepare("SELECT `id` FROM `users` WHERE `email` = ? AND `id` != ? LIMIT 1");
        $check->execute([$email, $id]);
        if ($check->fetch()) {
            send_json(['success' => false, 'message' => "The email '{$email}' is already in use by another account."], 409);
        }
    }

    if (!empty($password)) {
        if (strlen($password) < 6) {
            send_json(['success' => false, 'message' => 'New password must be at least 6 characters.'], 422);
        }
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("
            UPDATE `users`
            SET `full_name` = ?, `email` = ?, `role` = ?, `status` = ?, `password` = ?, `plain_password` = ?
            WHERE `id` = ?
        ");
        $upd->execute([$fullName, $email, $role, $status, $hashed, $password, $id]);
    } else {
        $upd = $pdo->prepare("
            UPDATE `users`
            SET `full_name` = ?, `email` = ?, `role` = ?, `status` = ?
            WHERE `id` = ?
        ");
        $upd->execute([$fullName, $email, $role, $status, $id]);
    }

    // Fetch updated user to return latest state
    $fetchUpdated = $pdo->prepare("SELECT `id`, `username`, `email`, `full_name`, `role`, `status`, `plain_password` FROM `users` WHERE `id` = ?");
    $fetchUpdated->execute([$id]);
    $updatedUser = $fetchUpdated->fetch();

    send_json([
        'success' => true,
        'message' => "User account '{$fullName}' updated successfully in the database!",
        'user'    => $updatedUser
    ]);
} catch (PDOException $e) {
    send_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
