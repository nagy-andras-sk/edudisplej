<?php

declare(strict_types=1);

function redirect_with_status(string $status): void {
    header('Location: /index.html?status=' . $status . '#kontakt');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.html');
    exit;
}

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($name === '' || $email === '' || $message === '') {
    redirect_with_status('err');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_status('err');
}

if (mb_strlen($name) > 100 || mb_strlen($email) > 150 || mb_strlen($message) > 2000) {
    redirect_with_status('err');
}

require_once __DIR__ . '/../control_edudisplej_sk/email_helper.php';

$cleanName = str_replace(["\r", "\n"], ' ', $name);
$cleanEmail = str_replace(["\r", "\n"], '', $email);
$cleanMessage = str_replace(["\r\n", "\r"], "\n", $message);

$subject = 'Nová správa z edudisplej.sk (kontakt formulár)';

$bodyHtml = '<p><strong>Meno:</strong> ' . htmlspecialchars($cleanName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
    . '<p><strong>Email:</strong> ' . htmlspecialchars($cleanEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
    . '<p><strong>Správa:</strong><br>'
    . nl2br(htmlspecialchars($cleanMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
    . '</p>';

$bodyText = "Meno: {$cleanName}\n";
$bodyText .= "Email: {$cleanEmail}\n\n";
$bodyText .= "Správa:\n{$cleanMessage}\n";

$queueId = queue_raw_email(
    'edudisplej@gmail.com',
    'EduDisplej Kontakt',
    $subject,
    $bodyHtml,
    $bodyText,
    'public_contact'
);

if ($queueId > 0) {
    try {
        process_email_queue_item($queueId);
    } catch (Throwable $e) {
        error_log('public contact queue process error: ' . $e->getMessage());
    }
    redirect_with_status('ok');
}

redirect_with_status('err');
