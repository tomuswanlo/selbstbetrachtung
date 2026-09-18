<?php
declare(strict_types=1);

/**
 * E-Mail-Versand, der von mehreren Zahlungs-Endpunkten gebraucht wird
 * (payment-webhook.php für Stripe/als PayPal-Backup, payment-erfolg.php als
 * primärer PayPal-Bestätigungspfad) – deshalb hier ausgelagert statt wie
 * sonst in dieser Codebasis üblich pro Datei dupliziert, da es sich um recht
 * umfangreiche Funktionen handelt.
 */

if (!function_exists('baseUrl')) {
    function baseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'selbstbetrachtung-online.de';
        return $scheme . '://' . $host;
    }
}

/**
 * Verschickt die Terminbestätigung für einen online bezahlten Folgetermin.
 * Bei einer "vor Ort"-Buchung übernimmt das termin-api.php synchron direkt
 * nach dem Anlegen – hier ist das nötig, weil die Buchung bei Online-Zahlung
 * erst durch die Zahlungsbestätigung (Webhook bzw. bei PayPal die Capture in
 * payment-erfolg.php), nicht durch den ursprünglichen Buchungsrequest, als
 * abgeschlossen gilt. Rein informativ/best effort wie die übrigen
 * Mailversände dieser Codebasis: ein Fehlschlag wird geloggt, nicht dem
 * auslösenden Request (Stripe/PayPal/Browser-Redirect) als Fehler gemeldet.
 */
function sendSessionPaidConfirmation(int $bookingId): void
{
    require_once __DIR__ . '/Booking.php';
    $pdo = Booking::db();
    $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = :id');
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch();
    if (!$booking || !$booking['email']) {
        return;
    }

    $smtpConfigFile = __DIR__ . '/../smtp_config.php';
    if (!file_exists($smtpConfigFile)) {
        return;
    }
    require_once $smtpConfigFile;
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';

    $typeLabel = Booking::TYPES[$booking['type']]['label'] ?? $booking['type'];
    $dateFormatted = (new DateTimeImmutable($booking['date']))->format('d.m.Y');
    $cancelUrl = baseUrl() . '/termin-absagen.php?token=' . urlencode((string) $booking['cancel_token']);

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
        $confirm->addAddress($booking['email'], $booking['name']);
        $confirm->Subject = "Terminbestätigung: {$typeLabel} am {$dateFormatted}, {$booking['start_time']} Uhr";
        $confirm->Body =
            "Liebe/r {$booking['name']},\n\n" .
            "vielen Dank für Ihre Online-Zahlung – Ihr Termin ist bestätigt:\n\n" .
            "{$typeLabel}\n" .
            "{$dateFormatted}, {$booking['start_time']}–{$booking['end_time']} Uhr\n\n" .
            "Sollten Sie den Termin nicht wahrnehmen können, sagen Sie ihn bitte hier ab:\n" .
            "{$cancelUrl}\n\n" .
            "Bitte beachten Sie: Bei einer Absage weniger als 24 Stunden vor dem Termin wird das Honorar trotz Absage fällig " .
            "(siehe AGB).\n\n" .
            "Diese Bestätigung wird automatisch versendet, bitte antworten Sie bei Rückfragen direkt auf diese E-Mail.\n\n" .
            "Herzliche Grüße\nGabriele Küppers\nSelbstbetrachtung – Psychologische Beratung / Coaching\n" .
            "Dachsweg 27, 41189 Mönchengladbach\nkontakt@selbstbetrachtung-online.de\n";
        $confirm->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Terminbestätigung (Online-Zahlung) an Klient*in fehlgeschlagen: ' . $e->getMessage());
    }

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
        $mail->addReplyTo($booking['email'], $booking['name']);
        $mail->Subject = "Online bezahlter Termin: {$typeLabel} am {$dateFormatted}, {$booking['start_time']} Uhr";
        $mail->Body = "Online-Zahlung eingegangen und Termin bestätigt.\n\nName: {$booking['name']}\nE-Mail: {$booking['email']}\n{$typeLabel} am {$dateFormatted}, {$booking['start_time']}–{$booking['end_time']} Uhr\n";
        $mail->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Praxis-Benachrichtigung (Online-Zahlung) fehlgeschlagen: ' . $e->getMessage());
    }
}

/**
 * Verschickt die Kaufbestätigung für ein bezahltes 5-Stunden-Paket – das ist die
 * EINZIGE Stelle, an der der Klient seinen purchase_token (Paket-Code) erhält,
 * ohne den sich später keine Stunde einlösen lässt (siehe termin-bezahlen.php).
 * Bis zu diesem Fix wurde nach einem Paket-Kauf gar keine E-Mail verschickt.
 */
