<?php
/**
 * Email Helper - SMTP client via fsockopen
 * Supports none/tls(STARTTLS)/ssl encryption, AUTH LOGIN/PLAIN
 */

require_once __DIR__ . '/dbkonfiguracia.php';
require_once __DIR__ . '/security_config.php';

function normalize_escaped_whitespace($value) {
    $text = (string)$value;
    $text = str_replace(["\\r\\n", "\\n", "\\r", "\\t"], ["\r\n", "\n", "\r", "\t"], $text);
    return $text;
}

/**
 * Load SMTP settings from the `system_settings` table and decrypt the password.
 *
 * @return array{host:string,port:int,encryption:string,user:string,pass:string,
 *               from_name:string,from_email:string,reply_to:string,timeout:int}
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
 * Load an email template by key and language with automatic fallback to 'en'.
 *
 * @param string $key  Template key (e.g. 'password_reset', 'mfa_enabled')
 * @param string $lang Language code ('hu', 'en', 'sk').  Falls back to 'en' if not found.
 * @return array{subject:string,body_html:string,body_text:string}|null
 *         Template row or null if no template exists for the key in any language.
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

function get_email_base_layout_html() {
    $default_layout = "<!doctype html>\n"
        . "<html><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"></head><body style=\"margin:0;padding:0;background:#f3f6fb;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;\">\n"
        . "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f3f6fb;padding:24px 12px;\">\n"
        . "  <tr><td align=\"center\">\n"
        . "    <table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;\">\n"
        . "      <tr><td style=\"background:linear-gradient(135deg,#1e40af 0%,#0369a1 100%);padding:20px 24px;color:#ffffff;font-size:20px;font-weight:700;\">{{site_name}}</td></tr>\n"
        . "      <tr><td style=\"padding:24px;\">\n"
        . "        <h2 style=\"margin:0 0 14px 0;font-size:20px;color:#0f172a;\">{{subject}}</h2>\n"
        . "        {{content}}\n"
        . "      </td></tr>\n"
        . "      <tr><td style=\"padding:16px 24px;color:#64748b;font-size:12px;border-top:1px solid #e5e7eb;\">This is an automated message from {{site_name}}.</td></tr>\n"
        . "    </table>\n"
        . "  </td></tr>\n"
        . "</table>\n"
        . "</body></html>";

    try {
        $conn = getDbConnection();
        $k = 'email_base_layout_html';
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->bind_param("s", $k);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        closeDbConnection($conn);

        $layout = trim((string)($row['setting_value'] ?? ''));
        if ($layout === '') {
            return normalize_escaped_whitespace($default_layout);
        }
        return normalize_escaped_whitespace($layout);
    } catch (Exception $e) {
        error_log('email_helper: get_email_base_layout_html error: ' . $e->getMessage());
        return normalize_escaped_whitespace($default_layout);
    }
}

function render_email_html_with_layout($subject, $body_html, $variables = []) {
    $site_name = trim((string)($variables['site_name'] ?? 'EduDisplej'));
    if ($site_name === '') {
        $site_name = 'EduDisplej';
    }

    $layout = get_email_base_layout_html();
    $static_footer_en = 'This is an automated message from {{site_name}}.';
    $layout = str_replace(
        [
            'Ez egy automatikus üzenet a(z) {{site_name}} rendszertől.',
            'Toto je automatická správa zo systému {{site_name}}.',
            '{{static_footer_text}}',
        ],
        [$static_footer_en, $static_footer_en, $static_footer_en],
        $layout
    );
    $wrapped = str_replace(
        ['{{subject}}', '{{content}}', '{{site_name}}'],
        [$subject, $body_html, $site_name],
        $layout
    );

    foreach ($variables as $k => $v) {
        $wrapped = str_replace('{{' . $k . '}}', (string)$v, $wrapped);
    }

    return $wrapped;
}

function _get_default_email_template($template_key, $lang = 'en') {
    $lang = strtolower(trim((string)$lang));
    if (!in_array($lang, ['hu', 'en', 'sk'], true)) {
        $lang = 'en';
    }

    if ($template_key === 'password_reset') {
        if ($lang === 'hu') {
            return [
                'subject' => 'Jelszó visszaállítás',
                'body_html' => '<p>Kedves {{name}},</p><p>Új jelszó beállításához kattintson az alábbi gombra:</p><p><a href="{{reset_link}}" style="display:inline-block;padding:10px 16px;background:#1e40af;color:#fff;text-decoration:none;border-radius:6px;">Jelszó visszaállítása</a></p><p>Ha nem Ön kérte, hagyja figyelmen kívül ezt az üzenetet.</p>',
                'body_text' => "Kedves {{name}},\n\nÚj jelszó beállításához nyissa meg: {{reset_link}}\n\nHa nem Ön kérte, hagyja figyelmen kívül ezt az üzenetet.",
            ];
        }
        if ($lang === 'sk') {
            return [
                'subject' => 'Obnovenie hesla',
                'body_html' => '<p>Dobrý deň {{name}},</p><p>Pre nastavenie nového hesla kliknite na tlačidlo nižšie:</p><p><a href="{{reset_link}}" style="display:inline-block;padding:10px 16px;background:#1e40af;color:#fff;text-decoration:none;border-radius:6px;">Obnoviť heslo</a></p><p>Ak ste o to nepožiadali, tento email ignorujte.</p>',
                'body_text' => "Dobrý deň {{name}},\n\nPre nastavenie nového hesla otvorte: {{reset_link}}\n\nAk ste o to nepožiadali, tento email ignorujte.",
            ];
        }
        return [
            'subject' => 'Password reset',
            'body_html' => '<p>Hello {{name}},</p><p>To set a new password, click the button below:</p><p><a href="{{reset_link}}" style="display:inline-block;padding:10px 16px;background:#1e40af;color:#fff;text-decoration:none;border-radius:6px;">Reset password</a></p><p>If you did not request this, you can ignore this message.</p>',
            'body_text' => "Hello {{name}},\n\nTo set a new password, open: {{reset_link}}\n\nIf you did not request this, you can ignore this message.",
        ];
    }

    return null;
}

function queue_raw_email($to_email, $to_name, $subject, $body_html, $body_text = '', $template_key = null) {
    try {
        $conn = getDbConnection();
        $status = 'queued';
        $stmt = $conn->prepare("INSERT INTO email_queue (template_key, to_email, to_name, subject, body_html, body_text, status, attempts) VALUES (?,?,?,?,?,?,?,0)");
        $stmt->bind_param("sssssss", $template_key, $to_email, $to_name, $subject, $body_html, $body_text, $status);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        closeDbConnection($conn);
        return $id;
    } catch (Exception $e) {
        error_log('email_helper: queue_raw_email error: ' . $e->getMessage());
        return 0;
    }
}

function process_email_queue_item($queue_id) {
    $queue_id = (int)$queue_id;
    if ($queue_id <= 0) {
        return false;
    }

    try {
        $conn = getDbConnection();
        $processing = 'processing';
        $allowedA = 'queued';
        $allowedB = 'failed';
        $u = $conn->prepare("UPDATE email_queue SET status = ?, updated_at = NOW() WHERE id = ? AND status IN (?, ?)");
        $u->bind_param("siss", $processing, $queue_id, $allowedA, $allowedB);
        $u->execute();
        $rows = $u->affected_rows;
        $u->close();

        if ($rows < 1) {
            closeDbConnection($conn);
            return false;
        }

        $s = $conn->prepare("SELECT id, template_key, to_email, to_name, subject, body_html, body_text, attempts FROM email_queue WHERE id = ? LIMIT 1");
        $s->bind_param("i", $queue_id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();

        if (!$row) {
            closeDbConnection($conn);
            return false;
        }

        $smtp = get_smtp_settings();
        if (empty($smtp['host']) || empty($smtp['from_email'])) {
            $newStatus = 'failed';
            $err = 'SMTP not configured';
            $attempts = (int)$row['attempts'] + 1;
            $f = $conn->prepare("UPDATE email_queue SET status = ?, attempts = ?, last_error = ?, updated_at = NOW() WHERE id = ?");
            $f->bind_param("sisi", $newStatus, $attempts, $err, $queue_id);
            $f->execute();
            $f->close();
            closeDbConnection($conn);

            _log_email($row['template_key'], $row['to_email'], $row['subject'], 'error', $err);
            return false;
        }

        $sendResult = _smtp_send($smtp, $row['to_email'], $row['to_name'], $row['subject'], $row['body_html'], $row['body_text']);
        $attempts = (int)$row['attempts'] + 1;

        if ($sendResult === true) {
            $sent = 'sent';
            $ok = $conn->prepare("UPDATE email_queue SET status = ?, attempts = ?, last_error = NULL, sent_at = NOW(), updated_at = NOW() WHERE id = ?");
            $ok->bind_param("sii", $sent, $attempts, $queue_id);
            $ok->execute();
            $ok->close();
            closeDbConnection($conn);

            _log_email($row['template_key'], $row['to_email'], $row['subject'], 'success', null);
            return true;
        }

        $failed = 'failed';
        $err = (string)$sendResult;
        $ko = $conn->prepare("UPDATE email_queue SET status = ?, attempts = ?, last_error = ?, updated_at = NOW() WHERE id = ?");
        $ko->bind_param("sisi", $failed, $attempts, $err, $queue_id);
        $ko->execute();
        $ko->close();
        closeDbConnection($conn);

        _log_email($row['template_key'], $row['to_email'], $row['subject'], 'error', $err);
        return false;
    } catch (Exception $e) {
        error_log('email_helper: process_email_queue_item error: ' . $e->getMessage());
        return false;
    }
}

function process_email_queue($limit = 20) {
    $limit = max(1, min(200, (int)$limit));
    $processed = 0;
    $sent = 0;

    try {
        $conn = getDbConnection();
        $q = $conn->prepare("SELECT id FROM email_queue WHERE status IN ('queued','failed') ORDER BY created_at ASC LIMIT ?");
        $q->bind_param("i", $limit);
        $q->execute();
        $res = $q->get_result();
        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $q->close();
        closeDbConnection($conn);

        foreach ($ids as $id) {
            $processed++;
            if (process_email_queue_item($id)) {
                $sent++;
            }
        }
    } catch (Exception $e) {
        error_log('email_helper: process_email_queue error: ' . $e->getMessage());
    }

    return ['processed' => $processed, 'sent' => $sent, 'failed' => max(0, $processed - $sent)];
}

function archive_email_queue_item($queue_id) {
    $queue_id = (int)$queue_id;
    if ($queue_id <= 0) {
        return false;
    }

    try {
        $conn = getDbConnection();
        $archived = 'archived';
        $stmt = $conn->prepare("UPDATE email_queue SET status = ?, archived_at = NOW(), updated_at = NOW() WHERE id = ? AND status IN ('sent','failed')");
        $stmt->bind_param("si", $archived, $queue_id);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        closeDbConnection($conn);
        return $ok;
    } catch (Exception $e) {
        error_log('email_helper: archive_email_queue_item error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Persist an email send attempt to the `email_logs` table.
 *
 * @param string|null $template_key Template key used (null for raw sends)
 * @param string      $to_email     Recipient e-mail address
 * @param string      $subject      Message subject
 * @param string      $result       'success' or 'error'
 * @param string|null $error_msg    Error detail (null on success)
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
 * Send an email using a named template.
 *
 * Loads the template identified by `$template_key` (with language fallback),
 * replaces all `{{variable}}` placeholders with values from `$variables`,
 * and delivers the message via the configured SMTP server.
 *
 * @param string   $template_key Template key (e.g. 'password_reset')
 * @param string   $to_email     Recipient e-mail address
 * @param string   $to_name      Recipient display name
 * @param array    $variables    Associative array for `{{key}}` substitution
 * @param string   $lang         Preferred language code ('hu', 'en', 'sk')
 * @return bool                  True on success, false on any failure
 */
