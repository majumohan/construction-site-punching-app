<?php
require_once dirname(__DIR__) . '/backend/config/database.php';
require_login('login.php');
$currentUser = current_user();

// Direct count of active sites from MySQL
$siteCount = $pdo->query("SELECT COUNT(*) FROM `sites` WHERE `status` = 'active'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Operations Dashboard • SiteTracker Admin Hub</title>
  
  <!-- Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  
  <!-- Leaflet Map CSS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

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
      --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
    body { background-color: #F8FAFC; color: var(--slate-900); display: flex; min-height: 100vh; }

    /* Sidebar */
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

    /* Main Workspace */
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

    .topbar-actions { display: flex; align-items: center; gap: 14px; }
    .live-indicator {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px 12px;
      background: var(--success-light);
      color: var(--success);
      border-radius: 9999px;
      font-size: 12px;
      font-weight: 600;
    }
    .pulse-dot {
      width: 8px;
      height: 8px;
      background: var(--success);
      border-radius: 50%;
      animation: pulse 1.8s infinite;
    }
    @keyframes pulse {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

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

    /* KPI Cards */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; }
    .kpi-card {
      background: white;
      border: 1px solid var(--slate-200);
      border-radius: var(--radius-md);
      padding: 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: var(--shadow-sm);
    }
    .kpi-info h3 { font-size: 28px; font-weight: 800; color: var(--slate-900); line-height: 1; margin-bottom: 6px; }
    .kpi-info p { font-size: 13px; color: var(--slate-500); font-weight: 500; }
    .kpi-icon {
      width: 48px;
      height: 48px;
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
    }
    .kpi-blue { background: var(--primary-light); color: var(--primary); }
    .kpi-green { background: var(--success-light); color: var(--success); }
    .kpi-purple { background: #F3E8FF; color: #9333EA; }

    /* Map Box */
    .map-container {
      background: white;
      border: 1px solid var(--slate-200);
      border-radius: var(--radius-lg);
      padding: 20px;
      box-shadow: var(--shadow-sm);
    }
    .map-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .map-header h3 { font-size: 16px; font-weight: 700; color: var(--slate-800); }
    #dashboardMap { height: 480px; width: 100%; border-radius: var(--radius-md); z-index: 1; }
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
      <li class="nav-item active">
        <a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
      </li>
      <li class="nav-item">
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

  <!-- Main Workspace -->
  <main class="main">
    <header class="topbar">
      <h1 class="page-title">Operations Command Dashboard</h1>
      <div class="topbar-actions">
        <div class="live-indicator">
          <div class="pulse-dot"></div>
          <span>GPS Tracking Active</span>
        </div>
        <a href="sites.php" class="btn btn-primary">
          <i class="fa-solid fa-plus"></i> Add Site
        </a>
        <a href="login.php?action=logout" class="btn btn-outline" style="color: #EF4444; border-color: rgba(239, 68, 68, 0.25);">
          <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
        </a>
      </div>
    </header>

    <div class="content">
      
      <!-- KPI Stats Grid -->
      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-info">
            <h3 id="statTotalSites"><?= $siteCount ?></h3>
            <p>Managed Construction Sites</p>
          </div>
          <div class="kpi-icon kpi-blue">
            <i class="fa-solid fa-city"></i>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-info">
            <h3 id="statActiveStaff">1</h3>
            <p>Active On-Site Supervisors</p>
          </div>
          <div class="kpi-icon kpi-green">
            <i class="fa-solid fa-person-digging"></i>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-info">
            <h3>100%</h3>
            <p>Geofence Compliance</p>
          </div>
          <div class="kpi-icon kpi-purple">
            <i class="fa-solid fa-shield-halved"></i>
          </div>
        </div>
      </div>

      <!-- Live GPS Geofence Map -->
      <div class="map-container">
        <div class="map-header">
          <h3><i class="fa-solid fa-earth-americas" style="color: var(--primary);"></i> Live Site Geofences & Active Supervisors</h3>
          <span id="mapLastUpdated" style="font-size: 12px; color: var(--slate-500);">Syncing with database...</span>
        </div>
        <div id="dashboardMap"></div>
      </div>

    </div>
  </main>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    let dashboardMap, dashboardLayerGroup;

    function initMap() {
      dashboardMap = L.map('dashboardMap').setView([9.9700, 76.2800], 12);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(dashboardMap);
      dashboardLayerGroup = L.layerGroup().addTo(dashboardMap);
    }

    async function loadLiveMap() {
      try {
        const res = await fetch('../backend/sites/list.php');
        const data = await res.json();
        
        if (data.success && data.sites) {
          dashboardLayerGroup.clearLayers();
          const bounds = [];

          data.sites.forEach(site => {
            const lat = parseFloat(site.latitude);
            const lng = parseFloat(site.longitude);
            const radius = parseInt(site.radius_meters || 300);

            if (!isNaN(lat) && !isNaN(lng)) {
              const latLng = [lat, lng];
              bounds.push(latLng);

              // Circular geofence
              L.circle(latLng, {
                color: '#0066FF',
                fillColor: '#38BDF8',
                fillOpacity: 0.15,
                radius: radius,
                weight: 1.5,
                dashArray: '4, 4'
              }).addTo(dashboardLayerGroup);

              // Building marker
              const siteIcon = L.divIcon({
                className: 'custom-site-icon',
                html: `<div style="background: #0066FF; color: white; width: 28px; height: 28px; border-radius: 8px; display:flex; align-items:center; justify-content:center; box-shadow: 0 2px 6px rgba(0,0,0,0.3);"><i class="fa-solid fa-building" style="font-size: 13px;"></i></div>`,
                iconSize: [28, 28],
                iconAnchor: [14, 14]
              });

              L.marker(latLng, { icon: siteIcon })
                .bindPopup(`<strong>${site.name}</strong><br><small>${site.code}</small><br>${site.address}<br><small style="color: #0066FF; font-weight:600;">Geofence: ${radius}m</small>`)
                .addTo(dashboardLayerGroup);
            }
          });

          // Supervisor marker demo
          const supLatLng = [9.9705, 76.2802];
          bounds.push(supLatLng);
          const supIcon = L.divIcon({
            className: 'custom-sup-icon',
            html: `<div style="background: #10B981; color: white; width: 34px; height: 34px; border-radius: 50%; display:flex; align-items:center; justify-content:center; border: 3px solid white; box-shadow: 0 4px 10px rgba(0,0,0,0.35); font-weight:800;"><i class="fa-solid fa-user" style="font-size: 14px;"></i></div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 17]
          });
          L.marker(supLatLng, { icon: supIcon })
            .bindPopup(`<strong>Vikram Sharma</strong><br><span>Site: Green Valley Apartment</span><br><b style="color:#10B981;">Verified On-Site (42m)</b>`)
            .addTo(dashboardLayerGroup);

          if (bounds.length > 0) {
            dashboardMap.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
          }

          document.getElementById('mapLastUpdated').textContent = 'Last synced: ' + new Date().toLocaleTimeString();
        }
      } catch (err) {
        console.error('Failed to load map data:', err);
      }
    }

    window.addEventListener('DOMContentLoaded', () => {
      initMap();
      loadLiveMap();
      setInterval(loadLiveMap, 15000);
    });
  </script>
</body>
</html>
