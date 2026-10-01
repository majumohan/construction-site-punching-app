<?php
/**
 * SiteTracker - API Check Active Shift
 */
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json; charset=UTF-8');

$user = current_user();
if (!$user) {
    send_json(['success' => false, 'message' => 'Unauthorized'], 401);
}

$empId = $user['employeeId'] ?? ('SUP-' . $user['id']);
$stmt = $pdo->prepare("
    SELECT * FROM `attendance` 
    WHERE (`supervisor_name` = ? OR `employee_id` = ?) 
      AND `punch_out_time` IS NULL 
    ORDER BY `punch_in_time` DESC LIMIT 1
");
$stmt->execute([$user['full_name'], $empId]);
$punch = $stmt->fetch();

if ($punch) {
    $siteId = '1';
    if (!empty($punch['site_name'])) {
        $sStmt = $pdo->prepare("SELECT `id` FROM `sites` WHERE `name` = ? LIMIT 1");
        $sStmt->execute([$punch['site_name']]);
        $sRow = $sStmt->fetch();
        if ($sRow) $siteId = (string)$sRow['id'];
    }

    send_json([
        'success' => true,
        'hasActivePunch' => true,
        'attendance' => [
            'id' => (string)$punch['id'],
            'siteId' => $siteId,
            'siteName' => $punch['site_name'],
            'punchInTime' => date('c', strtotime($punch['punch_in_time'])),
            'punchInLatitude' => (float)$punch['punch_in_latitude'],
            'punchInLongitude' => (float)$punch['punch_in_longitude'],
            'distanceMeters' => (int)$punch['distance_meters'],
            'address' => $punch['address'] ?? '',
            'isWithinGeofence' => (bool)$punch['is_within_geofence'],
            'photoUrl' => $punch['photo_url'],
            'status' => 'punched_in',
            'punchIn' => [
                'timestamp' => date('c', strtotime($punch['punch_in_time'])),
                'latitude' => (float)$punch['punch_in_latitude'],
                'longitude' => (float)$punch['punch_in_longitude'],
                'distanceFromSiteMeters' => (int)$punch['distance_meters'],
                'address' => $punch['address'] ?? '',
                'isWithinGeofence' => (bool)$punch['is_within_geofence'],
                'photoUrl' => $punch['photo_url']
            ]
        ]
    ]);
} else {
    send_json([
        'success' => true,
        'hasActivePunch' => false,
        'attendance' => null
    ]);
}
