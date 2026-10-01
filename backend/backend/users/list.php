<?php
/**
 * SiteTracker - API List User Accounts
 */
require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=UTF-8');

if (!is_logged_in()) {
    send_json(['success' => false, 'message' => 'Unauthorized. Please log in.'], 401);
}

try {
    $search = trim($_GET['search'] ?? '');
    if (!empty($search)) {
        $stmt = $pdo->prepare("
            SELECT `id`, `username`, `email`, `plain_password`, `full_name`, `role`, `status`, `created_at`, `last_login`
            FROM `users`
            WHERE `full_name` LIKE ? OR `username` LIKE ? OR `email` LIKE ? OR `role` LIKE ?
            ORDER BY `id` DESC
        ");
        $term = "%{$search}%";
        $stmt->execute([$term, $term, $term, $term]);
    } else {
        $stmt = $pdo->query("
            SELECT `id`, `username`, `email`, `plain_password`, `full_name`, `role`, `status`, `created_at`, `last_login`
            FROM `users`
            ORDER BY `id` DESC
        ");
    }

    $rows = $stmt->fetchAll();
    $users = array_map(function($u) {
        $parts = explode(' ', trim($u['full_name']));
        $initials = count($parts) >= 2
            ? strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1))
            : strtoupper(substr($u['full_name'], 0, 2));

        return [
            'id'             => (int)$u['id'],
            'username'       => $u['username'],
            'email'          => $u['email'],
            'plain_password' => $u['plain_password'] ?? '',
            'full_name'      => $u['full_name'],
            'role'           => $u['role'],
            'status'         => $u['status'],
            'initials'       => $initials ?: 'US',
            'created_at'     => $u['created_at'],
            'last_login'     => $u['last_login'] ? date('M d, Y h:i A', strtotime($u['last_login'])) : 'Never'
        ];
    }, $rows);

    send_json([
        'success' => true,
        'count'   => count($users),
        'users'   => $users
    ]);
} catch (PDOException $e) {
    send_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
