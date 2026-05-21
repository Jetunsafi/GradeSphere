<?php
// ============================================================
//  config.php  –  Database connection & global settings
//  Place this file in your project root alongside index.html
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // XAMPP default
define('DB_PASS', '');              // XAMPP default (empty password)
define('DB_NAME', 'erms_db');
define('DB_CHARSET', 'utf8mb4');

define('SITE_NAME', 'ERMS - Exam Result Management System');
define('SESSION_TIMEOUT', 3600);    // 1 hour in seconds

// ─── Create PDO connection ───────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ─── Session helpers ─────────────────────────────────────────
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_strict_mode', 1);
        session_start();
    }
    // Timeout check
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

function isLoggedIn(): bool {
    startSecureSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function requireLogin(string $role = ''): void {
    if (!isLoggedIn()) {
        if (isAjax()) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated', 'redirect' => 'login.html']);
            exit;
        }
        header('Location: login.html');
        exit;
    }
    if ($role && $_SESSION['role'] !== $role) {
        if (isAjax()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        header('Location: login.html');
        exit;
    }
}

function isAjax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// ─── Activity logger ─────────────────────────────────────────
function logActivity(int $userId, string $username, string $role, string $action): void {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $db->prepare("INSERT INTO activity_log (user_id, username, role, action, ip_address) VALUES (?,?,?,?,?)");
        $stmt->execute([$userId, $username, $role, $action, $ip]);
    } catch (Exception $e) {
        // Log silently – never break the app for logging errors
    }
}

// ─── JSON response helper ────────────────────────────────────
function jsonResponse(bool $success, string $message, array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

// ─── Grade calculator (matches frontend logic) ───────────────
function calculateGrade(int $total): string {
    if ($total >= 90) return 'A+';
    if ($total >= 80) return 'A';
    if ($total >= 70) return 'B+';
    if ($total >= 60) return 'B';
    if ($total >= 50) return 'C';
    if ($total >= 40) return 'D';
    return 'F';
}

function calculateSGPA(float $percentage): float {
    return round($percentage / 10, 2);
}
?>
