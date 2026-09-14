<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');
session_start();

require __DIR__ . '/lib/Booking.php';
require __DIR__ . '/lib/Buchhaltung.php';

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

/** @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file */
function handleReceiptUpload(array $file): array
{
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
    $maxBytes = 8 * 1024 * 1024;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Beleg-Upload fehlgeschlagen.'];
    }
    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'Beleg ist zu groß (max. 8 MB).'];
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return ['ok' => false, 'error' => 'Nur JPG, PNG oder PDF sind als Beleg erlaubt.'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    if ($mime !== $allowed[$ext]) {
        return ['ok' => false, 'error' => 'Dateiinhalt passt nicht zur Dateiendung.'];
    }

    $receiptDir = __DIR__ . '/data/belege';
    if (!is_dir($receiptDir)) {
        mkdir($receiptDir, 0775, true);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $receiptDir . '/' . $filename)) {
        return ['ok' => false, 'error' => 'Beleg konnte nicht gespeichert werden.'];
    }
    @chmod($receiptDir . '/' . $filename, 0664);

    return ['ok' => true, 'filename' => $filename, 'original_name' => basename((string) $file['name'])];
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
    $amount = Buchhaltung::formatEuro((int) $inv['amount_cents']);
    $issued = (new DateTimeImmutable($inv['issued_at']))->format('d.m.Y');
    $sessionDate = $inv['session_date'] ? (new DateTimeImmutable((string) $inv['session_date']))->format('d.m.Y') : null;
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Rechnung <?= htmlspecialchars($inv['invoice_number']) ?> – Selbstbetrachtung</title>
<style>
  :root{ --ink:#2E3439; --ink-mute:#626B71; --gold:#D6A26A; --gold-dark:#B3813F; --danger:#B84A3C; }
  *{box-sizing:border-box;}
  body{ margin:0; background:#EDE5D8; color:var(--ink); font-family:"Mulish",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
  .toolbar{ padding:1rem 1.5rem; text-align:right; }
  .btn{ border:none; border-radius:999px; padding:.55rem 1.3rem; font-weight:700; cursor:pointer; font-family:inherit; background:var(--gold); color:#fff; font-size:.9rem; }
  .btn:hover{ background:var(--gold-dark); }
  .sheet{ max-width:700px; margin:0 auto 3rem; background:#fff; padding:3rem; box-shadow:0 2px 16px rgba(0,0,0,.08); }
  h1{ font-family:"Lora","Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif; font-weight:600; font-size:1.4rem; margin:2.5rem 0 1rem; }
  .from{ font-size:.8rem; color:var(--ink-mute); border-bottom:1px solid #ddd; padding-bottom:.3rem; margin-bottom:1.2rem; }
  .meta{ display:flex; justify-content:space-between; gap:1rem; margin:1.5rem 0; font-size:.92rem; }
  table{ width:100%; border-collapse:collapse; margin:1.5rem 0; }
  th, td{ text-align:left; padding:.6rem .3rem; border-bottom:1px solid #ddd; }
  tfoot td{ font-weight:700; border-top:2px solid var(--ink); border-bottom:none; white-space:nowrap; }
  .hinweis{ font-size:.85rem; color:var(--ink-mute); margin-top:2rem; }
  .footer{ font-size:.78rem; color:var(--ink-mute); margin-top:3rem; border-top:1px solid #ddd; padding-top:.8rem; }
  .storniert{ color:var(--danger); font-weight:700; }
  @media print{
    body{ background:#fff; }
    .toolbar{ display:none; }
    .sheet{ box-shadow:none; margin:0; padding:0; max-width:none; }
  }
</style>
</head>
<body>
<div class="toolbar"><button class="btn" onclick="window.print()">Drucken / Als PDF speichern</button></div>
<div class="sheet">
  <div class="from"><?= htmlspecialchars($settings['sender_name']) ?> · <?= nl2br(htmlspecialchars($settings['sender_address'])) ?></div>
  <?php if ($inv['status'] === 'storniert'): ?><p class="storniert">STORNIERT</p><?php endif; ?>
  <div class="meta">
    <div>
      <strong><?= htmlspecialchars($inv['client_name']) ?></strong><br>
      <?php if ($inv['client_address']): ?><?= nl2br(htmlspecialchars((string) $inv['client_address'])) ?><?php endif; ?>
    </div>
    <div style="text-align:right">
      Rechnungsnummer: <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong><br>
      Rechnungsdatum: <?= htmlspecialchars($issued) ?>
    </div>
  </div>

  <h1>Rechnung</h1>

  <table>
    <thead><tr><th>Beschreibung</th><th>Leistungsdatum</th><th style="text-align:right">Betrag</th></tr></thead>
    <tbody>
      <tr>
        <td><?= htmlspecialchars($inv['description']) ?></td>
        <td><?= htmlspecialchars($sessionDate ?? '–') ?></td>
        <td style="text-align:right"><?= htmlspecialchars($amount) ?></td>
      </tr>
    </tbody>
    <tfoot>
      <tr><td colspan="2">Gesamtbetrag</td><td style="text-align:right"><?= htmlspecialchars($amount) ?></td></tr>
    </tfoot>
  </table>

  <p class="hinweis"><?= htmlspecialchars($settings['kleinunternehmer_hinweis']) ?></p>
  <?php if ($inv['notes']): ?><p><?= nl2br(htmlspecialchars((string) $inv['notes'])) ?></p><?php endif; ?>

  <div class="footer">
    <p><?= htmlspecialchars($settings['payment_terms_note']) ?></p>
    <?php if (trim((string) $settings['sender_bank']) !== ''): ?><p><?= nl2br(htmlspecialchars($settings['sender_bank'])) ?></p><?php endif; ?>
    <p>Steuernummer: <?= htmlspecialchars($settings['sender_taxid']) ?></p>
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

// --- Reine Lese-Aktionen mit eigenem Response-Typ (kein HTML-Seitenlayout) ---
if ($isLoggedIn && isset($_GET['print_invoice'])) {
    $invoice = Buchhaltung::findInvoice($pdo, (int) $_GET['print_invoice']);
    if (!$invoice) {
        http_response_code(404);
        exit('Rechnung nicht gefunden.');
    }
    renderInvoicePrint($invoice, Buchhaltung::allSettings($pdo));
    exit;
}

if ($isLoggedIn && isset($_GET['download_receipt'])) {
    $expense = Buchhaltung::findExpense($pdo, (int) $_GET['download_receipt']);
    $path = ($expense && $expense['receipt_filename']) ? __DIR__ . '/data/belege/' . $expense['receipt_filename'] : null;
    if (!$path || !is_file($path)) {
        http_response_code(404);
        exit('Beleg nicht gefunden.');
    }
    $downloadName = $expense['receipt_original_name'] ?: $expense['receipt_filename'];
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode((string) $downloadName) . '"');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

if ($isLoggedIn && isset($_GET['export_csv'])) {
    $exportYear = (int) $_GET['export_csv'];
    $rows = Buchhaltung::exportRows($pdo, $exportYear);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="buchhaltung-' . $exportYear . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM, damit Excel Umlaute korrekt anzeigt
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Datum', 'Art', 'Nr./Kategorie', 'Beschreibung', 'Betrag (EUR)'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [
            (new DateTimeImmutable($r['date']))->format('d.m.Y'),
            $r['type'],
            $r['ref'],
            $r['description'],
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
        foreach (['sender_name', 'sender_address', 'sender_taxid', 'sender_bank', 'kleinunternehmer_hinweis', 'payment_terms_note'] as $field) {
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
        $sessionDate = trim((string) ($_POST['session_date'] ?? ''));
        $sessionType = trim((string) ($_POST['session_type'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $issuedAt = trim((string) ($_POST['issued_at'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $cents = Buchhaltung::parseAmountToCents((string) ($_POST['amount'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
            $sessionDate = '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedAt)) {
            $issuedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        }

        if ($clientName === '' || $description === '' || $cents === null || $cents <= 0) {
            $_SESSION['flash_error'] = 'Bitte Klient*in, Beschreibung und einen gültigen Betrag angeben.';
            redirectBack('#neue-rechnung');
        }

        $result = Buchhaltung::createInvoice($pdo, [
            'client_name' => $clientName,
            'client_address' => $clientAddress !== '' ? $clientAddress : null,
            'session_date' => $sessionDate !== '' ? $sessionDate : null,
            'session_type' => $sessionType !== '' ? $sessionType : null,
            'description' => $description,
            'amount_cents' => $cents,
            'issued_at' => $issuedAt,
            'notes' => $notes !== '' ? $notes : null,
        ]);
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

    if ($do === 'add_expense') {
        $date = trim((string) ($_POST['date'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
        $cents = Buchhaltung::parseAmountToCents((string) ($_POST['amount'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $category === '' || $description === '' || $cents === null || $cents <= 0) {
            $_SESSION['flash_error'] = 'Bitte Datum, Kategorie, Beschreibung und einen gültigen Betrag angeben.';
            redirectBack('#neue-ausgabe');
        }

        $receiptFilename = null;
        $receiptOriginalName = null;
        if (!empty($_FILES['receipt']['name']) && ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = handleReceiptUpload($_FILES['receipt']);
            if (!$upload['ok']) {
                $_SESSION['flash_error'] = $upload['error'];
                redirectBack('#neue-ausgabe');
            }
            $receiptFilename = $upload['filename'];
            $receiptOriginalName = $upload['original_name'];
        }

        Buchhaltung::createExpense($pdo, $date, $category, $description, $cents, $paymentMethod !== '' ? $paymentMethod : null, $receiptFilename, $receiptOriginalName);
        redirectBack('#ausgaben');
    }

    if ($do === 'delete_expense') {
        $id = (int) ($_POST['id'] ?? 0);
        $expense = Buchhaltung::findExpense($pdo, $id);
        if ($expense) {
            if ($expense['receipt_filename']) {
                @unlink(__DIR__ . '/data/belege/' . $expense['receipt_filename']);
            }
            Buchhaltung::deleteExpense($pdo, $id);
        }
        redirectBack('#ausgaben');
    }
}

$flashError = $_SESSION['flash_error'] ?? null;
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$currentYear = (int) substr($today, 0, 4);

$settings = $isLoggedIn ? Buchhaltung::allSettings($pdo) : [];
$invoices = $isLoggedIn ? Buchhaltung::listInvoices($pdo) : [];
$expenses = $isLoggedIn ? Buchhaltung::listExpenses($pdo) : [];
$invoicableBookings = $isLoggedIn ? Booking::listForInvoicing($bookingPdo) : [];
$availableYears = $isLoggedIn ? Buchhaltung::availableYears($pdo) : [];
$csrf = $isLoggedIn ? csrfToken() : '';

$viewYear = $currentYear;
if ($isLoggedIn && isset($_GET['year'])) {
    $y = (int) $_GET['year'];
    if ($y >= 2000 && $y <= $currentYear + 1) {
        $viewYear = $y;
    }
}
$summary = $isLoggedIn ? Buchhaltung::yearSummary($pdo, $viewYear) : null;

// Vorbelegung der "Neue Rechnung"-Felder, wenn von einem Termin aus verlinkt wurde
// (siehe listForInvoicing()) – die Daten werden beim Speichern kopiert, nicht live
// verknüpft, damit die Rechnung unabhängig vom (später ggf. gelöschten) Termin bleibt.
$fromBooking = null;
if ($isLoggedIn && isset($_GET['from_booking'])) {
    $wantId = (int) $_GET['from_booking'];
    foreach ($invoicableBookings as $b) {
        if ((int) $b['id'] === $wantId) {
            $fromBooking = $b;
            break;
        }
    }
}
$fromBookingTypeLabel = $fromBooking ? (Booking::TYPES[$fromBooking['type']]['label'] ?? $fromBooking['type']) : '';
$defaultSessionDate = $fromBooking ? $fromBooking['date'] : $today;
$defaultDescription = $fromBooking
    ? ('Psychologische Beratung – ' . $fromBookingTypeLabel . ' am ' . (new DateTimeImmutable($fromBooking['date']))->format('d.m.Y'))
    : '';
$defaultAmountValue = '';
if ($fromBooking) {
    $priceKey = null;
    if ($fromBooking['type'] === 'erstgespraech') {
        $priceKey = 'price_erstgespraech_cents';
    } elseif ($fromBooking['type'] === 'folgetermin') {
        $priceKey = 'price_folgetermin_cents';
    }
    if ($priceKey && ($settings[$priceKey] ?? '') !== '') {
        $defaultAmountValue = Buchhaltung::centsToInputValue((int) $settings[$priceKey]);
    }
}

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
  *{box-sizing:border-box;}
  body{ margin:0; background:var(--cream); color:var(--ink); font-family:var(--font-body); line-height:1.5; }
  header{ display:flex; justify-content:space-between; align-items:center; padding:1rem 1.5rem; background:var(--cream-light); border-bottom:1px solid var(--cream-dark); }
  header a{ color:var(--ink-mute); text-decoration:none; font-size:.9rem; }
  header .nav{ display:flex; gap:1.25rem; align-items:center; }
  main{ max-width:960px; margin:0 auto; padding:2rem 1.25rem 4rem; }
  h1{ font-family:var(--font-head); font-weight:600; }
  h2{ font-family:var(--font-head); font-weight:600; font-size:1.15rem; margin:0 0 1rem; }
  section.card{ background:var(--cream-light); border:1px solid var(--cream-dark); border-radius:14px; padding:1.25rem 1.5rem; margin-bottom:1.5rem; }
  table{ width:100%; border-collapse:collapse; margin-bottom:1rem; font-size:.92rem; }
  th, td{ text-align:left; padding:.5rem .4rem; border-bottom:1px solid var(--cream-dark); vertical-align:top; }
  form.inline{ display:flex; gap:.5rem; flex-wrap:wrap; align-items:end; }
  form.inline label{ display:flex; flex-direction:column; font-size:.8rem; color:var(--ink-mute); gap:.25rem; }
  input, select, textarea{ font-family:inherit; font-size:.95rem; padding:.45rem .6rem; border:1.5px solid var(--cream-dark); border-radius:8px; background:#fff; color:var(--ink); }
  textarea{ min-height:3.5rem; }
  .btn{ border:none; border-radius:999px; padding:.5rem 1.1rem; font-weight:700; cursor:pointer; font-family:inherit; background:var(--gold); color:#fff; font-size:.88rem; text-decoration:none; display:inline-block; }
  .btn:hover{ background:var(--gold-dark); }
  .btn--danger{ background:var(--danger); }
  .btn--danger:hover{ opacity:.85; }
  .btn--ghost{ background:none; color:var(--ink-mute); padding:.5rem .7rem; }
  .login-card{ max-width:340px; margin:3rem auto; }
  .login-card input[type=password]{ width:100%; margin-bottom:1rem; }
  .error{ color:var(--danger); font-weight:600; }
  .success{ color:#3f7d52; font-weight:600; }
  .muted{ color:var(--ink-mute); font-size:.85rem; }
  .badge{ display:inline-block; background:var(--cream-dark); border-radius:999px; padding:.1rem .6rem; font-size:.78rem; }
  .badge--paid{ background:var(--green); }
  .badge--cancelled{ background:var(--danger); color:#fff; }
  .quicknav{ margin:0 0 1.5rem; font-size:.9rem; }
  .quicknav a{ color:var(--ink-mute); }
  .summary-cards{ display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; }
  .summary-tile{ background:#fff; border:1px solid var(--cream-dark); border-radius:10px; padding:.8rem 1.1rem; min-width:150px; }
  .summary-tile .num{ font-family:var(--font-head); font-size:1.3rem; font-weight:600; }
  .years a{ margin-right:.6rem; }
  .years a.active{ color:var(--ink); font-weight:700; text-decoration:underline; }
  .actions{ display:flex; gap:.4rem; flex-wrap:wrap; }
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
  <p class="muted">Einnahmen aus Terminen, Ausgaben/Belege und Rechnungsstellung. Kleinunternehmer nach § 19 UStG – ersetzt keine steuerliche Beratung.</p>
  <?php if ($flashError): ?><p class="error"><?= htmlspecialchars($flashError) ?></p><?php endif; ?>
  <?php if ($flashSuccess): ?><p class="success"><?= htmlspecialchars($flashSuccess) ?></p><?php endif; ?>

  <p class="quicknav">
    <a href="#uebersicht">Übersicht</a> ·
    <a href="#rechnungen">Rechnungen</a> ·
    <a href="#ausgaben">Ausgaben</a> ·
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
      <div class="summary-tile"><div class="muted">Ausgaben</div><div class="num"><?= htmlspecialchars(Buchhaltung::formatEuro($summary['expense_total'])) ?></div></div>
      <div class="summary-tile"><div class="muted">Ergebnis</div><div class="num"><?= htmlspecialchars(Buchhaltung::formatEuro($summary['income_total'] - $summary['expense_total'])) ?></div></div>
      <?php if ($summary['open_cents'] > 0): ?>
      <div class="summary-tile"><div class="muted">Offene Rechnungen (alle Jahre)</div><div class="num"><?= htmlspecialchars(Buchhaltung::formatEuro($summary['open_cents'])) ?></div></div>
      <?php endif; ?>
    </div>

    <table>
      <thead><tr><th>Monat</th><th>Einnahmen</th><th>Ausgaben</th><th>Ergebnis</th></tr></thead>
      <tbody>
      <?php foreach ($summary['months'] as $m => $vals): ?>
        <tr>
          <td><?= htmlspecialchars($monthNames[$m]) ?></td>
          <td><?= htmlspecialchars(Buchhaltung::formatEuro($vals['income_cents'])) ?></td>
          <td><?= htmlspecialchars(Buchhaltung::formatEuro($vals['expense_cents'])) ?></td>
          <td><?= htmlspecialchars(Buchhaltung::formatEuro($vals['income_cents'] - $vals['expense_cents'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted">Einnahmen zählen nach Zahlungseingang (Zuflussprinzip der Einnahmen-Überschuss-Rechnung), nicht nach Rechnungsdatum.</p>
  </section>

  <section class="card" id="rechnungen">
    <h2>Rechnungen</h2>
    <table>
      <thead><tr><th>Nr.</th><th>Datum</th><th>Klient*in</th><th>Beschreibung</th><th>Betrag</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($invoices as $inv): ?>
        <tr>
          <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
          <td><?= htmlspecialchars((new DateTimeImmutable($inv['issued_at']))->format('d.m.Y')) ?></td>
          <td><?= htmlspecialchars($inv['client_name']) ?></td>
          <td><?= htmlspecialchars($inv['description']) ?></td>
          <td><?= htmlspecialchars(Buchhaltung::formatEuro((int) $inv['amount_cents'])) ?></td>
          <td><?= invoiceStatusBadge($inv['status']) ?></td>
          <td>
            <div class="actions">
              <a class="btn btn--ghost" href="?print_invoice=<?= (int) $inv['id'] ?>" target="_blank" rel="noopener">Drucken</a>
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
    <h2 style="font-size:1rem; margin-top:1.5rem;">Aus Termin übernehmen</h2>
    <p class="muted">Kommende und kürzlich vergangene Termine (letzte 60 Tage). „Übernehmen“ füllt das Formular unten vor – die Rechnung bleibt danach unabhängig vom Termin bestehen.</p>
    <table>
      <thead><tr><th>Datum</th><th>Zeit</th><th>Art</th><th>Klient*in</th><th></th></tr></thead>
      <tbody>
      <?php foreach (array_slice($invoicableBookings, 0, 15) as $b): ?>
        <tr>
          <td><?= htmlspecialchars((new DateTimeImmutable($b['date']))->format('d.m.Y')) ?></td>
          <td><?= htmlspecialchars($b['start_time']) ?></td>
          <td><?= htmlspecialchars(Booking::TYPES[$b['type']]['label'] ?? $b['type']) ?></td>
          <td><?= htmlspecialchars($b['name']) ?></td>
          <td><a class="btn btn--ghost" href="?from_booking=<?= (int) $b['id'] ?>#neue-rechnung">Übernehmen</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <h2 id="neue-rechnung" style="font-size:1rem; margin-top:1.5rem;">Neue Rechnung</h2>
    <?php if ($fromBooking): ?><p class="muted">Vorausgefüllt aus dem Termin von <?= htmlspecialchars($fromBooking['name']) ?> am <?= htmlspecialchars((new DateTimeImmutable($fromBooking['date']))->format('d.m.Y')) ?>.</p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="do" value="create_invoice">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:.8rem;">
        <label>Klient*in
          <input type="text" name="client_name" value="<?= htmlspecialchars($fromBooking['name'] ?? '') ?>" required>
        </label>
        <label>Anschrift (optional – ab 250 € Rechnungsbetrag empfohlen)
          <textarea name="client_address" placeholder="Straße, PLZ Ort"></textarea>
        </label>
        <label>Leistungsdatum
          <input type="date" name="session_date" value="<?= htmlspecialchars($defaultSessionDate) ?>">
        </label>
        <label>Art der Leistung
          <input type="text" name="session_type" value="<?= htmlspecialchars($fromBookingTypeLabel) ?>" placeholder="z. B. Erstgespräch">
        </label>
        <label style="grid-column:1/-1">Beschreibung
          <input type="text" name="description" value="<?= htmlspecialchars($defaultDescription) ?>" required>
        </label>
        <label>Betrag (€)
          <input type="text" inputmode="decimal" name="amount" value="<?= htmlspecialchars($defaultAmountValue) ?>" placeholder="z. B. 85,00" required>
        </label>
        <label>Rechnungsdatum
          <input type="date" name="issued_at" value="<?= htmlspecialchars($today) ?>" required>
        </label>
        <label style="grid-column:1/-1">Notizen (optional, nur intern)
          <textarea name="notes"></textarea>
        </label>
      </div>
      <p><button type="submit" class="btn">Rechnung erstellen</button></p>
    </form>
  </section>

  <section class="card" id="ausgaben">
    <h2>Ausgaben</h2>
    <table>
      <thead><tr><th>Datum</th><th>Kategorie</th><th>Beschreibung</th><th>Betrag</th><th>Zahlungsart</th><th>Beleg</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($expenses as $exp): ?>
        <tr>
          <td><?= htmlspecialchars((new DateTimeImmutable($exp['date']))->format('d.m.Y')) ?></td>
          <td><?= htmlspecialchars($exp['category']) ?></td>
          <td><?= htmlspecialchars($exp['description']) ?></td>
          <td><?= htmlspecialchars(Buchhaltung::formatEuro((int) $exp['amount_cents'])) ?></td>
          <td><?= htmlspecialchars((string) $exp['payment_method']) ?: '<span class="muted">–</span>' ?></td>
          <td><?php if ($exp['receipt_filename']): ?><a href="?download_receipt=<?= (int) $exp['id'] ?>">Herunterladen</a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <td>
            <form method="post" style="margin:0" onsubmit="return confirm('Diese Ausgabe wirklich löschen?');">
              <input type="hidden" name="do" value="delete_expense">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $exp['id'] ?>">
              <button type="submit" class="btn btn--danger">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$expenses): ?><tr><td colspan="7" class="muted">Noch keine Ausgaben erfasst.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <h2 id="neue-ausgabe" style="font-size:1rem; margin-top:1.5rem;">Neue Ausgabe</h2>
    <datalist id="kategorien">
      <option value="Praxisraum/Miete"><option value="Fortbildung"><option value="Fachliteratur">
      <option value="Bürobedarf"><option value="Versicherung"><option value="Software/Abo">
      <option value="Reisekosten"><option value="Sonstiges">
    </datalist>
    <datalist id="zahlungsarten">
      <option value="Überweisung"><option value="Lastschrift"><option value="Kreditkarte"><option value="Bar">
    </datalist>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="do" value="add_expense">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:.8rem;">
        <label>Datum
          <input type="date" name="date" value="<?= htmlspecialchars($today) ?>" required>
        </label>
        <label>Kategorie
          <input type="text" name="category" list="kategorien" required>
        </label>
        <label style="grid-column:1/-1">Beschreibung
          <input type="text" name="description" required>
        </label>
        <label>Betrag (€)
          <input type="text" inputmode="decimal" name="amount" placeholder="z. B. 49,90" required>
        </label>
        <label>Zahlungsart (optional)
          <input type="text" name="payment_method" list="zahlungsarten">
        </label>
        <label style="grid-column:1/-1">Beleg (optional, JPG/PNG/PDF, max. 8 MB)
          <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf">
        </label>
      </div>
      <p><button type="submit" class="btn">Ausgabe speichern</button></p>
    </form>
  </section>

  <section class="card" id="export">
    <h2>Export für den Steuerberater</h2>
    <p class="muted">CSV-Export je Jahr (Einnahmen nach Zahlungseingang, Ausgaben nach Datum) als Arbeitsgrundlage – ersetzt keine amtliche Anlage EÜR.</p>
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
      <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:.8rem;">
        <label>Absendername (auf Rechnungen)
          <input type="text" name="sender_name" value="<?= htmlspecialchars($settings['sender_name']) ?>">
        </label>
        <label>Steuernummer
          <input type="text" name="sender_taxid" value="<?= htmlspecialchars($settings['sender_taxid']) ?>">
        </label>
        <label style="grid-column:1/-1">Absenderanschrift
          <textarea name="sender_address"><?= htmlspecialchars($settings['sender_address']) ?></textarea>
        </label>
        <label style="grid-column:1/-1">Bankverbindung (optional, erscheint auf Rechnungen)
          <textarea name="sender_bank" placeholder="z. B. IBAN"><?= htmlspecialchars($settings['sender_bank']) ?></textarea>
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
      <p class="muted">Die Preise dienen nur der Vorbelegung neuer Rechnungen aus einem Termin – jede Rechnung bleibt vor dem Speichern editierbar.</p>
      <p><button type="submit" class="btn">Einstellungen speichern</button></p>
    </form>
  </section>

<?php endif; ?>
</main>
</body>
</html>
