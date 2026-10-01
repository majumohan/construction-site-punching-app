<?php
/**
 * SiteTracker - API Punch Out (Photo is Optional, Notes is Optional)
 */
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json; charset=UTF-8');

$user = current_user();
if (!$user) {
    send_json(['success' => false, 'message' => 'Unauthorized. Please log in first.'], 401);
}

$data = get_request_data();
$lat   = (float)($data['latitude']  ?? 0);
$lng   = (float)($data['longitude'] ?? 0);
$notes = trim($data['notes'] ?? '');

$empId = $user['employeeId'] ?? ('SUP-' . $user['id']);
$stmt  = $pdo->prepare("
    SELECT * FROM `attendance`
    WHERE (`supervisor_name` = ? OR `employee_id` = ?)
      AND `punch_out_time` IS NULL
    ORDER BY `punch_in_time` DESC LIMIT 1
");
$stmt->execute([$user['full_name'], $empId]);
$punch = $stmt->fetch();

if (!$punch) {
    send_json(['success' => false, 'message' => 'No active shift found to punch out.'], 404);
}

// Optional punch-out photo
$outPhotoUrl = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = dirname(dirname(__DIR__)) . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $ext      = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $fileName = 'punchout_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $fileName)) {
        $outPhotoUrl = '/sitetracker/uploads/' . $fileName;
    }
}

// Build notes value to store
$existingNotes = trim($punch['notes'] ?? '');
if ($notes !== '') {
    $notesValue = $existingNotes !== ''
        ? $existingNotes . "\n[Out Notes]: " . $notes
        : '[Out Notes]: ' . $notes;
} else {
    $notesValue = $existingNotes !== '' ? $existingNotes : null;
}

$upd = $pdo->prepare("
    UPDATE `attendance`
    SET `punch_out_time`      = NOW(),
        `punch_out_latitude`  = ?,
        `punch_out_longitude` = ?,
        `punch_out_photo_url` = COALESCE(?, `punch_out_photo_url`),
        `notes`               = ?
    WHERE `id` = ?
");
$upd->execute([
    $lat ?: null,
    $lng ?: null,
    $outPhotoUrl,
    $notesValue,
    $punch['id'],
]);

// Close any dangling active shifts for this user
$updDangling = $pdo->prepare("
    UPDATE `attendance`
    SET `punch_out_time` = NOW(),
        `notes` = CASE
            WHEN `notes` IS NOT NULL AND `notes` != '' THEN CONCAT(`notes`, '\n[Auto-Closed]')
            ELSE '[Auto-Closed]'
        END
    WHERE (`supervisor_name` = ? OR `employee_id` = ?)
      AND `punch_out_time` IS NULL
      AND `id` != ?
");
$updDangling->execute([$user['full_name'], $empId, $punch['id']]);

$diffMinutes = max(1, round((time() - strtotime($punch['punch_in_time'])) / 60));

send_json([
    'success'    => true,
    'message'    => 'Shift completed and punch-out recorded successfully.',
    'attendance' => [
        'id'              => (string)$punch['id'],
        'siteName'        => $punch['site_name'],
        'punchInTime'     => date('c', strtotime($punch['punch_in_time'])),
        'punchOutTime'    => date('c'),
        'punchOutPhotoUrl'=> $outPhotoUrl,
        'notes'           => $notesValue,
        'durationMinutes' => $diffMinutes,
        'status'          => 'punched_out',
    ],
]);
