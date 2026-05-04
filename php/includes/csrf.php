<?php
/**
 * CSRF token for SPA FormData POSTs (session-bound).
 * Issue via GET auth.php?action=csrf; client sends csrf_token on each POST.
 */
function csrf_token_get(bool $force = false): string {
    if ($force || empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_validate(): bool {
    $t = $_POST['csrf_token'] ?? '';
    if (!is_string($t) || $t === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $t);
}
