<?php
/**
 * Security event logging helpers.
 * EduDisplej Control Panel
 */

/**
 * Return the real client IP address, honouring common proxy headers.
 */
function get_client_ip(): string {
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Return the HTTP User-Agent string of the current request.
 */
function get_user_agent(): string {
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

/**
 * Log a security-relevant event.
 *
 * Writes to the PHP error log unconditionally, and also tries to insert a row
 * into the `security_log` database table if it exists.
 *
 * @param string      $event      Short event identifier, e.g. 'successful_login'
 * @param int|null    $user_id    Numeric user ID, or null for anonymous events
 * @param string      $username   Username or email for human-readable context
 * @param string      $ip         Client IP address
 * @param string      $user_agent HTTP User-Agent string
 * @param array       $context    Additional key-value pairs to store with the event
 */
function log_security_event(string $event, $user_id, string $username, string $ip, string $user_agent, array $context = []): void {
    $uid = ($user_id !== null) ? (int)$user_id : null;
    $ctx = json_encode($context, JSON_UNESCAPED_UNICODE);

    error_log(sprintf(
        '[EDUDISPLEJ][SECURITY] event=%s user_id=%s username=%s ip=%s context=%s',
        $event,
        ($uid !== null) ? (string)$uid : 'null',
        $username,
        $ip,
        $ctx
    ));

    try {
        $conn = getDbConnection();
        $check = $conn->query("SHOW TABLES LIKE 'security_log'");
        if ($check && $check->num_rows > 0) {
            $stmt = $conn->prepare(
                "INSERT INTO security_log (event, user_id, username, ip_address, user_agent, context, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param('sissss', $event, $uid, $username, $ip, $user_agent, $ctx);
                $stmt->execute();
                $stmt->close();
            }
        }
        closeDbConnection($conn);
    } catch (Throwable $e) {
        error_log('[EDUDISPLEJ][SECURITY] DB log failed: ' . $e->getMessage());
    }
}
