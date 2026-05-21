<?php
// ============================================================
//  session_check.php  –  Returns current session info as JSON
//  Called by every dashboard page on load to verify auth
// ============================================================

require_once 'config.php';
header('Content-Type: application/json');
startSecureSession();

if (!isLoggedIn()) {
    jsonResponse(false, 'Not authenticated', ['redirect' => 'login.html']);
}

// Build response payload
$data = [
    'user_id'  => $_SESSION['user_id'],
    'username' => $_SESSION['username'],
    'role'     => $_SESSION['role'],
    'name'     => $_SESSION['full_name'] ?? $_SESSION['username'],
];

if ($_SESSION['role'] === 'student') {
    $data['roll_number'] = $_SESSION['roll_number'] ?? '';
    $data['course_name'] = $_SESSION['course_name'] ?? '';
    $data['course_code'] = $_SESSION['course_code'] ?? '';
    $data['semester']    = $_SESSION['semester'] ?? 1;
    $data['student_id']  = $_SESSION['student_id'] ?? null;
}

jsonResponse(true, 'Authenticated', $data);
?>
