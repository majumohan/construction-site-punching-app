<?php
require_once dirname(__DIR__) . '/backend/config/database.php';
require_login('login.php');
$currentUser = current_user();

$alertSuccess = '';
$alertError = '';

// Handle direct POST submission for adding a new site
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_site') {
    $name         = trim($_POST['name'] ?? '');
    $code         = strtoupper(trim($_POST['code'] ?? ''));
    $clientName   = trim($_POST['client_name'] ?? 'Standard Project');
    $address      = trim($_POST['address'] ?? '');
    $latitude     = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude    = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $radiusMeters = filter_var($_POST['radius_meters'] ?? 300, FILTER_VALIDATE_INT);

    if (empty($name) || empty($code) || empty($address) || $latitude === false || $longitude === false) {
        $alertError = 'Please fill out all required fields with valid coordinates.';
    } else {
        try {
            $check = $pdo->prepare("SELECT `id` FROM `sites` WHERE `code` = ? LIMIT 1");
            $check->execute([$code]);
            if ($check->fetch()) {
                $alertError = "A construction site with code '$code' already exists in the database.";
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO `sites` (`code`, `name`, `client_name`, `address`, `latitude`, `longitude`, `radius_meters`, `status`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $ins->execute([$code, $name, $clientName, $address, $latitude, $longitude, $radiusMeters ?: 300]);
                $alertSuccess = "Construction site '{$name}' registered and saved to the database successfully!";
            }
        } catch (PDOException $e) {
            $alertError = "Database error: " . $e->getMessage();
        }
    }
}

// Handle direct POST submission for updating an existing site
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_site') {
    $editId       = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $name         = trim($_POST['name'] ?? '');
    $code         = strtoupper(trim($_POST['code'] ?? ''));
    $clientName   = trim($_POST['client_name'] ?? 'Standard Project');
    $address      = trim($_POST['address'] ?? '');
    $latitude     = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude    = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $radiusMeters = filter_var($_POST['radius_meters'] ?? 300, FILTER_VALIDATE_INT);
    $status       = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

    if (!$editId || empty($name) || empty($code) || empty($address) || $latitude === false || $longitude === false) {
        $alertError = 'Please fill out all required fields with valid coordinates.';
    } else {
        try {
            $check = $pdo->prepare("SELECT `id` FROM `sites` WHERE `code` = ? AND `id` != ? LIMIT 1");
            $check->execute([$code, $editId]);
            if ($check->fetch()) {
                $alertError = "Another construction site with code '$code' already exists.";
            } else {
                $upd = $pdo->prepare("
                    UPDATE `sites`
                    SET `code` = ?, `name` = ?, `client_name` = ?, `address` = ?, `latitude` = ?, `longitude` = ?, `radius_meters` = ?, `status` = ?, `updated_at` = NOW()
                    WHERE `id` = ?
                ");
                $upd->execute([$code, $name, $clientName, $address, $latitude, $longitude, $radiusMeters ?: 300, $status, $editId]);
                $alertSuccess = "Construction site '{$name}' has been updated successfully in the database!";
            }
        } catch (PDOException $e) {
            $alertError = "Database error: " . $e->getMessage();
        }
    }
}

// Handle direct POST deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_site') {
    $delId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($delId) {
        try {
            $stmtSite = $pdo->prepare("SELECT `id`, `name` FROM `sites` WHERE `id` = ? LIMIT 1");
            $stmtSite->execute([$delId]);
            $targetSite = $stmtSite->fetch();

            if ($targetSite) {
                // Permanently remove all attendance records associated with this site
                $delAtt = $pdo->prepare("DELETE FROM `attendance` WHERE `site_name` = ?");
                $delAtt->execute([$targetSite['name']]);
                $attCount = $delAtt->rowCount();

                // Permanently delete site from database
                $del = $pdo->prepare("DELETE FROM `sites` WHERE `id` = ?");
                $del->execute([$delId]);

                $alertSuccess = "Construction site '{$targetSite['name']}' and all associated site data permanently deleted ({$attCount} attendance records removed).";
            }
        } catch (PDOException $e) {
            $alertError = "Error deleting site: " . $e->getMessage();
        }
    }
}

