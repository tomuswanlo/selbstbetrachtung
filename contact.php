<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode nicht erlaubt.']);
    exit;
}

$configFile = __DIR__ . '/smtp_config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server-Konfiguration fehlt.']);
    exit;
}
require $configFile; // definiert SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, MAIL_TO

require __DIR__ . '/lib/PHPMailer/src/Exception.php';
require __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function field(string $name): string
{
    return trim((string)($_POST[$name] ?? ''));
}

$name = field('name');
$email = field('email');
$message = field('message');
$consent = field('consent') !== '';

// Honeypot: verstecktes Feld, das nur Bots ausfüllen. Bei Treffer stillschweigend "Erfolg" vortäuschen.
if (field('website') !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

$errors = [];
if ($name === '') {
    $errors[] = 'name';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}
if ($message === '') {
    $errors[] = 'message';
}
if (!$consent) {
    $errors[] = 'consent';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Bitte füllen Sie die markierten Felder aus.', 'fields' => $errors]);
    exit;
}

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Port 465, implizites TLS
    $mail->Port = SMTP_PORT;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(SMTP_USER, 'Selbstbetrachtung Website');
    $mail->addAddress(MAIL_TO);
    $mail->addReplyTo($email, $name);

    $mail->Subject = 'Anfrage über die Website – ' . $name;
    $mail->Body = "Name: {$name}\nE-Mail: {$email}\n\nNachricht:\n{$message}\n";

    $mail->send();
    echo json_encode(['ok' => true]);
} catch (PHPMailerException $e) {
    http_response_code(500);
    error_log('Kontaktformular Mailversand fehlgeschlagen: ' . $mail->ErrorInfo);
    echo json_encode(['ok' => false, 'error' => 'Die Nachricht konnte nicht gesendet werden. Bitte versuchen Sie es später erneut oder schreiben Sie direkt eine E-Mail.']);
}
