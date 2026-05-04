<?php
/**
 * BUKID CONNECT — Database & app configuration
 */
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'bukid_connect');

/** Development: include OTP in API JSON (disable in production). */
define('BUKID_DEV_OTP', true);

/** Session idle timeout (seconds). */
define('SESSION_TIMEOUT', 1800);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}
$conn->set_charset('utf8mb4');

/**
 * Strip tags for safe display/storage (always use prepared statements for SQL).
 */
function sanitize(mysqli $conn, $str) {
    return htmlspecialchars(strip_tags(trim((string) $str)), ENT_QUOTES, 'UTF-8');
}

/**
 * Enforce idle session timeout.
 */
function touch_session(): bool {
    if (!isset($_SESSION['user_id'])) {
        return true;
    }
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', $now - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = $now;
    return true;
}

require_once __DIR__ . '/notifications_helper.php';
