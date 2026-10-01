<?php
/**
 * SiteTracker - API List Construction Sites
 * Rule: When a supervisor is currently punched in, ONLY return the active punched site!
 */
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json; charset=UTF-8');

// Optional login: if logged in, check for active shift; if not, return all active sites for mobile app selection
try {
    $user = current_user();
    $activePunchedSiteName = null;

    if ($user) {
        $empId = $user['employeeId'] ?? ('SUP-' . $user['id']);
        $chkActive = $pdo->prepare("
            SELECT `site_name` FROM `attendance` 
            WHERE (`supervisor_name` = ? OR `employee_id` = ?) 
              AND `punch_out_time` IS NULL 
            ORDER BY `punch_in_time` DESC LIMIT 1
        ");
        $chkActive->execute([$user['full_name'], $empId]);
        $activeRow = $chkActive->fetch();
        if ($activeRow && !empty($activeRow['site_name'])) {
            $activePunchedSiteName = $activeRow['site_name'];
        }
    }

    $search = trim($_GET['search'] ?? '');
    
    if ($activePunchedSiteName) {
        // Punched in: strictly show only the active punched site
        $stmt = $pdo->prepare("SELECT * FROM `sites` WHERE `name` = ? LIMIT 1");
        $stmt->execute([$activePunchedSiteName]);
    } elseif (!empty($search)) {
        $stmt = $pdo->prepare("
            SELECT * FROM `sites` 
            WHERE `status` = 'active' AND (`name` LIKE ? OR `code` LIKE ? OR `address` LIKE ? OR `client_name` LIKE ?)
            ORDER BY `id` DESC
        ");
        $term = "%{$search}%";
        $stmt->execute([$term, $term, $term, $term]);
    } else {
        $stmt = $pdo->query("SELECT * FROM `sites` WHERE `status` = 'active' ORDER BY `id` DESC");
    }

    $rows = $stmt->fetchAll();
    $sites = array_map(function($r) {
        $img = 'assets/images/green_valley.jpg';
        $nameLower = strtolower($r['name']);
        if (strpos($nameLower, 'palm') !== false) {
            $img = 'assets/images/palm_residency.jpg';
        } elseif (strpos($nameLower, 'lakeview') !== false) {
            $img = 'assets/images/lakeview_villas.jpg';
        }

        return [
            'id'              => (string)$r['id'],
            '_id'             => (string)$r['id'],
            'code'            => $r['code'],
            'name'            => $r['name'],
            'client_name'     => $r['client_name'] ?: 'Standard Project',
            'clientName'      => $r['client_name'] ?: 'Standard Project',
            'address'         => $r['address'],
            'latitude'        => (float)$r['latitude'],
            'longitude'       => (float)$r['longitude'],
            'radius_meters'   => (int)$r['radius_meters'],
            'radiusMeters'    => (int)$r['radius_meters'],
            'location'        => [
                'latitude'     => (float)$r['latitude'],
                'longitude'    => (float)$r['longitude'],
                'radiusMeters' => (int)$r['radius_meters']
            ],
            'supervisorNames' => 'Operations Assigned',
            'status'          => $r['status'] ?: 'active',
            'imageUrl'        => $img,
            'created_at'      => $r['created_at'],
            'updated_at'      => $r['updated_at']
        ];
    }, $rows);

    send_json([
        'success'        => true,
        'count'          => count($sites),
        'isPunchedIn'    => !empty($activePunchedSiteName),
        'activeSiteName' => $activePunchedSiteName,
        'sites'          => $sites,
        'data'           => $sites
    ]);
} catch (PDOException $e) {
    send_json(['success' => false, 'message' => 'Database query failed: ' . $e->getMessage()], 500);
}
