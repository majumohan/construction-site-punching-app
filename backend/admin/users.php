<?php
require_once dirname(__DIR__) . '/backend/config/database.php';
require_login('login.php');
$currentUser = current_user();

$alertSuccess = '';
$alertError = '';

// Handle direct POST create user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = strtolower(trim($_POST['username'] ?? ''));
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $role     = trim($_POST['role'] ?? 'Site Supervisor');
    $status   = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $employeeId = trim($_POST['employee_id'] ?? '');

    if (empty($fullName) || empty($username) || empty($email) || empty($password)) {
        $alertError = 'Please fill out all required fields.';
    } elseif (strlen($username) < 3) {
        $alertError = 'Username must be at least 3 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alertError = 'Please provide a valid email address.';
    } elseif (strlen($password) < 6) {
        $alertError = 'Password must be at least 6 characters.';
    } else {
        try {
            // Check uniqueness
            $checkUser = $pdo->prepare("SELECT `id` FROM `users` WHERE `username` = ? LIMIT 1");
            $checkUser->execute([$username]);
            if ($checkUser->fetch()) {
                $alertError = "The username '{$username}' is already taken.";
            } else {
                $checkEmail = $pdo->prepare("SELECT `id` FROM `users` WHERE `email` = ? LIMIT 1");
                $checkEmail->execute([$email]);
                if ($checkEmail->fetch()) {
                    $alertError = "The email '{$email}' is already registered.";
                } else {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("
                        INSERT INTO `users` (`username`, `email`, `password`, `plain_password`, `full_name`, `role`, `status`, `employee_id`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$username, $email, $hashed, $password, $fullName, $role, $status, $employeeId]);
                    $alertSuccess = "User account '{$fullName}' (@{$username}) has been created successfully!";
                }
            }
        } catch (PDOException $e) {
            $alertError = "Database error: " . $e->getMessage();
        }
    }
}

// Handle direct POST update user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_user') {
    $editId   = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $role     = trim($_POST['role'] ?? 'Site Supervisor');
    $status   = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $password = trim($_POST['password'] ?? '');

    if (!$editId || empty($fullName) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alertError = 'Please provide a valid name and email address.';
    } else {
        try {
            // Check email uniqueness across others
            $check = $pdo->prepare("SELECT `id` FROM `users` WHERE `email` = ? AND `id` != ? LIMIT 1");
            $check->execute([$email, $editId]);
            if ($check->fetch()) {
                $alertError = "The email '{$email}' is already in use by another user.";
            } else {
                if (!empty($password)) {
                    if (strlen($password) < 6) {
                        $alertError = 'New password must be at least 6 characters.';
                    } else {
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $upd = $pdo->prepare("UPDATE `users` SET `full_name` = ?, `email` = ?, `role` = ?, `status` = ?, `password` = ?, `plain_password` = ? WHERE `id` = ?");
                        $upd->execute([$fullName, $email, $role, $status, $hashed, $password, $editId]);
                        $alertSuccess = "User account '{$fullName}' updated successfully with new password!";
                    }
                } else {
                    $upd = $pdo->prepare("UPDATE `users` SET `full_name` = ?, `email` = ?, `role` = ?, `status` = ? WHERE `id` = ?");
                    $upd->execute([$fullName, $email, $role, $status, $editId]);
                    $alertSuccess = "User account '{$fullName}' details updated successfully!";
                }
            }
        } catch (PDOException $e) {
            $alertError = "Database error: " . $e->getMessage();
        }
    }
}

// Handle direct POST delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $delId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($delId) {
        if ($delId === (int)$currentUser['id']) {
            $alertError = "You cannot delete your own logged-in account.";
        } elseif (stripos($currentUser['role'], 'admin') === false) {
            $alertError = "Permission denied. Only Super Admin can delete user accounts.";
        } else {
            try {
                $stmtU = $pdo->prepare("SELECT `id`, `full_name`, `username`, `employee_id` FROM `users` WHERE `id` = ? LIMIT 1");
                $stmtU->execute([$delId]);
                $targetU = $stmtU->fetch();

                if ($targetU) {
                    $empId = !empty($targetU['employee_id']) ? $targetU['employee_id'] : ('SUP-' . $targetU['id']);
                    $delAtt = $pdo->prepare("DELETE FROM `attendance` WHERE `employee_id` = ? OR `supervisor_name` = ?");
                    $delAtt->execute([$empId, $targetU['full_name']]);

                    $del = $pdo->prepare("DELETE FROM `users` WHERE `id` = ?");
                    $del->execute([$delId]);
                    $alertSuccess = "User account '{$targetU['full_name']}' (@{$targetU['username']}) was permanently deleted.";
                } else {
                    $alertError = "User account not found or already deleted.";
                }
            } catch (PDOException $e) {
                $alertError = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Fetch all users
$stmt = $pdo->query("SELECT * FROM `users` ORDER BY `id` DESC");
$users = $stmt->fetchAll();

$totalUsers = count($users);
$totalSupervisors = 0;
$totalAdmins = 0;
foreach ($users as $u) {
    if (stripos($u['role'], 'admin') !== false) {
        $totalAdmins++;
    } else {
        $totalSupervisors++;
    }
}

// Avatar palette helper
$avatarColors = [
    ['bg' => '#0066FF', 'color' => '#FFFFFF'],
    ['bg' => '#8B5CF6', 'color' => '#FFFFFF'],
    ['bg' => '#EC4899', 'color' => '#FFFFFF'],
    ['bg' => '#F97316', 'color' => '#FFFFFF'],
    ['bg' => '#14B8A6', 'color' => '#FFFFFF'],
    ['bg' => '#6366F1', 'color' => '#FFFFFF'],
    ['bg' => '#E11D48', 'color' => '#FFFFFF'],
    ['bg' => '#0D9488', 'color' => '#FFFFFF'],
    ['bg' => '#3B82F6', 'color' => '#FFFFFF'],
];

function getAvatarStyle($name) {
    global $avatarColors;
    $hash = crc32($name);
    $idx = abs($hash) % count($avatarColors);
    return $avatarColors[$idx];
}

function getInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2)) ?: 'ST';
}

