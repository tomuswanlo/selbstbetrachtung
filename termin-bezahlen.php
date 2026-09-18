<?php
declare(strict_types=1);

/**
 * Öffentliche Zahlseite für einen bereits gebuchten Folgetermin. Nur über den
 * mit der Buchung erzeugten payment_token erreichbar (wie termin-absagen.php
 * und rechnung-bezahlen.php) – der Token selbst ist die Zugriffsberechtigung,
 * deshalb hier bewusst kein zusätzlicher Spam-/Turnstile-Schutz nötig. Buchen
 * (termin.php/termin-api.php) und Bezahlen sind bewusst zwei getrennte
 * Schritte, damit ein Zahlungsproblem nie die eigentliche Terminbuchung/
 * -bestätigung blockiert und die Turnstile-Prüfung nur einmal (beim Buchen)
 * nötig ist.
 */

date_default_timezone_set('Europe/Berlin');

require __DIR__ . '/lib/Booking.php';
require __DIR__ . '/lib/Buchhaltung.php';
require __DIR__ . '/lib/Pakete.php';
require __DIR__ . '/lib/Vertrag.php';
require __DIR__ . '/lib/Payments.php';
require __DIR__ . '/lib/Notifications.php';

function tbBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'selbstbetrachtung-online.de');
}

