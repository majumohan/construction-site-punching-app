<?php
/**
 * SiteTracker - API Update / Modify Construction Site
 */
require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=UTF-8');

if (!is_logged_in()) {
    send_json(['success' => false, 'message' => 'Unauthorized. Please log in.'], 401);
}

$data = get_request_data();
$id = filter_var($data['id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT);

if (!$id) {
    send_json(['success' => false, 'message' => 'Valid Site ID is required.'], 422);
}

// Handle delete operation via update.php?action=delete or method=DELETE
$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim($data['action'] ?? $_GET['action'] ?? ''));
if ($method === 'DELETE' || $action === 'delete') {
    try {
        // Query site first to get site name and details
        $stmtSite = $pdo->prepare("SELECT `id`, `name`, `code` FROM `sites` WHERE `id` = ? LIMIT 1");
        $stmtSite->execute([$id]);
        $targetSite = $stmtSite->fetch();

        if (!$targetSite) {
            send_json(['success' => false, 'message' => 'Site not found or already deleted.'], 404);
        }

        // Permanently delete all attendance records associated with this site
        $delAtt = $pdo->prepare("DELETE FROM `attendance` WHERE `site_name` = ?");
        $delAtt->execute([$targetSite['name']]);
        $attCount = $delAtt->rowCount();

        // Permanently delete the site record
        $del = $pdo->prepare("DELETE FROM `sites` WHERE `id` = ?");
        $del->execute([$id]);

        send_json([
            'success' => true,
            'message' => "Construction site '{$targetSite['name']}' and all associated records permanently deleted ({$attCount} attendance records removed)."
        ]);
    } catch (PDOException $e) {
        send_json(['success' => false, 'message' => 'Error deleting site: ' . $e->getMessage()], 500);
    }
}

// Check existing site
$stmt = $pdo->prepare("SELECT * FROM `sites` WHERE `id` = ? LIMIT 1");
$stmt->execute([$id]);
$site = $stmt->fetch();
if (!$site) {
    send_json(['success' => false, 'message' => 'Construction site not found.'], 404);
}

// Fields to update
$name         = isset($data['name']) ? trim($data['name']) : $site['name'];
$code         = isset($data['code']) ? strtoupper(trim($data['code'])) : $site['code'];
$clientName   = isset($data['client_name']) ? trim($data['client_name']) : (isset($data['clientName']) ? trim($data['clientName']) : $site['client_name']);
$address      = isset($data['address']) ? trim($data['address']) : $site['address'];
$latitude     = isset($data['latitude']) ? filter_var($data['latitude'], FILTER_VALIDATE_FLOAT) : (float)$site['latitude'];
$longitude    = isset($data['longitude']) ? filter_var($data['longitude'], FILTER_VALIDATE_FLOAT) : (float)$site['longitude'];
$radiusMeters = isset($data['radius_meters']) ? filter_var($data['radius_meters'], FILTER_VALIDATE_INT) : (int)$site['radius_meters'];
$status       = in_array($data['status'] ?? '', ['active', 'inactive']) ? $data['status'] : $site['status'];

if (empty($name) || empty($code) || empty($address)) {
    send_json(['success' => false, 'message' => 'Name, code, and address cannot be empty.'], 422);
}

try {
    // If code is changed, check for duplicate
    if ($code !== $site['code']) {
        $check = $pdo->prepare("SELECT `id` FROM `sites` WHERE `code` = ? AND `id` != ? LIMIT 1");
        $check->execute([$code, $id]);
        if ($check->fetch()) {
            send_json(['success' => false, 'message' => "Another site with code '$code' already exists."], 409);
        }
    }

    $update = $pdo->prepare("
        UPDATE `sites`
        SET `code` = ?, `name` = ?, `client_name` = ?, `address` = ?, `latitude` = ?, `longitude` = ?, `radius_meters` = ?, `status` = ?, `updated_at` = NOW()
        WHERE `id` = ?
    ");
    $update->execute([$code, $name, $clientName, $address, $latitude, $longitude, $radiusMeters, $status, $id]);

    send_json([
        'success' => true,
        'message' => "Construction site '{$name}' updated successfully!",
        'site' => [
            'id'            => $id,
            'code'          => $code,
            'name'          => $name,
            'client_name'   => $clientName,
            'clientName'    => $clientName,
            'address'       => $address,
            'latitude'      => (float)$latitude,
            'longitude'     => (float)$longitude,
            'radius_meters' => (int)$radiusMeters,
            'radiusMeters'  => (int)$radiusMeters,
            'status'        => $status
        ]
    ]);
} catch (PDOException $e) {
    send_json(['success' => false, 'message' => 'Database error while updating site: ' . $e->getMessage()], 500);
}
