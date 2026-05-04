<?php
// Set session cookie path to project root to ensure it's shared across /php/ and /html/
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$cookiePath = '/';
if (strpos($scriptPath, '/php/') !== false) {
    $cookiePath = substr($scriptPath, 0, strpos($scriptPath, '/php/') + 1);
}
session_set_cookie_params([
    'path' => $cookiePath,
    'samesite' => 'Lax',
    'httponly' => true
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/event_log.php';
require_once __DIR__ . '/includes/csrf.php';

if (!touch_session()) {
    // Session expired mid-request
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'csrf' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode(['success' => true, 'csrf' => csrf_token_get(true)]); // Force fresh token
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action !== '' && !csrf_validate()) {
    echo json_encode(['success' => false, 'message' => 'Invalid or missing security token. Refresh the page and try again.']);
    exit;
}

function audit(mysqli $conn, ?int $userId, string $action, string $details): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?,?,?,?)');
    if ($stmt) {
        $stmt->bind_param('isss', $userId, $action, $details, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

/** True if `password` column holds a PHP password_hash() value (bcrypt, Argon2, etc.). */
function stored_password_is_hashed(string $stored): bool {
    $name = password_get_info($stored)['algoName'] ?? 'unknown';
    return $name !== 'unknown';
}

/**
 * Match login password against DB: bcrypt/Argon via password_verify(), or legacy plain text (hash_equals).
 * After a successful plain-text match, callers should upgrade the row to password_hash().
 */
function verify_stored_password(string $plain, string $stored): bool {
    if (stored_password_is_hashed($stored)) {
        return password_verify($plain, $stored);
    }
    return hash_equals($stored, $plain);
}

function upgrade_password_to_hash(mysqli $conn, int $userId, string $plainPassword): void {
    $hash = password_hash($plainPassword, PASSWORD_BCRYPT);
    $stmt = $conn->prepare('UPDATE users SET password=? WHERE id=?');
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
    $stmt->close();
    audit($conn, $userId, 'PASSWORD_REHASH', 'Password upgraded from legacy storage to bcrypt');
}

function send_otp_for_user(mysqli $conn, int $userId, string $email): array {
    $otp = (string) random_int(100000, 999999);
    $exp = date('Y-m-d H:i:s', time() + 600);
    $stmt = $conn->prepare('UPDATE users SET otp_code=?, otp_expiry=? WHERE id=?');
    $stmt->bind_param('ssi', $otp, $exp, $userId);
    $stmt->execute();
    $stmt->close();

    $subject = 'Bukid Connect — Your verification code';
    $body = "Your OTP is: $otp\nValid for 10 minutes.\n\n— Bukid Connect";
    @mail($email, $subject, $body, "From: noreply@bukidconnect.local\r\n");

    $out = ['sent' => true, 'expires_at' => $exp];
    if (defined('BUKID_DEV_OTP') && BUKID_DEV_OTP) {
        $out['dev_otp'] = $otp;
    }
    return $out;
}

// ── REGISTER ───────────────────────────────────────────────
if ($action === 'register') {
    $username  = sanitize($conn, $_POST['username'] ?? '');
    $email     = sanitize($conn, $_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $role      = in_array($_POST['role'] ?? '', ['farmer', 'buyer'], true) ? $_POST['role'] : 'buyer';
    $full_name = sanitize($conn, $_POST['full_name'] ?? '');
    $phone     = sanitize($conn, $_POST['phone'] ?? '');
    $address   = sanitize($conn, $_POST['address'] ?? '');
    $farm_name = $role === 'farmer' ? sanitize($conn, $_POST['farm_name'] ?? '') : '';
    $province  = sanitize($conn, $_POST['province'] ?? '');

    if ($username === '' || $email === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Username, email, and password are required.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }
    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        exit;
    }

    $dup = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $dup->bind_param('ss', $username, $email);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        $dup->close();
        echo json_encode(['success' => false, 'message' => 'Username or email already exists.']);
        exit;
    }
    $dup->close();

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $emailVerified = 0;
    $stmt = $conn->prepare('INSERT INTO users (username,email,password,role,full_name,phone,address,farm_name,province,email_verified) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('sssssssssi', $username, $email, $hash, $role, $full_name, $phone, $address, $farm_name, $province, $emailVerified);

    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Registration failed.']);
        exit;
    }
    $uid = (int) $conn->insert_id;
    $stmt->close();

    audit($conn, $uid, 'REGISTER', "New user: $username ($role)");
    log_event($conn, 'user.registered', $uid, 'user', $uid, ['username' => $username, 'role' => $role]);

    $otpInfo = send_otp_for_user($conn, $uid, $email);

    echo json_encode([
        'success' => true,
        'message' => 'Account created. Enter the OTP sent to your email to verify.',
        'need_verification' => true,
        'user_id' => $uid,
        'email' => $email,
        'otp_info' => $otpInfo,
    ]);
    exit;
}

// ── VERIFY OTP (Gmail / email OTP) ─────────────────────────
if ($action === 'verify_otp') {
    $email = sanitize($conn, $_POST['email'] ?? '');
    $code  = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($code) < 6) {
        echo json_encode(['success' => false, 'message' => 'Valid email and 6-digit code required.']);
        exit;
    }

    $stmt = $conn->prepare('SELECT id, otp_code, otp_expiry FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row['otp_code'] || $row['otp_code'] !== $code) {
        echo json_encode(['success' => false, 'message' => 'Invalid OTP.']);
        exit;
    }
    if (strtotime($row['otp_expiry']) < time()) {
        echo json_encode(['success' => false, 'message' => 'OTP expired. Request a new code.']);
        exit;
    }

    $uid = (int) $row['id'];
    $clr = $conn->prepare('UPDATE users SET email_verified=1, otp_code=NULL, otp_expiry=NULL WHERE id=?');
    $clr->bind_param('i', $uid);
    $clr->execute();
    $clr->close();
    audit($conn, $uid, 'EMAIL_VERIFIED', $email);
    log_event($conn, 'user.email_verified', $uid, 'user', $uid, []);

    echo json_encode(['success' => true, 'message' => 'Email verified. You may sign in.']);
    exit;
}

// ── RESEND OTP ─────────────────────────────────────────────
if ($action === 'resend_otp') {
    $email = sanitize($conn, $_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email.']);
        exit;
    }
    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND email_verified = 0 LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'No pending verification for this email.']);
        exit;
    }
    $uid = (int) $r->fetch_assoc()['id'];
    $stmt->close();

    $otpInfo = send_otp_for_user($conn, $uid, $email);
    echo json_encode(['success' => true, 'message' => 'A new OTP has been sent.', 'otp_info' => $otpInfo]);
    exit;
}

