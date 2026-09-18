<?php
declare(strict_types=1);

/**
 * Öffentliche Zahlseite für eine einzelne, von der Praxis freigegebene
 * Rechnung. Nur über den personalisierten Token-Link erreichbar (wie
 * termin-absagen.php) – der Token selbst ist die Zugriffsberechtigung,
 * deshalb hier bewusst kein zusätzlicher Spam-/Turnstile-Schutz nötig.
 */

date_default_timezone_set('Europe/Berlin');

require __DIR__ . '/lib/Buchhaltung.php';
require __DIR__ . '/lib/Payments.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$pdo = Buchhaltung::db();
$invoice = $token !== '' ? Buchhaltung::findInvoiceByPaymentToken($pdo, $token) : null;

if ($invoice === null) {
    http_response_code(403);
    ?><!DOCTYPE html>
<html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nicht verfügbar – Selbstbetrachtung</title><meta name="robots" content="noindex, nofollow">
<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#F4EFE7;color:#2E3439;margin:0;padding:3rem 1.25rem;text-align:center;}
.card{max-width:480px;margin:0 auto;background:#FBF8F2;border:1px solid #EDE5D8;border-radius:16px;padding:2rem 1.5rem;}
a{color:#B3813F;}</style></head>
<body><main class="card"><h1>Dieser Link ist nicht (mehr) gültig</h1>
<p>Entweder wurde diese Rechnung bereits bezahlt, oder der Link ist fehlerhaft. Bei Fragen wenden Sie sich gerne über das <a href="/#kontakt">Kontaktformular</a> an uns.</p></main></body></html>
    <?php
    exit;
}

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provider = $_POST['provider'] ?? '';
    $baseUrl = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'selbstbetrachtung-online.de');
    $description = 'Rechnung ' . $invoice['invoice_number'] . ' – Selbstbetrachtung';
    $cancelUrl = $baseUrl . '/rechnung-bezahlen.php?token=' . urlencode($token);

    if ($provider === 'stripe' && file_exists(__DIR__ . '/stripe_config.php')) {
        require __DIR__ . '/stripe_config.php';
        $successUrl = $baseUrl . '/payment-erfolg.php?provider=stripe&kind=invoice&ref=' . $invoice['id'] . '&session_id={CHECKOUT_SESSION_ID}';
        $result = Payments::createStripeCheckoutSession(
            (int) $invoice['amount_cents'],
            $description,
            $successUrl,
            $cancelUrl,
            ['kind' => 'invoice', 'reference_id' => (string) $invoice['id']],
            $invoice['client_email'] ?: null
        );
        if ($result['ok']) {
            header('Location: ' . $result['url']);
            exit;
        }
        $errorMessage = $result['error'] ?? 'Die Zahlung konnte nicht gestartet werden.';
    } elseif ($provider === 'paypal' && file_exists(__DIR__ . '/paypal_config.php')) {
        require __DIR__ . '/paypal_config.php';
        $returnUrl = $baseUrl . '/payment-erfolg.php?provider=paypal&kind=invoice&ref=' . $invoice['id'];
        $result = Payments::createPaypalOrder(
            (int) $invoice['amount_cents'],
            $description,
            $returnUrl,
            $cancelUrl,
            'invoice:' . $invoice['id']
        );
        if ($result['ok']) {
            header('Location: ' . $result['url']);
            exit;
        }
        $errorMessage = $result['error'] ?? 'Die Zahlung konnte nicht gestartet werden.';
    } else {
        $errorMessage = 'Online-Zahlung ist derzeit nicht verfügbar.';
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rechnung online bezahlen – Selbstbetrachtung</title>
<meta name="robots" content="noindex, nofollow">
<style>
  :root{
    --cream:#F4EFE7; --cream-light:#FBF8F2; --cream-dark:#EDE5D8;
    --ink:#2E3439; --ink-2:#23282C; --ink-mute:#626B71;
    --gold:#D6A26A; --gold-dark:#B3813F; --danger:#B84A3C;
    --font-head:"Lora","Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;
    --font-body:"Mulish",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  }
  *{box-sizing:border-box;}
  body{ margin:0; background:var(--cream); color:var(--ink); font-family:var(--font-body); line-height:1.55; }
  main{ max-width:480px; margin:0 auto; padding:3rem 1.25rem; }
  .card{ background:var(--cream-light); border:1px solid var(--cream-dark); border-radius:16px; padding:2rem 1.5rem; text-align:center; }
  h1{ font-family:var(--font-head); font-weight:600; font-size:1.4rem; margin:0 0 .5rem; }
  .amount{ font-family:var(--font-head); font-size:2.2rem; font-weight:600; margin:1rem 0; }
  .meta{ color:var(--ink-mute); font-size:.9rem; margin-bottom:1.5rem; }
  .pay-options{ display:flex; flex-direction:column; gap:.75rem; }
  .btn{ display:block; border:none; border-radius:999px; padding:.85rem 1.5rem; font-weight:700; font-size:1rem; cursor:pointer; font-family:inherit; width:100%; }
  .btn--stripe{ background:var(--ink); color:#fff; }
  .btn--stripe:hover{ background:var(--ink-2); }
  .btn--paypal{ background:#FFC439; color:#003087; }
  .btn--paypal:hover{ background:#ffb400; }
  .form-error{ color:var(--danger); font-weight:600; margin-top:1rem; }
</style>
</head>
<body>
<main>
  <div class="card">
    <h1>Rechnung <?= htmlspecialchars($invoice['invoice_number']) ?></h1>
    <p class="meta"><?= htmlspecialchars($invoice['client_name']) ?></p>
    <p class="amount"><?= htmlspecialchars(Buchhaltung::formatEuro((int) $invoice['amount_cents'])) ?></p>
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
    </div>
  </div>
</main>
</body>
</html>