function tbUnavailablePage(string $heading, string $text): void
{
    http_response_code(403);
    ?><!DOCTYPE html>
<html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($heading) ?> – Selbstbetrachtung</title><meta name="robots" content="noindex, nofollow">
<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#F4EFE7;color:#2E3439;margin:0;padding:3rem 1.25rem;text-align:center;}
.card{max-width:480px;margin:0 auto;background:#FBF8F2;border:1px solid #EDE5D8;border-radius:16px;padding:2rem 1.5rem;}
a{color:#B3813F;}</style></head>
<body><main class="card"><h1><?= htmlspecialchars($heading) ?></h1>
<p><?= $text ?></p></main></body></html>
    <?php
    exit;
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$bookingPdo = Booking::db();
$booking = $token !== '' ? Booking::findByPaymentToken($bookingPdo, $token) : null;

if ($booking === null) {
    tbUnavailablePage('Dieser Link ist nicht (mehr) gültig', 'Entweder wurde der Termin storniert, oder der Link ist fehlerhaft. Bei Fragen wenden Sie sich gerne über das <a href="/#kontakt">Kontaktformular</a> an uns.');
}
if ($booking['type'] !== 'folgetermin') {
    tbUnavailablePage('Keine Online-Zahlung nötig', 'Für dieses Erstgespräch ist keine Zahlung erforderlich.');
}
if ($booking['payment_status'] === 'paid') {
    tbUnavailablePage('Bereits bezahlt ✓', 'Dieser Termin wurde bereits online bezahlt, vielen Dank!');
}
if ($booking['payment_status'] === 'package') {
    tbUnavailablePage('Bereits verrechnet ✓', 'Dieser Termin wurde bereits mit einer Paketstunde verrechnet.');
}

$buchhaltungPdo = Buchhaltung::db();
$settings = Buchhaltung::allSettings($buchhaltungPdo);
$priceCents = (int) ($settings['price_folgetermin_cents'] ?: 7000);
$dateFormatted = (new DateTimeImmutable($booking['date']))->format('d.m.Y');

$errorMessage = '';
$packageSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provider = $_POST['provider'] ?? '';
    $packageCode = trim((string) ($_POST['package_code'] ?? ''));
    $baseUrl = tbBaseUrl();
    $cancelUrl = $baseUrl . '/termin-bezahlen.php?token=' . urlencode($token);
    $description = 'Folgetermin (60 Min.) – Selbstbetrachtung';

    if (in_array($provider, ['stripe', 'paypal'], true)) {
        Vertrag::recordConsent($buchhaltungPdo, 'session', $booking['id'], [
            'name' => $booking['name'], 'address' => null, 'email' => $booking['email'], 'phone' => $booking['phone'], 'geburtsdatum' => null,
        ]);

        if ($provider === 'stripe' && file_exists(__DIR__ . '/stripe_config.php')) {
            require __DIR__ . '/stripe_config.php';
            $successUrl = $baseUrl . '/payment-erfolg.php?provider=stripe&kind=session&ref=' . $booking['id'] . '&session_id={CHECKOUT_SESSION_ID}';
            $result = Payments::createStripeCheckoutSession(
                $priceCents,
                $description,
                $successUrl,
                $cancelUrl,
                ['kind' => 'session', 'reference_id' => (string) $booking['id']],
                $booking['email'] ?: null
            );
        } elseif ($provider === 'paypal' && file_exists(__DIR__ . '/paypal_config.php')) {
            require __DIR__ . '/paypal_config.php';
            $returnUrl = $baseUrl . '/payment-erfolg.php?provider=paypal&kind=session&ref=' . $booking['id'];
            $result = Payments::createPaypalOrder($priceCents, $description, $returnUrl, $cancelUrl, 'session:' . $booking['id']);
        } else {
            $result = ['ok' => false, 'error' => 'Online-Zahlung ist derzeit nicht verfügbar.'];
        }

        if ($result['ok']) {
            Booking::markSessionPending($bookingPdo, (int) $booking['id'], $provider, $result['session_id'] ?? $result['order_id']);
            header('Location: ' . $result['url']);
            exit;
        }
        $errorMessage = $result['error'] ?? 'Die Zahlung konnte nicht gestartet werden.';
    } elseif ($packageCode !== '') {
        $package = Pakete::findByToken($buchhaltungPdo, $packageCode);
        if ($package === null) {
            $errorMessage = 'Dieser Paket-Code ist unbekannt oder das Paket ist noch nicht bezahlt.';
        } elseif (Pakete::hoursRemaining($buchhaltungPdo, $package) < 0.999) {
            $errorMessage = 'Auf diesem Paket ist keine Stunde mehr übrig.';
        } else {
            Booking::markSessionUsingPackage($bookingPdo, (int) $booking['id'], (int) $package['id']);
            Pakete::logUsage($buchhaltungPdo, (int) $package['id'], 1.0, (string) $booking['id'], 'Termin am ' . $dateFormatted . ', ' . $booking['start_time'] . ' Uhr');
            sendPackageRedeemedConfirmation((int) $booking['id'], (int) $package['id']);
            $packageSuccess = true;
        }
    } else {
        $errorMessage = 'Bitte wählen Sie eine Zahlungsart oder geben Sie einen Paket-Code ein.';
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Termin online bezahlen – Selbstbetrachtung</title>
<meta name="robots" content="noindex, nofollow">
<style>
  :root{
    --cream:#F4EFE7; --cream-light:#FBF8F2; --cream-dark:#EDE5D8;
    --ink:#2E3439; --ink-2:#23282C; --ink-mute:#626B71;
    --gold:#D6A26A; --gold-dark:#B3813F; --danger:#B84A3C; --green:#8FD0A8;
    --font-head:"Lora","Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;
    --font-body:"Mulish",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  }
  *{box-sizing:border-box;}
  body{ margin:0; background:var(--cream); color:var(--ink); font-family:var(--font-body); line-height:1.55; }
  main{ max-width:480px; margin:0 auto; padding:3rem 1.25rem; }
  .card{ background:var(--cream-light); border:1px solid var(--cream-dark); border-radius:16px; padding:2rem 1.5rem; text-align:center; }
  h1{ font-family:var(--font-head); font-weight:600; font-size:1.4rem; margin:0 0 .5rem; }
  .amount{ font-family:var(--font-head); font-size:2.2rem; font-weight:600; margin:.75rem 0; }
  .meta{ color:var(--ink-mute); font-size:.9rem; margin-bottom:1rem; }
  .vertrag-box{ background:#fff; border:1px solid var(--cream-dark); border-radius:12px; padding:.85rem 1rem; margin:0 0 1.25rem; font-size:.83rem; color:var(--ink-mute); text-align:left; max-height:8rem; overflow-y:auto; }
  .pay-options{ display:flex; flex-direction:column; gap:.75rem; }
  .btn{ display:block; border:none; border-radius:999px; padding:.85rem 1.5rem; font-weight:700; font-size:1rem; cursor:pointer; font-family:inherit; width:100%; }
  .btn--stripe{ background:var(--ink); color:#fff; }
  .btn--stripe:hover{ background:var(--ink-2); }
  .btn--paypal{ background:#FFC439; color:#003087; }
  .btn--paypal:hover{ background:#ffb400; }
  .divider{ display:flex; align-items:center; gap:.75rem; color:var(--ink-mute); font-size:.8rem; margin:.5rem 0; }
  .divider::before, .divider::after{ content:''; flex:1; height:1px; background:var(--cream-dark); }
  .package-form{ display:flex; gap:.5rem; }
  .package-form input{ flex:1; padding:.65rem .8rem; font-family:inherit; font-size:1rem; border:1.5px solid var(--cream-dark); border-radius:10px; background:#fff; }
  .package-form .btn{ width:auto; flex:0 0 auto; padding:.65rem 1.1rem; font-size:.9rem; background:var(--gold); color:#fff; }
  .package-form .btn:hover{ background:var(--gold-dark); }
  .form-error{ color:var(--danger); font-weight:600; margin-top:1rem; }
  .success-card{ border-color:var(--green); }
  .success-card h1{ color:#2f7a53; }
  .hint{ color:var(--ink-mute); font-size:.85rem; margin-top:1.25rem; }
</style>
</head>
<body>
<main>
  <?php if ($packageSuccess): ?>
  <div class="card success-card">
    <h1>Bezahlt ✓</h1>
    <p>Ihr Termin am <?= htmlspecialchars($dateFormatted) ?> um <?= htmlspecialchars($booking['start_time']) ?> Uhr wurde mit einer Paketstunde verrechnet. Wir freuen uns auf Sie!</p>
  </div>
  <?php else: ?>
  <div class="card">
    <h1>Termin bezahlen</h1>
    <p class="meta"><?= htmlspecialchars(Booking::TYPES[$booking['type']]['label']) ?> am <?= htmlspecialchars($dateFormatted) ?> um <?= htmlspecialchars($booking['start_time']) ?> Uhr</p>
    <p class="amount"><?= htmlspecialchars(Buchhaltung::formatEuro($priceCents)) ?></p>
    <div class="vertrag-box">Mit der Online-Zahlung schließen Sie zugleich den Beratervertrag für diesen Termin ab. Es gelten die <a href="/#agb" target="_blank" rel="noopener">Allgemeinen Geschäftsbedingungen</a> von Selbstbetrachtung (u. a. keine Psychotherapie, 24h-Stornofrist, Schweigepflicht gem. § 203 StGB). Mit Klick auf „Mit Karte/SEPA bezahlen" bzw. „Mit PayPal bezahlen" erklären Sie Ihr rechtsverbindliches Einverständnis in Textform (§ 126b BGB).</div>
    <?php if ($errorMessage !== ''): ?>
      <p class="form-error"><?= htmlspecialchars($errorMessage) ?></p>
    <?php endif; ?>
    <div class="pay-options">
      <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="provider" value="stripe">
        <button type="submit" class="btn btn--stripe">Mit Karte/SEPA bezahlen</button>
      </form>
      <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="provider" value="paypal">
        <button type="submit" class="btn btn--paypal">Mit PayPal bezahlen</button>
      </form>
      <div class="divider">oder</div>
      <form method="post" class="package-form">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="text" name="package_code" placeholder="Paket-Code" autocomplete="off">
        <button type="submit" class="btn">Einlösen</button>
      </form>
    </div>
    <p class="hint">Barzahlung oder Zahlung auf Rechnung vor Ort ist nur nach vorheriger Absprache möglich.</p>
  </div>
  <?php endif; ?>
</main>
</body>
</html>