// ── FORGOT PASSWORD: REQUEST OTP ───────────────────────────
if ($action === 'forgot_password_request') {
    $email = sanitize($conn, $_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }
    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'If that email is registered, a reset code has been sent.']);
        exit;
    }
    $uid = (int) $r->fetch_assoc()['id'];
    $stmt->close();

    $otpInfo = send_otp_for_user($conn, $uid, $email);
    audit($conn, $uid, 'PASSWORD_RESET_REQUEST', 'OTP issued for password reset');
    log_event($conn, 'auth.password_reset_requested', $uid, 'user', $uid, []);

    $out = ['success' => true, 'message' => 'If that email is registered, a reset code has been sent.', 'email' => $email];
    if (isset($otpInfo['dev_otp'])) {
        $out['otp_info'] = $otpInfo;
    }
    echo json_encode($out);
    exit;
}

// ── FORGOT PASSWORD: RESET WITH OTP ────────────────────────
if ($action === 'forgot_password_reset') {
    $email = sanitize($conn, $_POST['email'] ?? '');
    $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
    $newPass = $_POST['new_password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($code) < 6) {
        echo json_encode(['success' => false, 'message' => 'Valid email and 6-digit code required.']);
        exit;
    }
    if (strlen($newPass) < 8) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
        exit;
    }

    $stmt = $conn->prepare('SELECT id, otp_code, otp_expiry FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Unable to reset password for this account.']);
        exit;
    }
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row['otp_code'] || $row['otp_code'] !== $code) {
        echo json_encode(['success' => false, 'message' => 'Invalid or incorrect code.']);
        exit;
    }
    if (strtotime($row['otp_expiry']) < time()) {
        echo json_encode(['success' => false, 'message' => 'Code expired. Request a new one.']);
        exit;
    }

    $uid = (int) $row['id'];
    $hash = password_hash($newPass, PASSWORD_BCRYPT);
    $clr = $conn->prepare('UPDATE users SET password=?, otp_code=NULL, otp_expiry=NULL, failed_logins=0, lockout_until=NULL WHERE id=?');
    $clr->bind_param('si', $hash, $uid);
    $clr->execute();
    $clr->close();
    audit($conn, $uid, 'PASSWORD_RESET_COMPLETE', 'Password reset via OTP');
    log_event($conn, 'auth.password_reset_complete', $uid, 'user', $uid, []);

    echo json_encode(['success' => true, 'message' => 'Password updated. You can sign in now.', 'csrf' => csrf_token_get()]);
    exit;
}

