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
// optional, sobald eingerichtet: TURNSTILE_SECRET_KEY

require __DIR__ . '/lib/PHPMailer/src/Exception.php';
require __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function field(string $name): string
{
    return trim((string)($_POST[$name] ?? ''));
}

// --- Spam-Abwehr ---------------------------------------------------------

// Honeypot: unauffällig benanntes, unsichtbares Feld. Bots füllen oft blind
// alle Felder aus; echte Besucher sehen und berühren es nie.
$isHoneypotTriggered = field('hp_confirm2') !== '';

// Zeit-Prüfung: Formulare, die schneller als 3s nach dem Laden abgeschickt
// werden, stammen praktisch nie von einem Menschen.
$renderedAt = (float) field('ts');
$isTooFast = $renderedAt > 0 && (microtime(true) * 1000 - $renderedAt) < 3000;

if ($isHoneypotTriggered || $isTooFast) {
    // Bots nicht verraten, dass sie erkannt wurden – stillschweigend "Erfolg" melden.
    echo json_encode(['ok' => true]);
    exit;
}

// Cloudflare Turnstile (aktiv, sobald TURNSTILE_SECRET_KEY in smtp_config.php gesetzt ist)
if (defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '') {
    $token = field('cf-turnstile-response');
    $verified = false;
    if ($token !== '') {
        $payload = http_build_query([
            'secret' => TURNSTILE_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $payload,
                'timeout' => 8,
            ],
        ]);
        $result = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        if ($result !== false) {
            $decoded = json_decode($result, true);
            $verified = is_array($decoded) && !empty($decoded['success']);
        }
    }
    if (!$verified) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Bitte bestätigen Sie die Sicherheitsabfrage.']);
        exit;
    }
}

// --- Eingaben validieren ---------------------------------------------------

$name = field('name');
$email = field('email');
$phone = field('phone');
$message = field('message');
$consent = field('consent') !== '';

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

// --- E-Mail an die Praxis ---------------------------------------------------

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
    $bodyLines = ["Name: {$name}", "E-Mail: {$email}"];
    if ($phone !== '') {
        $bodyLines[] = "Telefon: {$phone}";
    }
    $bodyLines[] = '';
    $bodyLines[] = 'Nachricht:';
    $bodyLines[] = $message;
    $mail->Body = implode("\n", $bodyLines) . "\n";

    $mail->send();
} catch (PHPMailerException $e) {
    http_response_code(500);
    error_log('Kontaktformular Mailversand fehlgeschlagen: ' . $mail->ErrorInfo);
    echo json_encode(['ok' => false, 'error' => 'Die Nachricht konnte nicht gesendet werden. Bitte versuchen Sie es später erneut oder schreiben Sie direkt eine E-Mail.']);
    exit;
}

// --- Automatische Eingangsbestätigung an den Absender ----------------------
// Best effort: Wenn das fehlschlägt, ist die Hauptanfrage trotzdem schon
// erfolgreich zugestellt – der Besucher bekommt keinen Fehler zu sehen.
try {
    $confirm = new PHPMailer(true);
    $confirm->isSMTP();
    $confirm->Host = SMTP_HOST;
    $confirm->SMTPAuth = true;
    $confirm->Username = SMTP_USER;
    $confirm->Password = SMTP_PASS;
    $confirm->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $confirm->Port = SMTP_PORT;
    $confirm->CharSet = 'UTF-8';

    $confirm->setFrom(SMTP_USER, 'Gabriele Küppers – Selbstbetrachtung');
    $confirm->addAddress($email, $name);

    $confirm->Subject = 'Ihre Anfrage bei Selbstbetrachtung ist eingegangen';
    $confirm->Body =
        "Liebe/r {$name},\n\n" .
        "vielen Dank für Ihre Nachricht über selbstbetrachtung-online.de. Sie ist bei mir angekommen " .
        "und ich melde mich in der Regel innerhalb von 24 Stunden bei Ihnen zurück.\n\n" .
        "Diese Bestätigung wird automatisch versendet, bitte antworten Sie bei Rückfragen direkt auf diese E-Mail.\n\n" .
        "Herzliche Grüße\nGabriele Küppers\nSelbstbetrachtung – Psychologische Beratung / Coaching\n" .
        "Dachsweg 27, 41189 Mönchengladbach\nkontakt@selbstbetrachtung-online.de\n\n" .
        "--\nIhre Nachricht im Wortlaut:\n{$message}\n";

    $confirm->send();
} catch (PHPMailerException $e) {
    error_log('Eingangsbestätigung an Absender fehlgeschlagen: ' . $confirm->ErrorInfo);
}

echo json_encode(['ok' => true]);