$currentAdminName = $currentUser['full_name'] ?? 'Chief Operations Admin';
$currentAdminRole = ucfirst($currentUser['role'] ?? 'Super Admin');
$adminInitials = getInitials($currentAdminName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Accounts • SiteTracker Operations Hub</title>
  
  <!-- Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <style>
    :root {
      --primary: #0066FF;
      --primary-hover: #0052CC;
      --primary-light: #EBF2FF;
      --success: #10B981;
      --success-light: #ECFDF5;
      --warning: #F59E0B;
      --warning-light: #FFFBEB;
      --danger: #EF4444;
      --danger-light: #FEF2F2;
      --purple: #8B5CF6;
      --purple-light: #F5F3FF;
      --slate-900: #0B132B;
      --slate-850: #0F172A;
      --slate-800: #1E293B;
      --slate-700: #334155;
      --slate-600: #475569;
      --slate-500: #64748B;
      --slate-400: #94A3B8;
      --slate-300: #CBD5E1;
      --slate-200: #E2E8F0;
      --slate-100: #F1F5F9;
      --slate-50:  #F8FAFC;
      --white: #FFFFFF;
      --font-main: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: var(--font-main);
      background-color: #F8FAFC;
      color: var(--slate-800);
      min-height: 100vh;
      display: flex;
      -webkit-font-smoothing: antialiased;
    }

    /* ─── SIDEBAR NAVIGATION ─── */
    .sidebar {
      width: 260px;
      background: #0B132B;
      color: #FFFFFF;
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
      position: sticky;
      top: 0;
      height: 100vh;
      z-index: 100;
      transition: all 0.3s ease;
    }

    .sidebar-header {
      padding: 24px 20px 20px 20px;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .brand-logo-icon {
      width: 38px;
      height: 38px;
      background: linear-gradient(135deg, #0066FF 0%, #00C6FF 100%);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 18px;
      box-shadow: 0 4px 12px rgba(0, 102, 255, 0.35);
      flex-shrink: 0;
    }

    .brand-text {
      display: flex;
      flex-direction: column;
    }

    .brand-title {
      font-size: 17px;
      font-weight: 800;
      letter-spacing: -0.3px;
      color: #FFFFFF;
      line-height: 1.2;
    }

    .brand-subtitle {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.8px;
      color: #64748B;
      text-transform: uppercase;
      margin-top: 2px;
    }

    .sidebar-menu {
      list-style: none;
      padding: 16px 14px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      flex-grow: 1;
      overflow-y: auto;
    }

    .menu-item a {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 11px 16px;
      border-radius: 10px;
      color: #94A3B8;
      text-decoration: none;
      font-size: 13.5px;
      font-weight: 600;
      transition: all 0.2s ease;
    }

    .menu-item a i {
      font-size: 16px;
      width: 20px;
      text-align: center;
      color: #64748B;
      transition: all 0.2s ease;
    }

    .menu-item a:hover {
      background: rgba(255, 255, 255, 0.05);
      color: #FFFFFF;
    }

    .menu-item a:hover i {
      color: #FFFFFF;
    }

    .menu-item.active a {
      background: #0066FF;
      color: #FFFFFF;
      box-shadow: 0 4px 14px rgba(0, 102, 255, 0.4);
    }

    .menu-item.active a i {
      color: #FFFFFF;
    }

    .sidebar-footer {
      padding: 16px 14px;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(0, 0, 0, 0.15);
    }

    .user-profile-badge {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 8px 10px;
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.04);
      text-decoration: none;
      color: inherit;
    }

    .user-avatar-circle {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #3B82F6;
      color: #FFFFFF;
      font-size: 13px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .user-profile-info {
      display: flex;
      flex-direction: column;
      flex-grow: 1;
      overflow: hidden;
    }

    .user-profile-name {
      font-size: 12.5px;
      font-weight: 700;
      color: #FFFFFF;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .user-profile-role {
      font-size: 11px;
      color: #94A3B8;
    }

    .logout-btn {
      color: #94A3B8;
      background: none;
      border: none;
      cursor: pointer;
      font-size: 15px;
      padding: 6px;
      border-radius: 6px;
      transition: color 0.2s;
    }

    .logout-btn:hover {
      color: #EF4444;
    }

    /* ─── MAIN CONTENT LAYOUT ─── */
    .main-wrapper {
      flex-grow: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
      overflow-x: hidden;
    }

    /* ─── TOP NAVBAR ─── */
    .top-header {
      background: #FFFFFF;
      height: 66px;
      border-bottom: 1px solid var(--slate-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 24px;
      position: sticky;
      top: 0;
      z-index: 90;
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 16px;
      flex-grow: 1;
      max-width: 480px;
    }

    .sidebar-toggle {
      background: none;
      border: none;
      font-size: 17px;
      color: var(--slate-600);
      cursor: pointer;
      padding: 4px;
    }

    .search-input-box {
      position: relative;
      width: 100%;
    }

    .search-input-box i {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--slate-400);
      font-size: 13px;
    }

    .search-input-box input {
      width: 100%;
      height: 38px;
      padding: 0 14px 0 36px;
      background: #F1F5F9;
      border: 1px solid transparent;
      border-radius: 9px;
      font-size: 13px;
      font-family: var(--font-main);
      color: var(--slate-800);
      transition: all 0.2s;
    }

    .search-input-box input:focus {
      outline: none;
      background: #FFFFFF;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
    }

    .header-right {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .btn-create-user-top {
      display: flex;
      align-items: center;
      gap: 7px;
      padding: 7px 16px;
      background: #0066FF;
      color: white;
      border: none;
      border-radius: 9px;
      font-size: 12.5px;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 2px 7px rgba(0, 102, 255, 0.25);
      transition: all 0.2s;
    }

    .btn-create-user-top:hover {
      background: #0052CC;
      transform: translateY(-1px);
    }

    .notif-bell-btn {
      position: relative;
      background: none;
      border: none;
      cursor: pointer;
      font-size: 17px;
      color: var(--slate-600);
      padding: 6px;
      border-radius: 8px;
      transition: background 0.2s;
    }

    .notif-bell-btn:hover {
      background: var(--slate-100);
    }

    .top-user-pill {
      display: flex;
      align-items: center;
      gap: 9px;
      cursor: pointer;
    }

    .top-user-details {
      display: flex;
      flex-direction: column;
      text-align: left;
    }

    .top-user-name {
      font-size: 12.5px;
      font-weight: 700;
      color: var(--slate-800);
    }

    .top-user-role {
      font-size: 10.5px;
      color: var(--slate-500);
    }

    /* ─── PAGE CONTAINER ─── */
    .page-container {
      padding: 20px 24px 32px 24px;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    /* Alert Banner */
    .alert-banner {
      padding: 12px 16px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 10px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }

    .alert-banner.success {
      background: #ECFDF5;
      color: #065F46;
      border: 1px solid #A7F3D0;
    }

    .alert-banner.error {
      background: #FEF2F2;
      color: #991B1B;
      border: 1px solid #FECACA;
    }

    /* Page Heading Row */
    .page-title-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 14px;
    }

    .page-heading-block h1 {
      font-size: 24px;
      font-weight: 800;
      color: var(--slate-900);
      letter-spacing: -0.4px;
      margin-bottom: 3px;
    }

    .page-heading-block p {
      font-size: 13px;
      color: var(--slate-500);
      font-weight: 500;
    }

    .page-date-badge {
      display: flex;
      align-items: center;
      gap: 7px;
      background: #FFFFFF;
      padding: 7px 14px;
      border-radius: 9px;
      border: 1px solid var(--slate-200);
      font-size: 12.5px;
      font-weight: 600;
      color: var(--slate-700);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    }

    /* ─── KPI METRIC CARDS ─── */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
    }

    .kpi-card {
      background: #FFFFFF;
      border-radius: 14px;
      padding: 16px 18px;
      border: 1px solid var(--slate-200);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.02);
      display: flex;
      align-items: center;
      gap: 14px;
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .kpi-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 14px rgba(0, 0, 0, 0.05);
    }

    .kpi-icon-box {
      width: 46px;
      height: 46px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 19px;
      flex-shrink: 0;
    }

    .kpi-icon-blue { background: #EFF6FF; color: #2563EB; }
    .kpi-icon-green { background: #ECFDF5; color: #10B981; }
    .kpi-icon-purple { background: #F5F3FF; color: #8B5CF6; }

    .kpi-body {
      display: flex;
      flex-direction: column;
    }

    .kpi-label {
      font-size: 12px;
      font-weight: 600;
      color: var(--slate-500);
      margin-bottom: 2px;
    }

    .kpi-val {
      font-size: 21px;
      font-weight: 800;
      color: var(--slate-900);
      letter-spacing: -0.4px;
      line-height: 1.1;
      margin-bottom: 2px;
    }

    .kpi-sub {
      font-size: 11px;
      font-weight: 600;
      color: #10B981;
    }

    /* ─── TABLE CONTAINER ─── */
    .table-container-card {
      background: #FFFFFF;
      border-radius: 14px;
      border: 1px solid var(--slate-200);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.02);
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    .table-header-toolbar {
      padding: 14px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid var(--slate-200);
      flex-wrap: wrap;
      gap: 12px;
    }

    .table-header-title-box h3 {
      font-size: 15px;
      font-weight: 800;
      color: var(--slate-900);
    }

    .table-header-title-box span {
      font-size: 11.5px;
      color: var(--slate-400);
    }

    .table-search-box {
      position: relative;
      width: 260px;
    }

    .table-search-box i {
      position: absolute;
      left: 11px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--slate-400);
      font-size: 12px;
    }

    .table-search-box input {
      width: 100%;
      height: 36px;
      padding: 0 12px 0 32px;
      background: #F8FAFC;
      border: 1px solid var(--slate-200);
      border-radius: 8px;
      font-size: 12.5px;
      font-family: var(--font-main);
      color: var(--slate-700);
      outline: none;
      transition: all 0.2s;
    }

    .table-search-box input:focus {
      background: white;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
    }

    .table-responsive {
      width: 100%;
      overflow-x: auto;
    }

    .modern-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    .modern-table th {
      background: #F8FAFC;
      padding: 10px 14px;
      font-size: 10.5px;
      font-weight: 800;
      color: var(--slate-500);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-bottom: 1px solid var(--slate-200);
      white-space: nowrap;
    }

    .modern-table td {
      padding: 12px 14px;
      font-size: 12.5px;
      color: var(--slate-700);
      border-bottom: 1px solid #F1F5F9;
      vertical-align: middle;
      white-space: nowrap;
    }

    .modern-table tbody tr:hover {
      background: #F8FAFC;
    }

    /* User Cell */
    .emp-cell {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .emp-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 700;
      flex-shrink: 0;
    }

    .emp-meta {
      display: flex;
      flex-direction: column;
    }

    .emp-name-row {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .emp-name {
      font-size: 13px;
      font-weight: 700;
      color: var(--slate-900);
    }

    .you-pill {
      font-size: 9.5px;
      font-weight: 800;
      background: #E2E8F0;
      color: var(--slate-700);
      padding: 1px 5px;
      border-radius: 4px;
      text-transform: uppercase;
    }

    .emp-code {
      font-size: 11px;
      font-weight: 600;
      color: var(--slate-400);
      margin-top: 1px;
    }

    /* Role Badges */
    .role-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
    }

    .role-badge.supervisor { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
    .role-badge.admin { background: #EFF6FF; color: #2563EB; border: 1px solid #BFDBFE; }
    .role-badge.engineer { background: #F5F3FF; color: #7C3AED; border: 1px solid #DDD6FE; }
    .role-badge.manager { background: #FFF7ED; color: #EA580C; border: 1px solid #FED7AA; }

    /* Status Badges */
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 9px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
    }

    .status-pill.active { background: #ECFDF5; color: #059669; }
    .status-pill.inactive { background: #FEF2F2; color: #DC2626; }
    .status-dot { width: 5px; height: 5px; border-radius: 50%; }
    .status-pill.active .status-dot { background: #10B981; }
    .status-pill.inactive .status-dot { background: #EF4444; }

    /* Password Display Pill */
    .pwd-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #F8FAFC;
      border: 1px solid var(--slate-200);
      padding: 3px 8px;
      border-radius: 7px;
    }

    .pwd-val {
      font-family: 'SFMono-Regular', Consolas, monospace;
      font-size: 12px;
      font-weight: 600;
      color: var(--slate-700);
      min-width: 65px;
      display: inline-block;
      letter-spacing: 0.8px;
    }

    .pwd-btn {
      background: none;
      border: none;
      cursor: pointer;
      color: var(--slate-400);
      padding: 2px 4px;
      font-size: 11.5px;
      border-radius: 4px;
      display: inline-flex;
      align-items: center;
      transition: color 0.15s;
    }

    .pwd-btn:hover { color: var(--slate-900); }
    .pwd-btn.copy:hover { color: var(--primary); }

    /* Actions Column */
    .actions-cell {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 6px;
    }

    .btn-action-edit {
      padding: 5px 10px;
      background: #F8FAFC;
      border: 1px solid var(--slate-200);
      border-radius: 7px;
      font-size: 11.5px;
      font-weight: 700;
      color: var(--slate-700);
      display: inline-flex;
      align-items: center;
      gap: 5px;
      cursor: pointer;
      transition: all 0.15s;
    }

    .btn-action-edit:hover {
      background: #EFF6FF;
      color: var(--primary);
      border-color: #BFDBFE;
    }

    .btn-action-del {
      padding: 5px 8px;
      background: none;
      border: 1px solid transparent;
      border-radius: 7px;
      font-size: 11.5px;
      color: var(--slate-400);
      cursor: pointer;
      transition: all 0.15s;
    }

    .btn-action-del:hover {
      background: #FEF2F2;
      color: #DC2626;
      border-color: #FECACA;
    }

    /* ─── MODAL DIALOGS ─── */
    .modal-backdrop {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transition: all 0.2s ease;
    }

    .modal-backdrop.open { opacity: 1; visibility: visible; }

    .modal-card {
      background: #FFFFFF;
      border-radius: 18px;
      width: 100%;
      max-width: 500px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
      transform: scale(0.95);
      transition: transform 0.2s ease;
      display: flex;
      flex-direction: column;
    }

    .modal-backdrop.open .modal-card { transform: scale(1); }

    .modal-header {
      padding: 18px 22px;
      border-bottom: 1px solid var(--slate-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .modal-header h3 {
      font-size: 16.5px;
      font-weight: 800;
      color: var(--slate-900);
    }

    .modal-close-btn {
      background: none;
      border: none;
      font-size: 17px;
      color: var(--slate-400);
      cursor: pointer;
    }

    .modal-body {
      padding: 22px;
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .form-group label {
      font-size: 12px;
      font-weight: 700;
      color: var(--slate-700);
    }

    .form-control {
      width: 100%;
      height: 38px;
      padding: 0 12px;
      border: 1px solid var(--slate-200);
      border-radius: 8px;
      font-size: 13px;
      font-family: var(--font-main);
      color: var(--slate-800);
      outline: none;
      transition: all 0.2s;
    }

    .form-control:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
    }

    .form-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .modal-footer {
      padding: 14px 22px;
      border-top: 1px solid var(--slate-200);
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      background: #F8FAFC;
      border-radius: 0 0 18px 18px;
    }

    /* Toast Notification */
    .toast-box {
      position: fixed;
      bottom: 24px;
      right: 24px;
      background: #0F172A;
      color: white;
      padding: 10px 18px;
      border-radius: 10px;
      font-size: 12.5px;
      font-weight: 600;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
      display: flex;
      align-items: center;
      gap: 8px;
      opacity: 0;
      visibility: hidden;
      transform: translateY(10px);
      transition: all 0.2s ease;
      z-index: 2000;
    }

    .toast-box.show {
      opacity: 1;
      visibility: visible;
      transform: translateY(0);
    }

    /* Responsive */
    @media (max-width: 1024px) {
      .kpi-grid { grid-template-columns: 1fr; }
      .sidebar { position: fixed; left: -260px; }
      .sidebar.open { left: 0; }
    }
  </style>
</head>
<body>

  <!-- ─── SIDEBAR ─── -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="brand-logo-icon">
        <i class="fa-solid fa-map-location-dot"></i>
      </div>
      <div class="brand-text">
        <span class="brand-title">SiteTracker</span>
        <span class="brand-subtitle">OPERATIONS HUB</span>
      </div>
    </div>

    <ul class="sidebar-menu">
      <li class="menu-item">
        <a href="dashboard.php">
          <i class="fa-solid fa-chart-pie"></i>
          <span>Dashboard</span>
        </a>
      </li>
      <li class="menu-item">
        <a href="sites.php">
          <i class="fa-solid fa-city"></i>
          <span>Construction Sites</span>
        </a>
      </li>
      <li class="menu-item">
        <a href="attendance.php">
          <i class="fa-solid fa-id-badge"></i>
          <span>Attendance Records</span>
        </a>
      </li>
      <li class="menu-item active">
        <a href="users.php">
          <i class="fa-solid fa-user-group"></i>
          <span>User Accounts</span>
        </a>
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="user-profile-badge">
        <div class="user-avatar-circle"><?= htmlspecialchars($adminInitials) ?></div>
        <div class="user-profile-info">
          <span class="user-profile-name"><?= htmlspecialchars($currentAdminName) ?></span>
          <span class="user-profile-role"><?= htmlspecialchars($currentAdminRole) ?></span>
        </div>
        <a href="login.php?logout=1" class="logout-btn" title="Sign Out">
          <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
      </div>
    </div>
  </aside>

  <!-- ─── MAIN CONTENT WRAPPER ─── -->
  <div class="main-wrapper">

    <!-- ─── TOP HEADER ─── -->
    <header class="top-header">
      <div class="header-left">
        <button class="sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div class="search-input-box">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="globalUserSearch" placeholder="Search user by name, username, email..." onkeyup="filterUsersTable()">
        </div>
      </div>

      <div class="header-right">
        <button class="btn-create-user-top" onclick="openCreateModal()">
          <i class="fa-solid fa-user-plus"></i>
          <span>Create User</span>
        </button>

        <button class="notif-bell-btn">
          <i class="fa-regular fa-bell"></i>
        </button>

        <div class="top-user-pill">
          <div class="user-avatar-circle"><?= htmlspecialchars($adminInitials) ?></div>
          <div class="top-user-details">
            <span class="top-user-name"><?= htmlspecialchars($currentAdminName) ?></span>
            <span class="top-user-role"><?= htmlspecialchars($currentAdminRole) ?></span>
          </div>
        </div>
      </div>
    </header>

    <!-- ─── PAGE CONTAINER ─── -->
    <main class="page-container">

      <!-- Alerts -->
      <?php if (!empty($alertSuccess)): ?>
        <div class="alert-banner success">
          <i class="fa-solid fa-circle-check"></i>
          <span><?= htmlspecialchars($alertSuccess) ?></span>
        </div>
      <?php endif; ?>
      <?php if (!empty($alertError)): ?>
        <div class="alert-banner error">
          <i class="fa-solid fa-circle-exclamation"></i>
          <span><?= htmlspecialchars($alertError) ?></span>
        </div>
      <?php endif; ?>

      <!-- Page Heading Row -->
      <div class="page-title-row">
        <div class="page-heading-block">
          <h1>User Accounts</h1>
          <p>Manage site supervisors, field engineers and administrative user credentials.</p>
        </div>
        <div class="page-date-badge">
          <i class="fa-regular fa-calendar"></i>
          <span><?= date('D, M d, Y') ?></span>
        </div>
      </div>

      <!-- ─── 3 KPI SUMMARY CARDS ─── -->
      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-icon-box kpi-icon-blue">
            <i class="fa-solid fa-users"></i>
          </div>
          <div class="kpi-body">
            <span class="kpi-label">Total User Accounts</span>
            <span class="kpi-val"><?= $totalUsers ?></span>
            <span class="kpi-sub">+2 this month</span>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon-box kpi-icon-green">
            <i class="fa-solid fa-helmet-safety"></i>
          </div>
          <div class="kpi-body">
            <span class="kpi-label">Site Supervisors & Engineers</span>
            <span class="kpi-val"><?= $totalSupervisors ?></span>
            <span class="kpi-sub">Field Staff</span>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon-box kpi-icon-purple">
            <i class="fa-solid fa-user-shield"></i>
          </div>
          <div class="kpi-body">
            <span class="kpi-label">Operations Administrators</span>
            <span class="kpi-val"><?= $totalAdmins ?></span>
            <span class="kpi-sub">Hub Managers</span>
          </div>
        </div>
      </div>

      <!-- ─── USERS TABLE CARD ─── -->
      <div class="table-container-card">
        
        <div class="table-header-toolbar">
          <div class="table-header-title-box">
            <h3>Registered Users (<?= $totalUsers ?>)</h3>
            <span>Database credentials for mobile app & dashboard login</span>
          </div>
          <div class="table-search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="inlineUserFilter" placeholder="Filter table records..." onkeyup="filterUsersTable()">
          </div>
        </div>

        <div class="table-responsive">
          <table class="modern-table">
            <thead>
              <tr>
                <th>USER & HANDLE</th>
                <th>EMAIL ADDRESS</th>
                <th>ROLE</th>
                <th>STATUS</th>
                <th>PASSWORD ACCESS</th>
                <th>REGISTERED ON</th>
                <th style="text-align: right;">ACTIONS</th>
              </tr>
            </thead>
            <tbody id="usersTableBody">
              <?php foreach ($users as $u): 
                $avStyle = getAvatarStyle($u['full_name']);
                $ini = getInitials($u['full_name']);
                $isSelf = ((int)$u['id'] === (int)$currentUser['id']);
                $empId = !empty($u['employee_id']) ? $u['employee_id'] : ('SUP-' . $u['id']);
                $plainPwd = $u['plain_password'] ?? '';
                
                $roleClass = 'supervisor';
                if (stripos($u['role'], 'admin') !== false) $roleClass = 'admin';
                elseif (stripos($u['role'], 'engineer') !== false) $roleClass = 'engineer';
                elseif (stripos($u['role'], 'manager') !== false) $roleClass = 'manager';

                $createdDate = !empty($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : 'Oct 01, 2026';

                $userJson = htmlspecialchars(json_encode([
                  'id' => $u['id'],
                  'full_name' => $u['full_name'],
                  'username' => $u['username'],
                  'email' => $u['email'],
                  'role' => $u['role'],
                  'status' => $u['status'],
                  'employee_id' => $empId,
                  'plain_password' => $plainPwd
                ]), ENT_QUOTES, 'UTF-8');
              ?>
                <tr class="user-row">
                  <!-- User & Handle -->
                  <td>
                    <div class="emp-cell">
                      <div class="emp-avatar" style="background: <?= $avStyle['bg'] ?>; color: <?= $avStyle['color'] ?>;">
                        <?= htmlspecialchars($ini) ?>
                      </div>
                      <div class="emp-meta">
                        <div class="emp-name-row">
                          <span class="emp-name"><?= htmlspecialchars($u['full_name']) ?></span>
                          <?php if ($isSelf): ?>
                            <span class="you-pill">You</span>
                          <?php endif; ?>
                        </div>
                        <span class="emp-code">@<?= htmlspecialchars($u['username']) ?> • <?= htmlspecialchars($empId) ?></span>
                      </div>
                    </div>
                  </td>

                  <!-- Email -->
                  <td style="color: var(--slate-600);"><?= htmlspecialchars($u['email']) ?></td>

                  <!-- Role -->
                  <td>
                    <span class="role-badge <?= $roleClass ?>"><?= htmlspecialchars($u['role']) ?></span>
                  </td>

                  <!-- Status -->
                  <td>
                    <span class="status-pill <?= $u['status'] === 'active' ? 'active' : 'inactive' ?>">
                      <span class="status-dot"></span> <?= ucfirst($u['status']) ?>
                    </span>
                  </td>

                  <!-- Password Access -->
                  <td>
                    <div class="pwd-pill">
                      <span class="pwd-val" id="pwd-val-<?= $u['id'] ?>" data-plain="<?= htmlspecialchars($plainPwd) ?>">••••••••</span>
                      <button type="button" class="pwd-btn" onclick="togglePasswordView(<?= $u['id'] ?>)" title="Reveal / Hide Password">
                        <i class="fa-solid fa-eye" id="pwd-icon-<?= $u['id'] ?>"></i>
                      </button>
                      <button type="button" class="pwd-btn copy" onclick="copyPasswordText('<?= htmlspecialchars(addslashes($plainPwd)) ?>')" title="Copy Password">
                        <i class="fa-regular fa-copy"></i>
                      </button>
                    </div>
                  </td>

                  <!-- Registered On -->
                  <td style="color: var(--slate-500); font-size: 12px;"><?= $createdDate ?></td>

                  <!-- Actions -->
                  <td>
                    <div class="actions-cell">
                      <button class="btn-action-edit" onclick="openEditModal(<?= $userJson ?>)">
                        <i class="fa-regular fa-pen-to-square"></i>
                        <span>Edit</span>
                      </button>
                      
                      <?php if (!$isSelf): ?>
                        <button class="btn-action-del" onclick="promptDeleteUser(<?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>')" title="Delete User">
                          <i class="fa-regular fa-trash-can"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      </div>

    </main>
  </div>

  <!-- ─── CREATE USER MODAL ─── -->
  <div class="modal-backdrop" id="createUserModal" onclick="closeModal('createUserModal')">
    <div class="modal-card" onclick="event.stopPropagation()">
      <div class="modal-header">
        <h3>Create New User Account</h3>
        <button class="modal-close-btn" onclick="closeModal('createUserModal')"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form action="users.php" method="POST">
        <input type="hidden" name="action" value="create_user">
        <div class="modal-body">
          
          <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="full_name" class="form-control" required placeholder="e.g. Rahul Sharma">
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label>Username *</label>
              <input type="text" name="username" class="form-control" required placeholder="e.g. rahul_sharma" autocomplete="off">
            </div>
            <div class="form-group">
              <label>Role Assignment *</label>
              <select name="role" class="form-control">
                <option value="Site Supervisor">Site Supervisor</option>
                <option value="Field Engineer">Field Engineer</option>
                <option value="Operations Manager">Operations Manager</option>
                <option value="Super Admin">Super Admin</option>
              </select>
            </div>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label>Email Address *</label>
              <input type="email" name="email" class="form-control" required placeholder="e.g. rahul@sitetracker.com">
            </div>
            <div class="form-group">
              <label>Employee ID (Optional)</label>
              <input type="text" name="employee_id" class="form-control" placeholder="e.g. SUP-12">
            </div>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <div style="display: flex; justify-content: space-between; align-items: center;">
                <label>Initial Password *</label>
                <button type="button" onclick="generateRandomPassword()" style="font-size: 11px; color: var(--primary); background: none; border: none; cursor: pointer; font-weight: 700;">
                  <i class="fa-solid fa-dice"></i> Generate
                </button>
              </div>
              <input type="text" name="password" id="create_password" class="form-control" required placeholder="Min 6 characters">
            </div>

            <div class="form-group">
              <label>Account Status *</label>
              <select name="status" class="form-control">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('createUserModal')" style="padding: 8px 16px; background: white; border: 1px solid var(--slate-200); border-radius: 8px; font-weight: 700; cursor: pointer;">
            Cancel
          </button>
          <button type="submit" style="padding: 8px 20px; background: var(--primary); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer;">
            Create Account
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ─── EDIT USER MODAL ─── -->
  <div class="modal-backdrop" id="editUserModal" onclick="closeModal('editUserModal')">
    <div class="modal-card" onclick="event.stopPropagation()">
      <div class="modal-header">
        <h3>Edit User Credentials</h3>
        <button class="modal-close-btn" onclick="closeModal('editUserModal')"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form action="users.php" method="POST">
        <input type="hidden" name="action" value="update_user">
        <input type="hidden" name="id" id="edit_user_id">
        <div class="modal-body">
          
          <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="full_name" id="edit_user_name" class="form-control" required>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label>Email Address *</label>
              <input type="email" name="email" id="edit_user_email" class="form-control" required>
            </div>
            <div class="form-group">
              <label>Role Assignment *</label>
              <select name="role" id="edit_user_role" class="form-control">
                <option value="Site Supervisor">Site Supervisor</option>
                <option value="Field Engineer">Field Engineer</option>
                <option value="Operations Manager">Operations Manager</option>
                <option value="Super Admin">Super Admin</option>
              </select>
            </div>
          </div>

          <div class="form-group" style="background: #F8FAFC; border: 1px solid var(--slate-200); border-radius: 10px; padding: 12px;">
            <label style="margin-bottom: 4px; font-size: 11.5px; color: var(--slate-500);">Current Plain Password</label>
            <div style="display: flex; align-items: center; gap: 8px;">
              <input type="password" id="edit_current_pwd" readonly class="form-control" style="background: white; font-family: monospace; font-weight: 600;">
              <button type="button" onclick="toggleEditPwdView()" style="padding: 8px 12px; background: white; border: 1px solid var(--slate-200); border-radius: 8px; cursor: pointer;">
                <i class="fa-solid fa-eye" id="edit_pwd_eye"></i>
              </button>
            </div>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label>Reset Password (Optional)</label>
              <input type="text" name="password" class="form-control" placeholder="Leave empty to keep current">
            </div>
            <div class="form-group">
              <label>Account Status *</label>
              <select name="status" id="edit_user_status" class="form-control">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('editUserModal')" style="padding: 8px 16px; background: white; border: 1px solid var(--slate-200); border-radius: 8px; font-weight: 700; cursor: pointer;">
            Cancel
          </button>
          <button type="submit" style="padding: 8px 20px; background: var(--primary); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer;">
            Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Form -->
  <form method="POST" action="users.php" id="deleteUserForm" style="display: none;">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="id" id="deleteUserId" value="">
  </form>

  <!-- Toast Box -->
  <div class="toast-box" id="toastBox">
    <i class="fa-solid fa-circle-check" style="color: #10B981;"></i>
    <span id="toastMsg">Password copied to clipboard!</span>
  </div>

  <script>
    function openCreateModal() {
      document.getElementById('createUserModal').classList.add('open');
    }

    function openEditModal(data) {
      document.getElementById('edit_user_id').value = data.id;
      document.getElementById('edit_user_name').value = data.full_name;
      document.getElementById('edit_user_email').value = data.email;
      document.getElementById('edit_user_role').value = data.role;
      document.getElementById('edit_user_status').value = data.status;
      document.getElementById('edit_current_pwd').value = data.plain_password;
      document.getElementById('editUserModal').classList.add('open');
    }

    function closeModal(id) {
      document.getElementById(id).classList.remove('open');
    }

    function promptDeleteUser(id, name) {
      if (confirm('Are you sure you want to permanently delete user account "' + name + '"?')) {
        document.getElementById('deleteUserId').value = id;
        document.getElementById('deleteUserForm').submit();
      }
    }

    function togglePasswordView(id) {
      const valEl = document.getElementById('pwd-val-' + id);
      const iconEl = document.getElementById('pwd-icon-' + id);
      const plain = valEl.getAttribute('data-plain');
      if (valEl.innerText === '••••••••') {
        valEl.innerText = plain || '(empty)';
        iconEl.classList.remove('fa-eye');
        iconEl.classList.add('fa-eye-slash');
      } else {
        valEl.innerText = '••••••••';
        iconEl.classList.remove('fa-eye-slash');
        iconEl.classList.add('fa-eye');
      }
    }

    function toggleEditPwdView() {
      const el = document.getElementById('edit_current_pwd');
      const icon = document.getElementById('edit_pwd_eye');
      if (el.type === 'password') {
        el.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        el.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    }

    function copyPasswordText(txt) {
      if (!txt) {
        showToast('No password set.');
        return;
      }
      navigator.clipboard.writeText(txt).then(() => {
        showToast('Password copied to clipboard!');
      });
    }

    function showToast(msg) {
      const toast = document.getElementById('toastBox');
      document.getElementById('toastMsg').innerText = msg;
      toast.classList.add('show');
      setTimeout(() => toast.classList.remove('show'), 2500);
    }

    function generateRandomPassword() {
      const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#$';
      let pwd = '';
      for (let i = 0; i < 8; i++) {
        pwd += chars.charAt(Math.floor(Math.random() * chars.length));
      }
      document.getElementById('create_password').value = pwd;
    }

    function filterUsersTable() {
      const term1 = document.getElementById('globalUserSearch').value.toLowerCase();
      const term2 = document.getElementById('inlineUserFilter').value.toLowerCase();
      const term = term1 || term2;

      document.querySelectorAll('#usersTableBody tr.user-row').forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
      });
    }
  </script>

</body>
</html>