// ── LOGIN ──────────────────────────────────────────────────
if ($action === 'login') {
    $username = sanitize($conn, $_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Username and password required.']);
        exit;
    }

    $stmt = $conn->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Invalid username/email or password.']);
        exit;
    }
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!(int) $user['is_active']) {
        echo json_encode(['success' => false, 'message' => 'This account has been deactivated. Contact an administrator.']);
        exit;
    }

    if (!empty($user['lockout_until']) && strtotime($user['lockout_until']) > time()) {
        $mins = (int) ceil((strtotime($user['lockout_until']) - time()) / 60);
        echo json_encode(['success' => false, 'message' => "Account locked. Try again in {$mins} minute(s)."]);
        exit;
    }

    if (!verify_stored_password($password, $user['password'])) {
        $fails = (int) $user['failed_logins'] + 1;
        $uid = (int) $user['id'];
        if ($fails >= 5) {
            $conn->query("UPDATE users SET failed_logins=$fails, lockout_until=DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id=$uid");
            audit($conn, $uid, 'AUTH_LOCKOUT', "After $fails failed attempts");
        } else {
            $conn->query("UPDATE users SET failed_logins=$fails WHERE id=$uid");
        }
        $remaining = max(0, 5 - $fails);
        echo json_encode(['success' => false, 'message' => "Invalid credentials. {$remaining} attempt(s) left."]);
        exit;
    }

    $uidForRow = (int) $user['id'];
    if (!stored_password_is_hashed($user['password'])) {
        upgrade_password_to_hash($conn, $uidForRow, $password);
    }

    if (!(int) $user['email_verified']) {
        $otpInfo = send_otp_for_user($conn, (int) $user['id'], $user['email']);
        echo json_encode([
            'success' => false,
            'need_verification' => true,
            'email' => $user['email'],
            'message' => 'Please verify your email with the OTP sent.',
            'otp_info' => $otpInfo,
        ]);
        exit;
    }

    $uid = (int) $user['id'];
    $conn->query("UPDATE users SET failed_logins=0, lockout_until=NULL WHERE id=$uid");

    if (function_exists('session_regenerate_id')) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = $uid;
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['last_activity'] = time();

    audit($conn, $uid, 'LOGIN', 'User logged in');
    log_event($conn, 'user.login', $uid, 'user', $uid, []);

    echo json_encode([
        'success' => true,
        'role' => $user['role'],
        'message' => 'Login successful.',
        'csrf' => csrf_token_get(),
    ]);
    exit;
}

// ── LOGOUT ─────────────────────────────────────────────────
if ($action === 'logout') {
    $uid = $_SESSION['user_id'] ?? null;
    session_destroy();
    if ($uid) {
        audit($conn, (int) $uid, 'LOGOUT', 'User logged out');
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── GET PROFILE (session) ───────────────────────────────────
if ($action === 'get_profile') {
    if (!isset($_SESSION['user_id']) || !touch_session()) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
    $uid = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT id, username, email, full_name, phone, address, farm_name, province, role, gov_id_verified FROM users WHERE id=?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['success' => true, 'profile' => $row]);
    exit;
}

// ── SESSION CHECK ─────────────────────────────────────────
if ($action === 'check') {
    if (!isset($_SESSION['user_id']) || !touch_session()) {
        echo json_encode(['logged_in' => false]);
        exit;
    }
    echo json_encode([
        'logged_in' => true,
        'id' => (int) $_SESSION['user_id'],
        'role' => $_SESSION['role'],
        'username' => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'csrf' => csrf_token_get(),
    ]);
    exit;
}

// ── UPDATE PROFILE ─────────────────────────────────────────
if ($action === 'update_profile') {
    if (!isset($_SESSION['user_id']) || !touch_session()) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
    $uid = (int) $_SESSION['user_id'];
    $full_name = sanitize($conn, $_POST['full_name'] ?? '');
    $phone = sanitize($conn, $_POST['phone'] ?? '');
    $address = sanitize($conn, $_POST['address'] ?? '');
    $farm_name = sanitize($conn, $_POST['farm_name'] ?? '');
    $province = sanitize($conn, $_POST['province'] ?? '');

    $stmt = $conn->prepare('UPDATE users SET full_name=?, phone=?, address=?, farm_name=COALESCE(NULLIF(?,\'\'), farm_name), province=COALESCE(NULLIF(?,\'\'), province) WHERE id=?');
    $stmt->bind_param('sssssi', $full_name, $phone, $address, $farm_name, $province, $uid);
    $stmt->execute();
    $stmt->close();
    $_SESSION['full_name'] = $full_name;
    audit($conn, $uid, 'PROFILE_UPDATE', 'Profile saved');
    echo json_encode(['success' => true, 'message' => 'Profile updated.']);
    exit;
}

// ── CHANGE PASSWORD ────────────────────────────────────────
if ($action === 'change_password') {
    if (!isset($_SESSION['user_id']) || !touch_session()) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
    $current = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    if (strlen($newPass) < 8) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
        exit;
    }
    $uid = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT password FROM users WHERE id=?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !verify_stored_password($current, $row['password'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }
    $hash = password_hash($newPass, PASSWORD_BCRYPT);
    $u = $conn->prepare('UPDATE users SET password=? WHERE id=?');
    $u->bind_param('si', $hash, $uid);
    $u->execute();
    $u->close();
    audit($conn, $uid, 'PASSWORD_CHANGE', 'Password updated');
    echo json_encode(['success' => true, 'message' => 'Password changed.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
