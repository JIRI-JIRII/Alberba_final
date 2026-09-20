<?php
/**
 * Session bootstrap + CSRF helpers.
 *
 * Require this INSTEAD of calling session_start() directly. The cookie flags below
 * have to be set before the session starts, which is why this lives in its own file
 * that every page pulls in as its very first statement.
 */

if (session_status() === PHP_SESSION_NONE) {

    // Only mark the cookie "secure" when we're actually on HTTPS, otherwise the
    // cookie would be dropped on a plain-HTTP XAMPP/localhost setup.
    $is_https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
             || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // Reject session IDs that we never issued (blocks session fixation).
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,   // JavaScript (and therefore XSS) can't read the cookie
        'samesite' => 'Lax',  // the browser won't attach it to cross-site POSTs
    ]);

    session_start();
}


// =====================================================================
// CSRF PROTECTION
// =====================================================================

/** Returns the session's CSRF token, creating one on first use. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop inside every state-changing <form>. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** True when the submitted token matches the one in the session. */
function csrf_verify(): bool
{
    $sent = $_POST['csrf_token'] ?? '';

    return is_string($sent)
        && $sent !== ''
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Guard for pages that handle POST. Call this once, right after the role checks
 * and before any POST handling. Non-POST requests pass straight through.
 */
function require_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    if (!csrf_verify()) {
        http_response_code(400);
        exit('Request could not be verified. Please go back, reload the page, and try again.');
    }
}
