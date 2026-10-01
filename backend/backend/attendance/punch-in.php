<?php
/**
 * SiteTracker - API Punch In
 */
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json; charset=UTF-8');

$user = current_user();
if (!$user) {
    send_json(['success' => false, 'message' => 'Unauthorized. Please log in first.'], 401);
}

$data = get_request_data();
$siteId = $data['siteId'] ?? $data['site_id'] ?? '';
$lat = (float)($data['latitude'] ?? 0);
$lng = (float)($data['longitude'] ?? 0);
$address = trim($data['address'] ?? '');
$notes = trim($data['notes'] ?? '');

// Find site
$siteStmt = $pdo->prepare("SELECT * FROM `sites` WHERE `id` = ? OR `code` = ? LIMIT 1");
$siteStmt->execute([$siteId, $siteId]);
$site = $siteStmt->fetch();

if (!$site) {
    send_json(['success' => false, 'message' => 'Construction site not found or inactive.'], 404);
}

// Haversine distance
$earthRadius = 6371000;
$latFrom = deg2rad($lat);
$lonFrom = deg2rad($lng);
$latTo = deg2rad((float)$site['latitude']);
$lonTo = deg2rad((float)$site['longitude']);
$latDelta = $latTo - $latFrom;
$lonDelta = $lonTo - $lonFrom;
$angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
$distMeters = round($angle * $earthRadius);
$isWithin = $distMeters <= ($site['radius_meters'] ?? 300);

// Handle photo upload
$photoUrl = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = dirname(dirname(__DIR__)) . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $fileName = 'punchin_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $fileName)) {
        $photoUrl = '/sitetracker/uploads/' . $fileName;
    }
}

$empId = $user['employeeId'] ?? ('SUP-' . $user['id']);

