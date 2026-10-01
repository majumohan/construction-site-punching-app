<?php
/**
 * SiteTracker Admin - Index Route
 */
require_once dirname(__DIR__) . '/backend/config/database.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

header('Location: dashboard.php');
exit;
