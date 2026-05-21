<?php
// ============================================================
//  logout.php  –  Destroys session and redirects to login
// ============================================================

require_once 'config.php';
startSecureSession();

if (isLoggedIn()) {
    logActivity($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], 'LOGOUT');
}

session_unset();
session_destroy();

// For AJAX calls
if (isAjax()) {
    jsonResponse(true, 'Logged out successfully', ['redirect' => 'login.html']);
}

// For direct navigation
header('Location: login.html');
exit;
?>
