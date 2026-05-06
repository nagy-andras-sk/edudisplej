<?php
/**
 * Email sending helpers.
 * EduDisplej Control Panel
 *
 * send_email_from_template() renders one of the built-in HTML e-mail templates
 * and delivers it via PHP's mail() function.
 *
 * SMTP configuration (optional):
 *   If the database table `email_settings` exists and contains a row, the values
 *   stored there are used to configure the From address and display name.
 *   Supported columns: smtp_from_address, smtp_from_name
 *
 * To configure SMTP delivery on a cPanel host, point the server's sendmail
 * to an authenticated SMTP relay, or install a plugin such as SMTP2GO.
 */

/**
 * Read the site email settings from the DB, if the table exists.
 *
 * @return array{from_address: string, from_name: string}
 */
function edudisplej_get_email_settings(): array {
    $defaults = [
        'from_address' => 'noreply@edudisplej.sk',
        'from_name'    => 'EduDisplej',
    ];

    try {
        $conn  = getDbConnection();
        $check = $conn->query("SHOW TABLES LIKE 'email_settings'");
        if (!$check || $check->num_rows === 0) {
            closeDbConnection($conn);
            return $defaults;
        }

        $row = $conn->query("SELECT smtp_from_address, smtp_from_name FROM email_settings LIMIT 1");
        if ($row && $row->num_rows > 0) {
            $data = $row->fetch_assoc();
            if (!empty($data['smtp_from_address'])) {
                $defaults['from_address'] = $data['smtp_from_address'];
            }
            if (!empty($data['smtp_from_name'])) {
                $defaults['from_name'] = $data['smtp_from_name'];
            }
        }

        closeDbConnection($conn);
    } catch (Throwable $e) {
        error_log('[EDUDISPLEJ][EMAIL] email_settings DB read failed: ' . $e->getMessage());
    }

    return $defaults;
}

/**
 * Substitute {{key}} placeholders in a template string.
 *
 * @param  string $template Template string with {{placeholder}} markers
 * @param  array  $vars     Associative array of placeholder => value pairs
 * @return string           Rendered string
 */