// Fetch all updated sites directly from MySQL
$stmt = $pdo->query("SELECT * FROM `sites` ORDER BY `id` DESC");
$sites = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Construction Sites • SiteTracker Admin Hub</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <style>
    :root {
      --primary: #0066FF;
      --primary-dark: #0052CC;
      --primary-light: #EBF2FF;
      --success: #10B981;
      --success-light: #ECFDF5;
      --warning: #F59E0B;
      --danger: #EF4444;
      --danger-light: #FEF2F2;
      --slate-900: #0F172A;
      --slate-800: #1E293B;
      --slate-700: #334155;
      --slate-600: #475569;
      --slate-500: #64748B;
      --slate-400: #94A3B8;
      --slate-200: #E2E8F0;
      --slate-100: #F1F5F9;
      --slate-50: #F8FAFC;
      --radius-sm: 8px;
      --radius-md: 12px;
      --radius-lg: 16px;
      --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
    body { background-color: #F8FAFC; color: var(--slate-900); display: flex; min-height: 100vh; }

    .sidebar {
      width: 260px;
      background: #0F172A;
      color: white;
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
      position: sticky;
      top: 0;
      height: 100vh;
      border-right: 1px solid rgba(255, 255, 255, 0.08);
      z-index: 1000;
    }

    .brand {
      padding: 24px 20px;
      display: flex;
      align-items: center;
      gap: 12px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .brand-icon {
      width: 40px;
      height: 40px;
      background: linear-gradient(135deg, #0066FF, #00C6FF);
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      box-shadow: 0 4px 12px rgba(0, 102, 255, 0.35);
    }

    .brand-text h2 { font-size: 17px; font-weight: 800; letter-spacing: -0.3px; }
    .brand-text span { font-size: 11px; color: var(--slate-400); text-transform: uppercase; letter-spacing: 0.5px; }

    .nav-menu { list-style: none; padding: 20px 14px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
    .nav-item a {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      color: var(--slate-400);
      background: transparent;
      border-radius: var(--radius-md);
      font-size: 14px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .nav-item a:hover { color: white; background: rgba(255, 255, 255, 0.06); }
    .nav-item.active a { color: white; background: var(--primary); box-shadow: 0 4px 12px rgba(0, 102, 255, 0.35); }
    .nav-item a i { font-size: 16px; width: 20px; text-align: center; }

    .sidebar-footer {
      padding: 18px 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(0, 0, 0, 0.15);
    }

    .admin-avatar {
      width: 38px;
      height: 38px;
      background: #3B82F6;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 14px;
    }

    .admin-info { flex: 1; line-height: 1.3; }
    .admin-info .name { font-size: 13px; font-weight: 700; }
    .admin-info .role { font-size: 11px; color: #10B981; display: flex; align-items: center; gap: 5px; }
    .admin-info .role::before { content: ''; width: 6px; height: 6px; background: #10B981; border-radius: 50%; }

    .logout-btn {
      color: var(--slate-400);
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.1);
      width: 32px;
      height: 32px;
      border-radius: var(--radius-sm);
      display: flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      font-size: 13px;
      transition: all 0.2s ease;
      flex-shrink: 0;
    }
    .logout-btn:hover { color: #EF4444; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); }

    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .topbar {
      height: 70px;
      background: white;
      border-bottom: 1px solid var(--slate-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 32px;
      position: sticky;
      top: 0;
      z-index: 99;
    }
    .page-title { font-size: 20px; font-weight: 700; color: var(--slate-900); }

    .btn {
      padding: 8px 16px;
      border-radius: var(--radius-sm);
      font-size: 13.5px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 1px solid transparent;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .btn-outline { background: white; border-color: var(--slate-200); color: var(--slate-700); }
    .btn-outline:hover { background: var(--slate-50); border-color: var(--slate-400); }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-dark); }

    .content { padding: 32px; display: flex; flex-direction: column; gap: 24px; }

    .table-container {
      background: white;
      border: 1px solid var(--slate-200);
      border-radius: var(--radius-md);
      overflow: hidden;
      box-shadow: var(--shadow-sm);
    }

    .table-header {
      padding: 20px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid var(--slate-200);
    }
    .table-header h3 { font-size: 16px; font-weight: 700; }

    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px; }
    th {
      background: var(--slate-50);
      padding: 12px 20px;
      font-weight: 600;
      color: var(--slate-600);
      border-bottom: 1px solid var(--slate-200);
      font-size: 12.5px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    td { padding: 16px 20px; border-bottom: 1px solid var(--slate-200); color: var(--slate-800); vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background-color: rgba(248, 250, 252, 0.7); }

    .badge {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 9999px;
      font-size: 12px;
      font-weight: 600;
    }
    .badge-primary { background: var(--primary-light); color: var(--primary); }
    .badge-success { background: var(--success-light); color: var(--success); }
    .badge-warning { background: #FEF3C7; color: #D97706; }

    /* Modal */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(4px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 20px;
    }
    .modal-overlay.open { display: flex; }

    .modal-card {
      background: white;
      border-radius: var(--radius-lg);
      width: 100%;
      max-width: 520px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
      overflow: hidden;
      animation: modalIn 0.25s ease-out;
    }
    @keyframes modalIn {
      from { opacity: 0; transform: scale(0.95); }
      to { opacity: 1; transform: scale(1); }
    }

    .modal-header {
      padding: 18px 24px;
      border-bottom: 1px solid var(--slate-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .modal-header h3 { font-size: 16.5px; font-weight: 700; color: var(--slate-900); }
    .modal-close {
      background: none;
      border: none;
      font-size: 22px;
      color: var(--slate-400);
      cursor: pointer;
      line-height: 1;
    }
    .modal-close:hover { color: var(--slate-700); }

    .modal-body { padding: 24px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; font-weight: 600; color: var(--slate-700); margin-bottom: 6px; }
    .form-group input {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid var(--slate-200);
      border-radius: var(--radius-sm);
      font-size: 14px;
      outline: none;
      transition: all 0.2s;
    }
    .form-group input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }

    .modal-footer {
      padding: 16px 24px;
      background: var(--slate-50);
      border-top: 1px solid var(--slate-200);
      display: flex;
      justify-content: flex-end;
      gap: 12px;
    }
  </style>
</head>
<body>

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-icon">
        <i class="fa-solid fa-map-location-dot"></i>
      </div>
      <div class="brand-text">
        <h2>SiteTracker</h2>
        <span>Operations Hub</span>
      </div>
    </div>

    <ul class="nav-menu">
      <li class="nav-item">
        <a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
      </li>
      <li class="nav-item active">
        <a href="sites.php"><i class="fa-solid fa-city"></i> Construction Sites</a>
      </li>
      <li class="nav-item">
        <a href="attendance.php"><i class="fa-solid fa-clipboard-list"></i> Attendance Records</a>
      </li>
      <li class="nav-item">
        <a href="users.php"><i class="fa-solid fa-users-gear"></i> User Accounts</a>
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="admin-avatar"><?= htmlspecialchars($currentUser['initials']) ?></div>
      <div class="admin-info">
        <div class="name"><?= htmlspecialchars($currentUser['full_name']) ?></div>
        <div class="role"><?= htmlspecialchars($currentUser['role']) ?></div>
      </div>
      <a href="login.php?action=logout" class="logout-btn" title="Sign Out">
        <i class="fa-solid fa-arrow-right-from-bracket"></i>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main">
    <header class="topbar">
      <h1 class="page-title">Construction Sites Management</h1>
      <div class="topbar-actions">
        <button class="btn btn-primary" onclick="openModal('addSiteModal')">
          <i class="fa-solid fa-plus"></i> Add New Site
        </button>
        <a href="login.php?action=logout" class="btn btn-outline" style="color: #EF4444; border-color: rgba(239, 68, 68, 0.25);">
          <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
        </a>
      </div>
    </header>

    <div class="content">
      <?php if (!empty($alertSuccess)): ?>
        <div style="background: var(--success-light); border: 1px solid var(--success); color: #065F46; padding: 14px 20px; border-radius: var(--radius-md); display: flex; align-items: center; gap: 10px; font-weight: 500;">
          <i class="fa-solid fa-circle-check" style="color: var(--success); font-size: 18px;"></i>
          <span><?= htmlspecialchars($alertSuccess) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!empty($alertError)): ?>
        <div style="background: var(--danger-light); border: 1px solid var(--danger); color: #991B1B; padding: 14px 20px; border-radius: var(--radius-md); display: flex; align-items: center; gap: 10px; font-weight: 500;">
          <i class="fa-solid fa-circle-exclamation" style="color: var(--danger); font-size: 18px;"></i>
          <span><?= htmlspecialchars($alertError) ?></span>
        </div>
      <?php endif; ?>

      <div class="table-container">
        <div class="table-header">
          <h3>Active Construction Projects</h3>
          <div style="font-size: 13px; color: var(--slate-500);">
            <i class="fa-solid fa-database" style="color: var(--primary);"></i> Synced with MySQL <code>sitetracker.sites</code>
          </div>
        </div>
        <table>
          <thead>
            <tr>
              <th>Site Code</th>
              <th>Project Name</th>
              <th>City & Address</th>
              <th>GPS Coordinates</th>
              <th>Geofence Radius</th>
              <th>Assigned Staff</th>
              <th>Status</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody id="sitesTableBody">
            <?php if (empty($sites)): ?>
              <tr><td colspan="8" style="text-align:center; padding: 24px; color: var(--slate-500);">No construction sites in database yet. Click "Add New Site" above.</td></tr>
            <?php else: ?>
              <?php foreach ($sites as $s): ?>
                <tr>
                  <td><span class="badge badge-primary"><?= htmlspecialchars($s['code']) ?></span></td>
                  <td>
                    <strong><?= htmlspecialchars($s['name']) ?></strong><br>
                    <small style="color: var(--slate-500);"><?= htmlspecialchars($s['client_name'] ?: 'Standard Project') ?></small>
                  </td>
                  <td><?= htmlspecialchars($s['address']) ?></td>
                  <td><code><?= number_format((float)$s['latitude'], 4) ?>, <?= number_format((float)$s['longitude'], 4) ?></code></td>
                  <td><?= (int)$s['radius_meters'] ?> meters</td>
                  <td>Operations Assigned</td>
                  <td><span class="badge <?= $s['status'] === 'active' ? 'badge-success' : 'badge-warning' ?>"><?= htmlspecialchars($s['status']) ?></span></td>
                  <td style="text-align: right; white-space: nowrap;">
                    <button class="btn btn-outline" style="padding: 5px 10px; font-size: 12px; color: var(--primary); border-color: rgba(0, 102, 255, 0.3); margin-right: 4px;" onclick='openEditModal(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Edit Site Details">
                      <i class="fa-solid fa-pen-to-square"></i> Edit
                    </button>
                    <button class="btn btn-outline" style="padding: 5px 9px; font-size: 12px; color: #EF4444; border-color: rgba(239, 68, 68, 0.25);" 
                            onclick="promptDeleteSite(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['name'])) ?>', '<?= htmlspecialchars(addslashes($s['code'])) ?>', '<?= htmlspecialchars(addslashes($s['client_name'] ?? '')) ?>')" 
                            title="Delete from Database">
                      <i class="fa-solid fa-trash-can"></i>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>

  <!-- Add Site Modal -->
  <div class="modal-overlay" id="addSiteModal">
    <div class="modal-card">
      <div class="modal-header">
        <h3>Add Construction Site</h3>
        <button class="modal-close" onclick="closeModal('addSiteModal')">&times;</button>
      </div>
      <form id="addSiteForm" action="sites.php" method="POST" onsubmit="submitAddSite(event)">
        <input type="hidden" name="action" value="create_site">
        <div class="modal-body">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--slate-200);">
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
              <span style="font-size: 12px; color: var(--slate-500);"><i class="fa-solid fa-database" style="color: var(--primary);"></i> Saves to <code>backend/sites/create.php</code></span>
              <span style="background: rgba(14, 165, 233, 0.12); color: #0284c7; font-weight: 600; font-size: 11px; padding: 3px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;">
                <i class="fa-solid fa-mobile-screen"></i> Syncs to Flutter Apps
              </span>
            </div>
            <button type="button" class="btn btn-outline" style="font-size: 11.5px; padding: 4px 10px; border-color: var(--primary); color: var(--primary);" onclick="fillSampleSite()">
              <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-fill Sample
            </button>
          </div>
          <div class="form-group">
            <label>Project / Site Name *</label>
            <input type="text" name="name" required placeholder="e.g. Marina Bay Heights">
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label>Site Code *</label>
              <input type="text" name="code" required placeholder="e.g. MBH-KCH-04" style="text-transform: uppercase;">
            </div>
            <div class="form-group">
              <label>Developer / Client</label>
              <input type="text" name="client_name" placeholder="e.g. Sobha Developers">
            </div>
          </div>
          <div class="form-group">
            <label>Physical Address / Location *</label>
            <input type="text" name="address" required placeholder="e.g. Marine Drive, Kochi, Kerala">
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label>Latitude Coordinates *</label>
              <input type="number" step="any" name="latitude" required placeholder="9.9700">
            </div>
            <div class="form-group">
              <label>Longitude Coordinates *</label>
              <input type="number" step="any" name="longitude" required placeholder="76.2800">
            </div>
          </div>
          <div class="form-group">
            <label>Geofence Radius (meters) *</label>
            <input type="number" name="radius_meters" value="300" placeholder="300" required>
            <small style="font-size: 11px; color: var(--slate-500); display: block; margin-top: 4px;">Supervisor punches outside this boundary radius trigger an outside geofence alert.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline" onclick="closeModal('addSiteModal')">Cancel</button>
          <button type="submit" class="btn btn-primary" id="saveSiteBtn">
            <i class="fa-solid fa-cloud-arrow-up"></i> Save Site to Database
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Site Modal -->
  <div class="modal-overlay" id="editSiteModal">
    <div class="modal-card">
      <div class="modal-header">
        <h3>Edit Construction Site</h3>
        <button class="modal-close" onclick="closeModal('editSiteModal')">&times;</button>
      </div>
      <form id="editSiteForm" action="sites.php" method="POST" onsubmit="submitEditSite(event)">
        <input type="hidden" name="action" value="update_site">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-body">
          <div class="form-group">
            <label>Project / Site Name *</label>
            <input type="text" name="name" id="edit_name" required placeholder="e.g. Marina Bay Heights">
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label>Site Code *</label>
              <input type="text" name="code" id="edit_code" required placeholder="e.g. MBH-KCH-04" style="text-transform: uppercase;">
            </div>
            <div class="form-group">
              <label>Developer / Client</label>
              <input type="text" name="client_name" id="edit_client_name" placeholder="e.g. Sobha Developers">
            </div>
          </div>
          <div class="form-group">
            <label>Physical Address / Location *</label>
            <input type="text" name="address" id="edit_address" required placeholder="e.g. Marine Drive, Kochi, Kerala">
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label>Latitude Coordinates *</label>
              <input type="number" step="any" name="latitude" id="edit_latitude" required placeholder="9.9700">
            </div>
            <div class="form-group">
              <label>Longitude Coordinates *</label>
              <input type="number" step="any" name="longitude" id="edit_longitude" required placeholder="76.2800">
            </div>
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
              <label>Geofence Radius (meters) *</label>
              <input type="number" name="radius_meters" id="edit_radius_meters" value="300" required>
            </div>
            <div class="form-group">
              <label>Operational Status *</label>
              <select name="status" id="edit_status" style="width: 100%; padding: 10px 14px; border: 1px solid var(--slate-200); border-radius: var(--radius-sm); font-size: 14px; background: white; outline: none;">
                <option value="active">Active (Tracking Live)</option>
                <option value="inactive">Inactive / On Hold</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
          <button type="button" class="btn btn-outline" style="color: #EF4444; border-color: rgba(239, 68, 68, 0.3);" onclick="deleteSiteFromEditModal()">
            <i class="fa-solid fa-trash-can"></i> Delete Site
          </button>
          <div style="display: flex; gap: 8px;">
            <button type="button" class="btn btn-outline" onclick="closeModal('editSiteModal')">Cancel</button>
            <button type="submit" class="btn btn-primary" id="updateSiteBtn">
              <i class="fa-solid fa-check"></i> Save Changes to Database
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Site Confirmation Modal -->
  <div class="modal-overlay" id="deleteSiteConfirmModal" onclick="closeModal('deleteSiteConfirmModal')">
    <div class="modal-card" style="max-width: 460px;" onclick="event.stopPropagation()">
      <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 16px;">
        <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(239, 68, 68, 0.12); display: flex; align-items: center; justify-content: center; color: #EF4444; font-size: 20px; flex-shrink: 0;">
          <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div>
          <h3 style="font-size: 16px; font-weight: 700; color: var(--slate-900); margin-bottom: 4px;">Delete Construction Site</h3>
          <p style="font-size: 13px; color: var(--slate-500); line-height: 1.4;">
            Are you sure you want to permanently delete this site from the database? All associated attendance and punch records will also be permanently removed.
          </p>
        </div>
      </div>

      <div style="background: var(--slate-50); border: 1px solid var(--slate-200); border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 20px; font-size: 13px;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
          <span style="color: var(--slate-500);">Site Name:</span>
          <strong style="color: var(--slate-900);" id="deleteTargetSiteName">-</strong>
        </div>
        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
          <span style="color: var(--slate-500);">Site Code:</span>
          <strong style="color: var(--slate-900);" id="deleteTargetSiteCode">-</strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span style="color: var(--slate-500);">Client:</span>
          <strong style="color: var(--slate-900);" id="deleteTargetSiteClient">-</strong>
        </div>
      </div>

      <input type="hidden" id="pendingDeleteSiteId" value="">

      <div style="display: flex; justify-content: flex-end; gap: 10px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('deleteSiteConfirmModal')">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmDeleteSiteBtn" style="background: #EF4444; border-color: #DC2626;" onclick="executeDeleteSite()">
          <i class="fa-solid fa-trash-can"></i> Yes, Permanently Delete
        </button>
      </div>
    </div>
  </div>

  <script>
    function openModal(id) { document.getElementById(id).classList.add('open'); }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }

    function openEditModal(site) {
      document.getElementById('edit_id').value = site.id;
      document.getElementById('edit_name').value = site.name || '';
      document.getElementById('edit_code').value = site.code || '';
      document.getElementById('edit_client_name').value = site.client_name || site.clientName || '';
      document.getElementById('edit_address').value = site.address || '';
      document.getElementById('edit_latitude').value = site.latitude || '';
      document.getElementById('edit_longitude').value = site.longitude || '';
      document.getElementById('edit_radius_meters').value = site.radius_meters || site.radiusMeters || 300;
      document.getElementById('edit_status').value = site.status || 'active';
      openModal('editSiteModal');
    }

    function fillSampleSite() {
      const form = document.getElementById('addSiteForm');
      if (!form) return;
      const samples = [
        { name: 'Marine Drive Waterfront Heights', code: 'MDW-KCH-' + Math.floor(10 + Math.random()*90), client: 'Skyline Builders', address: 'Marine Drive Walkway, Kochi', lat: '9.9765', lng: '76.2750', radius: '350' },
        { name: 'SmartCity Tech Park Tower B', code: 'SCT-KKN-' + Math.floor(10 + Math.random()*90), client: 'SmartCity SEZ Ltd', address: 'SmartCity Road, Kakkanad, Kochi', lat: '10.0090', lng: '76.3650', radius: '500' },
        { name: 'Metro Central Station Hub', code: 'MCS-ALV-' + Math.floor(10 + Math.random()*90), client: 'KMRL Infrastructure', address: 'Aluva Metro Terminal Area, Kochi', lat: '10.1080', lng: '76.3530', radius: '400' }
      ];
      const pick = samples[Math.floor(Math.random() * samples.length)];
      form.elements['name'].value = pick.name;
      form.elements['code'].value = pick.code;
      if (form.elements['client_name']) form.elements['client_name'].value = pick.client;
      form.elements['address'].value = pick.address;
      form.elements['latitude'].value = pick.lat;
      form.elements['longitude'].value = pick.lng;
      form.elements['radius_meters'].value = pick.radius;
    }

    async function submitAddSite(e) {
      e.preventDefault();
      const form = e.target;
      const btn = document.getElementById('saveSiteBtn');
      btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Saving...';
      btn.disabled = true;

      const data = Object.fromEntries(new FormData(form).entries());

      try {
        const res = await fetch('../backend/sites/create.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) {
          closeModal('addSiteModal');
          form.reset();
          showNotification(result.message || 'Site added to database!', 'success');
          setTimeout(() => window.location.reload(), 800);
        } else {
          alert(result.message || 'Failed to save site.');
        }
      } catch (err) {
        // Fallback to standard form submit
        form.submit();
      } finally {
        btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Save Site to Database';
        btn.disabled = false;
      }
    }

    async function submitEditSite(e) {
      e.preventDefault();
      const form = e.target;
      const btn = document.getElementById('updateSiteBtn');
      btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Updating...';
      btn.disabled = true;

      const data = Object.fromEntries(new FormData(form).entries());

      try {
        const res = await fetch('../backend/sites/update.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) {
          closeModal('editSiteModal');
          showNotification(result.message || 'Site updated in database!', 'success');
          setTimeout(() => window.location.reload(), 700);
        } else {
          alert(result.message || 'Failed to update site.');
        }
      } catch (err) {
        // Fallback to standard form submit
        form.submit();
      } finally {
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Save Changes to Database';
        btn.disabled = false;
      }
    }

    function promptDeleteSite(id, name, code, client) {
      document.getElementById('pendingDeleteSiteId').value = id;
      document.getElementById('deleteTargetSiteName').textContent = name || 'Construction Site';
      document.getElementById('deleteTargetSiteCode').textContent = code || ('ID #' + id);
      document.getElementById('deleteTargetSiteClient').textContent = client || 'Standard Client';
      const btn = document.getElementById('confirmDeleteSiteBtn');
      if (btn) {
        btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Yes, Permanently Delete';
        btn.disabled = false;
      }
      openModal('deleteSiteConfirmModal');
    }

    function deleteSiteFromEditModal() {
      const id = document.getElementById('edit_id').value;
      const name = document.getElementById('edit_name').value;
      const code = document.getElementById('edit_code').value;
      const client = document.getElementById('edit_client_name').value;
      if (!id) return;
      closeModal('editSiteModal');
      promptDeleteSite(id, name, code, client);
    }

    async function executeDeleteSite() {
      const id = document.getElementById('pendingDeleteSiteId').value;
      if (!id) return;

      const btn = document.getElementById('confirmDeleteSiteBtn');
      if (btn) {
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Deleting...';
        btn.disabled = true;
      }

      try {
        const res = await fetch('../backend/sites/update.php?action=delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: id, action: 'delete' })
        });
        const result = await res.json();
        if (result.success) {
          closeModal('deleteSiteConfirmModal');
          showNotification(result.message || 'Site and all site data permanently deleted.', 'success');
          setTimeout(() => window.location.reload(), 700);
        } else {
          alert(result.message || 'Failed to delete site.');
          if (btn) {
            btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Yes, Permanently Delete';
            btn.disabled = false;
          }
        }
      } catch (e) {
        // Fallback to standard form submit
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = 'sites.php';
        f.innerHTML = `<input type="hidden" name="action" value="delete_site"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f);
        f.submit();
      }
    }

    function deleteSite(id, name) {
      promptDeleteSite(id, name, '', '');
    }

    function showNotification(msg) {
      let toast = document.createElement('div');
      toast.style.cssText = `
        position: fixed; bottom: 24px; right: 24px; z-index: 99999;
        background: #0F172A; color: white; padding: 14px 20px;
        border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.4);
        box-shadow: 0 10px 25px rgba(0,0,0,0.5); font-size: 14px;
        display: flex; align-items: center; gap: 12px;
      `;
      toast.innerHTML = `<i class="fa-solid fa-circle-check" style="color: #10B981; font-size: 18px;"></i> <span>${msg}</span>`;
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 3500);
    }
  </script>
</body>
</html>
