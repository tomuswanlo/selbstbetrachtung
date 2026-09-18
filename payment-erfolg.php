<?php
declare(strict_types=1);

/**
 * Rückkehr-Seite nach dem Checkout.
 *
 * Bei Stripe ist NICHT diese Seite die Zahlungsbestätigung (ein Klient könnte
 * sie auch ohne zu bezahlen manuell aufrufen) – die eigentliche Verbuchung
 * übernimmt ausschließlich payment-webhook.php anhand der geprüften Stripe-
 * Signatur. Hier wird nur freundlich angezeigt, ob dieser Webhook (der meist
 * innerhalb von 1–2 Sekunden eintrifft) bereits verarbeitet wurde.
 *
 * Bei PayPal ist der Ablauf anders: Der Klient wurde nach Zustimmung hierher
 * zurückgeleitet, aber noch nichts wurde abgebucht ("Order genehmigt" ist bei
 * PayPal nicht gleich "bezahlt"). Diese Seite ruft deshalb selbst die Capture
 * an – das ist hier der eigentliche, verbindliche Zahlungsvorgang.
 */

date_default_timezone_set('Europe/Berlin');

require __DIR__ . '/lib/Buchhaltung.php';
require __DIR__ . '/lib/Pakete.php';
require __DIR__ . '/lib/Vertrag.php';
require __DIR__ . '/lib/Booking.php';
require __DIR__ . '/lib/Payments.php';
require __DIR__ . '/lib/Notifications.php';

$provider = $_GET['provider'] ?? '';
$kind = $_GET['kind'] ?? '';
$refId = (int) ($_GET['ref'] ?? 0);

$status = 'pending'; // pending | ok | error
$errorMessage = '';

if ($provider === 'paypal' && in_array($kind, ['package', 'session', 'invoice'], true) && $refId > 0) {
    $orderId = $_GET['token'] ?? ''; // von PayPal automatisch an return_url angehängt
    $configFile = __DIR__ . '/paypal_config.php';
    if ($orderId === '' || !file_exists($configFile)) {
        $status = 'error';
        $errorMessage = 'Die Zahlung konnte nicht bestätigt werden.';
    } else {
        require $configFile;
        $capture = Payments::capturePaypalOrder($orderId);
        if ($capture['ok']) {
            $result = Payments::recordSuccessfulPayment([
                'kind' => $kind,
                'reference_id' => $refId,
                'provider' => 'paypal',
                'payment_reference' => $orderId,
                'amount_cents' => $capture['amount_cents'],
            ]);
            $status = $result['ok'] ? 'ok' : 'error';
            $errorMessage = $result['error'] ?? '';
            if ($result['ok'] && $kind === 'session' && empty($result['already_processed'])) {
                sendSessionPaidConfirmation($refId);
            }
        } else {
            $status = 'error';
            $errorMessage = $capture['error'] ?? 'Die Zahlung konnte nicht abgeschlossen werden.';
        }
    }
} elseif ($provider === 'stripe' && in_array($kind, ['package', 'session', 'invoice'], true) && $refId > 0) {
    // Nur anzeigen, ob der Webhook schon durch ist – keine eigene Verbuchung hier.
    $buchhaltungPdo = Buchhaltung::db();
    if ($kind === 'package') {
        $pkg = Pakete::findById($buchhaltungPdo, $refId);
        $status = ($pkg && $pkg['status'] === 'bezahlt') ? 'ok' : 'pending';
    } elseif ($kind === 'invoice') {
        $invoice = Buchhaltung::findInvoice($buchhaltungPdo, $refId);
        $status = ($invoice && $invoice['status'] === 'bezahlt') ? 'ok' : 'pending';
    } else { // session
        $bookingPdo = Booking::db();
        $stmt = $bookingPdo->prepare('SELECT payment_status FROM bookings WHERE id = :id');
        $stmt->execute(['id' => $refId]);
        $paymentStatus = $stmt->fetchColumn();
        $status = ($paymentStatus === 'paid') ? 'ok' : 'pending';
    }
} else {
    $status = 'error';
    $errorMessage = 'Ungültiger Aufruf.';
}

