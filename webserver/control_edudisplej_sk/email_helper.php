<?php
/**
 * Email Helper - SMTP client via fsockopen
 * Supports none/tls(STARTTLS)/ssl encryption, AUTH LOGIN/PLAIN
 */

require_once __DIR__ . '/dbkonfiguracia.php';
require_once __DIR__ . '/security_config.php';

/**
 * Load SMTP settings from system_settings table, decrypt password
 */
function get_smtp_settings() {
    try {
        $conn = getDbConnection();
        $result = $conn->query("SELECT setting_key, setting_value, is_encrypted FROM system_settings WHERE setting_key LIKE 'smtp_%' OR setting_key IN ('from_name','from_email','reply_to','mail_timeout')");
        $settings = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $val = $row['setting_value'];
                if ($row['is_encrypted'] && !empty($val)) {
                    $val = decrypt_data($val);
                }
                $settings[$row['setting_key']] = $val;
            }
        }
        closeDbConnection($conn);
    } catch (Exception $e) {
        error_log('email_helper: get_smtp_settings error: ' . $e->getMessage());
        return [];
    }

    return [
        'host'       => $settings['smtp_host']       ?? '',
        'port'       => (int)($settings['smtp_port'] ?? 587),
        'encryption' => $settings['smtp_encryption']  ?? 'tls',
        'user'       => $settings['smtp_user']        ?? '',
        'pass'       => $settings['smtp_pass']        ?? '',
        'from_name'  => $settings['from_name']        ?? 'EduDisplej',
        'from_email' => $settings['from_email']       ?? '',
        'reply_to'   => $settings['reply_to']         ?? '',
        'timeout'    => (int)($settings['mail_timeout'] ?? 30),
    ];
}

/**
 * Load an email template by key and lang, with fallback to 'en'
 */
