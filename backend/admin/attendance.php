<?php
require_once dirname(__DIR__) . '/backend/config/database.php';
require_login('login.php');
$currentUser = current_user();

$alertSuccess = '';
$alertError = '';

// Ensure overtime_hours column exists in attendance table
try {
    $pdo->exec("ALTER TABLE `attendance` ADD COLUMN IF NOT EXISTS `overtime_hours` DECIMAL(5,2) DEFAULT NULL");
} catch (Exception $e) {}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_attendance') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $pdo->prepare("DELETE FROM `attendance` WHERE `id` = ?");
            $del->execute([$id]);
            $alertSuccess = "Attendance record #{$id} has been permanently deleted.";
        }
    } elseif ($action === 'update_working_hours') {
        $hours = (int)($_POST['working_hours'] ?? 8);
        if ($hours >= 1 && $hours <= 24) {
            $pdo->prepare("INSERT INTO `system_meta` (`meta_key`, `meta_value`) VALUES ('working_hours', ?) ON DUPLICATE KEY UPDATE `meta_value` = ?")->execute([$hours, $hours]);
            $alertSuccess = "Standard daily working hours updated to {$hours}h.";
        }
    } elseif ($action === 'set_record_overtime') {
        $id = (int)($_POST['id'] ?? 0);
        $otVal = trim($_POST['overtime_hours'] ?? '');
        $otNote = trim($_POST['overtime_note'] ?? '');
        if ($id > 0) {
            if ($otVal === '' || $otVal === 'auto') {
                $pdo->prepare("UPDATE `attendance` SET `overtime_hours` = NULL WHERE `id` = ?")->execute([$id]);
                $alertSuccess = "Record #{$id} overtime reset to automatic calculation.";
            } else {
                $hoursFloat = max(0, floatval($otVal));
                $isOt = $hoursFloat > 0 ? 1 : 0;
                $pdo->prepare("UPDATE `attendance` SET `overtime_hours` = ?, `is_overtime` = ? WHERE `id` = ?")->execute([$hoursFloat, $isOt, $id]);
                if (!empty($otNote)) {
                    $pdo->prepare("UPDATE `attendance` SET `notes` = CASE WHEN `notes` IS NOT NULL AND `notes` != '' THEN CONCAT(`notes`, '\n[Admin OT Note]: ', ?) ELSE CONCAT('[Admin OT Note]: ', ?) END WHERE `id` = ?")->execute([$otNote, $otNote, $id]);
                }
                $alertSuccess = "Successfully set overtime for record #{$id} to {$hoursFloat} hours.";
            }
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids) && is_array($ids)) {
            $sanitizedIds = array_filter(array_map('intval', $ids));
            if (!empty($sanitizedIds)) {
                $inQuery = implode(',', $sanitizedIds);
                $count = $pdo->exec("DELETE FROM `attendance` WHERE `id` IN ($inQuery)");
                $alertSuccess = "Successfully deleted {$count} selected attendance records.";
            }
        }
    } elseif ($action === 'clean_duplicates') {
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

        $closeOrphaned = $pdo->exec("
            UPDATE `attendance` a
            JOIN `attendance` newer 
              ON (a.supervisor_name = newer.supervisor_name OR a.employee_id = newer.employee_id)
              AND newer.punch_in_time > a.punch_in_time
            SET a.punch_out_time = newer.punch_in_time,
                a.notes = CASE 
                    WHEN a.notes IS NOT NULL AND a.notes != '' THEN CONCAT(a.notes, '\n[Auto-Closed]: Synchronized before subsequent punch')
                    ELSE '[Auto-Closed]: Synchronized before subsequent punch'
                END
            WHERE a.punch_out_time IS NULL
        ");

        $alertSuccess = "Cleaned {$deletedCount} duplicate punch records and synchronized {$closeOrphaned} orphaned active shifts.";
    }
}

// Fetch standard working hours setting
$whStmt = $pdo->query("SELECT `meta_value` FROM `system_meta` WHERE `meta_key` = 'working_hours'");
$wh = $whStmt->fetchColumn();
$standardWorkingHours = $wh ? (int)$wh : 8;
$standardWorkingSeconds = $standardWorkingHours * 3600;

// Fetch filter lists
$allUsersStmt = $pdo->query("SELECT `id`, `full_name`, `username`, `employee_id`, `department` FROM `users` ORDER BY `full_name` ASC");
$allUsersList = $allUsersStmt->fetchAll();

$allSitesStmt = $pdo->query("SELECT `id`, `name` FROM `sites` ORDER BY `name` ASC");
$allSitesList = $allSitesStmt->fetchAll();

// Get distinct available months in attendance records for the month switcher
$availMonthsStmt = $pdo->query("SELECT DISTINCT DATE_FORMAT(punch_in_time, '%Y-%m') as ym FROM `attendance` WHERE punch_in_time IS NOT NULL ORDER BY ym DESC");
$availableMonths = $availMonthsStmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($availableMonths)) {
    $availableMonths = [date('Y-m')];
}
if (!in_array(date('Y-m'), $availableMonths)) {
    array_unshift($availableMonths, date('Y-m'));
}