function sendPackagePaidConfirmation(int $packageId): void
{
    require_once __DIR__ . '/Pakete.php';
    require_once __DIR__ . '/Buchhaltung.php';
    $pdo = Buchhaltung::db();
    $pkg = Pakete::findById($pdo, $packageId);
    if (!$pkg || !$pkg['client_email']) {
        return;
    }

    $smtpConfigFile = __DIR__ . '/../smtp_config.php';
    if (!file_exists($smtpConfigFile)) {
        return;
    }
    require_once $smtpConfigFile;
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';

    $hours = (int) $pkg['hours_total'];
    $priceLabel = Buchhaltung::formatEuro((int) $pkg['price_cents']);

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
        $confirm->addAddress($pkg['client_email'], $pkg['client_name']);
        $confirm->Subject = "Ihr {$hours}-Stunden-Paket ist bezahlt";
        $confirm->Body =
            "Liebe/r {$pkg['client_name']},\n\n" .
            "vielen Dank für Ihre Online-Zahlung – Ihr {$hours}-Stunden-Paket ({$priceLabel}) ist bestätigt.\n\n" .
            "Ihr Paket-Code lautet:\n{$pkg['purchase_token']}\n\n" .
            "Bei jeder künftigen Terminbuchung eines Folgetermins können Sie diesen Code auf der Bezahlseite " .
            "im Feld \"Mit Paket-Code bezahlen\" eingeben, um eine Stunde von Ihrem Restguthaben abzubuchen, " .
            "statt erneut zu bezahlen. Bewahren Sie diese E-Mail daher gut auf.\n\n" .
            "Diese Bestätigung wird automatisch versendet, bitte antworten Sie bei Rückfragen direkt auf diese E-Mail.\n\n" .
            "Herzliche Grüße\nGabriele Küppers\nSelbstbetrachtung – Psychologische Beratung / Coaching\n" .
            "Dachsweg 27, 41189 Mönchengladbach\nkontakt@selbstbetrachtung-online.de\n";
        $confirm->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Paket-Kaufbestätigung an Klient*in fehlgeschlagen: ' . $e->getMessage());
    }

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
        $mail->addReplyTo($pkg['client_email'], $pkg['client_name']);
        $mail->Subject = "Online bezahltes {$hours}-Stunden-Paket: {$pkg['client_name']}";
        $mail->Body = "Paket-Kauf online bezahlt.\n\nName: {$pkg['client_name']}\nE-Mail: {$pkg['client_email']}\nBetrag: {$priceLabel}\n";
        $mail->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Praxis-Benachrichtigung (Paket-Kauf) fehlgeschlagen: ' . $e->getMessage());
    }
}

/**
 * Verschickt die Bestätigung, wenn ein Termin auf termin-bezahlen.php per
 * Paket-Code (statt Stripe/PayPal) bezahlt wurde – mit dem verbleibenden
 * Restguthaben, damit der Klient weiß, wie viele Stunden noch übrig sind.
 * Muss NACH Pakete::logUsage() aufgerufen werden, damit hoursRemaining()
 * bereits den aktuellen (verringerten) Stand zurückgibt.
 */
function sendPackageRedeemedConfirmation(int $bookingId, int $packageId): void
{
    require_once __DIR__ . '/Booking.php';
    require_once __DIR__ . '/Pakete.php';
    require_once __DIR__ . '/Buchhaltung.php';
    $bookingPdo = Booking::db();
    $stmt = $bookingPdo->prepare('SELECT * FROM bookings WHERE id = :id');
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch();
    if (!$booking || !$booking['email']) {
        return;
    }
    $buchhaltungPdo = Buchhaltung::db();
    $pkg = Pakete::findById($buchhaltungPdo, $packageId);
    if (!$pkg) {
        return;
    }

    $smtpConfigFile = __DIR__ . '/../smtp_config.php';
    if (!file_exists($smtpConfigFile)) {
        return;
    }
    require_once $smtpConfigFile;
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';

    $typeLabel = Booking::TYPES[$booking['type']]['label'] ?? $booking['type'];
    $dateFormatted = (new DateTimeImmutable($booking['date']))->format('d.m.Y');
    $cancelUrl = baseUrl() . '/termin-absagen.php?token=' . urlencode((string) $booking['cancel_token']);
    $remaining = Pakete::hoursRemaining($buchhaltungPdo, $pkg);
    $remainingLabel = rtrim(rtrim(number_format($remaining, 1, ',', ''), '0'), ',');
    $totalHours = (int) $pkg['hours_total'];

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
        $confirm->addAddress($booking['email'], $booking['name']);
        $confirm->Subject = "Mit Paket bezahlt: {$typeLabel} am {$dateFormatted}, {$booking['start_time']} Uhr";
        $confirm->Body =
            "Liebe/r {$booking['name']},\n\n" .
            "Ihr Termin ist bestätigt und wurde mit einer Stunde von Ihrem {$totalHours}-Stunden-Paket bezahlt:\n\n" .
            "{$typeLabel}\n" .
            "{$dateFormatted}, {$booking['start_time']}–{$booking['end_time']} Uhr\n\n" .
            "Sie haben noch ein Guthaben von {$remainingLabel} von {$totalHours} Stunden auf Ihrem Paket übrig.\n\n" .
            "Sollten Sie den Termin nicht wahrnehmen können, sagen Sie ihn bitte hier ab:\n" .
            "{$cancelUrl}\n\n" .
            "Bitte beachten Sie: Bei einer Absage weniger als 24 Stunden vor dem Termin wird die Stunde trotz Absage " .
            "von Ihrem Paket abgezogen (siehe AGB).\n\n" .
            "Diese Bestätigung wird automatisch versendet, bitte antworten Sie bei Rückfragen direkt auf diese E-Mail.\n\n" .
            "Herzliche Grüße\nGabriele Küppers\nSelbstbetrachtung – Psychologische Beratung / Coaching\n" .
            "Dachsweg 27, 41189 Mönchengladbach\nkontakt@selbstbetrachtung-online.de\n";
        $confirm->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Paket-Einlösung-Bestätigung an Klient*in fehlgeschlagen: ' . $e->getMessage());
    }

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
        $mail->addReplyTo($booking['email'], $booking['name']);
        $mail->Subject = "Mit Paket bezahlter Termin: {$typeLabel} am {$dateFormatted}, {$booking['start_time']} Uhr";
        $mail->Body = "Termin mit Paketstunde bezahlt.\n\nName: {$booking['name']}\nE-Mail: {$booking['email']}\n{$typeLabel} am {$dateFormatted}, {$booking['start_time']}–{$booking['end_time']} Uhr\nRestguthaben auf dem Paket: {$remainingLabel} von {$totalHours} Std.\n";
        $mail->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Praxis-Benachrichtigung (Paket-Einlösung) fehlgeschlagen: ' . $e->getMessage());
    }
}