function edudisplej_render_template(string $template, array $vars): string {
    foreach ($vars as $key => $value) {
        $template = str_replace('{{' . $key . '}}', htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'), $template);
    }
    return $template;
}

/**
 * Return the HTML body for the password-reset e-mail.
 *
 * @param  array  $vars  Variables: name, reset_link, site_name
 * @param  string $lang  Language code: 'hu', 'sk', 'en'
 * @return array{subject: string, body: string}
 */
function edudisplej_template_password_reset(array $vars, string $lang): array {
    $templates = [
        'hu' => [
            'subject' => '{{site_name}} – Jelszó visszaállítása',
            'heading' => 'Jelszó visszaállítása',
            'greeting' => 'Kedves {{name}}!',
            'body'    => 'Jelszó-visszaállítási kérelmet kaptunk a fiókodhoz. Kattints az alábbi gombra az új jelszó beállításához. A link 1 óráig érvényes.',
            'button'  => 'Jelszó visszaállítása',
            'ignore'  => 'Ha nem kérted ezt, hagyd figyelmen kívül ezt az emailt.',
            'footer'  => 'Ez egy automatikusan generált üzenet. Ne válaszolj erre az emailre.',
        ],
        'sk' => [
            'subject' => '{{site_name}} – Obnova hesla',
            'heading' => 'Obnova hesla',
            'greeting' => 'Dobrý deň, {{name}}!',
            'body'    => 'Dostali sme žiadosť o obnovenie hesla pre váš účet. Kliknite na tlačidlo nižšie a nastavte si nové heslo. Odkaz je platný 1 hodinu.',
            'button'  => 'Obnoviť heslo',
            'ignore'  => 'Ak ste túto žiadosť nezadali, ignorujte tento e-mail.',
            'footer'  => 'Toto je automaticky generovaná správa. Neodpovedajte na tento e-mail.',
        ],
        'en' => [
            'subject' => '{{site_name}} – Password reset',
            'heading' => 'Password reset',
            'greeting' => 'Hello {{name}},',
            'body'    => 'We received a request to reset the password for your account. Click the button below to set a new password. The link is valid for 1 hour.',
            'button'  => 'Reset password',
            'ignore'  => 'If you did not request this, please ignore this email.',
            'footer'  => 'This is an automatically generated message. Please do not reply to this email.',
        ],
    ];

    $tpl = $templates[$lang] ?? $templates['en'];

    $subject = edudisplej_render_template($tpl['subject'], $vars);
    $heading = edudisplej_render_template($tpl['heading'], $vars);
    $greeting = edudisplej_render_template($tpl['greeting'], $vars);
    $body_text = edudisplej_render_template($tpl['body'], $vars);
    $button   = edudisplej_render_template($tpl['button'], $vars);
    $ignore   = edudisplej_render_template($tpl['ignore'], $vars);
    $footer   = edudisplej_render_template($tpl['footer'], $vars);

    $reset_link_escaped = htmlspecialchars($vars['reset_link'] ?? '#', ENT_QUOTES, 'UTF-8');
    $site_name_escaped  = htmlspecialchars($vars['site_name'] ?? 'EduDisplej', ENT_QUOTES, 'UTF-8');

    $body = <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$heading}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f9;padding:40px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <tr>
          <td style="background:#1a73e8;padding:24px 40px;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">{$site_name_escaped}</h1>
          </td>
        </tr>
        <tr>
          <td style="padding:40px;">
            <h2 style="margin:0 0 16px;color:#202124;font-size:20px;">{$heading}</h2>
            <p style="margin:0 0 12px;color:#3c4043;font-size:15px;">{$greeting}</p>
            <p style="margin:0 0 28px;color:#3c4043;font-size:15px;line-height:1.6;">{$body_text}</p>
            <p style="margin:0 0 28px;">
              <a href="{$reset_link_escaped}" style="display:inline-block;background:#1a73e8;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:4px;font-size:15px;font-weight:600;">{$button}</a>
            </p>
            <p style="margin:0 0 24px;color:#5f6368;font-size:13px;">{$ignore}</p>
            <hr style="border:none;border-top:1px solid #e8eaed;margin:24px 0;">
            <p style="margin:0;color:#9aa0a6;font-size:12px;">{$footer}</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    return ['subject' => $subject, 'body' => $body];
}

/**
 * Render a named e-mail template and send it to the given address.
 *
 * Supported template names:
 *   - 'password_reset'  Variables: name, reset_link, site_name
 *
 * @param  string $template  Template identifier
 * @param  string $to        Recipient e-mail address
 * @param  string $name      Recipient display name (used in headers)
 * @param  array  $vars      Template variables
 * @param  string $lang      Language code ('hu', 'sk', 'en')
 * @return bool              True if mail() accepted the message, false otherwise
 */
function send_email_from_template(string $template, string $to, string $name, array $vars, string $lang): bool {
    $settings = edudisplej_get_email_settings();

    switch ($template) {
        case 'password_reset':
            $rendered = edudisplej_template_password_reset($vars, $lang);
            break;
        default:
            error_log('[EDUDISPLEJ][EMAIL] Unknown email template: ' . $template);
            return false;
    }

    $from_name    = mb_encode_mimeheader($settings['from_name'], 'UTF-8', 'B');
    $to_name      = mb_encode_mimeheader($name, 'UTF-8', 'B');
    $subject      = mb_encode_mimeheader($rendered['subject'], 'UTF-8', 'B');

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";
    $headers .= "From: {$from_name} <{$settings['from_address']}>\r\n";
    $headers .= "Reply-To: {$settings['from_address']}\r\n";
    $headers .= "X-Mailer: EduDisplej/1.0\r\n";

    $to_header = "{$to_name} <{$to}>";
    $body      = chunk_split(base64_encode($rendered['body']));

    $result = mail($to_header, $subject, $body, $headers);

    if (!$result) {
        error_log('[EDUDISPLEJ][EMAIL] mail() failed for template=' . $template . ' to=' . $to);
    }

    return $result;
}
