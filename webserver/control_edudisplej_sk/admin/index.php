<?php
/**
 * Admin Panel - EduDisplej Control System
 * Redirects to modern dashboard
 */

session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

// Redirect to dashboard
header('Location: dashboard.php');
exit();