function send_email_from_template($template_key, $to_email, $to_name, $variables = [], $lang = 'hu') {
    $tpl = get_email_template($template_key, $lang);
    if (!$tpl) {
        $tpl = _get_default_email_template($template_key, $lang);
    }

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

    $body_text = normalize_escaped_whitespace($body_text);
    if (trim($body_text) === '') {
        $plain_source = preg_replace('/<br\s*\/?>/i', "\n", (string)$body_html);
        $plain_source = preg_replace('/<\/p>/i', "\n\n", (string)$plain_source);
        $body_text = trim(html_entity_decode(strip_tags((string)$plain_source), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $body_html = render_email_html_with_layout($subject, $body_html, $variables);

    return send_raw_email(
        ['email' => $to_email, 'name' => $to_name],
        $subject,
        $body_html,
        $body_text,
        $template_key
    );
}

/**
 * Send a raw email without template processing.
 *
 * @param string|array $to          Recipient: plain e-mail string or ['email'=>..., 'name'=>...]
 * @param string       $subject     Message subject
 * @param string       $body_html   HTML body
 * @param string       $body_text   Plain-text body (optional; used as multipart/alternative fallback)
 * @param string|null  $template_key Template key for logging purposes (null for ad-hoc sends)
 * @return bool                     True on success, false on any failure
 */
function send_raw_email($to, $subject, $body_html, $body_text = '', $template_key = null) {
    $to_email = is_array($to) ? ($to['email'] ?? '') : $to;
    $to_name  = is_array($to) ? ($to['name']  ?? '') : '';

    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        _log_email($template_key, $to_email, $subject, 'error', 'Invalid recipient email');
        return false;
    }

    if (stripos((string)$body_html, '<html') === false) {
        $body_html = render_email_html_with_layout($subject, (string)$body_html, ['site_name' => 'EduDisplej']);
    }

    $body_text = normalize_escaped_whitespace($body_text);
    if (trim((string)$body_text) === '') {
        $plain_source = preg_replace('/<br\s*\/?>/i', "\n", (string)$body_html);
        $plain_source = preg_replace('/<\/p>/i', "\n\n", (string)$plain_source);
        $body_text = trim(html_entity_decode(strip_tags((string)$plain_source), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $queue_id = queue_raw_email($to_email, $to_name, $subject, $body_html, $body_text, $template_key);
    if ($queue_id <= 0) {
        _log_email($template_key, $to_email, $subject, 'error', 'Queue insert failed');
        return false;
    }

    try {
        return process_email_queue_item($queue_id);
    } catch (Exception $e) {
        _log_email($template_key, $to_email, $subject, 'error', $e->getMessage());
        return false;
    }
}

/**
 * Low-level SMTP delivery via PHP `fsockopen`.
 *
 * Supports three encryption modes:
 *  - `none` – plain TCP connection
 *  - `tls`  – plain TCP upgraded via STARTTLS (RFC 3207, typically port 587)
 *  - `ssl`  – direct TLS from the start (typically port 465)
 *
 * AUTH LOGIN and AUTH PLAIN are both attempted; the method is chosen based on
 * the server's EHLO capability advertisement.
 *
 * @param array  $smtp      SMTP configuration from get_smtp_settings()
 * @param string $to_email  Recipient address
 * @param string $to_name   Recipient display name (used in To: header)
 * @param string $subject   Message subject
 * @param string $body_html HTML message body
 * @param string $body_text Optional plain-text alternative body
 * @return true|string      true on success; an error description string on failure
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
    $plain = normalize_escaped_whitespace($plain);

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