// ─── FILTERS & SEARCH PARAMETERS ───
$filterUser = trim($_GET['user'] ?? '');
$filterSite = trim($_GET['site'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterMonth = trim($_GET['month'] ?? date('Y-m'));
$filterDateRange = trim($_GET['date_range'] ?? '');
$filterSearch = trim($_GET['search'] ?? '');
$pageSize = (int)($_GET['page_size'] ?? 8);
if ($pageSize < 4) $pageSize = 8;
$page = max(1, (int)($_GET['page'] ?? 1));

// ─── ACCURATE WORKING DAYS & MONTHLY OVERTIME CALCULATIONS ───
$statsWhere = ["1=1"];
$statsParams = [];
$statsPeriodLabel = '';

if ($filterDateRange !== '') {
    $dates = explode(' - ', $filterDateRange);
    if (count($dates) === 2) {
        $d1 = date('Y-m-d', strtotime(str_replace('/', '-', trim($dates[0]))));
        $d2 = date('Y-m-d', strtotime(str_replace('/', '-', trim($dates[1]))));
        $statsWhere[] = "DATE(punch_in_time) BETWEEN ? AND ?";
        $statsParams[] = $d1;
        $statsParams[] = $d2;
        $statsPeriodLabel = date('M d, Y', strtotime($d1)) . ' to ' . date('M d, Y', strtotime($d2));
    } elseif (count($dates) === 1 && !empty(trim($dates[0]))) {
        $d1 = date('Y-m-d', strtotime(str_replace('/', '-', trim($dates[0]))));
        $statsWhere[] = "DATE(punch_in_time) = ?";
        $statsParams[] = $d1;
        $statsPeriodLabel = date('M d, Y', strtotime($d1));
    }
} elseif ($filterMonth !== '' && $filterMonth !== 'all') {
    $statsWhere[] = "DATE_FORMAT(punch_in_time, '%Y-%m') = ?";
    $statsParams[] = $filterMonth;
    $statsPeriodLabel = date('F Y', strtotime($filterMonth . '-01'));
} else {
    $statsPeriodLabel = "All Time Records";
}

// Query all logs in this stats period
$statsSql = "SELECT * FROM `attendance` WHERE " . implode(' AND ', $statsWhere) . " ORDER BY `punch_in_time` ASC";
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($statsParams);
$periodLogs = $statsStmt->fetchAll();

// Group records by user and calendar day
$userStats = []; // [uKey] => ['name', 'emp_id', 'days' => [dayKey => ['dur', 'manual_ot_sum', 'manual_ot_cnt', 'records']]]
$recordOvertimeMap = [];
$orgTotalPeriodWorkedSec = 0;
$orgTotalPeriodOtSec = 0;

foreach ($periodLogs as $pl) {
    $uKey = !empty($pl['supervisor_name']) ? trim($pl['supervisor_name']) : ('USER_' . $pl['employee_id']);
    $dayKey = substr($pl['punch_in_time'], 0, 10);
    $recId = $pl['id'];

    $pIn = strtotime($pl['punch_in_time']);
    $pOut = !empty($pl['punch_out_time']) ? strtotime($pl['punch_out_time']) : time();
    $dur = max(0, $pOut - $pIn);

    if (!isset($userStats[$uKey])) {
        $userStats[$uKey] = [
            'name' => $pl['supervisor_name'] ?: ('Employee ' . $pl['employee_id']),
            'emp_id' => $pl['employee_id'] ?: '',
            'days' => []
        ];
    }

    if (!isset($userStats[$uKey]['days'][$dayKey])) {
        $userStats[$uKey]['days'][$dayKey] = [
            'duration' => 0,
            'manual_ot_sum' => 0,
            'manual_ot_count' => 0,
            'records' => []
        ];
    }

    $userStats[$uKey]['days'][$dayKey]['duration'] += $dur;
    $userStats[$uKey]['days'][$dayKey]['records'][] = $pl;

    if ($pl['overtime_hours'] !== null && is_numeric($pl['overtime_hours'])) {
        $otSec = max(0, floatval($pl['overtime_hours']) * 3600);
        $userStats[$uKey]['days'][$dayKey]['manual_ot_sum'] += $otSec;
        $userStats[$uKey]['days'][$dayKey]['manual_ot_count']++;
        $recordOvertimeMap[$recId] = $otSec;
    }
}

// Compute accurate summaries per user
$userSummaries = []; // [uKey] => summary array

foreach ($userStats as $uKey => $uData) {
    $uTotalDur = 0;
    $uTotalOt = 0;
    $uDaysCount = count($uData['days']);

    foreach ($uData['days'] as $dayKey => $dData) {
        $dayDur = $dData['duration'];
        $uTotalDur += $dayDur;

        if ($dData['manual_ot_count'] > 0) {
            $dayOt = $dData['manual_ot_sum'];
        } else {
            $dayOt = max(0, $dayDur - $standardWorkingSeconds);
        }
        $uTotalOt += $dayOt;

        // Assign individual record overtimes for display in table
        foreach ($dData['records'] as $r) {
            $rId = $r['id'];
            if (!isset($recordOvertimeMap[$rId])) {
                if (count($dData['records']) === 1) {
                    $recordOvertimeMap[$rId] = $dayOt;
                } else {
                    $rIn = strtotime($r['punch_in_time']);
                    $rOut = !empty($r['punch_out_time']) ? strtotime($r['punch_out_time']) : time();
                    $rDur = max(0, $rOut - $rIn);
                    $recordOvertimeMap[$rId] = round(($rDur / max(1, $dayDur)) * $dayOt);
                }
            }
        }
    }

    $userSummaries[$uKey] = [
        'name' => $uData['name'],
        'emp_id' => $uData['emp_id'],
        'working_days' => $uDaysCount,
        'total_seconds' => $uTotalDur,
        'overtime_seconds' => $uTotalOt,
        'standard_target_seconds' => $uDaysCount * $standardWorkingSeconds,
        'avg_daily_seconds' => $uDaysCount > 0 ? round($uTotalDur / $uDaysCount) : 0,
    ];

    $orgTotalPeriodWorkedSec += $uTotalDur;
    $orgTotalPeriodOtSec += $uTotalOt;
}

// Find selected user summary if filtered
$selectedUserSummary = null;
$selectedUserKey = '';
if ($filterUser !== '') {
    foreach ($userSummaries as $uKey => $uSumm) {
        if (strcasecmp($uKey, $filterUser) === 0 || strcasecmp($uSumm['name'], $filterUser) === 0 || strcasecmp($uSumm['emp_id'], $filterUser) === 0) {
            $selectedUserSummary = $uSumm;
            $selectedUserKey = $uKey;
            break;
        }
    }
    // If not found in periodLogs, try finding in user table
    if (!$selectedUserSummary) {
        $selectedUserSummary = [
            'name' => $filterUser,
            'emp_id' => '',
            'working_days' => 0,
            'total_seconds' => 0,
            'overtime_seconds' => 0,
            'standard_target_seconds' => 0,
            'avg_daily_seconds' => 0
        ];
    }
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_overtime_export_' . date('Y-m-d_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Employee Name', 'Employee ID', 'Site Name', 'Date', 'Punch In', 'Punch Out', 'Shift Duration (Hours)', 'Daily Overtime (Hours)', 'Overtime Mode', 'Status', 'Geofence Status', 'Address', 'Notes']);
    
    $exportStmt = $pdo->query("SELECT * FROM `attendance` ORDER BY `punch_in_time` DESC");
    while ($row = $exportStmt->fetch()) {
        $uKey = !empty($row['supervisor_name']) ? trim($row['supervisor_name']) : ('USER_' . $row['employee_id']);
        $pIn = strtotime($row['punch_in_time']);
        $pOut = !empty($row['punch_out_time']) ? strtotime($row['punch_out_time']) : null;
        $durationHours = $pOut ? round(($pOut - $pIn) / 3600, 2) : round((time() - $pIn) / 3600, 2);
        
        $recOtSec = $recordOvertimeMap[$row['id']] ?? ($row['overtime_hours'] !== null ? (floatval($row['overtime_hours']) * 3600) : 0);
        $recOtHours = round($recOtSec / 3600, 2);
        $otMode = ($row['overtime_hours'] !== null) ? 'Admin Approved / Set' : 'Auto-Calculated';

        $status = empty($row['punch_out_time']) ? 'Active' : ($recOtSec > 0 ? 'Overtime' : 'Completed');
        $geo = !empty($row['is_within_geofence']) ? 'Inside Geofence' : 'Outside Geofence';

        fputcsv($output, [
            $row['id'],
            $row['supervisor_name'] ?: 'N/A',
            $row['employee_id'] ?: 'N/A',
            $row['site_name'] ?: 'N/A',
            date('Y-m-d', $pIn),
            date('h:i:s A', $pIn),
            $pOut ? date('h:i:s A', $pOut) : '--',
            $durationHours,
            $recOtHours,
            $otMode,
            $status,
            $geo,
            $row['address'] ?: 'N/A',
            $row['notes'] ?: ''
        ]);
    }
    fclose($output);
    exit;
}

// Global Metrics Calculation
$todayDate = date('Y-m-d');
$totalUsersCount = (int)$pdo->query("SELECT COUNT(*) FROM `users` WHERE `status` = 'active'")->fetchColumn();
if ($totalUsersCount === 0) {
    $totalUsersCount = (int)$pdo->query("SELECT COUNT(DISTINCT supervisor_name) FROM `attendance`")->fetchColumn() ?: 24;
}

// Present Today
$presentTodayCount = (int)$pdo->query("SELECT COUNT(DISTINCT supervisor_name) FROM `attendance` WHERE DATE(punch_in_time) = '$todayDate'")->fetchColumn();
$presentPercentage = $totalUsersCount > 0 ? round(($presentTodayCount / $totalUsersCount) * 100) : 0;

// Active Shifts
$activeShiftsCount = (int)$pdo->query("SELECT COUNT(*) FROM `attendance` WHERE `punch_out_time` IS NULL")->fetchColumn();

// ─── BUILD PAGINATED MAIN QUERY ───
$where = ["1=1"];
$params = [];

if ($filterUser !== '') {
    $where[] = "(supervisor_name = ? OR employee_id = ?)";
    $params[] = $filterUser;
    $params[] = $filterUser;
}

if ($filterSite !== '') {
    $where[] = "site_name = ?";
    $params[] = $filterSite;
}

if ($filterStatus !== '') {
    if ($filterStatus === 'active') {
        $where[] = "punch_out_time IS NULL";
    } elseif ($filterStatus === 'completed') {
        $where[] = "punch_out_time IS NOT NULL";
    } elseif ($filterStatus === 'overtime') {
        $where[] = "(overtime_hours > 0 OR is_overtime = 1 OR TIMESTAMPDIFF(SECOND, punch_in_time, IFNULL(punch_out_time, NOW())) > $standardWorkingSeconds)";
    }
}

if ($filterDateRange !== '') {
    $dates = explode(' - ', $filterDateRange);
    if (count($dates) === 2) {
        $d1 = date('Y-m-d', strtotime(str_replace('/', '-', trim($dates[0]))));
        $d2 = date('Y-m-d', strtotime(str_replace('/', '-', trim($dates[1]))));
        $where[] = "DATE(punch_in_time) BETWEEN ? AND ?";
        $params[] = $d1;
        $params[] = $d2;
    } elseif (count($dates) === 1 && !empty(trim($dates[0]))) {
        $d1 = date('Y-m-d', strtotime(str_replace('/', '-', trim($dates[0]))));
        $where[] = "DATE(punch_in_time) = ?";
        $params[] = $d1;
    }
} elseif ($filterMonth !== '' && $filterMonth !== 'all') {
    $where[] = "DATE_FORMAT(punch_in_time, '%Y-%m') = ?";
    $params[] = $filterMonth;
}

if ($filterSearch !== '') {
    $where[] = "(supervisor_name LIKE ? OR employee_id LIKE ? OR site_name LIKE ? OR address LIKE ? OR notes LIKE ?)";
    $sTerm = "%{$filterSearch}%";
    $params[] = $sTerm;
    $params[] = $sTerm;
    $params[] = $sTerm;
    $params[] = $sTerm;
    $params[] = $sTerm;
}

$whereSql = implode(' AND ', $where);

// Count Total Filtered Records
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM `attendance` WHERE $whereSql");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRecords / $pageSize));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $pageSize;

// Fetch Filtered & Paginated Records
$recordsQuery = "SELECT * FROM `attendance` WHERE $whereSql ORDER BY `punch_in_time` DESC LIMIT $pageSize OFFSET $offset";
$recordsStmt = $pdo->prepare($recordsQuery);
$recordsStmt->execute($params);
$records = $recordsStmt->fetchAll();

// Avatar Palette Helper
$avatarColors = [
    ['bg' => '#0066FF', 'color' => '#FFFFFF'],
    ['bg' => '#8B5CF6', 'color' => '#FFFFFF'],
    ['bg' => '#EC4899', 'color' => '#FFFFFF'],
    ['bg' => '#F97316', 'color' => '#FFFFFF'],
    ['bg' => '#14B8A6', 'color' => '#FFFFFF'],
    ['bg' => '#6366F1', 'color' => '#FFFFFF'],
    ['bg' => '#E11D48', 'color' => '#FFFFFF'],
    ['bg' => '#0D9488', 'color' => '#FFFFFF'],
    ['bg' => '#3B82F6', 'color' => '#FFFFFF'],
];

function getAvatarStyle($name) {
    global $avatarColors;
    $hash = crc32($name);
    $idx = abs($hash) % count($avatarColors);
    return $avatarColors[$idx];
}

function getInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2)) ?: 'ST';
}

