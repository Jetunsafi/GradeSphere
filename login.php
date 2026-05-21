<?php
// ============================================================
//  login.php  –  Handles login for both students and admin
//  Called via AJAX POST from login.html
// ============================================================

require_once 'config.php';
header('Content-Type: application/json');

startSecureSession();

// Already logged in?
if (isLoggedIn()) {
    $redirect = ($_SESSION['role'] === 'admin') ? 'admindashboard.html' : 'student-dashboard.html';
    jsonResponse(true, 'Already logged in', ['redirect' => $redirect, 'role' => $_SESSION['role']]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($username) || empty($password)) {
    jsonResponse(false, 'Username and password are required');
}

try {
    $db = getDB();

    // Fetch user (allow login by username or student roll_number)
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.password, u.role, u.is_active 
        FROM users u
        LEFT JOIN students s ON u.id = s.user_id
        WHERE u.username = ? OR s.roll_number = ?
        LIMIT 1
    ");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(false, 'Invalid credentials');
    }

    if (!$user['is_active']) {
        jsonResponse(false, 'Your account has been deactivated. Contact the administrator.');
    }

    // Verify password
    if (!password_verify($password, $user['password'])) {
        jsonResponse(false, 'Invalid credentials');
    }
    // Set session
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];

    // Update last_login
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    // Log activity
    logActivity($user['id'], $user['username'], $user['role'], 'LOGIN');

    // If student, fetch profile info
    if ($user['role'] === 'student') {
        $stmt2 = $db->prepare("
            SELECT s.*, c.name AS course_name, c.code AS course_code
            FROM students s
            JOIN courses c ON s.course_id = c.id
            WHERE s.user_id = ?
        ");
        $stmt2->execute([$user['id']]);
        $student = $stmt2->fetch();

        if ($student) {
            $_SESSION['student_id']   = $student['id'];
            $_SESSION['full_name']    = $student['full_name'];
            $_SESSION['roll_number']  = $student['roll_number'];
            $_SESSION['course_name']  = $student['course_name'];
            $_SESSION['course_code']  = $student['course_code'];
            $_SESSION['semester']     = $student['current_semester'];
        }
    } else {
        $_SESSION['full_name'] = 'Administrator';
    }

    $redirect = ($user['role'] === 'admin') ? 'admindashboard.html' : 'student-dashboard.html';
    jsonResponse(true, 'Login successful', [
        'redirect' => $redirect,
        'role'     => $user['role'],
        'name'     => $_SESSION['full_name']
    ]);

} catch (PDOException $e) {
    jsonResponse(false, 'Server error. Please try again.');
}
?>