/**
 * Informiert den/die Klient*in, wenn DIE PRAXIS (nicht der Klient selbst) einen
 * bezahlten Termin storniert hat – termin-admin.php benachrichtigt sonst niemanden
 * automatisch (siehe Hinweistext dort), aber bei einer Zahlung gibt es jetzt eine
 * echte Konsequenz (Rückbuchung/Erstattung offen/verfällt), die mitgeteilt werden
 * sollte. $paymentStatusAfterCancel ist der Rückgabewert von Booking::cancelById()
 * NACH der Stornierung, entscheidet also direkt den Mailtext.
 */
function sendAdminCancellationNotice(int $bookingId, string $paymentStatusAfterCancel): void
{
    require_once __DIR__ . '/Booking.php';
    $pdo = Booking::db();
    $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = :id');
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch();
    if (!$booking || !$booking['email']) {
        return;
    }

    $smtpConfigFile = __DIR__ . '/../smtp_config.php';
    if (!file_exists($smtpConfigFile)) {
        return;
    }
    require_once $smtpConfigFile;
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';

    $typeLabel = Booking::TYPES[$booking['type']]['label'] ?? $booking['type'];
    $dateFormatted = (new DateTimeImmutable($booking['date']))->format('d.m.Y');

    $refundNote = match ($paymentStatusAfterCancel) {
        'refunded' => 'Ihre Paketstunde wurde Ihnen gutgeschrieben.',
        'refund_pending' => 'Ihre Online-Zahlung wird zeitnah erstattet.',
        default => 'Da die Absage weniger als ' . Booking::REFUND_LEAD_HOURS . ' Stunden vor dem Termin erfolgt, verfällt die Zahlung bzw. Paketstunde gemäß unserer AGB.',
    };

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
        $mail->setFrom(SMTP_USER, 'Gabriele Küppers – Selbstbetrachtung');
        $mail->addAddress($booking['email'], $booking['name']);
        $mail->Subject = "Terminabsage: {$typeLabel} am {$dateFormatted}, {$booking['start_time']} Uhr";
        $mail->Body =
            "Liebe/r {$booking['name']},\n\n" .
            "Ihr Termin wurde von uns storniert:\n\n" .
            "{$typeLabel}\n" .
            "{$dateFormatted}, {$booking['start_time']}–{$booking['end_time']} Uhr\n\n" .
            "{$refundNote}\n\n" .
            "Bei Fragen erreichen Sie uns über das Kontaktformular (" . baseUrl() . "/#kontakt) " .
            "oder telefonisch unter +49 151 4135 7281.\n\n" .
            "Diese Mail wird automatisch versendet, bitte antworten Sie bei Rückfragen direkt auf diese E-Mail.\n\n" .
            "Herzliche Grüße\nGabriele Küppers\nSelbstbetrachtung – Psychologische Beratung / Coaching\n" .
            "Dachsweg 27, 41189 Mönchengladbach\nkontakt@selbstbetrachtung-online.de\n";
        $mail->send();
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('Admin-Storno-Benachrichtigung an Klient*in fehlgeschlagen: ' . $e->getMessage());
    }
}
