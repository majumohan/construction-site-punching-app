<?php
/**
 * SiteTracker - API Create Construction Site
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

$name         = trim($data['name'] ?? '');
$code         = strtoupper(trim($data['code'] ?? ''));
$clientName   = trim($data['client_name'] ?? $data['clientName'] ?? 'Standard Project');
$address      = trim($data['address'] ?? '');
$latitude     = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude    = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$radiusMeters = filter_var($data['radius_meters'] ?? $data['radiusMeters'] ?? 300, FILTER_VALIDATE_INT);
$status       = in_array($data['status'] ?? '', ['active', 'inactive']) ? $data['status'] : 'active';

// Validation
if (empty($name)) {
    send_json(['success' => false, 'message' => 'Site Name is required.'], 422);
}
if (empty($code)) {
    send_json(['success' => false, 'message' => 'Site Code is required.'], 422);
}
if (empty($address)) {
    send_json(['success' => false, 'message' => 'Site Address is required.'], 422);
}
if ($latitude === false || $latitude < -90 || $latitude > 90) {
    send_json(['success' => false, 'message' => 'Please provide a valid latitude (-90 to 90).'], 422);
}
if ($longitude === false || $longitude < -180 || $longitude > 180) {
    send_json(['success' => false, 'message' => 'Please provide a valid longitude (-180 to 180).'], 422);
}
if ($radiusMeters === false || $radiusMeters <= 0) {
    $radiusMeters = 300;
}

try {
    // Check duplicate code
    $check = $pdo->prepare("SELECT `id` FROM `sites` WHERE `code` = ? LIMIT 1");
    $check->execute([$code]);
    if ($check->fetch()) {
        send_json(['success' => false, 'message' => "A construction site with code '$code' already exists."], 409);
    }

    $insert = $pdo->prepare("
        INSERT INTO `sites` (`code`, `name`, `client_name`, `address`, `latitude`, `longitude`, `radius_meters`, `status`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([$code, $name, $clientName, $address, $latitude, $longitude, $radiusMeters, $status]);
    $newId = (int)$pdo->lastInsertId();

    send_json([
        'success' => true,
        'message' => "Construction site '{$name}' created and saved to database successfully!",
        'site' => [
            'id'              => $newId,
            'code'            => $code,
            'name'            => $name,
            'client_name'     => $clientName,
            'clientName'      => $clientName,
            'address'         => $address,
            'latitude'        => $latitude,
            'longitude'       => $longitude,
            'radius_meters'   => $radiusMeters,
            'radiusMeters'    => $radiusMeters,
            'supervisorNames' => 'Operations Assigned',
            'status'          => $status
        ]
    ], 201);
} catch (PDOException $e) {
    send_json(['success' => false, 'message' => 'Database error while saving site: ' . $e->getMessage()], 500);
}