function get_email_template($key, $lang = 'hu') {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT subject, body_html, body_text FROM email_templates WHERE template_key = ? AND lang = ?");
        $stmt->bind_param("ss", $key, $lang);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row && $lang !== 'en') {
            $en = 'en';
            $stmt = $conn->prepare("SELECT subject, body_html, body_text FROM email_templates WHERE template_key = ? AND lang = ?");
            $stmt->bind_param("ss", $key, $en);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        closeDbConnection($conn);
        return $row ?: null;
    } catch (Exception $e) {
        error_log('email_helper: get_email_template error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Log email send result to email_logs
 */
function _log_email($template_key, $to_email, $subject, $result, $error_msg = null) {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("INSERT INTO email_logs (template_key, to_email, subject, result, error_message) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss", $template_key, $to_email, $subject, $result, $error_msg);
        $stmt->execute();
        $stmt->close();
        closeDbConnection($conn);
    } catch (Exception $e) {
        error_log('email_helper: _log_email error: ' . $e->getMessage());
    }
}

/**
 * Send email using a template, replacing {{var}} placeholders
 */
function send_email_from_template($template_key, $to_email, $to_name, $variables = [], $lang = 'hu') {
    $tpl = get_email_template($template_key, $lang);
    if (!$tpl) {
        _log_email($template_key, $to_email, '', 'error', 'Template not found: ' . $template_key);
        return false;
    }

    $subject   = $tpl['subject'];
    $body_html = $tpl['body_html'];
    $body_text = $tpl['body_text'] ?? '';

    foreach ($variables as $k => $v) {
        $subject   = str_replace('{{' . $k . '}}', $v, $subject);
        $body_html = str_replace('{{' . $k . '}}', $v, $body_html);
        $body_text = str_replace('{{' . $k . '}}', $v, $body_text);
    }

    return send_raw_email(
        ['email' => $to_email, 'name' => $to_name],
        $subject,
        $body_html,
        $body_text,
        $template_key
    );
}

/**
 * Send a raw email directly
 * $to can be string (email) or ['email'=>..., 'name'=>...]
 */
function send_raw_email($to, $subject, $body_html, $body_text = '', $template_key = null) {
    $smtp = get_smtp_settings();

    if (empty($smtp['host']) || empty($smtp['from_email'])) {
        _log_email($template_key, is_array($to) ? $to['email'] : $to, $subject, 'error', 'SMTP not configured');
        return false;
    }

    $to_email = is_array($to) ? ($to['email'] ?? '') : $to;
    $to_name  = is_array($to) ? ($to['name']  ?? '') : '';

    try {
        $result = _smtp_send($smtp, $to_email, $to_name, $subject, $body_html, $body_text);
        _log_email($template_key, $to_email, $subject, $result ? 'success' : 'error', $result === true ? null : $result);
        return $result === true;
    } catch (Exception $e) {
        _log_email($template_key, $to_email, $subject, 'error', $e->getMessage());
        return false;
    }
}

/**
 * Low-level SMTP send via fsockopen
 * Returns true on success, error string on failure
 */
function _smtp_send(array $smtp, $to_email, $to_name, $subject, $body_html, $body_text) {
    $host       = $smtp['host'];
    $port       = $smtp['port'];
    $encryption = strtolower($smtp['encryption']); // none, tls, ssl
    $timeout    = $smtp['timeout'];

    // Open socket
    if ($encryption === 'ssl') {
        $sock = @fsockopen('ssl://' . $host, $port, $errno, $errstr, $timeout);
    } else {
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
    }

    if (!$sock) {
        return "Cannot connect to SMTP {$host}:{$port} – {$errstr} ({$errno})";
    }

    stream_set_timeout($sock, $timeout);

    $read = function() use ($sock) {
        $data = '';
        while ($line = fgets($sock, 512)) {
            $data .= $line;
            if ($line[3] === ' ') break; // last line of response
        }
        return $data;
    };

    $cmd = function($c) use ($sock, $read) {
        fwrite($sock, $c . "\r\n");
        return $read();
    };

    // Greeting
    $banner = $read();
    if (substr($banner, 0, 3) !== '220') {
        fclose($sock);
        return "SMTP greeting failed: " . trim($banner);
    }

    // EHLO
    $ehlo = $cmd('EHLO ' . (gethostname() ?: 'localhost'));
    if (substr($ehlo, 0, 3) !== '250') {
        $ehlo = $cmd('HELO localhost');
    }

    // STARTTLS upgrade
    if ($encryption === 'tls') {
        $tls_resp = $cmd('STARTTLS');
        if (substr($tls_resp, 0, 3) !== '220') {
            fclose($sock);
            return "STARTTLS failed: " . trim($tls_resp);
        }
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($sock);
            return "TLS handshake failed";
        }
        // Re-EHLO after TLS
        $ehlo = $cmd('EHLO ' . (gethostname() ?: 'localhost'));
    }

    // AUTH
    if (!empty($smtp['user']) && !empty($smtp['pass'])) {
        // Try AUTH LOGIN
        $auth = $cmd('AUTH LOGIN');
        if (substr($auth, 0, 3) === '334') {
            $cmd(base64_encode($smtp['user']));
            $pass_resp = $cmd(base64_encode($smtp['pass']));
            if (substr($pass_resp, 0, 3) !== '235') {
                fclose($sock);
                return "SMTP AUTH LOGIN failed";
            }
        } else {
            // Try AUTH PLAIN
            $credentials = base64_encode("\0" . $smtp['user'] . "\0" . $smtp['pass']);
            $plain_resp  = $cmd('AUTH PLAIN ' . $credentials);
            if (substr($plain_resp, 0, 3) !== '235') {
                fclose($sock);
                return "SMTP AUTH PLAIN failed";
            }
        }
    }

    // MAIL FROM
    $from_email = $smtp['from_email'];
    $r = $cmd("MAIL FROM:<{$from_email}>");
    if (substr($r, 0, 3) !== '250') {
        fclose($sock);
        return "MAIL FROM rejected: " . trim($r);
    }

    // RCPT TO
    $r = $cmd("RCPT TO:<{$to_email}>");
    if (substr($r, 0, 3) !== '250' && substr($r, 0, 3) !== '251') {
        fclose($sock);
        return "RCPT TO rejected: " . trim($r);
    }

    // DATA
    $r = $cmd('DATA');
    if (substr($r, 0, 3) !== '354') {
        fclose($sock);
        return "DATA rejected: " . trim($r);
    }

    // Build MIME message
    $boundary = '=_mime_' . md5(uniqid('', true));
    $date     = date('r');
    $from_display = !empty($smtp['from_name'])
        ? '=?UTF-8?B?' . base64_encode($smtp['from_name']) . '?= <' . $from_email . '>'
        : $from_email;
    $to_display = !empty($to_name)
        ? '=?UTF-8?B?' . base64_encode($to_name) . '?= <' . $to_email . '>'
        : $to_email;
    $subject_encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers  = "Date: {$date}\r\n";
    $headers .= "From: {$from_display}\r\n";
    $headers .= "To: {$to_display}\r\n";
    if (!empty($smtp['reply_to'])) {
        $headers .= "Reply-To: {$smtp['reply_to']}\r\n";
    }
    $headers .= "Subject: {$subject_encoded}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: EduDisplej\r\n";

    $plain = !empty($body_text) ? $body_text : strip_tags($body_html);

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plain)) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($body_html)) . "\r\n";
    $body .= "--{$boundary}--\r\n";

    // Dot-stuff: lines starting with '.' must be doubled (SMTP requires per-line)
    $message = $headers . "\r\n" . $body;
    $message = preg_replace('/^\./m', '..', $message);

    fwrite($sock, $message . "\r\n.\r\n");
    $end_resp = $read();
    if (substr($end_resp, 0, 3) !== '250') {
        fclose($sock);
        return "Message rejected: " . trim($end_resp);
    }

    $cmd('QUIT');
    fclose($sock);
    return true;
}
