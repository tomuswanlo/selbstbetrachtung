<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');
require __DIR__ . '/lib/Booking.php';

$pdo = Booking::db();
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$booking = $token !== '' ? Booking::findByToken($pdo, $token) : null;

$justCancelled = false;
$cancelError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $booking) {
    $result = Booking::cancelByToken($pdo, $token, 'client');
    if ($result['ok']) {
        $justCancelled = true;
        $booking = $result['booking'];

        // Praxis per E-Mail informieren (best effort)
        $configFile = __DIR__ . '/smtp_config.php';
        if (file_exists($configFile)) {
            require $configFile;
            require __DIR__ . '/lib/PHPMailer/src/Exception.php';
            require __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
            require __DIR__ . '/lib/PHPMailer/src/SMTP.php';
            try {
                $typeLabel = Booking::TYPES[$booking['type']]['label'] ?? $booking['type'];
                $dateFormatted = (new DateTimeImmutable($booking['date']))->format('d.m.Y');
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
                $mail->Subject = "Termin abgesagt: {$typeLabel} am {$dateFormatted}, {$booking['start_time']} Uhr";
                $mail->Body = "{$booking['name']} ({$booking['email']}) hat den folgenden Termin online storniert:\n\n" .
                    "{$typeLabel}\n{$dateFormatted}, {$booking['start_time']}–{$booking['end_time']} Uhr\n";
                $mail->send();
            } catch (Throwable $e) {
                error_log('Storno-Benachrichtigung an Praxis fehlgeschlagen: ' . $e->getMessage());
            }
        }
    } else {
        $cancelError = $result['error'];
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Termin absagen – Selbstbetrachtung</title>
<meta name="robots" content="noindex, nofollow">
<style>
  :root{
    --cream:#F4EFE7; --cream-light:#FBF8F2; --cream-dark:#EDE5D8;
    --ink:#2E3439; --ink-mute:#626B71; --gold:#D6A26A; --gold-dark:#B3813F; --danger:#B84A3C;
    --font-head:"Lora","Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;
    --font-body:"Mulish",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  }
  body{ margin:0; background:var(--cream); color:var(--ink); font-family:var(--font-body); line-height:1.55; }
  .site-header{ padding:1rem 1.5rem; background:var(--cream-light); border-bottom:1px solid var(--cream-dark); }
  .site-header a{ color:var(--ink-mute); text-decoration:none; font-size:.95rem; }
  main{ max-width:520px; margin:0 auto; padding:2.5rem 1.25rem 4rem; }
  h1{ font-family:var(--font-head); font-weight:600; font-size:1.6rem; }
  .card{ background:var(--cream-light); border:1px solid var(--cream-dark); border-radius:16px; padding:1.5rem; }
  .btn{ display:inline-block; border:none; border-radius:999px; padding:.85rem 1.75rem; font-weight:700; font-size:1rem; cursor:pointer; font-family:inherit; background:var(--danger); color:#fff; margin-top:1rem; }
  .btn:hover{ opacity:.9; }
  .error{ color:var(--danger); font-weight:600; }
  .ok{ color:#2f7a53; }
</style>
</head>
<body>
  <header class="site-header"><a href="/">← Zur Startseite</a></header>
  <main>
    <h1>Termin absagen</h1>
    <div class="card">
    <?php if (!$booking): ?>
      <p class="error">Dieser Link ist ungültig oder abgelaufen. Bitte kontaktieren Sie uns direkt unter <a href="mailto:kontakt@selbstbetrachtung-online.de">kontakt@selbstbetrachtung-online.de</a>.</p>
    <?php elseif ($justCancelled): ?>
      <p class="ok">Ihr Termin wurde storniert.</p>
      <p>Sie können jederzeit über die Website einen neuen Termin buchen.</p>
      <p><a href="/termin.php">Neuen Termin buchen</a></p>
    <?php elseif ($booking['status'] === 'cancelled'): ?>
      <p>Dieser Termin wurde bereits storniert.</p>
      <p><a href="/termin.php">Neuen Termin buchen</a></p>
    <?php else:
        $typeLabel = Booking::TYPES[$booking['type']]['label'] ?? $booking['type'];
        $dateFormatted = (new DateTimeImmutable($booking['date']))->format('d.m.Y');
    ?>
      <p>Möchten Sie den folgenden Termin wirklich absagen?</p>
      <p><strong><?= htmlspecialchars($typeLabel) ?></strong><br>
      <?= htmlspecialchars($dateFormatted) ?>, <?= htmlspecialchars($booking['start_time']) ?>–<?= htmlspecialchars($booking['end_time']) ?> Uhr</p>
      <?php if ($cancelError): ?><p class="error"><?= htmlspecialchars($cancelError) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <button type="submit" class="btn">Termin absagen</button>
      </form>
    <?php endif; ?>
    </div>
  </main>
</body>
</html>