// 1. Debounce check: Prevent duplicate punch-in if called within 60s for the same user & site
$recentStmt = $pdo->prepare("
    SELECT * FROM `attendance`
    WHERE (`supervisor_name` = ? OR `employee_id` = ?)
      AND `site_name` = ?
      AND `punch_in_time` >= (NOW() - INTERVAL 60 SECOND)
    ORDER BY `punch_in_time` DESC LIMIT 1
");
$recentStmt->execute([$user['full_name'], $empId, $site['name']]);
$recentPunch = $recentStmt->fetch();

if ($recentPunch) {
    send_json([
        'success' => true,
        'message' => 'Punch in already recorded (duplicate ignored).',
        'attendance' => [
            'id' => (string)$recentPunch['id'],
            'siteId' => (string)$site['id'],
            'siteName' => $recentPunch['site_name'],
            'punchInTime' => date('c', strtotime($recentPunch['punch_in_time'])),
            'punchInLatitude' => (float)$recentPunch['punch_in_latitude'],
            'punchInLongitude' => (float)$recentPunch['punch_in_longitude'],
            'distanceMeters' => (int)$recentPunch['distance_meters'],
            'address' => $recentPunch['address'] ?? ($site['address'] ?? ''),
            'isWithinGeofence' => (bool)$recentPunch['is_within_geofence'],
            'photoUrl' => $recentPunch['photo_url'],
            'status' => 'punched_in',
            'punchIn' => [
                'timestamp' => date('c', strtotime($recentPunch['punch_in_time'])),
                'latitude' => (float)$recentPunch['punch_in_latitude'],
                'longitude' => (float)$recentPunch['punch_in_longitude'],
                'address' => $recentPunch['address'] ?? ($site['address'] ?? ''),
                'photoUrl' => $recentPunch['photo_url'],
                'isWithinGeofence' => (bool)$recentPunch['is_within_geofence'],
                'distanceFromSiteMeters' => (int)$recentPunch['distance_meters']
            ]
        ]
    ], 200);
    exit;
}

// 2. Active shift check: If user already has an active shift
$activeStmt = $pdo->prepare("
    SELECT * FROM `attendance`
    WHERE (`supervisor_name` = ? OR `employee_id` = ?)
      AND `punch_out_time` IS NULL
    ORDER BY `punch_in_time` DESC
");
$activeStmt->execute([$user['full_name'], $empId]);
$activeRows = $activeStmt->fetchAll();

if (!empty($activeRows)) {
    // If an active shift already exists for this exact site, return it instead of creating a duplicate
    foreach ($activeRows as $ar) {
        if ($ar['site_name'] === $site['name']) {
            send_json([
                'success' => true,
                'message' => 'Active shift already exists for this site.',
                'attendance' => [
                    'id' => (string)$ar['id'],
                    'siteId' => (string)$site['id'],
                    'siteName' => $ar['site_name'],
                    'punchInTime' => date('c', strtotime($ar['punch_in_time'])),
                    'punchInLatitude' => (float)$ar['punch_in_latitude'],
                    'punchInLongitude' => (float)$ar['punch_in_longitude'],
                    'distanceMeters' => (int)$ar['distance_meters'],
                    'address' => $ar['address'] ?? ($site['address'] ?? ''),
                    'isWithinGeofence' => (bool)$ar['is_within_geofence'],
                    'photoUrl' => $ar['photo_url'],
                    'status' => 'punched_in',
                    'punchIn' => [
                        'timestamp' => date('c', strtotime($ar['punch_in_time'])),
                        'latitude' => (float)$ar['punch_in_latitude'],
                        'longitude' => (float)$ar['punch_in_longitude'],
                        'address' => $ar['address'] ?? ($site['address'] ?? ''),
                        'photoUrl' => $ar['photo_url'],
                        'isWithinGeofence' => (bool)$ar['is_within_geofence'],
                        'distanceFromSiteMeters' => (int)$ar['distance_meters']
                    ]
                ]
            ], 200);
            exit;
        }
    }

    // If active shift was on a different site, auto-close previous shifts so there is never multiple live shifts
    $closeStmt = $pdo->prepare("
        UPDATE `attendance`
        SET `punch_out_time` = NOW(),
            `notes` = CASE
                WHEN `notes` IS NOT NULL AND `notes` != '' THEN CONCAT(`notes`, '\n[Auto-Closed]: Shift closed upon new site punch-in')
                ELSE '[Auto-Closed]: Shift closed upon new site punch-in'
            END
        WHERE (`supervisor_name` = ? OR `employee_id` = ?)
          AND `punch_out_time` IS NULL
    ");
    $closeStmt->execute([$user['full_name'], $empId]);
}

// Insert into attendance
$ins = $pdo->prepare("
    INSERT INTO `attendance` (
        `supervisor_name`, `employee_id`, `site_name`, 
        `punch_in_time`, `punch_in_latitude`, `punch_in_longitude`, 
        `distance_meters`, `address`, `is_within_geofence`, `notes`, `photo_url`
    )
    VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
");
$ins->execute([
    $user['full_name'],
    $empId,
    $site['name'],
    $lat,
    $lng,
    $distMeters,
    $address ?: ($site['address'] ?? 'Site Location Coordinates Verified'),
    $isWithin ? 1 : 0,
    $notes ?: null,
    $photoUrl
]);
$attId = $pdo->lastInsertId();

send_json([
    'success' => true,
    'message' => 'Punch in verified and recorded successfully!',
    'attendance' => [
        'id' => (string)$attId,
        'siteId' => (string)$site['id'],
        'siteName' => $site['name'],
        'punchInTime' => date('c'),
        'punchInLatitude' => $lat,
        'punchInLongitude' => $lng,
        'distanceMeters' => $distMeters,
        'address' => $address ?: ($site['address'] ?? ''),
        'isWithinGeofence' => $isWithin,
        'photoUrl' => $photoUrl,
        'status' => 'punched_in',
        'punchIn' => [
            'timestamp' => date('c'),
            'latitude' => $lat,
            'longitude' => $lng,
            'address' => $address ?: ($site['address'] ?? ''),
            'photoUrl' => $photoUrl,
            'isWithinGeofence' => $isWithin,
            'distanceFromSiteMeters' => $distMeters
        ]
    ]
], 201);
