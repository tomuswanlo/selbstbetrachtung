<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');
session_start();

require __DIR__ . '/lib/Booking.php';
require __DIR__ . '/lib/Buchhaltung.php';
require __DIR__ . '/lib/Pakete.php';
require __DIR__ . '/lib/Notifications.php';

$configFile = __DIR__ . '/termin_admin_config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Admin-Konfiguration fehlt (termin_admin_config.php). Siehe smtp_config.php als Vorbild.');
}
require $configFile;

$pdo = Buchhaltung::db();
$bookingPdo = Booking::db();

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function checkCsrf(): bool
{
    return isset($_POST['csrf']) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}

function redirectBack(string $hash = ''): void
{
    header('Location: buchhaltung-admin.php' . $hash);
    exit;
}

function invoiceStatusBadge(string $status): string
{
    $map = [
        'offen' => ['Offen', ''],
        'bezahlt' => ['Bezahlt', 'badge--paid'],
        'storniert' => ['Storniert', 'badge--cancelled'],
    ];
    [$label, $class] = $map[$status] ?? [$status, ''];
    return '<span class="badge ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

/** Rendert die eigenständige Druckansicht einer Rechnung (kein Layout der übrigen Seite) und beendet das Skript. */
function renderInvoicePrint(array $inv, array $settings): void
{
    $issued = (new DateTimeImmutable($inv['issued_at']))->format('d.m.Y');
    $due = $inv['due_date'] ? (new DateTimeImmutable((string) $inv['due_date']))->format('d.m.Y') : null;
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $overdue = $inv['status'] === 'offen' && $inv['due_date'] && $inv['due_date'] < $today;
    $verwendungszweck = $inv['invoice_number'] . ' · ' . $inv['client_name'];
    $hasBank = trim((string) $settings['sender_bank_iban']) !== '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $baseUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'selbstbetrachtung-online.de');
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Rechnung <?= htmlspecialchars($inv['invoice_number']) ?> – Selbstbetrachtung</title>
<style>
  :root{
    --cr:#F4EFE7; --cw:#fff; --pt:#2E3439; --pt2:#23282C;
    --sd:#D6A26A; --sl:#7E909A;
    --ik:#2E3439; --ik2:#545C62; --ik3:#7C868C;
    --ln:rgba(46,52,57,.12); --ln2:rgba(46,52,57,.07);
    --font:"Mulish",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    --font-head:"Lora","Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{ font-family:var(--font); background:#e8e2d8; color:var(--ik); }
  .toolbar{ padding:1rem 1.5rem; text-align:right; }
  .btn{ border:none; border-radius:999px; padding:.55rem 1.3rem; font-weight:700; cursor:pointer; font-family:inherit; background:var(--sd); color:#fff; font-size:.9rem; }
  .btn:hover{ background:#B3813F; }
  .invoice{ background:var(--cw); border-radius:14px; padding:26px 30px; box-shadow:0 10px 34px -18px rgba(46,52,57,.3); max-width:680px; margin:0 auto 3rem; }
  .inv-header{ display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid var(--ln); }
  .brand-name{ font-family:var(--font-head); font-size:17px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--pt); }
  .brand-tag{ font-size:11px; color:var(--ik3); margin-top:3px; }
  .inv-meta{ text-align:right; }
  .inv-label{ font-size:10px; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--sd); margin-bottom:2px; }
  .inv-num{ font-size:19px; font-weight:700; color:var(--pt); font-family:var(--font-head); }
  .inv-date{ font-size:11px; color:var(--ik3); margin-top:4px; line-height:1.6; }
  .overdue{ color:#B84A3C; font-weight:700; }
  .storniert{ color:#B84A3C; font-weight:700; margin-bottom:10px; }
  .addresses{ display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:16px; }
  .addr-label{ font-size:10px; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--sl); margin-bottom:5px; }
  .addr-name{ font-size:13px; font-weight:600; color:var(--pt); margin-bottom:2px; }
  .addr-text{ font-size:11px; color:var(--ik2); line-height:1.6; }
  .addr-extra{ font-size:11px; color:var(--ik3); margin-top:5px; line-height:1.6; }
  .table-wrap{ border-radius:8px; overflow:hidden; border:1px solid var(--ln); margin-bottom:12px; }
  table{ width:100%; border-collapse:collapse; }
  thead tr{ background:var(--pt); }
  thead th{ padding:8px 10px; text-align:left; font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:rgba(255,255,255,.65); }
  thead th.r{ text-align:right; }
  tbody tr{ border-bottom:1px solid var(--ln2); }
  tbody tr:last-child{ border-bottom:none; }
  tbody td{ padding:8px 10px; font-size:11px; color:var(--ik2); vertical-align:top; }
  td.r{ text-align:right; } td.c{ text-align:center; }
  .svc-name{ font-size:12px; font-weight:600; color:var(--pt); }
  .svc-desc{ font-size:10px; color:var(--ik3); margin-top:1px; }
  .td-amt{ font-weight:600; color:var(--pt); text-align:right; white-space:nowrap; }
  .totals{ padding-top:8px; border-top:2px solid var(--sd); margin-top:2px; }
  .total-row.grand{ display:flex; justify-content:space-between; font-size:14px; font-weight:700; color:var(--pt); padding-top:4px; }
  .grand-amt{ color:var(--sd); font-size:17px; white-space:nowrap; }
  .payment-section{ margin-top:14px; padding-top:12px; border-top:1px solid var(--ln); }
  .pay-label{ font-size:10px; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--sl); margin-bottom:8px; }
  .pay-grid{ display:grid; grid-template-columns:1fr 1fr; gap:6px 18px; }
  .pi-lbl{ font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--ik3); }
  .pi-val{ font-size:12px; font-weight:500; color:var(--pt); }
  .inv-note{ background:var(--cr); border:1px solid var(--ln); border-radius:7px; padding:11px 15px; margin-top:14px; font-size:11px; color:var(--ik2); line-height:1.6; }
  .inv-footer{ text-align:center; margin-top:14px; padding-top:12px; border-top:1px solid var(--ln); }
  .footer-brand{ font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--pt); margin-bottom:4px; }
  .footer-sub{ font-size:10px; color:var(--ik3); }
  .acc-line{ width:26px; height:2px; background:var(--sd); border-radius:2px; margin:6px auto; }
  @media print{
    body{ background:#fff; }
    .toolbar{ display:none; }
    .invoice{ box-shadow:none; border-radius:0; padding:14mm 16mm; max-width:none; margin:0; }
    @page{ margin:0; size:A4; }
  }
</style>
</head>
<body>
<div class="toolbar"><button class="btn" onclick="window.print()">Drucken / Als PDF speichern</button></div>
<div class="invoice">
  <div class="inv-header">
    <div>
      <div class="brand-name">Selbstbetrachtung</div>
      <div class="brand-tag"><?= htmlspecialchars($settings['sender_tagline'] ?? '') ?></div>
    </div>
    <div class="inv-meta">
      <div class="inv-label">Rechnung</div>
      <div class="inv-num"><?= htmlspecialchars($inv['invoice_number']) ?></div>
      <div class="inv-date">
        Ausgestellt: <?= htmlspecialchars($issued) ?><br>
        <?php if ($due): ?>Fällig: <span class="<?= $overdue ? 'overdue' : '' ?>"><?= htmlspecialchars($due) ?><?= $overdue ? ' (überfällig)' : '' ?></span><?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($inv['status'] === 'storniert'): ?><p class="storniert">STORNIERT</p><?php endif; ?>

  <div class="addresses">
    <div>
      <div class="addr-label">Von</div>
      <div class="addr-name"><?= htmlspecialchars($settings['sender_name']) ?></div>
      <div class="addr-text">Selbstbetrachtung<br><?= nl2br(htmlspecialchars($settings['sender_address'])) ?><br>Deutschland</div>
      <div class="addr-extra">Steuernummer: <?= htmlspecialchars($settings['sender_taxid']) ?></div>
    </div>
    <div>
      <div class="addr-label">An</div>
      <div class="addr-name"><?= htmlspecialchars($inv['client_name']) ?></div>
      <?php if ($inv['client_address']): ?><div class="addr-text"><?= nl2br(htmlspecialchars((string) $inv['client_address'])) ?></div><?php endif; ?>
      <?php if ($inv['client_email']): ?><div class="addr-extra"><?= htmlspecialchars((string) $inv['client_email']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr>
        <th style="width:40%">Leistung</th><th>Datum</th><th class="r" style="text-align:center">Menge</th><th class="r">Einzelpreis</th><th class="r">Betrag</th>
      </tr></thead>
      <tbody>
      <?php foreach ($inv['items'] as $item): $lineTotal = (int) round($item['quantity'] * $item['unit_price_cents']); ?>
        <tr>
          <td>
            <div class="svc-name"><?= htmlspecialchars($item['description']) ?></div>
            <?php if ($item['detail']): ?><div class="svc-desc"><?= htmlspecialchars($item['detail']) ?></div><?php endif; ?>
          </td>
          <td style="white-space:nowrap"><?= $item['item_date'] ? htmlspecialchars((new DateTimeImmutable($item['item_date']))->format('d.m.Y')) : '–' ?></td>
          <td class="c"><?= htmlspecialchars(rtrim(rtrim(number_format((float) $item['quantity'], 2, ',', ''), '0'), ',')) ?></td>
          <td class="r"><?= htmlspecialchars(Buchhaltung::formatEuro((int) $item['unit_price_cents'])) ?></td>
          <td class="td-amt"><?= htmlspecialchars(Buchhaltung::formatEuro($lineTotal)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="totals">
    <div class="total-row grand"><span>Gesamtbetrag (netto)</span><span class="grand-amt"><?= htmlspecialchars(Buchhaltung::formatEuro((int) $inv['amount_cents'])) ?></span></div>
  </div>

  <?php if (!empty($inv['online_payment_enabled']) && $inv['status'] === 'offen' && !empty($inv['payment_token'])): ?>
  <div class="payment-section">
    <div class="pay-label">Online bezahlen</div>
    <p style="font-size:12px; color:var(--ik2); line-height:1.6;">Diese Rechnung kann bequem online per Kreditkarte, SEPA-Lastschrift oder PayPal beglichen werden:<br>
    <a href="<?= htmlspecialchars($baseUrl . '/rechnung-bezahlen.php?token=' . urlencode($inv['payment_token'])) ?>" style="color:var(--sd); font-weight:600;"><?= htmlspecialchars($baseUrl . '/rechnung-bezahlen.php?token=' . urlencode($inv['payment_token'])) ?></a></p>
  </div>
  <?php endif; ?>

  <?php if ($hasBank): ?>
  <div class="payment-section">
    <div class="pay-label">Bankverbindung</div>
    <div class="pay-grid">
      <div><div class="pi-lbl">Kontoinhaber</div><div class="pi-val"><?= htmlspecialchars($settings['sender_bank_inhaber']) ?></div></div>
      <div><div class="pi-lbl">Bank</div><div class="pi-val"><?= htmlspecialchars($settings['sender_bank_name']) ?></div></div>
      <div><div class="pi-lbl">IBAN</div><div class="pi-val"><?= htmlspecialchars($settings['sender_bank_iban']) ?></div></div>
      <div><div class="pi-lbl">BIC</div><div class="pi-val"><?= htmlspecialchars($settings['sender_bank_bic']) ?></div></div>
      <div style="grid-column:span 2"><div class="pi-lbl">Verwendungszweck</div><div class="pi-val"><?= htmlspecialchars($verwendungszweck) ?></div></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="inv-note"><strong>Hinweis:</strong> <?= htmlspecialchars($settings['kleinunternehmer_hinweis']) ?> Diese Rechnung ist maschinell erstellt und ohne Unterschrift gültig.</div>
  <?php if ($inv['notes']): ?><div class="inv-note"><?= nl2br(htmlspecialchars((string) $inv['notes'])) ?></div><?php endif; ?>
  <?php if (trim((string) $settings['payment_terms_note']) !== ''): ?><p style="font-size:11px;color:var(--ik3);margin-top:10px"><?= htmlspecialchars($settings['payment_terms_note']) ?></p><?php endif; ?>

  <div class="inv-footer">
    <div class="footer-brand">Selbstbetrachtung</div>
    <div class="acc-line"></div>
    <div class="footer-sub">Vielen Dank für Ihr Vertrauen!</div>
  </div>
</div>
</body>
</html>
<?php
}

$isLoggedIn = !empty($_SESSION['termin_admin']);
$loginError = null;

// --- Login/Logout (gemeinsame Session mit der Terminverwaltung – ein Passwort für beide) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'login') {
    $submitted = (string) ($_POST['password'] ?? '');
    if (hash_equals(TERMIN_ADMIN_PASSWORD, $submitted)) {
        session_regenerate_id(true);
        $_SESSION['termin_admin'] = true;
        redirectBack();
    } else {
        usleep(500000); // Brute-Force ein wenig ausbremsen
        $loginError = 'Falsches Passwort.';
        $isLoggedIn = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'logout') {
    session_destroy();
    header('Location: buchhaltung-admin.php');
    exit;
}

// --- Reine Lese-Aktion mit eigenem Response-Typ (kein HTML-Seitenlayout) ---
if ($isLoggedIn && isset($_GET['print_invoice'])) {
    $invoice = Buchhaltung::findInvoice($pdo, (int) $_GET['print_invoice']);
    if (!$invoice) {
        http_response_code(404);
        exit('Rechnung nicht gefunden.');
    }
    renderInvoicePrint($invoice, Buchhaltung::allSettings($pdo));
    exit;
}

if ($isLoggedIn && isset($_GET['export_csv'])) {
    $exportYear = (int) $_GET['export_csv'];
    $rows = Buchhaltung::exportRows($pdo, $exportYear);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="einnahmen-' . $exportYear . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM, damit Excel Umlaute korrekt anzeigt
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Datum (Zahlungseingang)', 'Rechnungsnummer', 'Klient*in/Firma', 'Betrag (EUR)'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [
            (new DateTimeImmutable($r['date']))->format('d.m.Y'),
            $r['invoice_number'],
            $r['client_name'],
            number_format($r['amount_cents'] / 100, 2, ',', ''),
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// --- Geschützte Aktionen ---
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && checkCsrf()) {
    $do = $_POST['do'] ?? '';

    if ($do === 'save_settings') {
        foreach ([
            'sender_name', 'sender_tagline', 'sender_address', 'sender_taxid',
            'sender_bank_inhaber', 'sender_bank_name', 'sender_bank_iban', 'sender_bank_bic',
            'kleinunternehmer_hinweis', 'payment_terms_note',
        ] as $field) {
            Buchhaltung::setSetting($pdo, $field, trim((string) ($_POST[$field] ?? '')));
        }
        foreach (['price_erstgespraech_cents', 'price_folgetermin_cents'] as $priceField) {
            $raw = trim((string) ($_POST[$priceField] ?? ''));
            if ($raw === '') {
                Buchhaltung::setSetting($pdo, $priceField, '');
            } else {
                $cents = Buchhaltung::parseAmountToCents($raw);
                if ($cents !== null) {
                    Buchhaltung::setSetting($pdo, $priceField, (string) $cents);
                }
            }
        }
        $_SESSION['flash_success'] = 'Einstellungen gespeichert.';
        redirectBack('#einstellungen');
    }

    if ($do === 'create_invoice') {
        $clientName = trim((string) ($_POST['client_name'] ?? ''));
        $clientAddress = trim((string) ($_POST['client_address'] ?? ''));
        $clientEmail = trim((string) ($_POST['client_email'] ?? ''));
        $issuedAt = trim((string) ($_POST['issued_at'] ?? ''));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedAt)) {
            $issuedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        }
        if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $dueDate = '';
        }

        $items = [];
        foreach ((array) ($_POST['items'] ?? []) as $raw) {
            $desc = trim((string) ($raw['description'] ?? ''));
            $cents = Buchhaltung::parseAmountToCents((string) ($raw['unit_price'] ?? ''));
            $qty = (float) str_replace(',', '.', (string) ($raw['quantity'] ?? '1'));
            if ($desc === '' || $cents === null || $qty <= 0) {
                continue;
            }
            $itemDate = trim((string) ($raw['item_date'] ?? ''));
            $items[] = [
                'description' => $desc,
                'detail' => trim((string) ($raw['detail'] ?? '')) ?: null,
                'item_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $itemDate) ? $itemDate : null,
                'quantity' => $qty,
                'unit_price_cents' => $cents,
            ];
        }

        if ($clientName === '' || !$items) {
            $_SESSION['flash_error'] = 'Bitte Klient*in/Firma und mindestens eine gültige Position (Bezeichnung, Menge, Preis) angeben.';
            redirectBack('#neue-rechnung');
        }

        $result = Buchhaltung::createInvoice($pdo, [
            'client_name' => $clientName,
            'client_address' => $clientAddress !== '' ? $clientAddress : null,
            'client_email' => $clientEmail !== '' ? $clientEmail : null,
            'issued_at' => $issuedAt,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'notes' => $notes !== '' ? $notes : null,
        ], $items);

        if ($result['ok']) {
            $_SESSION['flash_success'] = 'Rechnung ' . $result['invoice_number'] . ' angelegt.';
        } else {
            $_SESSION['flash_error'] = $result['error'];
        }
        redirectBack('#rechnungen');
    }

    if ($do === 'update_invoice_status') {
        Buchhaltung::updateInvoiceStatus($pdo, (int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''));
        redirectBack('#rechnungen');
    }

    if ($do === 'toggle_online_payment') {
        Buchhaltung::setOnlinePaymentEnabled($pdo, (int) ($_POST['id'] ?? 0), ($_POST['enabled'] ?? '') === '1');
        redirectBack('#rechnungen');
    }

    if ($do === 'log_package_usage') {
        $packageId = (int) ($_POST['package_id'] ?? 0);
        $usedHours = (float) str_replace(',', '.', (string) ($_POST['used_hours'] ?? '1'));
        $note = trim((string) ($_POST['note'] ?? ''));
        $itemDate = trim((string) ($_POST['item_date'] ?? ''));
        $noteWithDate = $itemDate !== '' ? $itemDate . ($note !== '' ? ' – ' . $note : '') : $note;
        if ($usedHours > 0) {
            $result = Pakete::logUsage($pdo, $packageId, $usedHours, null, $noteWithDate !== '' ? $noteWithDate : null);
            if (!$result['ok']) {
                $_SESSION['flash_error'] = $result['error'];
            }
        }
        redirectBack('#pakete');
    }

    if ($do === 'delete_package_usage') {
        Pakete::deleteUsageLogEntry($pdo, (int) ($_POST['log_id'] ?? 0));
        redirectBack('#pakete');
    }

    if ($do === 'resend_package_mail') {
        $packageId = (int) ($_POST['package_id'] ?? 0);
        $pkg = Pakete::findById($pdo, $packageId);
        if ($pkg && $pkg['status'] === 'bezahlt') {
            sendPackagePaidConfirmation($packageId);
            $_SESSION['flash_success'] = 'Paket-Code wurde erneut an ' . $pkg['client_email'] . ' verschickt.';
        } else {
            $_SESSION['flash_error'] = 'Paket nicht gefunden oder noch nicht bezahlt.';
        }
        redirectBack('#pakete');
    }
}

