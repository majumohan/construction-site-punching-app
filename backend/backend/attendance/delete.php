<?php
/**
 * SiteTracker - API Delete Attendance Record or Clean Duplicates
 */
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json; charset=UTF-8');

$user = current_user();
if (!$user) {
    send_json(['success' => false, 'message' => 'Unauthorized. Please log in first.'], 401);
}

$data = get_request_data();
$action = $data['action'] ?? $_GET['action'] ?? 'delete';

if ($action === 'clean_duplicates') {
    // 1. Find and remove duplicate punches:
    // Identical user & site within 120 seconds of each other
    $dupQuery = "
        SELECT a2.id AS duplicate_id
        FROM `attendance` a1
        JOIN `attendance` a2 
          ON (a1.supervisor_name = a2.supervisor_name OR a1.employee_id = a2.employee_id)
          AND a1.site_name = a2.site_name
          AND a1.id < a2.id
          AND ABS(TIMESTAMPDIFF(SECOND, a1.punch_in_time, a2.punch_in_time)) <= 120
    ";
    $stmt = $pdo->query($dupQuery);
    $dupIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $deletedCount = 0;
    if (!empty($dupIds)) {
        $inQuery = implode(',', array_map('intval', $dupIds));
        $deletedCount = $pdo->exec("DELETE FROM `attendance` WHERE `id` IN ($inQuery)");
    }

    // 2. Auto-close orphaned unclosed active shifts if the user has newer shifts that are already closed
    $closeOrphaned = $pdo->exec("
        UPDATE `attendance` a
        JOIN `attendance` newer 
          ON (a.supervisor_name = newer.supervisor_name OR a.employee_id = newer.employee_id)
          AND newer.punch_in_time > a.punch_in_time
        SET a.punch_out_time = newer.punch_in_time,
            a.notes = CASE 
                WHEN a.notes IS NOT NULL AND a.notes != '' THEN CONCAT(a.notes, '\n[Auto-Closed]: Shift closed before subsequent punch')
                ELSE '[Auto-Closed]: Shift closed before subsequent punch'
            END
        WHERE a.punch_out_time IS NULL
    ");

    send_json([
        'success' => true,
        'message' => "Duplicate records cleaned: {$deletedCount} removed, {$closeOrphaned} orphaned active shifts synchronized.",
        'deletedCount' => $deletedCount,
        'closedCount' => $closeOrphaned
    ]);
    exit;
}

// Single delete
$id = (int)($data['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) {
    send_json(['success' => false, 'message' => 'Invalid attendance record ID.'], 400);
}

$findStmt = $pdo->prepare("SELECT * FROM `attendance` WHERE `id` = ? LIMIT 1");
$findStmt->execute([$id]);
$record = $findStmt->fetch();

if (!$record) {
    send_json(['success' => false, 'message' => 'Attendance record not found.'], 404);
}

$del = $pdo->prepare("DELETE FROM `attendance` WHERE `id` = ?");
$del->execute([$id]);

send_json([
    'success' => true,
    'message' => "Attendance record #{$id} ({$record['site_name']} - {$record['supervisor_name']}) permanently deleted.",
    'id' => $id
]);
