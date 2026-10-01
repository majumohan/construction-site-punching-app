<?php
/**
 * SiteTracker - Root Entry Point
 * Routes to the admin workspace
 */
require_once __DIR__ . '/backend/config/database.php';

if (!is_logged_in()) {
    header('Location: admin/login.php');
    exit;
}

header('Location: admin/dashboard.php');
exit;