$flashError = $_SESSION['flash_error'] ?? null;
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$currentYear = (int) substr($today, 0, 4);

$settings = $isLoggedIn ? Buchhaltung::allSettings($pdo) : [];
$invoices = $isLoggedIn ? Buchhaltung::listInvoices($pdo) : [];
$invoicableBookings = $isLoggedIn ? Booking::listForInvoicing($bookingPdo) : [];
$availableYears = $isLoggedIn ? Buchhaltung::availableYears($pdo) : [];
$packages = $isLoggedIn ? Pakete::listAll($pdo) : [];
$csrf = $isLoggedIn ? csrfToken() : '';

$viewYear = $currentYear;
if ($isLoggedIn && isset($_GET['year'])) {
    $y = (int) $_GET['year'];
    if ($y >= 2000 && $y <= $currentYear + 1) {
        $viewYear = $y;
    }
}
$summary = $isLoggedIn ? Buchhaltung::yearSummary($pdo, $viewYear) : null;

// Vorbelegung der "Neue Rechnung"-Positionen, wenn von einem oder mehreren Terminen
// aus verlinkt wurde (siehe Booking::listForInvoicing()) – die Daten werden beim
// Speichern kopiert, nicht live verknüpft, damit die Rechnung unabhängig vom
// (später ggf. gelöschten) Termin bleibt. Akzeptiert sowohl ?from_booking=5 (ein
// einzelner Termin, Link aus termin-admin.php) als auch ?from_booking[]=1&...
// (Mehrfachauswahl aus der Tabelle weiter unten).
$rawFromBooking = $_GET['from_booking'] ?? [];
$fromBookingIds = array_map('intval', is_array($rawFromBooking) ? $rawFromBooking : [$rawFromBooking]);
$fromBookings = [];
foreach ($invoicableBookings as $b) {
    if (in_array((int) $b['id'], $fromBookingIds, true)) {
        $fromBookings[] = $b;
    }
}