$kindLabel = ['package' => 'Ihr 5-Stunden-Paket', 'session' => 'Ihr Termin', 'invoice' => 'Ihre Rechnung'][$kind] ?? 'Ihre Zahlung';
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zahlung – Selbstbetrachtung</title>
<meta name="robots" content="noindex, nofollow">
<style>
  :root{
    --cream:#F4EFE7; --cream-light:#FBF8F2; --cream-dark:#EDE5D8;
    --ink:#2E3439; --ink-mute:#626B71; --gold:#D6A26A; --gold-dark:#B3813F;
    --green:#8FD0A8; --danger:#B84A3C;
    --font-head:"Lora","Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;
    --font-body:"Mulish",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  }
  *{box-sizing:border-box;}
  body{ margin:0; background:var(--cream); color:var(--ink); font-family:var(--font-body); line-height:1.55; }
  main{ max-width:520px; margin:0 auto; padding:3rem 1.25rem; text-align:center; }
  .card{ background:var(--cream-light); border-radius:16px; padding:2rem 1.5rem; border:1px solid var(--cream-dark); }
  .card.ok{ border-color:var(--green); }
  .card.error{ border-color:var(--danger); }
  h1{ font-family:var(--font-head); font-weight:600; font-size:1.5rem; margin:0 0 .75rem; }
  .card.ok h1{ color:#2f7a53; }
  .card.error h1{ color:var(--danger); }
  p{ color:var(--ink-mute); }
  a.btn{ display:inline-block; margin-top:1.25rem; background:var(--gold); color:#fff; text-decoration:none; font-weight:700; border-radius:999px; padding:.75rem 1.5rem; }
  a.btn:hover{ background:var(--gold-dark); }
</style>
</head>
<body>
<main>
  <?php if ($status === 'ok'): ?>
    <div class="card ok">
      <h1>Vielen Dank – Zahlung erfolgreich ✓</h1>
      <p><?= htmlspecialchars($kindLabel) ?> ist bestätigt. Sie erhalten in Kürze eine Bestätigung per E-Mail.</p>
      <a class="btn" href="/">Zur Startseite</a>
    </div>
  <?php elseif ($status === 'pending'):
      $attempt = (int) ($_GET['wait'] ?? 0);
  ?>
    <?php if ($attempt < 6): ?>
      <div class="card" id="pendingCard">
        <h1>Zahlung wird bestätigt …</h1>
        <p>Einen Moment bitte, die Bestätigung Ihrer Zahlung wird gerade verarbeitet. Diese Seite aktualisiert sich automatisch.</p>
      </div>
      <script>
        setTimeout(function(){
          var url = new URL(window.location.href);
          url.searchParams.set('wait', '<?= $attempt + 1 ?>');
          window.location.href = url.toString();
        }, 3000);
      </script>
    <?php else: ?>
      <div class="card">
        <h1>Zahlung wird noch verarbeitet</h1>
        <p>Das dauert gerade länger als gewöhnlich. Sie erhalten eine Bestätigung per E-Mail, sobald es geklappt hat – bei Fragen melden Sie sich gerne über das <a href="/#kontakt">Kontaktformular</a>.</p>
        <a class="btn" href="/">Zur Startseite</a>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="card error">
      <h1>Etwas ist schiefgelaufen</h1>
      <p><?= htmlspecialchars($errorMessage !== '' ? $errorMessage : 'Die Zahlung konnte nicht bestätigt werden.') ?> Falls bereits Geld abgebucht wurde, melden Sie sich bitte über das <a href="/#kontakt">Kontaktformular</a> – wir klären das gerne persönlich.</p>
      <a class="btn" href="/">Zur Startseite</a>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
