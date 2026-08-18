<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/lib/Booking.php';

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function field(string $name): string
{
    return trim((string) ($_POST[$name] ?? $_GET[$name] ?? ''));
}

function baseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'selbstbetrachtung-online.de';
    return $scheme . '://' . $host;
}

$pdo = Booking::db();
$action = field('action');

// ---------------------------------------------------------------------
// GET: Kalender-Übersicht für einen Monat
// ---------------------------------------------------------------------
if ($action === 'month' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $year = (int) field('year');
    $month = (int) field('month');
    $type = field('type');

    if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12 || !isset(Booking::TYPES[$type])) {
        respond(['ok' => false, 'error' => 'Ungültige Anfrage.'], 400);
    }

    $dates = Booking::availableDatesInMonth($pdo, $year, $month, $type);
    respond(['ok' => true, 'dates' => $dates]);
}

// ---------------------------------------------------------------------
// GET: freie Slots für ein Datum
// ---------------------------------------------------------------------
if ($action === 'slots' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $date = field('date');
    $type = field('type');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !isset(Booking::TYPES[$type])) {
        respond(['ok' => false, 'error' => 'Ungültige Anfrage.'], 400);
    }

    $slots = Booking::slotsForDate($pdo, $date, $type);
    respond(['ok' => true, 'slots' => $slots]);
}

// ---------------------------------------------------------------------
// POST: Termin buchen
// ---------------------------------------------------------------------
if ($action === 'book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Spam-Abwehr (wie contact.php) -------------------------------
    $isHoneypotTriggered = field('hp_confirm2') !== '';
    $renderedAt = (float) field('ts');
    $isTooFast = $renderedAt > 0 && (microtime(true) * 1000 - $renderedAt) < 3000;

    if ($isHoneypotTriggered || $isTooFast) {
        respond(['ok' => false, 'error' => 'Bitte versuchen Sie es erneut.'], 422);
    }

    $configFile = __DIR__ . '/smtp_config.php';
    if (!file_exists($configFile)) {
        respond(['ok' => false, 'error' => 'Server-Konfiguration fehlt.'], 500);
    }
    require $configFile;

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
            respond(['ok' => false, 'error' => 'Bitte bestätigen Sie die Sicherheitsabfrage.'], 422);
        }
    }

    // --- Eingaben validieren -----------------------------------------
    $date = field('date');
    $startTime = field('start_time');
    $type = field('type');
    $name = field('name');
    $email = field('email');
    $phone = field('phone');
    $message = field('message');
    $consent = field('consent') !== '';

    $errors = [];
    if (!isset(Booking::TYPES[$type])) {
        $errors[] = 'type';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = 'date';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) {
        $errors[] = 'start_time';
    }
    if ($name === '') {
        $errors[] = 'name';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'email';
    }
    if (!$consent) {
        $errors[] = 'consent';
    }
    if ($errors) {
        respond(['ok' => false, 'error' => 'Bitte füllen Sie die markierten Felder aus.', 'fields' => $errors], 422);
    }

    $result = Booking::createBooking($pdo, [
        'date' => $date,
        'start_time' => $startTime,
        'type' => $type,
        'name' => $name,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
        'message' => $message !== '' ? $message : null,
    ]);

    if (!$result['ok']) {
        respond($result, $result['conflict'] ?? false ? 409 : 400);
    }

    $booking = $result['booking'];
    $typeLabel = Booking::TYPES[$type]['label'];
    $dateFormatted = (new DateTimeImmutable($date))->format('d.m.Y');
    $cancelUrl = baseUrl() . '/termin-absagen.php?token=' . urlencode($booking['cancel_token']);

    require __DIR__ . '/lib/PHPMailer/src/Exception.php';
    require __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
    require __DIR__ . '/lib/PHPMailer/src/SMTP.php';

    // E-Mail an die Praxis
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_USER, 'Selbstbetrachtung Website');
        $mail->addAddress(MAIL_TO);
        $mail->addReplyTo($email, $name);

        $mail->Subject = "Neuer Online-Termin: {$typeLabel} am {$dateFormatted}, {$startTime} Uhr";
        $bodyLines = [
            "Neue Terminbuchung über die Website:",
            '',
            "Terminart: {$typeLabel}",
            "Datum: {$dateFormatted}",
            "Uhrzeit: {$startTime}–{$booking['end_time']} Uhr",
            '',
            "Name: {$name}",
            "E-Mail: {$email}",
        ];
        if ($phone !== '') {
            $bodyLines[] = "Telefon: {$phone}";
        }
        if ($message !== '') {
            $bodyLines[] = '';
            $bodyLines[] = 'Nachricht:';
            $bodyLines[] = $message;
        }
        $mail->Body = implode("\n", $bodyLines) . "\n";
        $mail->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Termin-Benachrichtigung an Praxis fehlgeschlagen: ' . $e->getMessage());
    }

    // Bestätigungs-E-Mail an die buchende Person
    try {
        $confirm = new PHPMailer\PHPMailer\PHPMailer(true);
        $confirm->isSMTP();
        $confirm->Host = SMTP_HOST;
        $confirm->SMTPAuth = true;
        $confirm->Username = SMTP_USER;
        $confirm->Password = SMTP_PASS;
        $confirm->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $confirm->Port = SMTP_PORT;
        $confirm->CharSet = 'UTF-8';

        $confirm->setFrom(SMTP_USER, 'Gabriele Küppers – Selbstbetrachtung');
        $confirm->addAddress($email, $name);

        $confirm->Subject = "Terminbestätigung: {$typeLabel} am {$dateFormatted}, {$startTime} Uhr";
        $confirm->Body =
            "Liebe/r {$name},\n\n" .
            "Ihr Termin ist bestätigt:\n\n" .
            "{$typeLabel}\n" .
            "{$dateFormatted}, {$startTime}–{$booking['end_time']} Uhr\n\n" .
            "Sollten Sie den Termin nicht wahrnehmen können, sagen Sie ihn bitte hier ab:\n" .
            "{$cancelUrl}\n\n" .
            "Diese Bestätigung wird automatisch versendet, bitte antworten Sie bei Rückfragen direkt auf diese E-Mail.\n\n" .
            "Herzliche Grüße\nGabriele Küppers\nSelbstbetrachtung – Psychologische Beratung / Coaching\n" .
            "Dachsweg 27, 41189 Mönchengladbach\nkontakt@selbstbetrachtung-online.de\n";
        $confirm->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Terminbestätigung an Klient*in fehlgeschlagen: ' . $e->getMessage());
    }

    respond([
        'ok' => true,
        'booking' => [
            'date' => $date,
            'date_formatted' => $dateFormatted,
            'start_time' => $startTime,
            'end_time' => $booking['end_time'],
            'type_label' => $typeLabel,
            'cancel_url' => $cancelUrl,
        ],
    ]);
}

respond(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