$initialItems = [];
foreach ($fromBookings as $b) {
    $typeLabel = Booking::TYPES[$b['type']]['label'] ?? $b['type'];
    $priceKey = $b['type'] === 'erstgespraech' ? 'price_erstgespraech_cents' : ($b['type'] === 'folgetermin' ? 'price_folgetermin_cents' : null);
    $price = ($priceKey && ($settings[$priceKey] ?? '') !== '') ? Buchhaltung::centsToInputValue((int) $settings[$priceKey]) : '';
    $initialItems[] = [
        'description' => $typeLabel,
        'detail' => '',
        'item_date' => $b['date'],
        'quantity' => '1',
        'unit_price' => $price,
    ];
}
if (!$initialItems) {
    $initialItems[] = ['description' => '', 'detail' => '', 'item_date' => $today, 'quantity' => '1', 'unit_price' => ''];
}
$defaultDueDate = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');

$monthNames = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'];
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Buchhaltung – Selbstbetrachtung</title>
<meta name="robots" content="noindex, nofollow">
<style>
  :root{
    --cream:#F4EFE7; --cream-light:#FBF8F2; --cream-dark:#EDE5D8;
    --ink:#2E3439; --ink-mute:#626B71; --gold:#D6A26A; --gold-dark:#B3813F; --danger:#B84A3C; --green:#8FD0A8;
    --font-head:"Lora","Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;
    --font-body:"Mulish",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  }
  /* Kompaktes Layout: diese Seite wird ausschließlich am PC oder auf dem iPad im
     Querformat verwaltet, nie auf dem Handy – deshalb bewusst auf Informations-
     dichte statt auf kleine Bildschirme optimiert. */
  *{box-sizing:border-box;}
  body{ margin:0; background:var(--cream); color:var(--ink); font-family:var(--font-body); line-height:1.4; font-size:.92rem; }
  header{ display:flex; justify-content:space-between; align-items:center; padding:.6rem 1.25rem; background:var(--cream-light); border-bottom:1px solid var(--cream-dark); }
  header a{ color:var(--ink-mute); text-decoration:none; font-size:.85rem; }
  header .nav{ display:flex; gap:1rem; align-items:center; }
  main{ max-width:1180px; margin:0 auto; padding:1.1rem 1.25rem 2.5rem; }
  h1{ font-family:var(--font-head); font-weight:600; font-size:1.3rem; margin:0 0 .3rem; }
  h2{ font-family:var(--font-head); font-weight:600; font-size:1rem; margin:0 0 .6rem; }
  section.card{ background:var(--cream-light); border:1px solid var(--cream-dark); border-radius:12px; padding:.9rem 1.1rem; margin-bottom:.9rem; }
  table{ width:100%; border-collapse:collapse; margin-bottom:.6rem; font-size:.85rem; }
  th, td{ text-align:left; padding:.3rem .4rem; border-bottom:1px solid var(--cream-dark); vertical-align:top; }
  form.inline{ display:flex; gap:.5rem; flex-wrap:wrap; align-items:end; }
  form.inline label{ display:flex; flex-direction:column; font-size:.76rem; color:var(--ink-mute); gap:.2rem; }
  input, select, textarea{ font-family:inherit; font-size:.88rem; padding:.32rem .5rem; border:1.5px solid var(--cream-dark); border-radius:7px; background:#fff; color:var(--ink); }
  textarea{ min-height:2.6rem; }
  .btn{ border:none; border-radius:999px; padding:.38rem .95rem; font-weight:700; cursor:pointer; font-family:inherit; background:var(--gold); color:#fff; font-size:.82rem; text-decoration:none; display:inline-block; }
  .btn:hover{ background:var(--gold-dark); }
  .btn--danger{ background:var(--danger); }
  .btn--danger:hover{ opacity:.85; }
  .btn--ghost{ background:none; color:var(--ink-mute); padding:.38rem .6rem; }
  .login-card{ max-width:340px; margin:3rem auto; }
  .login-card input[type=password]{ width:100%; margin-bottom:1rem; }
  .error{ color:var(--danger); font-weight:600; }
  .success{ color:#3f7d52; font-weight:600; }
  .muted{ color:var(--ink-mute); font-size:.8rem; }
  .badge{ display:inline-block; background:var(--cream-dark); border-radius:999px; padding:.05rem .55rem; font-size:.72rem; }
  .badge--paid{ background:var(--green); }
  .badge--cancelled{ background:var(--danger); color:#fff; }
  .badge--overdue{ background:var(--danger); color:#fff; }
  .in-invoice{ background:rgba(214,162,106,.25); }
  .quicknav{ margin:0 0 .9rem; font-size:.85rem; }
  .quicknav a{ color:var(--ink-mute); }
  .summary-cards{ display:flex; gap:.7rem; flex-wrap:wrap; margin-bottom:.8rem; }
  .summary-tile{ background:#fff; border:1px solid var(--cream-dark); border-radius:9px; padding:.55rem .85rem; min-width:130px; }
  .summary-tile .num{ font-family:var(--font-head); font-size:1.1rem; font-weight:600; }
  .years a{ margin-right:.5rem; }
  .years a.active{ color:var(--ink); font-weight:700; text-decoration:underline; }
  .actions{ display:flex; gap:.35rem; flex-wrap:wrap; }
  .pos-list{ display:flex; flex-direction:column; gap:.45rem; margin-bottom:.5rem; }
  .pos-item{ background:#fff; border:1px solid var(--cream-dark); border-radius:9px; padding:.55rem; position:relative; }
  .pos-item input{ width:100%; margin-top:.15rem; }
  .pos-label{ font-size:.68rem; color:var(--ink-mute); font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
  .pos-actions{ position:absolute; top:.4rem; right:.4rem; display:flex; gap:.15rem; }
  .pos-btn{ background:none; border:none; cursor:pointer; color:var(--ink-mute); font-size:.85rem; line-height:1; padding:.1rem .3rem; border-radius:4px; font-weight:700; }
  .pos-btn:hover{ background:var(--cream-dark); }
  .pos-btn.del:hover{ color:#fff; background:var(--danger); }
  .pos-row{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:.35rem; margin-top:.35rem; }
  .btn-add{ background:var(--cream-dark); color:var(--ink-mute); border:1px dashed var(--ink-mute); border-radius:7px; padding:.4rem; width:100%; cursor:pointer; font-family:inherit; }
  .btn-add:hover{ background:#e2d9c8; }
  .progress{ background:var(--cream-dark); border-radius:999px; height:.5rem; overflow:hidden; width:100%; max-width:160px; }
  .progress > span{ display:block; height:100%; background:var(--gold); }
  .pkg-hours{ font-size:.78rem; color:var(--ink-mute); white-space:nowrap; }
</style>
</head>
<body>
<header>
  <strong>Buchhaltung</strong>
  <div class="nav">
  <?php if ($isLoggedIn): ?>
    <a href="termin-admin.php">Terminverwaltung</a>
    <form method="post" style="margin:0"><input type="hidden" name="do" value="logout"><button type="submit" class="btn btn--ghost">Abmelden</button></form>
  <?php else: ?>
    <a href="/">Zur Website</a>
  <?php endif; ?>
  </div>
</header>
<main>
<?php if (!$isLoggedIn): ?>

  <div class="card login-card">
    <h1 style="font-size:1.3rem">Anmelden</h1>
    <?php if ($loginError): ?><p class="error"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="do" value="login">
      <input type="password" name="password" placeholder="Admin-Passwort" required autofocus>
      <button type="submit" class="btn">Anmelden</button>
    </form>
    <p class="muted">Dasselbe Passwort wie die Terminverwaltung.</p>
  </div>

<?php else: ?>

  <h1>Buchhaltung</h1>
  <p class="muted">Rechnungsstellung für die Praxis. Kleinunternehmer nach § 19 UStG. Ausgaben/Belege werden separat als Excel-Aufstellung geführt, nicht hier.</p>
  <?php if ($flashError): ?><p class="error"><?= htmlspecialchars($flashError) ?></p><?php endif; ?>
  <?php if ($flashSuccess): ?><p class="success"><?= htmlspecialchars($flashSuccess) ?></p><?php endif; ?>

  <p class="quicknav">
    <a href="#uebersicht">Übersicht</a> ·
    <a href="#rechnungen">Rechnungen</a> ·
    <a href="#pakete">Pakete</a> ·
    <a href="#export">Export</a> ·
    <a href="#einstellungen">Einstellungen</a>
  </p>

  <section class="card" id="uebersicht">
    <h2>Übersicht <?= (int) $viewYear ?></h2>
    <p class="years">
      <?php foreach ($availableYears as $y): ?>
        <a href="?year=<?= (int) $y ?>#uebersicht" class="<?= $y === $viewYear ? 'active' : '' ?>"><?= (int) $y ?></a>
      <?php endforeach; ?>
    </p>

    <div class="summary-cards">
      <div class="summary-tile"><div class="muted">Einnahmen (bezahlt)</div><div class="num"><?= htmlspecialchars(Buchhaltung::formatEuro($summary['income_total'])) ?></div></div>
      <?php if ($summary['open_cents'] > 0): ?>
      <div class="summary-tile"><div class="muted">Offene Rechnungen (alle Jahre)</div><div class="num"><?= htmlspecialchars(Buchhaltung::formatEuro($summary['open_cents'])) ?></div></div>
      <?php endif; ?>
      <?php if ($summary['overdue_cents'] > 0): ?>
      <div class="summary-tile" style="border-color:var(--danger)"><div class="muted">Davon überfällig</div><div class="num" style="color:var(--danger)"><?= htmlspecialchars(Buchhaltung::formatEuro($summary['overdue_cents'])) ?></div></div>
      <?php endif; ?>
    </div>

    <table>
      <thead><tr><th>Monat</th><th>Einnahmen</th></tr></thead>
      <tbody>
      <?php foreach ($summary['months'] as $m => $cents): ?>
        <tr><td><?= htmlspecialchars($monthNames[$m]) ?></td><td><?= htmlspecialchars(Buchhaltung::formatEuro($cents)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted">Einnahmen zählen nach Zahlungseingang (Zuflussprinzip der Einnahmen-Überschuss-Rechnung), nicht nach Rechnungsdatum. Ausgaben stehen in der separaten Excel-Aufstellung.</p>
  </section>

  <section class="card" id="rechnungen">
    <h2>Rechnungen</h2>
    <table>
      <thead><tr><th>Nr.</th><th>Datum</th><th>Fällig</th><th>Klient*in/Firma</th><th>Betrag</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($invoices as $inv):
        $isOverdue = $inv['status'] === 'offen' && $inv['due_date'] && $inv['due_date'] < $today;
      ?>
        <tr>
          <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
          <td><?= htmlspecialchars((new DateTimeImmutable($inv['issued_at']))->format('d.m.Y')) ?></td>
          <td><?= $inv['due_date'] ? htmlspecialchars((new DateTimeImmutable($inv['due_date']))->format('d.m.Y')) : '<span class="muted">–</span>' ?></td>
          <td><?= htmlspecialchars($inv['client_name']) ?></td>
          <td><?= htmlspecialchars(Buchhaltung::formatEuro((int) $inv['amount_cents'])) ?></td>
          <td>
            <?= invoiceStatusBadge($inv['status']) ?> <?php if ($isOverdue): ?><span class="badge badge--overdue">Überfällig</span><?php endif; ?>
            <?php if (!empty($inv['online_payment_enabled']) && !empty($inv['payment_token'])): ?>
              <br><a class="muted" href="/rechnung-bezahlen.php?token=<?= htmlspecialchars($inv['payment_token']) ?>" target="_blank" rel="noopener">Zahl-Link ↗</a>
            <?php endif; ?>
          </td>
          <td>
            <div class="actions">
              <a class="btn btn--ghost" href="?print_invoice=<?= (int) $inv['id'] ?>" target="_blank" rel="noopener">Drucken</a>
              <?php if ($inv['status'] === 'offen'): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="do" value="toggle_online_payment">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <input type="hidden" name="enabled" value="<?= !empty($inv['online_payment_enabled']) ? '0' : '1' ?>">
                  <button type="submit" class="btn btn--ghost"><?= !empty($inv['online_payment_enabled']) ? 'Online-Zahlung sperren' : 'Für Online-Zahlung freigeben' ?></button>
                </form>
              <?php endif; ?>
              <?php if ($inv['status'] === 'offen'): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="do" value="update_invoice_status">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <input type="hidden" name="status" value="bezahlt">
                  <button type="submit" class="btn">Als bezahlt markieren</button>
                </form>
              <?php elseif ($inv['status'] === 'bezahlt'): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="do" value="update_invoice_status">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <input type="hidden" name="status" value="offen">
                  <button type="submit" class="btn btn--ghost">Als offen markieren</button>
                </form>
              <?php endif; ?>
              <?php if ($inv['status'] !== 'storniert'): ?>
                <form method="post" style="margin:0" onsubmit="return confirm('Diese Rechnung wirklich stornieren? Sie bleibt zur Nachvollziehbarkeit erhalten, nur der Status ändert sich.');">
                  <input type="hidden" name="do" value="update_invoice_status">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <input type="hidden" name="status" value="storniert">
                  <button type="submit" class="btn btn--danger">Stornieren</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$invoices): ?><tr><td colspan="7" class="muted">Noch keine Rechnungen.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <?php if ($invoicableBookings): ?>
    <h2 style="font-size:1rem; margin-top:1.5rem;">Aus Terminen übernehmen</h2>
    <p class="muted">Kommende und kürzlich vergangene Termine (letzte 60 Tage). Mehrfachauswahl möglich, z. B. um mehrere Sitzungen eines Zeitraums in einer Sammelrechnung zu bündeln.</p>
    <form method="get" action="buchhaltung-admin.php#neue-rechnung">
      <table>
        <thead><tr><th></th><th>Datum</th><th>Zeit</th><th>Art</th><th>Klient*in</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($invoicableBookings, 0, 20) as $b): ?>
          <?php $isPending = in_array((int) $b['id'], $fromBookingIds, true); ?>
          <tr class="<?= $isPending ? 'in-invoice' : '' ?>">
            <td><input type="checkbox" name="from_booking[]" value="<?= (int) $b['id'] ?>" <?= $isPending ? 'checked' : '' ?> onchange="this.closest('tr').classList.toggle('in-invoice', this.checked)"></td>
            <td><?= htmlspecialchars((new DateTimeImmutable($b['date']))->format('d.m.Y')) ?></td>
            <td><?= htmlspecialchars($b['start_time']) ?></td>
            <td><?= htmlspecialchars(Booking::TYPES[$b['type']]['label'] ?? $b['type']) ?></td>
            <td><?= htmlspecialchars($b['name']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="btn btn--ghost">Ausgewählte übernehmen</button>
    </form>
    <?php endif; ?>

    <h2 id="neue-rechnung" style="font-size:1rem; margin-top:1.5rem;">Neue Rechnung</h2>
    <?php if ($fromBookings): ?><p class="muted"><?= count($fromBookings) ?> Termin(e) übernommen – unten als Positionen vorausgefüllt, weitere Positionen können ergänzt werden.</p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="do" value="create_invoice">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:.8rem; margin-bottom:1rem;">
        <label>Klient*in / Firma
          <input type="text" name="client_name" value="<?= htmlspecialchars($fromBookings[0]['name'] ?? '') ?>" required>
        </label>
        <label>E-Mail (optional)
          <input type="email" name="client_email" value="<?= htmlspecialchars($fromBookings[0]['email'] ?? '') ?>">
        </label>
        <label style="grid-column:1/-1">Anschrift (optional – ab 250 € Rechnungsbetrag empfohlen)
          <textarea name="client_address" placeholder="Straße, PLZ Ort"></textarea>
        </label>
        <label>Rechnungsdatum
          <input type="date" name="issued_at" value="<?= htmlspecialchars($today) ?>" required>
        </label>
        <label>Fällig am
          <input type="date" name="due_date" value="<?= htmlspecialchars($defaultDueDate) ?>">
        </label>
      </div>

      <div class="pos-list" id="posList">
      <?php foreach ($initialItems as $i => $item): ?>
        <div class="pos-item">
          <div class="pos-actions">
            <button type="button" class="pos-btn" onclick="movePosItem(this,-1)" title="Nach oben">▲</button>
            <button type="button" class="pos-btn" onclick="movePosItem(this,1)" title="Nach unten">▼</button>
            <button type="button" class="pos-btn del" onclick="delPosItem(this)" title="Entfernen">×</button>
          </div>
          <div class="pos-label">Bezeichnung</div>
          <input type="text" name="items[<?= $i ?>][description]" value="<?= htmlspecialchars($item['description']) ?>" placeholder="z. B. Einzelsitzung – Psychologisches Coaching" required>
          <div class="pos-label" style="margin-top:.4rem">Details (optional)</div>
          <input type="text" name="items[<?= $i ?>][detail]" value="<?= htmlspecialchars($item['detail']) ?>" placeholder="z. B. 60 Min. Online-Beratung via Video">
          <div class="pos-row">
            <div><div class="pos-label">Datum</div><input type="date" name="items[<?= $i ?>][item_date]" value="<?= htmlspecialchars($item['item_date']) ?>"></div>
            <div><div class="pos-label">Menge</div><input type="text" inputmode="decimal" name="items[<?= $i ?>][quantity]" value="<?= htmlspecialchars($item['quantity']) ?>"></div>
            <div><div class="pos-label">Einzelpreis (€)</div><input type="text" inputmode="decimal" name="items[<?= $i ?>][unit_price]" value="<?= htmlspecialchars($item['unit_price']) ?>" placeholder="z. B. 70,00" required></div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <button type="button" class="btn-add" onclick="addPosItem()">+ Position hinzufügen</button>

      <label style="display:flex; flex-direction:column; font-size:.8rem; color:var(--ink-mute); gap:.25rem; margin-top:1rem;">Notizen (optional, nur intern)
        <textarea name="notes"></textarea>
      </label>
      <p><button type="submit" class="btn">Rechnung erstellen</button></p>
    </form>

    <template id="posTemplate">
      <div class="pos-item">
        <div class="pos-actions">
          <button type="button" class="pos-btn" onclick="movePosItem(this,-1)" title="Nach oben">▲</button>
          <button type="button" class="pos-btn" onclick="movePosItem(this,1)" title="Nach unten">▼</button>
          <button type="button" class="pos-btn del" onclick="delPosItem(this)" title="Entfernen">×</button>
        </div>
        <div class="pos-label">Bezeichnung</div>
        <input type="text" name="items[__I__][description]" placeholder="z. B. Einzelsitzung – Psychologisches Coaching" required>
        <div class="pos-label" style="margin-top:.4rem">Details (optional)</div>
        <input type="text" name="items[__I__][detail]" placeholder="z. B. 60 Min. Online-Beratung via Video">
        <div class="pos-row">
          <div><div class="pos-label">Datum</div><input type="date" name="items[__I__][item_date]"></div>
          <div><div class="pos-label">Menge</div><input type="text" inputmode="decimal" name="items[__I__][quantity]" value="1"></div>
          <div><div class="pos-label">Einzelpreis (€)</div><input type="text" inputmode="decimal" name="items[__I__][unit_price]" placeholder="z. B. 70,00" required></div>
        </div>
      </div>
    </template>
    <script>
      let posCounter = <?= count($initialItems) ?>;
      function addPosItem(){
        const tpl = document.getElementById('posTemplate').content.cloneNode(true);
        const idx = 'n' + (posCounter++);
        tpl.querySelectorAll('[name]').forEach(function(el){ el.name = el.name.replace('__I__', idx); });
        document.getElementById('posList').appendChild(tpl);
      }
      function movePosItem(btn, dir){
        const item = btn.closest('.pos-item');
        const sib = dir < 0 ? item.previousElementSibling : item.nextElementSibling;
        if (!sib) return;
        if (dir < 0) item.parentNode.insertBefore(item, sib);
        else item.parentNode.insertBefore(sib, item);
      }
      function delPosItem(btn){
        const list = document.getElementById('posList');
        if (list.children.length <= 1) { alert('Mindestens eine Position wird benötigt.'); return; }
        btn.closest('.pos-item').remove();
      }
    </script>
  </section>

  <section class="card" id="pakete">
    <h2>Pakete</h2>
    <p class="muted">5-Stunden-Pakete, online gekauft über <a href="/paket-kaufen.php" target="_blank" rel="noopener">paket-kaufen.php</a>. Bezahlte Pakete erzeugen automatisch eine Rechnung (siehe „Rechnungen“ oben). Restguthaben wird bei jeder Terminbuchung mit Paket-Code automatisch abgezogen; Sitzungen, die nicht über die Website gebucht wurden, können hier manuell verrechnet werden.</p>
    <table>
      <thead><tr><th>Käufer*in</th><th>Status</th><th>Gekauft am</th><th>Restguthaben</th><th>Code</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($packages as $pkg): ?>
        <tr>
          <td>
            <?= htmlspecialchars($pkg['client_name']) ?>
            <br><span class="muted"><?= htmlspecialchars($pkg['client_email']) ?></span>
          </td>
          <td><?= invoiceStatusBadge($pkg['status'] === 'bezahlt' ? 'bezahlt' : ($pkg['status'] === 'storniert' ? 'storniert' : 'offen')) ?></td>
          <td><?= htmlspecialchars($pkg['paid_at'] ? (new DateTimeImmutable($pkg['paid_at']))->format('d.m.Y') : (new DateTimeImmutable($pkg['created_at']))->format('d.m.Y')) ?></td>
          <td>
            <?php if ($pkg['status'] === 'bezahlt'): ?>
              <div class="progress"><span style="width:<?= (float) $pkg['hours_total'] > 0 ? min(100, ($pkg['hours_used'] / (float) $pkg['hours_total']) * 100) : 0 ?>%"></span></div>
              <span class="pkg-hours"><?= htmlspecialchars(rtrim(rtrim(number_format($pkg['hours_remaining'], 1, ',', ''), '0'), ',')) ?> von <?= (int) $pkg['hours_total'] ?> Std. übrig</span>
            <?php else: ?>
              <span class="muted">–</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($pkg['status'] === 'bezahlt'): ?>
              <code style="font-size:.72rem; word-break:break-all;"><?= htmlspecialchars((string) $pkg['purchase_token']) ?></code>
              <form method="post" style="margin:.25rem 0 0;">
                <input type="hidden" name="do" value="resend_package_mail">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="package_id" value="<?= (int) $pkg['id'] ?>">
                <button type="submit" class="btn btn--ghost" style="padding:.15rem .5rem; font-size:.72rem;">Mail erneut senden</button>
              </form>
            <?php else: ?>
              <span class="muted">–</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($pkg['status'] === 'bezahlt'): ?>
            <details>
              <summary class="muted" style="cursor:pointer;">Verbrauch (<?= (int) count(Pakete::usageLog($pdo, (int) $pkg['id'])) ?>)</summary>
              <table style="margin-top:.4rem;">
                <?php foreach (Pakete::usageLog($pdo, (int) $pkg['id']) as $log): ?>
                  <tr>
                    <td style="border:none; padding:.2rem 0;"><?= htmlspecialchars(rtrim(rtrim(number_format((float) $log['used_hours'], 1, ',', ''), '0'), ',')) ?> Std.</td>
                    <td style="border:none; padding:.2rem 0;"><?= htmlspecialchars((string) $log['note']) ?></td>
                    <td style="border:none; padding:.2rem 0;">
                      <form method="post" style="margin:0" onsubmit="return confirm('Diese Verbrauchsbuchung wirklich löschen (Stunde wird dem Paket wieder gutgeschrieben)?');">
                        <input type="hidden" name="do" value="delete_package_usage">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="log_id" value="<?= (int) $log['id'] ?>">
                        <button type="submit" class="pos-btn del" title="Löschen">×</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </table>
              <form method="post" class="inline" style="margin-top:.5rem;">
                <input type="hidden" name="do" value="log_package_usage">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="package_id" value="<?= (int) $pkg['id'] ?>">
                <label>Datum <input type="date" name="item_date" value="<?= htmlspecialchars($today) ?>"></label>
                <label>Stunden <input type="text" inputmode="decimal" name="used_hours" value="1" style="width:4rem;"></label>
                <label>Notiz <input type="text" name="note" placeholder="z. B. Sitzung vor Ort"></label>
                <button type="submit" class="btn btn--ghost">Verrechnen</button>
              </form>
            </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$packages): ?><tr><td colspan="6" class="muted">Noch keine Pakete gekauft.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="card" id="export">
    <h2>Export für den Steuerberater</h2>
    <p class="muted">CSV-Export der Einnahmen je Jahr (nach Zahlungseingang) – Arbeitsgrundlage, keine amtliche Anlage EÜR. Ausgaben stehen in der separaten Excel-Aufstellung.</p>
    <p>
      <?php foreach ($availableYears as $y): ?>
        <a class="btn btn--ghost" href="?export_csv=<?= (int) $y ?>">CSV <?= (int) $y ?></a>
      <?php endforeach; ?>
    </p>
  </section>

  <section class="card" id="einstellungen">
    <h2>Einstellungen</h2>
    <form method="post">
      <input type="hidden" name="do" value="save_settings">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:.8rem;">
        <label>Absendername (auf Rechnungen)
          <input type="text" name="sender_name" value="<?= htmlspecialchars($settings['sender_name']) ?>">
        </label>
        <label>Steuernummer
          <input type="text" name="sender_taxid" value="<?= htmlspecialchars($settings['sender_taxid']) ?>">
        </label>
        <label style="grid-column:1/-1">Untertitel (auf Rechnungen, unter dem Praxisnamen)
          <input type="text" name="sender_tagline" value="<?= htmlspecialchars($settings['sender_tagline'] ?? 'Psychologische Beratung & Coaching online') ?>">
        </label>
        <label style="grid-column:1/-1">Absenderanschrift
          <textarea name="sender_address"><?= htmlspecialchars($settings['sender_address']) ?></textarea>
        </label>
        <label>Kontoinhaber
          <input type="text" name="sender_bank_inhaber" value="<?= htmlspecialchars($settings['sender_bank_inhaber']) ?>">
        </label>
        <label>Bank
          <input type="text" name="sender_bank_name" value="<?= htmlspecialchars($settings['sender_bank_name']) ?>">
        </label>
        <label>IBAN
          <input type="text" name="sender_bank_iban" value="<?= htmlspecialchars($settings['sender_bank_iban']) ?>">
        </label>
        <label>BIC
          <input type="text" name="sender_bank_bic" value="<?= htmlspecialchars($settings['sender_bank_bic']) ?>">
        </label>
        <label style="grid-column:1/-1">Kleinunternehmer-Hinweis (§ 19 UStG)
          <input type="text" name="kleinunternehmer_hinweis" value="<?= htmlspecialchars($settings['kleinunternehmer_hinweis']) ?>">
        </label>
        <label style="grid-column:1/-1">Zahlungshinweis
          <input type="text" name="payment_terms_note" value="<?= htmlspecialchars($settings['payment_terms_note']) ?>">
        </label>
        <label>Preis Erstgespräch (€, <?= (int) Booking::TYPES['erstgespraech']['duration'] ?> Min.)
          <input type="text" inputmode="decimal" name="price_erstgespraech_cents" value="<?= $settings['price_erstgespraech_cents'] !== '' ? htmlspecialchars(Buchhaltung::centsToInputValue((int) $settings['price_erstgespraech_cents'])) : '' ?>" placeholder="noch nicht hinterlegt">
        </label>
        <label>Preis Folgetermin (€, <?= (int) Booking::TYPES['folgetermin']['duration'] ?> Min.)
          <input type="text" inputmode="decimal" name="price_folgetermin_cents" value="<?= $settings['price_folgetermin_cents'] !== '' ? htmlspecialchars(Buchhaltung::centsToInputValue((int) $settings['price_folgetermin_cents'])) : '' ?>" placeholder="noch nicht hinterlegt">
        </label>
      </div>
      <p class="muted">Bankverbindung nur ausgefüllt nötig, falls sie auf der Rechnung erscheinen soll (IBAN leer = Abschnitt wird ausgeblendet). Die Preise dienen nur der Vorbelegung neuer Positionen aus einem Termin.</p>
      <p><button type="submit" class="btn">Einstellungen speichern</button></p>
    </form>
  </section>

<?php endif; ?>
</main>
</body>
</html>
