<?php
/**
 * SiteTracker - API List Attendance Records
 * Supports standard list or user-wise daily timesheet breakdown
 */
require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=UTF-8');

if (!is_logged_in()) {
    send_json(['success' => false, 'message' => 'Unauthorized. Please log in.'], 401);
}

try {
    $where = [];
    $params = [];

    if (!empty($_GET['supervisor_id'])) {
        $where[] = '`supervisor_id` = ?';
        $params[] = $_GET['supervisor_id'];
    }
    if (!empty($_GET['employee_id'])) {
        $where[] = '`employee_id` = ?';
        $params[] = $_GET['employee_id'];
    }
    if (!empty($_GET['site_id'])) {
        $where[] = '`site_id` = ?';
        $params[] = (int)$_GET['site_id'];
    }
    if (!empty($_GET['date'])) {
        $where[] = 'DATE(`punch_in_time`) = ?';
        $params[] = $_GET['date'];
    }

    $sql = "SELECT * FROM `attendance`";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY `punch_in_time` DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $formatRecord = function($r) {
        $punchIn = strtotime($r['punch_in_time']);
        $punchOut = !empty($r['punch_out_time']) ? strtotime($r['punch_out_time']) : null;
        
        $durationFormatted = 'In Progress';
        $durationMinutes = 0;
        if ($punchOut) {
            $diffSeconds = max(0, $punchOut - $punchIn);
            $hours = floor($diffSeconds / 3600);
            $mins = floor(($diffSeconds % 3600) / 60);
            $durationMinutes = round($diffSeconds / 60);
            $durationFormatted = sprintf("%02dh %02dm", $hours, $mins);
        } else {
            $diffSeconds = max(0, time() - $punchIn);
            $hours = floor($diffSeconds / 3600);
            $mins = floor(($diffSeconds % 3600) / 60);
            $durationMinutes = round($diffSeconds / 60);
            $durationFormatted = sprintf("%02dh %02dm (Live)", $hours, $mins);
        }

        return [
            'id'                  => (int)$r['id'],
            'supervisorId'        => $r['supervisor_id'] ?? null,
            'supervisorName'      => $r['supervisor_name'],
            'employeeId'          => $r['employee_id'],
            'siteId'              => (int)($r['site_id'] ?? 0),
            'siteName'            => $r['site_name'],
            'date'                => date('Y-m-d', $punchIn),
            'punchInTime'         => $r['punch_in_time'],
            'punchInFormatted'    => date('h:i A, M d', $punchIn),
            'punchInLatitude'     => $r['punch_in_latitude'] ? (float)$r['punch_in_latitude'] : null,
            'punchInLongitude'    => $r['punch_in_longitude'] ? (float)$r['punch_in_longitude'] : null,
            'punchInCoords'       => ($r['punch_in_latitude'] && $r['punch_in_longitude']) ? number_format((float)$r['punch_in_latitude'], 4) . ', ' . number_format((float)$r['punch_in_longitude'], 4) : null,
            'distanceMeters'      => (int)($r['distance_meters'] ?? 0),
            'isWithinGeofence'    => (bool)($r['is_within_geofence'] ?? true),
            'punchInPhoto'        => $r['photo_url'] ?? null,
            'punchOutTime'        => $r['punch_out_time'] ?? null,
            'punchOutFormatted'   => $punchOut ? date('h:i A, M d', $punchOut) : 'In Progress (Active Shift)',
            'punchOutLatitude'    => $r['punch_out_latitude'] ? (float)$r['punch_out_latitude'] : null,
            'punchOutLongitude'   => $r['punch_out_longitude'] ? (float)$r['punch_out_longitude'] : null,
            'punchOutPhoto'       => $r['punch_out_photo_url'] ?? null,
            'address'             => $r['address'] ?? null,
            'notes'               => $r['notes'] ?? null,
            'durationMinutes'     => $durationMinutes,
            'duration'            => $durationFormatted
        ];
    };

    $records = array_map($formatRecord, $rows);

    // Group user-wise
    $usersWise = [];
    foreach ($records as $rec) {
        $uKey = $rec['employeeId'] ?: $rec['supervisorName'];
        if (!isset($usersWise[$uKey])) {
            $usersWise[$uKey] = [
                'supervisorName'   => $rec['supervisorName'],
                'employeeId'       => $rec['employeeId'],
                'totalShifts'      => 0,
                'totalMinutes'     => 0,
                'totalHoursText'   => '0h 00m',
                'days'             => []
            ];
        }

        $dateKey = $rec['date'];
        if (!isset($usersWise[$uKey]['days'][$dateKey])) {
            $usersWise[$uKey]['days'][$dateKey] = [
                'date'         => $dateKey,
                'dateDisplay'  => date('l, M d, Y', strtotime($dateKey)),
                'shiftsCount'  => 0,
                'totalMinutes' => 0,
                'firstIn'      => $rec['punchInFormatted'],
                'lastOut'      => $rec['punchOutFormatted'],
                'punches'      => []
            ];
        }

        $usersWise[$uKey]['totalShifts']++;
        $usersWise[$uKey]['totalMinutes'] += $rec['durationMinutes'];
        $usersWise[$uKey]['days'][$dateKey]['shiftsCount']++;
        $usersWise[$uKey]['days'][$dateKey]['totalMinutes'] += $rec['durationMinutes'];
        $usersWise[$uKey]['days'][$dateKey]['punches'][] = $rec;
    }

    // Format totals for each user and day
    foreach ($usersWise as $uKey => &$uData) {
        $hours = floor($uData['totalMinutes'] / 60);
        $mins = $uData['totalMinutes'] % 60;
        $uData['totalHoursText'] = sprintf("%dh %02dm", $hours, $mins);
        
        $dayList = [];
        foreach ($uData['days'] as $dKey => $dayData) {
            $dHours = floor($dayData['totalMinutes'] / 60);
            $dMins = $dayData['totalMinutes'] % 60;
            $dayData['totalHoursText'] = sprintf("%dh %02dm", $dHours, $dMins);
            $dayList[] = $dayData;
        }
        $uData['days'] = $dayList;
    }
    unset($uData);

    send_json([
        'success'    => true,
        'count'      => count($records),
        'usersWise'  => array_values($usersWise),
        'records'    => $records
    ]);
} catch (PDOException $e) {
    send_json(['success' => false, 'message' => 'Database query failed: ' . $e->getMessage()], 500);
}