function extractLocationArea($address) {
    if (empty($address)) return 'On Site';
    $parts = explode(',', $address);
    return trim($parts[0]);
}

function formatDurationHms($seconds) {
    $seconds = max(0, (int)$seconds);
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $h, $m, $s);
}

function formatDurationHmShort($seconds) {
    $seconds = max(0, (int)$seconds);
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    if ($h > 0) {
        return "{$h}h {$m}m";
    }
    return "{$m}m";
}

$currentAdminName = $currentUser['full_name'] ?? 'Chief Operations Admin';
$currentAdminRole = ucfirst($currentUser['role'] ?? 'Super Admin');
$adminInitials = getInitials($currentAdminName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance & Overtime Management • SiteTracker Operations Hub</title>
  
  <!-- Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  
  <!-- Flatpickr Datepicker -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <style>
    :root {
      --primary: #0066FF;
      --primary-hover: #0052CC;
      --primary-light: #EBF2FF;
      --success: #10B981;
      --success-light: #ECFDF5;
      --warning: #F59E0B;
      --warning-light: #FFFBEB;
      --danger: #EF4444;
      --danger-light: #FEF2F2;
      --purple: #8B5CF6;
      --purple-light: #F5F3FF;
      --slate-900: #0B132B;
      --slate-850: #0F172A;
      --slate-800: #1E293B;
      --slate-700: #334155;
      --slate-600: #475569;
      --slate-500: #64748B;
      --slate-400: #94A3B8;
      --slate-300: #CBD5E1;
      --slate-200: #E2E8F0;
      --slate-100: #F1F5F9;
      --slate-50:  #F8FAFC;
      --white: #FFFFFF;
      --font-main: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: var(--font-main);
      background-color: #F8FAFC;
      color: var(--slate-800);
      min-height: 100vh;
      display: flex;
      -webkit-font-smoothing: antialiased;
    }

    /* ─── SIDEBAR NAVIGATION ─── */
    .sidebar {
      width: 260px;
      background: #0B132B;
      color: #FFFFFF;
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
      position: sticky;
      top: 0;
      height: 100vh;
      z-index: 100;
      transition: all 0.3s ease;
    }

    .sidebar-header {
      padding: 24px 20px 20px 20px;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .brand-logo-icon {
      width: 40px;
      height: 40px;
      background: linear-gradient(135deg, #0066FF 0%, #3B82F6 100%);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      color: #FFFFFF;
      box-shadow: 0 4px 12px rgba(0, 102, 255, 0.35);
    }

    .brand-text {
      display: flex;
      flex-direction: column;
    }

    .brand-title {
      font-size: 18px;
      font-weight: 800;
      letter-spacing: -0.5px;
      color: #FFFFFF;
      line-height: 1.2;
    }

    .brand-subtitle {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1.5px;
      color: #64748B;
      text-transform: uppercase;
    }

    .sidebar-menu {
      list-style: none;
      padding: 16px 12px;
      flex-grow: 1;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .menu-item a {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      color: #94A3B8;
      text-decoration: none;
      font-size: 13.5px;
      font-weight: 600;
      border-radius: 10px;
      transition: all 0.2s ease;
    }

    .menu-item a:hover {
      background: rgba(255, 255, 255, 0.06);
      color: #FFFFFF;
    }

    .menu-item.active a {
      background: #0066FF;
      color: #FFFFFF;
      box-shadow: 0 4px 14px rgba(0, 102, 255, 0.4);
    }

    .menu-item a i {
      font-size: 16px;
      width: 20px;
      text-align: center;
    }

    .sidebar-footer {
      padding: 16px 14px;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(0, 0, 0, 0.15);
    }

    .user-profile-badge {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .user-avatar-circle {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: linear-gradient(135deg, #8B5CF6, #6366F1);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 13px;
      box-shadow: 0 2px 8px rgba(139, 92, 246, 0.3);
    }

    .user-profile-info {
      flex-grow: 1;
      overflow: hidden;
    }

    .user-profile-name {
      display: block;
      font-size: 13px;
      font-weight: 700;
      color: #FFFFFF;
      white-space: nowrap;
      text-overflow: ellipsis;
      overflow: hidden;
    }

    .user-profile-role {
      display: block;
      font-size: 11px;
      color: #64748B;
      white-space: nowrap;
      text-overflow: ellipsis;
      overflow: hidden;
    }

    .logout-btn {
      color: #94A3B8;
      font-size: 15px;
      text-decoration: none;
      padding: 6px;
      border-radius: 6px;
      transition: all 0.2s;
    }

    .logout-btn:hover {
      color: #EF4444;
      background: rgba(239, 68, 68, 0.1);
    }

    /* ─── MAIN CONTENT AREA ─── */
    .main-wrapper {
      flex-grow: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
      background-color: #F8FAFC;
    }

    /* ─── TOP NAVBAR ─── */
    .top-navbar {
      height: 64px;
      background: #FFFFFF;
      border-bottom: 1px solid var(--slate-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 24px;
      position: sticky;
      top: 0;
      z-index: 90;
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 16px;
      flex-grow: 1;
      max-width: 480px;
    }

    .sidebar-toggle {
      background: none;
      border: none;
      font-size: 18px;
      color: var(--slate-600);
      cursor: pointer;
      padding: 4px;
    }

    .search-input-box {
      position: relative;
      width: 100%;
    }

    .search-input-box i {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--slate-400);
      font-size: 13px;
    }

    .search-input-box input {
      width: 100%;
      height: 38px;
      padding: 0 14px 0 36px;
      background: #F1F5F9;
      border: 1px solid transparent;
      border-radius: 9px;
      font-size: 13px;
      font-family: var(--font-main);
      color: var(--slate-800);
      transition: all 0.2s;
    }

    .search-input-box input:focus {
      outline: none;
      background: #FFFFFF;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
    }

    .header-right {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .btn-wh-settings {
      display: flex;
      align-items: center;
      gap: 7px;
      padding: 7px 14px;
      background: #F1F5F9;
      color: var(--slate-700);
      border: 1px solid var(--slate-300);
      border-radius: 9px;
      font-size: 12.5px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s;
    }

    .btn-wh-settings:hover {
      background: #E2E8F0;
      color: var(--slate-900);
    }

    .btn-export-top {
      display: flex;
      align-items: center;
      gap: 7px;
      padding: 7px 15px;
      background: #0066FF;
      color: white;
      text-decoration: none;
      border-radius: 9px;
      font-size: 12.5px;
      font-weight: 700;
      box-shadow: 0 2px 7px rgba(0, 102, 255, 0.25);
      transition: all 0.2s;
    }

    .btn-export-top:hover {
      background: #0052CC;
      transform: translateY(-1px);
    }

    /* ─── PAGE CONTAINER ─── */
    .page-container {
      padding: 20px 24px 32px 24px;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    /* Alert Banner */
    .alert-banner {
      padding: 12px 16px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 10px;
      animation: fadeIn 0.3s ease;
    }

    .alert-banner.success {
      background: var(--success-light);
      color: #065F46;
      border: 1px solid #A7F3D0;
    }

    /* Page Heading Row */
    .page-title-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }

    .page-heading-block h1 {
      font-size: 22px;
      font-weight: 800;
      color: var(--slate-900);
      letter-spacing: -0.5px;
    }

    .page-heading-block p {
      font-size: 13px;
      color: var(--slate-500);
      margin-top: 2px;
    }

    .page-date-badge {
      display: flex;
      align-items: center;
      gap: 8px;
      background: #FFFFFF;
      padding: 7px 14px;
      border-radius: 10px;
      border: 1px solid var(--slate-200);
      font-size: 12.5px;
      font-weight: 700;
      color: var(--slate-700);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }

    /* ─── 4 KPI SUMMARY CARDS ─── */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
    }

    @media (max-width: 1100px) {
      .kpi-grid { grid-template-columns: repeat(2, 1fr); }
    }

    @media (max-width: 640px) {
      .kpi-grid { grid-template-columns: 1fr; }
    }

    .kpi-card {
      background: #FFFFFF;
      border-radius: 14px;
      padding: 16px 18px;
      border: 1px solid var(--slate-200);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.02);
      display: flex;
      align-items: center;
      gap: 14px;
      transition: all 0.2s ease;
    }

    .kpi-card:hover {
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.05);
      border-color: var(--slate-300);
    }

    .kpi-icon-box {
      width: 46px;
      height: 46px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      flex-shrink: 0;
    }

    .kpi-icon-blue   { background: #EBF2FF; color: #0066FF; }
    .kpi-icon-green  { background: #ECFDF5; color: #10B981; }
    .kpi-icon-orange { background: #FFF7ED; color: #F97316; }
    .kpi-icon-purple { background: #F5F3FF; color: #8B5CF6; }

    .kpi-body {
      display: flex;
      flex-direction: column;
      gap: 2px;
      min-width: 0;
    }

    .kpi-label {
      font-size: 11.5px;
      font-weight: 700;
      color: var(--slate-500);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .kpi-val {
      font-size: 22px;
      font-weight: 800;
      color: var(--slate-900);
      line-height: 1.15;
    }

    .kpi-sub {
      font-size: 11px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .kpi-sub-green  { color: #059669; }
    .kpi-sub-orange { color: #EA580C; }
    .kpi-sub-purple { color: #7C3AED; }

    /* ─── DEDICATED EMPLOYEE OVERTIME & WORKING DAYS ANALYTICS BANNER ─── */
    .user-analytics-banner {
      background: linear-gradient(135deg, #0B132B 0%, #1E293B 100%);
      border-radius: 14px;
      padding: 18px 22px;
      color: white;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 18px;
      box-shadow: 0 6px 18px rgba(11, 19, 43, 0.25);
      border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .user-analytics-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .user-analytics-avatar {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: linear-gradient(135deg, #0066FF, #3B82F6);
      color: white;
      font-weight: 800;
      font-size: 17px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 10px rgba(0, 102, 255, 0.4);
      flex-shrink: 0;
    }

    .user-analytics-titles h3 {
      font-size: 17px;
      font-weight: 800;
      color: #FFFFFF;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .user-analytics-titles .user-tag {
      font-size: 10.5px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 6px;
      background: rgba(255, 255, 255, 0.15);
      color: #93C5FD;
      text-transform: uppercase;
    }

    .user-analytics-titles p {
      font-size: 12px;
      color: #94A3B8;
      margin-top: 3px;
    }

    .user-analytics-metrics {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .user-stat-card {
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 11px;
      padding: 10px 16px;
      display: flex;
      flex-direction: column;
      min-width: 130px;
      text-align: left;
    }

    .user-stat-card.ot-highlight {
      background: rgba(245, 158, 11, 0.12);
      border-color: rgba(245, 158, 11, 0.45);
    }

    .user-stat-card .stat-label {
      font-size: 10px;
      font-weight: 700;
      color: #94A3B8;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .user-stat-card.ot-highlight .stat-label {
      color: #FCD34D;
    }

    .user-stat-card .stat-num {
      font-size: 18px;
      font-weight: 800;
      color: #FFFFFF;
      margin-top: 2px;
    }

    .user-stat-card.ot-highlight .stat-num {
      color: #FBBF24;
    }

    .user-stat-card .stat-sub {
      font-size: 10px;
      color: #64748B;
      margin-top: 2px;
    }

    .user-stat-card.ot-highlight .stat-sub {
      color: rgba(252, 211, 77, 0.8);
    }

    /* ─── FILTERS & SEARCH BAR ─── */
    .filter-card {
      background: #FFFFFF;
      border-radius: 14px;
      padding: 16px 20px;
      border: 1px solid var(--slate-200);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.02);
    }

    .filter-grid {
      display: grid;
      grid-template-columns: 1.5fr 1.2fr 1fr 1.3fr 1fr auto;
      gap: 12px;
      align-items: flex-end;
    }

    @media (max-width: 1200px) {
      .filter-grid { grid-template-columns: repeat(3, 1fr); }
    }

    @media (max-width: 768px) {
      .filter-grid { grid-template-columns: 1fr; }
    }

    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .filter-group label {
      font-size: 11.5px;
      font-weight: 700;
      color: var(--slate-600);
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }

    .filter-control {
      height: 38px;
      padding: 0 12px;
      border: 1px solid var(--slate-300);
      border-radius: 8px;
      background: #FFFFFF;
      font-size: 12.5px;
      font-family: var(--font-main);
      color: var(--slate-800);
      outline: none;
      transition: all 0.2s;
      width: 100%;
    }

    .filter-control:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
    }

    .filter-input-wrap {
      position: relative;
    }

    .filter-input-wrap i {
      position: absolute;
      left: 11px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--slate-400);
      font-size: 12px;
    }

    .filter-control.has-icon {
      padding-left: 32px;
    }

    .btn-search {
      height: 38px;
      padding: 0 20px;
      background: #0066FF;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      transition: all 0.2s;
    }

    .btn-search:hover {
      background: #0052CC;
    }

    .btn-reset-filters {
      height: 38px;
      padding: 0 14px;
      background: #F1F5F9;
      color: var(--slate-600);
      border: 1px solid var(--slate-200);
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 600;
      text-decoration: none;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: all 0.2s;
    }

    .btn-reset-filters:hover {
      background: #E2E8F0;
      color: var(--slate-800);
    }

    /* ─── TABLE CONTAINER CARD ─── */
    .table-container-card {
      background: #FFFFFF;
      border-radius: 14px;
      border: 1px solid var(--slate-200);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.02);
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    .table-header-toolbar {
      padding: 14px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid var(--slate-200);
      flex-wrap: wrap;
      gap: 12px;
    }

    .table-header-title-box h3 {
      font-size: 15px;
      font-weight: 800;
      color: var(--slate-900);
    }

    .table-header-title-box span {
      font-size: 11.5px;
      color: var(--slate-400);
    }

    .table-actions-right {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .table-search-box {
      position: relative;
      width: 240px;
    }

    .table-search-box i {
      position: absolute;
      left: 11px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--slate-400);
      font-size: 12px;
    }

    .table-search-box input {
      width: 100%;
      height: 35px;
      padding: 0 12px 0 32px;
      background: #F8FAFC;
      border: 1px solid var(--slate-200);
      border-radius: 8px;
      font-size: 12.5px;
      font-family: var(--font-main);
      color: var(--slate-700);
      outline: none;
      transition: all 0.2s;
    }

    .table-search-box input:focus {
      background: white;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
    }

    .table-responsive {
      width: 100%;
      overflow-x: auto;
    }

    .modern-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    .modern-table th {
      background: #F8FAFC;
      padding: 10px 14px;
      font-size: 10.5px;
      font-weight: 800;
      color: var(--slate-500);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-bottom: 1px solid var(--slate-200);
      white-space: nowrap;
    }

    .modern-table td {
      padding: 11px 14px;
      font-size: 12.5px;
      color: var(--slate-700);
      border-bottom: 1px solid #F1F5F9;
      vertical-align: middle;
      white-space: nowrap;
    }

    .modern-table tbody tr:hover {
      background: #F8FAFC;
    }

    /* Table cells */
    .emp-cell {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .emp-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11.5px;
      font-weight: 700;
      flex-shrink: 0;
    }

    .emp-meta {
      display: flex;
      flex-direction: column;
    }

    .emp-name {
      font-size: 12.5px;
      font-weight: 700;
      color: var(--slate-900);
    }

    .emp-code {
      font-size: 10.5px;
      color: var(--slate-400);
    }

    .site-cell {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .site-icon-bullet {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #0066FF;
    }

    .time-cell {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 12px;
      font-weight: 600;
      color: var(--slate-700);
    }

    .time-cell.dash {
      color: var(--slate-400);
      font-weight: 500;
    }

    /* Duration & Overtime Pills */
    .dur-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 8px;
      background: #F1F5F9;
      color: var(--slate-700);
      border-radius: 6px;
      font-size: 11.5px;
      font-weight: 700;
      font-family: monospace;
    }

    .dur-badge.active-live {
      background: #ECFDF5;
      color: #059669;
      border: 1px solid #A7F3D0;
    }

    .dur-badge.active-live .live-pulse-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #10B981;
      animation: blink 1.2s infinite;
    }

    .ot-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 8px;
      background: #FFFBEB;
      color: #B45309;
      border: 1px solid #FDE68A;
      border-radius: 6px;
      font-size: 11.5px;
      font-weight: 700;
      font-family: monospace;
    }

    .ot-badge.zero {
      background: transparent;
      border-color: transparent;
      color: var(--slate-300);
      font-weight: 500;
    }

    /* Status Pills */
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 9px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
    }

    .status-pill.active {
      background: #ECFDF5;
      color: #059669;
    }

    .status-pill.completed {
      background: #F1F5F9;
      color: #475569;
    }

    .status-pill.overtime {
      background: #FFF7ED;
      color: #C2410C;
    }

    .status-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
    }

    .status-pill.active .status-dot { background: #10B981; animation: blink 1.2s infinite; }
    .status-pill.completed .status-dot { background: #94A3B8; }
    .status-pill.overtime .status-dot { background: #EA580C; }

    /* Actions Button Group */
    .action-btn-group {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 6px;
    }

    .btn-view-details {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 11px;
      background: #FFFFFF;
      border: 1px solid var(--slate-200);
      border-radius: 7px;
      font-size: 12px;
      font-weight: 700;
      color: var(--slate-700);
      cursor: pointer;
      transition: all 0.2s;
    }

    .btn-view-details:hover {
      background: var(--primary-light);
      border-color: var(--primary);
      color: var(--primary);
    }

    .btn-row-del {
      background: none;
      border: none;
      color: var(--slate-400);
      font-size: 13px;
      padding: 6px;
      cursor: pointer;
      border-radius: 6px;
      transition: all 0.2s;
    }

    .btn-row-del:hover {
      color: var(--danger);
      background: var(--danger-light);
    }

    /* ─── PAGINATION ─── */
    .table-pagination-footer {
      padding: 12px 20px;
      border-top: 1px solid var(--slate-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }

    .pagination-info {
      font-size: 12px;
      color: var(--slate-500);
      font-weight: 600;
    }

    .pagination-controls {
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .page-btn {
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 7px;
      border: 1px solid var(--slate-200);
      background: #FFFFFF;
      color: var(--slate-700);
      text-decoration: none;
      font-size: 12px;
      font-weight: 700;
      transition: all 0.2s;
    }

    .page-btn:hover:not(.disabled) {
      background: #F1F5F9;
      border-color: var(--slate-300);
    }

    .page-btn.active {
      background: #0066FF;
      border-color: #0066FF;
      color: #FFFFFF;
    }

    .page-btn.disabled {
      opacity: 0.4;
      pointer-events: none;
    }

    /* ─── MODALS ─── */
    .modal-backdrop {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(11, 19, 43, 0.65);
      backdrop-filter: blur(5px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transition: all 0.2s ease;
      padding: 20px;
    }

    .modal-backdrop.open {
      opacity: 1;
      visibility: visible;
    }

    .modal-card {
      background: #FFFFFF;
      border-radius: 18px;
      width: 100%;
      max-width: 600px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
      transform: scale(0.95);
      transition: transform 0.2s ease;
      display: flex;
      flex-direction: column;
    }

    .modal-backdrop.open .modal-card {
      transform: scale(1);
    }

    .modal-header {
      padding: 18px 22px;
      border-bottom: 1px solid var(--slate-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .modal-header h3 {
      font-size: 16.5px;
      font-weight: 800;
      color: var(--slate-900);
    }

    .modal-close-btn {
      background: none;
      border: none;
      font-size: 17px;
      color: var(--slate-400);
      cursor: pointer;
    }

    .modal-body {
      padding: 22px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .selfie-card-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .selfie-box {
      border: 1px solid var(--slate-200);
      border-radius: 12px;
      overflow: hidden;
      background: #F8FAFC;
      display: flex;
      flex-direction: column;
    }

    .selfie-box-title {
      padding: 8px 12px;
      font-size: 11px;
      font-weight: 800;
      color: var(--slate-600);
      background: #F1F5F9;
      border-bottom: 1px solid var(--slate-200);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .selfie-img-container {
      height: 170px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #0F172A;
      position: relative;
      overflow: hidden;
    }

    .selfie-img-container img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      cursor: pointer;
      transition: transform 0.25s ease;
    }

    .selfie-img-container img:hover {
      transform: scale(1.04);
    }

    .no-photo-text {
      color: #94A3B8;
      font-size: 12px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
    }

    .detail-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .detail-item {
      display: flex;
      flex-direction: column;
      gap: 2px;
      background: #F8FAFC;
      padding: 10px 14px;
      border-radius: 9px;
      border: 1px solid var(--slate-200);
    }

    .detail-label {
      font-size: 10.5px;
      font-weight: 700;
      color: var(--slate-400);
      text-transform: uppercase;
    }

    .detail-val {
      font-size: 13px;
      font-weight: 700;
      color: var(--slate-800);
    }

    /* Admin Overtime Setter Section in Modal */
    .admin-ot-setter-box {
      background: #FFFBEB;
      border: 1px solid #FDE68A;
      border-radius: 12px;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .admin-ot-setter-title {
      font-size: 12.5px;
      font-weight: 800;
      color: #92400E;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .ot-input-row {
      display: flex;
      gap: 8px;
    }

    .ot-input {
      flex: 1;
      height: 36px;
      padding: 0 10px;
      border: 1px solid #FCD34D;
      border-radius: 7px;
      font-size: 12.5px;
      font-weight: 700;
      outline: none;
    }

    .btn-save-ot {
      height: 36px;
      padding: 0 14px;
      background: #D97706;
      color: white;
      border: none;
      border-radius: 7px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
    }

    /* Fullscreen Photo Lightbox */
    .lightbox-backdrop {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0, 0, 0, 0.88);
      z-index: 2000;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      visibility: hidden;
      transition: all 0.2s ease;
      padding: 20px;
    }

    .lightbox-backdrop.open {
      opacity: 1;
      visibility: visible;
    }

    .lightbox-card {
      max-width: 90vw;
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      position: relative;
    }

    .lightbox-img {
      max-width: 100%;
      max-height: 82vh;
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
      border: 2px solid rgba(255, 255, 255, 0.2);
    }

    .lightbox-close-btn {
      position: absolute;
      top: -40px;
      right: 0;
      background: rgba(255, 255, 255, 0.2);
      border: none;
      color: white;
      font-size: 20px;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    @keyframes blink {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.4; transform: scale(0.85); }
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-5px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body>

  <!-- ─── SIDEBAR NAVIGATION ─── -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="brand-logo-icon">
        <i class="fa-solid fa-map-location-dot"></i>
      </div>
      <div class="brand-text">
        <span class="brand-title">SiteTracker</span>
        <span class="brand-subtitle">OPERATIONS HUB</span>
      </div>
    </div>

    <ul class="sidebar-menu">
      <li class="menu-item">
        <a href="dashboard.php">
          <i class="fa-solid fa-chart-pie"></i>
          <span>Dashboard</span>
        </a>
      </li>
      <li class="menu-item">
        <a href="sites.php">
          <i class="fa-solid fa-city"></i>
          <span>Construction Sites</span>
        </a>
      </li>
      <li class="menu-item active">
        <a href="attendance.php">
          <i class="fa-solid fa-id-badge"></i>
          <span>Attendance Records</span>
        </a>
      </li>
      <li class="menu-item">
        <a href="users.php">
          <i class="fa-solid fa-user-group"></i>
          <span>User Accounts</span>
        </a>
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="user-profile-badge">
        <div class="user-avatar-circle"><?= htmlspecialchars($adminInitials) ?></div>
        <div class="user-profile-info">
          <span class="user-profile-name"><?= htmlspecialchars($currentAdminName) ?></span>
          <span class="user-profile-role"><?= htmlspecialchars($currentAdminRole) ?></span>
        </div>
        <a href="login.php?logout=1" class="logout-btn" title="Sign Out">
          <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
      </div>
    </div>
  </aside>

  <!-- ─── MAIN CONTENT ─── -->
  <div class="main-wrapper">

    <!-- Top Navbar -->
    <header class="top-navbar">
      <div class="header-left">
        <button class="sidebar-toggle" id="sidebarToggleBtn" title="Toggle Sidebar">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div class="search-input-box">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="globalAttendanceSearch" placeholder="Search employee, site or address..." onkeyup="filterTableLive()">
        </div>
      </div>

      <div class="header-right">
        <button class="btn-wh-settings" onclick="openWhModal()" title="Configure Standard Shift & Overtime Policy">
          <i class="fa-solid fa-sliders"></i>
          <span>Shift Config: <?= $standardWorkingHours ?>h/day</span>
        </button>

        <a href="attendance.php?export=csv" class="btn-export-top" title="Export Current Records to CSV">
          <i class="fa-solid fa-file-csv"></i>
          <span>Export CSV</span>
        </a>
      </div>
    </header>

    <!-- Page Body -->
    <main class="page-container">

      <?php if ($alertSuccess): ?>
        <div class="alert-banner success">
          <i class="fa-solid fa-circle-check"></i>
          <span><?= htmlspecialchars($alertSuccess) ?></span>
        </div>
      <?php endif; ?>

      <!-- Page Heading Row -->
      <div class="page-title-row">
        <div class="page-heading-block">
          <h1>Attendance & Overtime Hub</h1>
          <p>Monitor real-time site attendance, track verified working days and accurately compute monthly overtime.</p>
        </div>
        <div class="page-date-badge">
          <i class="fa-regular fa-calendar"></i>
          <span id="liveDateTime"><?= date('D, M d, Y  h:i A') ?></span>
        </div>
      </div>

      <!-- ─── 4 TOP KPI SUMMARY CARDS ─── -->
      <div class="kpi-grid">
        
        <!-- Total Registered Users -->
        <div class="kpi-card">
          <div class="kpi-icon-box kpi-icon-blue">
            <i class="fa-solid fa-users"></i>
          </div>
          <div class="kpi-body">
            <span class="kpi-label">Active Users</span>
            <span class="kpi-val"><?= $totalUsersCount ?></span>
            <span class="kpi-sub kpi-sub-green"><i class="fa-solid fa-shield-halved"></i> Registered workforce</span>
          </div>
        </div>

        <!-- Present Today -->
        <div class="kpi-card">
          <div class="kpi-icon-box kpi-icon-green">
            <i class="fa-solid fa-user-check"></i>
          </div>
          <div class="kpi-body">
            <span class="kpi-label">Present Today</span>
            <span class="kpi-val"><?= $presentTodayCount ?></span>
            <span class="kpi-sub kpi-sub-green"><?= $presentPercentage ?>% turnout today</span>
          </div>
        </div>

        <!-- Active Shifts -->
        <div class="kpi-card">
          <div class="kpi-icon-box kpi-icon-orange">
            <i class="fa-solid fa-clock"></i>
          </div>
          <div class="kpi-body">
            <span class="kpi-label">Active Shifts</span>
            <span class="kpi-val"><?= $activeShiftsCount ?></span>
            <span class="kpi-sub kpi-sub-orange"><i class="fa-solid fa-tower-broadcast"></i> Currently on site</span>
          </div>
        </div>

        <!-- Total Monthly Overtime (Period) -->
        <div class="kpi-card">
          <div class="kpi-icon-box kpi-icon-purple">
            <i class="fa-solid fa-stopwatch-20"></i>
          </div>
          <div class="kpi-body">
            <span class="kpi-label">Total Overtime (<?= htmlspecialchars($statsPeriodLabel) ?>)</span>
            <span class="kpi-val"><?= formatDurationHmShort($orgTotalPeriodOtSec) ?></span>
            <span class="kpi-sub kpi-sub-purple"><i class="fa-solid fa-circle-check"></i> Standard Shift: <?= $standardWorkingHours ?>h/day</span>
          </div>
        </div>

      </div>

      <!-- ─── DEDICATED EMPLOYEE OVERTIME & WORKING DAYS SUMMARY (WHEN USER SELECTED) ─── -->
      <?php if ($selectedUserSummary): 
        $uInit = getInitials($selectedUserSummary['name']);
      ?>
        <div class="user-analytics-banner">
          <div class="user-analytics-left">
            <div class="user-analytics-avatar"><?= htmlspecialchars($uInit) ?></div>
            <div class="user-analytics-titles">
              <h3>
                <?= htmlspecialchars($selectedUserSummary['name']) ?>
                <?php if (!empty($selectedUserSummary['emp_id'])): ?>
                  <span class="user-tag"><?= htmlspecialchars($selectedUserSummary['emp_id']) ?></span>
                <?php endif; ?>
              </h3>
              <p><i class="fa-regular fa-calendar-check"></i> Verified performance summary for <strong><?= htmlspecialchars($statsPeriodLabel) ?></strong></p>
            </div>
          </div>
          
          <div class="user-analytics-metrics">
            
            <!-- 1. Exact Working Days -->
            <div class="user-stat-card">
              <span class="stat-label">Working Days</span>
              <span class="stat-num"><?= $selectedUserSummary['working_days'] ?> Days</span>
              <span class="stat-sub">Unique days present</span>
            </div>

            <!-- 2. Total Working Month / Logged Shift Duration -->
            <div class="user-stat-card">
              <span class="stat-label">Total Worked</span>
              <span class="stat-num"><?= formatDurationHmShort($selectedUserSummary['total_seconds']) ?></span>
              <span class="stat-sub">Logged shift duration</span>
            </div>

            <!-- 3. Total Monthly Overtime (Accurate) -->
            <div class="user-stat-card ot-highlight">
              <span class="stat-label">Total Overtime</span>
              <span class="stat-num">+<?= formatDurationHmShort($selectedUserSummary['overtime_seconds']) ?> OT</span>
              <span class="stat-sub">Accurate overtime hours</span>
            </div>

            <!-- 4. Daily Average -->
            <div class="user-stat-card">
              <span class="stat-label">Daily Avg</span>
              <span class="stat-num"><?= $selectedUserSummary['working_days'] > 0 ? round($selectedUserSummary['total_seconds'] / ($selectedUserSummary['working_days'] * 3600), 1) : 0 ?> hrs/d</span>
              <span class="stat-sub">Target: <?= $standardWorkingHours ?>.0h shift</span>
            </div>

          </div>
        </div>
      <?php endif; ?>

      <!-- ─── FILTERS & SEARCH BAR ─── -->
      <div class="filter-card">
        <form method="GET" action="attendance.php" class="filter-grid" id="filterForm">
          
          <!-- User Selector -->
          <div class="filter-group">
            <label>Select User</label>
            <select name="user" class="filter-control select-control" onchange="this.form.submit()">
              <option value="">-- All Registered Users --</option>
              <?php foreach ($allUsersList as $u): 
                $sel = ($filterUser === $u['full_name'] || $filterUser === $u['employee_id']) ? 'selected' : '';
              ?>
                <option value="<?= htmlspecialchars($u['full_name']) ?>" <?= $sel ?>>
                  <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['employee_id'] ?: 'SUP') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Month Selector -->
          <div class="filter-group">
            <label>Select Month</label>
            <select name="month" class="filter-control select-control" onchange="this.form.submit()">
              <option value="all" <?= $filterMonth === 'all' ? 'selected' : '' ?>>All Months</option>
              <?php foreach ($availableMonths as $ym): 
                $mLabel = date('F Y', strtotime($ym . '-01'));
                $mSel = ($filterMonth === $ym) ? 'selected' : '';
              ?>
                <option value="<?= htmlspecialchars($ym) ?>" <?= $mSel ?>><?= htmlspecialchars($mLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Construction Site Filter -->
          <div class="filter-group">
            <label>Site</label>
            <select name="site" class="filter-control select-control">
              <option value="">All Sites</option>
              <?php foreach ($allSitesList as $s): ?>
                <option value="<?= htmlspecialchars($s['name']) ?>" <?= $filterSite === $s['name'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($s['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Date Range Filter -->
          <div class="filter-group">
            <label>Custom Date Range</label>
            <div class="filter-input-wrap">
              <i class="fa-regular fa-calendar"></i>
              <input type="text" name="date_range" id="dateRangePicker" class="filter-control has-icon" placeholder="DD/MM/YYYY - DD/MM/YYYY" value="<?= htmlspecialchars($filterDateRange) ?>">
            </div>
          </div>

          <!-- Status Filter -->
          <div class="filter-group">
            <label>Status</label>
            <select name="status" class="filter-control select-control">
              <option value="">All Status</option>
              <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active Shifts</option>
              <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
              <option value="overtime" <?= $filterStatus === 'overtime' ? 'selected' : '' ?>>Overtime Shifts</option>
            </select>
          </div>

          <!-- Action Buttons -->
          <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn-search">
              <i class="fa-solid fa-magnifying-glass"></i>
              <span>Search</span>
            </button>
            <a href="attendance.php" class="btn-reset-filters" title="Clear all filters">
              <i class="fa-solid fa-rotate-left"></i>
            </a>
          </div>

        </form>
      </div>

      <!-- ─── ATTENDANCE TABLE ─── -->
      <div class="table-container-card">
        
        <div class="table-header-toolbar">
          <div class="table-header-title-box">
            <h3>Attendance Records</h3>
            <span>Showing <?= count($records) ?> of <?= $totalRecords ?> records (<?= htmlspecialchars($statsPeriodLabel) ?>)</span>
          </div>

          <div class="table-actions-right">
            <div class="table-search-box">
              <i class="fa-solid fa-filter"></i>
              <input type="text" id="inlineAttendanceFilter" placeholder="Filter rows in table..." onkeyup="filterTableLive()">
            </div>
          </div>
        </div>

        <div class="table-responsive">
          <table class="modern-table">
            <thead>
              <tr>
                <th>USER & ID</th>
                <th>SITE LOCATION</th>
                <th>DATE</th>
                <th>PUNCH IN</th>
                <th>PUNCH OUT</th>
                <th>DURATION</th>
                <th>OVERTIME</th>
                <th>STATUS</th>
                <th style="text-align: right;">ACTIONS</th>
              </tr>
            </thead>
            <tbody id="attendanceTableBody">
              <?php if (empty($records)): ?>
                <tr>
                  <td colspan="9" style="text-align: center; padding: 40px 20px; color: var(--slate-400);">
                    <i class="fa-regular fa-folder-open" style="font-size: 32px; margin-bottom: 8px; display: block;"></i>
                    No attendance records found for the selected criteria.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($records as $row): 
                  $avStyle = getAvatarStyle($row['supervisor_name'] ?: 'EMP');
                  $initials = getInitials($row['supervisor_name'] ?: 'EMP');
                  $pIn = strtotime($row['punch_in_time']);
                  $pOut = !empty($row['punch_out_time']) ? strtotime($row['punch_out_time']) : null;
                  $isActive = ($pOut === null);

                  $durSec = $isActive ? (time() - $pIn) : max(0, $pOut - $pIn);
                  $durFormatted = formatDurationHms($durSec);

                  $recOtSec = $recordOvertimeMap[$row['id']] ?? 0;
                  $isManualOt = ($row['overtime_hours'] !== null && is_numeric($row['overtime_hours']));
                  $isOvertimeRow = ($recOtSec > 0 || !empty($row['is_overtime']));

                  $statusClass = $isActive ? 'active' : ($isOvertimeRow ? 'overtime' : 'completed');
                  $statusLabel = $isActive ? 'Active' : ($isOvertimeRow ? 'Overtime' : 'Completed');

                  $detailJson = htmlspecialchars(json_encode([
                    'id' => $row['id'],
                    'supervisor_name' => $row['supervisor_name'],
                    'employee_id' => $row['employee_id'] ?: 'SUP',
                    'site_name' => $row['site_name'],
                    'date' => date('Y-m-d', $pIn),
                    'date_formatted' => date('l, M d, Y', $pIn),
                    'punch_in_time' => date('h:i:s A', $pIn),
                    'punch_out_time' => $pOut ? date('h:i:s A', $pOut) : 'Active Shift',
                    'duration_formatted' => $durFormatted,
                    'duration_seconds' => $durSec,
                    'overtime_seconds' => $recOtSec,
                    'overtime_hours_val' => $row['overtime_hours'] !== null ? $row['overtime_hours'] : '',
                    'is_manual_ot' => $isManualOt,
                    'is_active' => $isActive,
                    'status' => $statusLabel,
                    'address' => $row['address'] ?: 'On-site coordinates verified',
                    'notes' => $row['notes'] ?: '',
                    'photo_url' => $row['photo_url'] ?? '',
                    'punch_out_photo_url' => $row['punch_out_photo_url'] ?? '',
                    'is_within_geofence' => (bool)$row['is_within_geofence']
                  ]), ENT_QUOTES, 'UTF-8');
                ?>
                  <tr class="att-row" data-search="<?= strtolower(htmlspecialchars($row['supervisor_name'] . ' ' . $row['employee_id'] . ' ' . $row['site_name'] . ' ' . $row['address'])) ?>">
                    
                    <!-- User & ID -->
                    <td>
                      <div class="emp-cell">
                        <div class="emp-avatar" style="background: <?= $avStyle['bg'] ?>; color: <?= $avStyle['color'] ?>;">
                          <?= htmlspecialchars($initials) ?>
                        </div>
                        <div class="emp-meta">
                          <span class="emp-name"><?= htmlspecialchars($row['supervisor_name'] ?: 'Unknown User') ?></span>
                          <span class="emp-code"><?= htmlspecialchars($row['employee_id'] ?: ('SUP-' . $row['id'])) ?></span>
                        </div>
                      </div>
                    </td>

                    <!-- Site Location -->
                    <td>
                      <div class="site-cell">
                        <div class="site-icon-bullet"></div>
                        <span><?= htmlspecialchars($row['site_name']) ?></span>
                      </div>
                    </td>

                    <!-- Date -->
                    <td style="color: var(--slate-600); font-size: 12px; font-weight: 600;">
                      <?= date('M d, Y', $pIn) ?>
                    </td>

                    <!-- Punch In -->
                    <td>
                      <div class="time-cell">
                        <i class="fa-regular fa-clock" style="color: #10B981; font-size: 11px;"></i>
                        <span><?= date('h:i A', $pIn) ?></span>
                      </div>
                    </td>

                    <!-- Punch Out -->
                    <td>
                      <?php if ($pOut): ?>
                        <div class="time-cell">
                          <i class="fa-regular fa-clock" style="color: #EF4444; font-size: 11px;"></i>
                          <span><?= date('h:i A', $pOut) ?></span>
                        </div>
                      <?php else: ?>
                        <span class="time-cell dash">--:-- --</span>
                      <?php endif; ?>
                    </td>

                    <!-- Shift Duration -->
                    <td>
                      <?php if ($isActive): ?>
                        <span class="dur-badge active-live live-shift-timer" data-start="<?= $pIn ?>">
                          <span class="live-pulse-dot"></span>
                          <span class="timer-text"><?= $durFormatted ?></span>
                        </span>
                      <?php else: ?>
                        <span class="dur-badge"><?= $durFormatted ?></span>
                      <?php endif; ?>
                    </td>

                    <!-- Overtime -->
                    <td>
                      <?php if ($recOtSec > 0): ?>
                        <span class="ot-badge" title="<?= $isManualOt ? 'Admin Approved Custom Overtime' : 'Calculated Shift Overtime' ?>">
                          <i class="fa-solid fa-plus" style="font-size: 9px;"></i>
                          <span><?= formatDurationHmShort($recOtSec) ?></span>
                          <?php if ($isManualOt): ?>
                            <i class="fa-solid fa-star" style="font-size: 9px; color: #D97706; margin-left: 2px;" title="Admin Set"></i>
                          <?php endif; ?>
                        </span>
                      <?php else: ?>
                        <span class="ot-badge zero">0h 0m</span>
                      <?php endif; ?>
                    </td>

                    <!-- Status -->
                    <td>
                      <span class="status-pill <?= $statusClass ?>">
                        <span class="status-dot"></span>
                        <span><?= $statusLabel ?></span>
                      </span>
                    </td>

                    <!-- Actions -->
                    <td>
                      <div class="action-btn-group">
                        <button type="button" class="btn-view-details" onclick="openDetailsModal(<?= $detailJson ?>)">
                          <i class="fa-regular fa-eye"></i>
                          <span>View Details</span>
                        </button>
                        
                        <button type="button" class="btn-row-del" onclick="promptDeleteAttendance(<?= $row['id'] ?>, '<?= addslashes($row['supervisor_name']) ?>')" title="Delete Record">
                          <i class="fa-regular fa-trash-can"></i>
                        </button>
                      </div>
                    </td>

                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- ─── PAGINATION FOOTER ─── -->
        <?php if ($totalPages > 1): ?>
          <div class="table-pagination-footer">
            <span class="pagination-info">
              Showing page <?= $page ?> of <?= $totalPages ?> (<?= $totalRecords ?> total records)
            </span>
            <div class="pagination-controls">
              
              <!-- Previous Page -->
              <?php 
                $prevUrl = 'attendance.php?' . http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)]));
                $nextUrl = 'attendance.php?' . http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)]));
              ?>
              <a href="<?= $prevUrl ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                <i class="fa-solid fa-chevron-left"></i>
              </a>

              <?php 
                $startP = max(1, $page - 2);
                $endP = min($totalPages, $page + 2);
                for ($p = $startP; $p <= $endP; $p++): 
                  $pUrl = 'attendance.php?' . http_build_query(array_merge($_GET, ['page' => $p]));
              ?>
                <a href="<?= $pUrl ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
              <?php endfor; ?>

              <!-- Next Page -->
              <a href="<?= $nextUrl ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <i class="fa-solid fa-chevron-right"></i>
              </a>

            </div>
          </div>
        <?php endif; ?>

      </div>

    </main>
  </div>

  <!-- ─── ATTENDANCE VIEW DETAILS MODAL ─── -->
  <div class="modal-backdrop" id="detailsModal" onclick="closeModal('detailsModal')">
    <div class="modal-card" onclick="event.stopPropagation()">
      
      <div class="modal-header">
        <h3 id="mdlTitle">Shift Attendance Details</h3>
        <button type="button" class="modal-close-btn" onclick="closeModal('detailsModal')">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="modal-body">
        
        <!-- Selfie Photos (Punch In & Punch Out) -->
        <div class="selfie-card-grid">
          
          <div class="selfie-box">
            <div class="selfie-box-title">
              <span><i class="fa-solid fa-camera"></i> Punch In Selfie</span>
              <span id="pInZoomHint" style="font-size: 9.5px; color: var(--primary); display: none;"><i class="fa-solid fa-magnifying-glass-plus"></i> Click to Zoom</span>
            </div>
            <div class="selfie-img-container" id="mdlPunchInPhotoBox">
              <span class="no-photo-text"><i class="fa-regular fa-image"></i> No selfie captured</span>
            </div>
          </div>

          <div class="selfie-box">
            <div class="selfie-box-title">
              <span><i class="fa-solid fa-camera"></i> Punch Out Selfie</span>
              <span id="pOutZoomHint" style="font-size: 9.5px; color: var(--primary); display: none;"><i class="fa-solid fa-magnifying-glass-plus"></i> Click to Zoom</span>
            </div>
            <div class="selfie-img-container" id="mdlPunchOutPhotoBox">
              <span class="no-photo-text"><i class="fa-regular fa-image"></i> No selfie captured</span>
            </div>
          </div>

        </div>

        <!-- Shift Key Details Grid -->
        <div class="detail-grid-2">
          
          <div class="detail-item">
            <span class="detail-label">Employee</span>
            <span class="detail-val" id="mdlEmpName">--</span>
          </div>

          <div class="detail-item">
            <span class="detail-label">Construction Site</span>
            <span class="detail-val" id="mdlSiteName">--</span>
          </div>

          <div class="detail-item">
            <span class="detail-label">Punch In Time</span>
            <span class="detail-val" id="mdlPunchInTime" style="color: #10B981;">--</span>
          </div>

          <div class="detail-item">
            <span class="detail-label">Punch Out Time</span>
            <span class="detail-val" id="mdlPunchOutTime" style="color: #EF4444;">--</span>
          </div>

          <div class="detail-item">
            <span class="detail-label">Total Duration</span>
            <span class="detail-val" id="mdlDuration">--</span>
          </div>

          <div class="detail-item">
            <span class="detail-label">Overtime Hours</span>
            <span class="detail-val" id="mdlOvertime" style="color: #D97706;">--</span>
          </div>

        </div>

        <!-- Location Verified -->
        <div class="detail-item">
          <span class="detail-label">Location / Geofence Address</span>
          <span class="detail-val" id="mdlAddress" style="font-weight: 500; font-size: 12px; margin-top: 3px;">--</span>
        </div>

        <!-- Admin Overtime Setting / Override Form -->
        <div class="admin-ot-setter-box">
          <div class="admin-ot-setter-title">
            <i class="fa-solid fa-user-shield"></i>
            <span>Admin Overtime Authorization</span>
          </div>
          <p style="font-size: 11px; color: #78350F;">
            Enter custom approved overtime hours (e.g. <code>2.5</code> for 2h 30m). Leave blank or type <code>auto</code> to recalculate automatically based on standard shift.
          </p>
          <form action="attendance.php" method="POST" class="ot-input-row" id="adminOtForm">
            <input type="hidden" name="action" value="set_record_overtime">
            <input type="hidden" name="id" id="mdlRecordId">
            <input type="text" name="overtime_hours" id="mdlOtInput" class="ot-input" placeholder="e.g. 2.0 (hours)">
            <button type="submit" class="btn-save-ot">Update Overtime</button>
          </form>
        </div>

      </div>

    </div>
  </div>

  <!-- ─── FULLSCREEN LIGHTBOX MODAL ─── -->
  <div class="lightbox-backdrop" id="lightboxModal" onclick="closeLightbox()">
    <div class="lightbox-card" onclick="event.stopPropagation()">
      <button type="button" class="lightbox-close-btn" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
      <img src="" id="lightboxImg" class="lightbox-img" alt="Enlarged Selfie View">
    </div>
  </div>

  <!-- ─── WORKING HOURS CONFIGURATION MODAL ─── -->
  <div class="modal-backdrop" id="whConfigModal" onclick="closeModal('whConfigModal')">
    <div class="modal-card" style="max-width: 440px;" onclick="event.stopPropagation()">
      <div class="modal-header">
        <h3>Standard Shift & Overtime Policy</h3>
        <button type="button" class="modal-close-btn" onclick="closeModal('whConfigModal')"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form action="attendance.php" method="POST">
        <input type="hidden" name="action" value="update_working_hours">
        <div class="modal-body">
          <p style="font-size: 12.5px; color: var(--slate-600);">
            Set the standard daily work shift hours for all site employees. Any continuous work duration exceeding this threshold is automatically categorized as daily overtime.
          </p>
          <div class="detail-item" style="background: white; border: 1px solid var(--slate-300);">
            <label style="font-size: 11px; font-weight: 800; color: var(--slate-600); margin-bottom: 5px;">Standard Shift Hours per Day</label>
            <input type="number" name="working_hours" min="1" max="24" value="<?= $standardWorkingHours ?>" required class="filter-control" style="font-size: 14px; font-weight: 700;">
          </div>
          <button type="submit" class="btn-search" style="width: 100%; height: 42px; margin-top: 4px;">
            <i class="fa-solid fa-check"></i>
            <span>Save Working Hours Policy</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ─── HIDDEN DELETE FORM ─── -->
  <form id="deleteAttendanceForm" action="attendance.php" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete_attendance">
    <input type="hidden" name="id" id="deleteAttendanceId">
  </form>

  <!-- ─── JAVASCRIPT LOGIC ─── -->
  <script>
    // Initialize Flatpickr for Date Range
    flatpickr("#dateRangePicker", {
      mode: "range",
      dateFormat: "d/m/Y",
      allowInput: true
    });

    // Real-Time Clock
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'short', month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
      const el = document.getElementById('liveDateTime');
      if (el) el.innerText = now.toLocaleDateString('en-US', options);
    }
    setInterval(updateClock, 1000);

    // Live Shift Durations Counter for Active Shifts
    function updateActiveShiftTimers() {
      const activeTimers = document.querySelectorAll('.live-shift-timer');
      const nowSec = Math.floor(Date.now() / 1000);

      activeTimers.forEach(el => {
        const startSec = parseInt(el.getAttribute('data-start'), 10);
        if (startSec) {
          const diff = Math.max(0, nowSec - startSec);
          const h = Math.floor(diff / 3600);
          const m = Math.floor((diff % 3600) / 60);
          const s = diff % 60;
          const textEl = el.querySelector('.timer-text');
          if (textEl) {
            textEl.innerText = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
          }
        }
      });
    }
    setInterval(updateActiveShiftTimers, 1000);

    // Bulletproof Photo URL Resolver
    function resolvePhotoUrl(rawUrl) {
      if (!rawUrl || typeof rawUrl !== 'string' || rawUrl.trim() === '' || rawUrl === 'null' || rawUrl === 'undefined') return '';
      rawUrl = rawUrl.trim();

      // If already full http / https URL
      if (rawUrl.startsWith('http://') || rawUrl.startsWith('https://')) {
        return rawUrl;
      }

      // If already starts with /sitetracker/
      if (rawUrl.startsWith('/sitetracker/')) {
        return rawUrl;
      }
      if (rawUrl.startsWith('sitetracker/')) {
        return '/' + rawUrl;
      }

      // If relative to admin (../uploads/)
      if (rawUrl.startsWith('../uploads/')) {
        return rawUrl;
      }

      // If starts with /uploads/ or uploads/
      if (rawUrl.startsWith('/uploads/')) {
        return '/sitetracker' + rawUrl;
      }
      if (rawUrl.startsWith('uploads/')) {
        return '/sitetracker/' + rawUrl;
      }

      // If starts with /backend/ or backend/
      if (rawUrl.startsWith('/backend/')) {
        return '/sitetracker' + rawUrl;
      }
      if (rawUrl.startsWith('backend/')) {
        return '/sitetracker/' + rawUrl;
      }

      // Default root prefix
      if (rawUrl.startsWith('/')) {
        return '/sitetracker' + rawUrl;
      }
      return '/sitetracker/' + rawUrl;
    }

    // Modal Control Functions
    function openDetailsModal(data) {
      document.getElementById('mdlTitle').innerText = 'Shift Details • ' + data.supervisor_name;
      document.getElementById('mdlRecordId').value = data.id;
      document.getElementById('mdlEmpName').innerText = data.supervisor_name + ' (' + data.employee_id + ')';
      document.getElementById('mdlSiteName').innerText = data.site_name;
      document.getElementById('mdlPunchInTime').innerText = data.date_formatted + ' at ' + data.punch_in_time;
      document.getElementById('mdlPunchOutTime').innerText = data.punch_out_time;
      document.getElementById('mdlDuration').innerText = data.duration_formatted;
      
      const otSec = data.overtime_seconds || 0;
      const otH = Math.floor(otSec / 3600);
      const otM = Math.floor((otSec % 3600) / 60);
      document.getElementById('mdlOvertime').innerText = otSec > 0 ? `${otH}h ${otM}m OT (${data.is_manual_ot ? 'Admin Set' : 'Auto-Calculated'})` : '0h 0m (No Overtime)';
      
      document.getElementById('mdlOtInput').value = data.overtime_hours_val !== '' ? data.overtime_hours_val : '';
      document.getElementById('mdlAddress').innerText = data.address + (data.is_within_geofence ? ' • Inside Geofence' : ' • Outside Geofence');

      // Render Punch In Photo
      const pInBox = document.getElementById('mdlPunchInPhotoBox');
      const pInHint = document.getElementById('pInZoomHint');
      const pInUrl = resolvePhotoUrl(data.photo_url);
      if (pInUrl) {
        pInBox.innerHTML = `<img src="${pInUrl}" alt="Punch In Selfie" onclick="openLightbox('${pInUrl}')" title="Click to enlarge" onerror="this.onerror=null; this.parentNode.innerHTML='<span class=\\'no-photo-text\\'><i class=\\'fa-solid fa-triangle-exclamation\\'></i> Image not found</span>'">`;
        if (pInHint) pInHint.style.display = 'inline';
      } else {
        pInBox.innerHTML = `<span class="no-photo-text"><i class="fa-regular fa-image"></i> No selfie captured</span>`;
        if (pInHint) pInHint.style.display = 'none';
      }

      // Render Punch Out Photo
      const pOutBox = document.getElementById('mdlPunchOutPhotoBox');
      const pOutHint = document.getElementById('pOutZoomHint');
      const pOutUrl = resolvePhotoUrl(data.punch_out_photo_url);
      if (pOutUrl) {
        pOutBox.innerHTML = `<img src="${pOutUrl}" alt="Punch Out Selfie" onclick="openLightbox('${pOutUrl}')" title="Click to enlarge" onerror="this.onerror=null; this.parentNode.innerHTML='<span class=\\'no-photo-text\\'><i class=\\'fa-solid fa-triangle-exclamation\\'></i> Image not found</span>'">`;
        if (pOutHint) pOutHint.style.display = 'inline';
      } else {
        pOutBox.innerHTML = `<span class="no-photo-text"><i class="fa-regular fa-image"></i> ${data.is_active ? 'Shift still active' : 'No selfie captured'}</span>`;
        if (pOutHint) pOutHint.style.display = 'none';
      }

      document.getElementById('detailsModal').classList.add('open');
    }

    // Lightbox Controls
    function openLightbox(url) {
      document.getElementById('lightboxImg').src = url;
      document.getElementById('lightboxModal').classList.add('open');
    }

    function closeLightbox() {
      document.getElementById('lightboxModal').classList.remove('open');
      document.getElementById('lightboxImg').src = '';
    }

    function openWhModal() {
      document.getElementById('whConfigModal').classList.add('open');
    }

    function closeModal(id) {
      document.getElementById(id).classList.remove('open');
    }

    function promptDeleteAttendance(id, name) {
      if (confirm(`Are you sure you want to permanently delete attendance record #${id} for "${name}"?`)) {
        document.getElementById('deleteAttendanceId').value = id;
        document.getElementById('deleteAttendanceForm').submit();
      }
    }

    // Live In-Table Filter
    function filterTableLive() {
      const term1 = document.getElementById('globalAttendanceSearch').value.toLowerCase();
      const term2 = document.getElementById('inlineAttendanceFilter').value.toLowerCase();
      const term = term1 || term2;

      document.querySelectorAll('#attendanceTableBody tr.att-row').forEach(row => {
        const text = row.getAttribute('data-search') || row.innerText.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
      });
    }
  </script>

</body>
</html>
