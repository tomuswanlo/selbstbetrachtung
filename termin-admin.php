<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');
session_start();

require __DIR__ . '/lib/Booking.php';

$configFile = __DIR__ . '/termin_admin_config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Admin-Konfiguration fehlt (termin_admin_config.php). Siehe smtp_config.php als Vorbild.');
}
require $configFile;

$pdo = Booking::db();

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

function redirectBack(): void
{
    header('Location: termin-admin.php');
    exit;
}

$isLoggedIn = !empty($_SESSION['termin_admin']);
$loginError = null;

// --- Login -----------------------------------------------------------
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

// --- Logout ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'logout') {
    session_destroy();
    header('Location: termin-admin.php');
    exit;
}

// --- Geschützte Aktionen -------------------------------------------------
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && checkCsrf()) {
    $do = $_POST['do'] ?? '';

    if ($do === 'add_rule' || $do === 'update_rule') {
        $weekday = (int) ($_POST['weekday'] ?? -1);
        $start = trim((string) ($_POST['start_time'] ?? ''));
        $end = trim((string) ($_POST['end_time'] ?? ''));
        if ($weekday >= 0 && $weekday <= 6 && preg_match('/^\d{2}:\d{2}$/', $start) && preg_match('/^\d{2}:\d{2}$/', $end) && $end > $start) {
            if ($do === 'update_rule') {
                Booking::updateRule($pdo, (int) ($_POST['id'] ?? 0), $weekday, $start, $end);
            } else {
                Booking::addRule($pdo, $weekday, $start, $end);
            }
        } else {
            $_SESSION['flash_error'] = 'Ungültige Zeiten (Ende muss nach Start liegen).';
        }
        redirectBack();
    }

    if ($do === 'delete_rule') {
        Booking::deleteRule($pdo, (int) ($_POST['id'] ?? 0));
        redirectBack();
    }

    if ($do === 'add_blocked_date') {
        $dateFrom = trim((string) ($_POST['date_from'] ?? ''));
        $dateTo = trim((string) ($_POST['date_to'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($dateTo === '') {
            $dateTo = $dateFrom;
        }
        $result = Booking::addBlockedDateRange($pdo, $dateFrom, $dateTo, $reason);
        if (!$result['ok']) {
            $_SESSION['flash_error'] = 'Ungültiger Zeitraum (Von: "' . $dateFrom . '", Bis: "' . $dateTo . '").';
        }
        redirectBack();
    }

    if ($do === 'update_blocked_date') {
        $date = trim((string) ($_POST['date_from'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $result = Booking::updateBlockedDate($pdo, (int) ($_POST['id'] ?? 0), $date, $reason);
            if (!$result['ok']) {
                $_SESSION['flash_error'] = $result['error'];
            }
        } else {
            $_SESSION['flash_error'] = 'Ungültiges Datum.';
        }
        redirectBack();
    }

    if ($do === 'delete_blocked_date') {
        Booking::deleteBlockedDate($pdo, (int) ($_POST['id'] ?? 0));
        redirectBack();
    }

    if ($do === 'add_manual_block') {
        $dateFrom = trim((string) ($_POST['date_from'] ?? ''));
        $dateTo = trim((string) ($_POST['date_to'] ?? ''));
        $start = trim((string) ($_POST['start_time'] ?? ''));
        $end = trim((string) ($_POST['end_time'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($dateTo === '') {
            $dateTo = $dateFrom;
        }
        $result = Booking::addManualBlockRange($pdo, $dateFrom, $dateTo, $start, $end, $reason);
        if ($result['added'] === 0) {
            $_SESSION['flash_error'] = 'Konnte nicht gespeichert werden (Von: "' . $dateFrom . '", Bis: "' . $dateTo . '"): ' . ($result['failed'][0]['error'] ?? 'Ungültiger Zeitraum.');
        } elseif ($result['failed']) {
            $days = implode(', ', array_map(
                static fn(array $f): string => (new DateTimeImmutable($f['date']))->format('d.m.'),
                $result['failed']
            ));
            $_SESSION['flash_error'] = $result['added'] . ' Tag(e) geblockt, ' . count($result['failed']) . ' Tag(e) übersprungen (Überschneidung mit bestehendem Termin): ' . $days;
        }
        redirectBack();
    }

    if ($do === 'cancel_booking') {
        Booking::cancelById($pdo, (int) ($_POST['id'] ?? 0), 'admin');
        redirectBack();
    }
}

$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

$rules = $isLoggedIn ? Booking::listRules($pdo) : [];
$blockedDates = $isLoggedIn ? Booking::listBlockedDates($pdo) : [];
$upcoming = $isLoggedIn ? Booking::listUpcoming($pdo) : [];
$csrf = $isLoggedIn ? csrfToken() : '';

// "Kommende Termine": echte Buchungen/Zeitraum-Blocker + ganztägige Sperrtage in einer
// gemeinsamen, chronologisch sortierten Übersicht zusammenführen.
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$agenda = [];
foreach ($upcoming as $u) {
    $agenda[] = ['kind' => 'booking', 'date' => $u['date'], 'sortKey' => $u['start_time'], 'data' => $u];
}
foreach ($blockedDates as $b) {
    if ($b['date'] >= $today) {
        $agenda[] = ['kind' => 'blocked_date', 'date' => $b['date'], 'sortKey' => '00:00', 'data' => $b];
    }
}
usort($agenda, static fn(array $a, array $b): int => [$a['date'], $a['sortKey']] <=> [$b['date'], $b['sortKey']]);

// Bearbeiten-Modus: welche Zeile (falls vorhanden) wird gerade im Formular vorausgefüllt?
$editRule = null;
if (isset($_GET['edit_rule'])) {
    $editRuleId = (int) $_GET['edit_rule'];
    foreach ($rules as $r) {
        if ((int) $r['id'] === $editRuleId) {
            $editRule = $r;
            break;
        }
    }
}
$editBlocked = null;
if (isset($_GET['edit_blocked'])) {
    $editBlockedId = (int) $_GET['edit_blocked'];
    foreach ($blockedDates as $b) {
        if ((int) $b['id'] === $editBlockedId) {
            $editBlocked = $b;
            break;
        }
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terminverwaltung – Selbstbetrachtung</title>
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
  main{ max-width:900px; margin:0 auto; padding:2rem 1.25rem 4rem; }
  h1{ font-family:var(--font-head); font-weight:600; }
  h2{ font-family:var(--font-head); font-weight:600; font-size:1.15rem; margin:0 0 1rem; }
  section.card{ background:var(--cream-light); border:1px solid var(--cream-dark); border-radius:14px; padding:1.25rem 1.5rem; margin-bottom:1.5rem; }
  table{ width:100%; border-collapse:collapse; margin-bottom:1rem; font-size:.92rem; }
  th, td{ text-align:left; padding:.5rem .4rem; border-bottom:1px solid var(--cream-dark); vertical-align:top; }
  form.inline{ display:flex; gap:.5rem; flex-wrap:wrap; align-items:end; }
  form.inline label{ display:flex; flex-direction:column; font-size:.8rem; color:var(--ink-mute); gap:.25rem; }
  input, select, textarea{ font-family:inherit; font-size:.95rem; padding:.45rem .6rem; border:1.5px solid var(--cream-dark); border-radius:8px; background:#fff; color:var(--ink); }
  textarea{ min-height:3.5rem; }
  .btn{ border:none; border-radius:999px; padding:.5rem 1.1rem; font-weight:700; cursor:pointer; font-family:inherit; background:var(--gold); color:#fff; font-size:.88rem; }
  .btn:hover{ background:var(--gold-dark); }
  .btn--danger{ background:var(--danger); }
  .btn--danger:hover{ opacity:.85; }
  .login-card{ max-width:340px; margin:3rem auto; }
  .login-card input[type=password]{ width:100%; margin-bottom:1rem; }
  .error{ color:var(--danger); font-weight:600; }
  .muted{ color:var(--ink-mute); font-size:.85rem; }
  .badge{ display:inline-block; background:var(--cream-dark); border-radius:999px; padding:.1rem .6rem; font-size:.78rem; }
</style>
</head>
<body>
<header>
  <strong>Terminverwaltung</strong>
  <?php if ($isLoggedIn): ?>
    <form method="post" style="margin:0"><input type="hidden" name="do" value="logout"><button type="submit" class="btn" style="background:none;color:var(--ink-mute)">Abmelden</button></form>
  <?php else: ?>
    <a href="/">Zur Website</a>
  <?php endif; ?>
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
  </div>

<?php else: ?>

  <h1>Terminverwaltung</h1>
  <?php if ($flashError): ?><p class="error"><?= htmlspecialchars($flashError) ?></p><?php endif; ?>

  <section class="card" id="wochenzeiten">
    <h2>Wöchentliche Verfügbarkeit</h2>
    <p class="muted">Zwischen zwei Terminen wird automatisch <?= Booking::BOOKING_BUFFER_MINUTES ?> Minuten Pufferzeit zur Nachbereitung freigehalten – das muss hier nicht extra eingeplant werden.</p>
    <table>
      <thead><tr><th>Wochentag</th><th>Von</th><th>Bis</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rules as $r): ?>
        <tr>
          <td><?= htmlspecialchars(Booking::WEEKDAYS[(int) $r['weekday']]) ?></td>
          <td><?= htmlspecialchars($r['start_time']) ?></td>
          <td><?= htmlspecialchars($r['end_time']) ?></td>
          <td style="display:flex; gap:.4rem;">
            <a class="btn" style="text-decoration:none" href="?edit_rule=<?= (int) $r['id'] ?>#wochenzeiten">Bearbeiten</a>
            <form method="post" style="margin:0" onsubmit="return confirm('Diese Regel wirklich löschen?');">
              <input type="hidden" name="do" value="delete_rule">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn btn--danger">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rules): ?><tr><td colspan="4" class="muted">Noch keine Verfügbarkeit eingetragen – es sind aktuell keine Termine buchbar.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <form class="inline" method="post">
      <input type="hidden" name="do" value="<?= $editRule ? 'update_rule' : 'add_rule' ?>">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <?php if ($editRule): ?><input type="hidden" name="id" value="<?= (int) $editRule['id'] ?>"><?php endif; ?>
      <label>Wochentag
        <select name="weekday" required>
          <?php foreach ([1,2,3,4,5,6,0] as $wd): ?>
            <option value="<?= $wd ?>" <?= $editRule && (int) $editRule['weekday'] === $wd ? 'selected' : '' ?>><?= htmlspecialchars(Booking::WEEKDAYS[$wd]) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Von <input type="time" name="start_time" value="<?= $editRule ? htmlspecialchars($editRule['start_time']) : '' ?>" required></label>
      <label>Bis <input type="time" name="end_time" value="<?= $editRule ? htmlspecialchars($editRule['end_time']) : '' ?>" required></label>
      <button type="submit" class="btn"><?= $editRule ? 'Speichern' : 'Hinzufügen' ?></button>
      <?php if ($editRule): ?><a href="termin-admin.php#wochenzeiten" style="color:var(--ink-mute)">Abbrechen</a><?php endif; ?>
    </form>
  </section>

  <section class="card" id="sperrtage">
    <h2>Sperrtage (ganztägig, z. B. Urlaub/Feiertage)</h2>
    <table>
      <thead><tr><th>Datum</th><th>Grund</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($blockedDates as $b): ?>
        <tr>
          <td><?= htmlspecialchars((new DateTimeImmutable($b['date']))->format('d.m.Y')) ?></td>
          <td><?= htmlspecialchars((string) $b['reason']) ?></td>
          <td style="display:flex; gap:.4rem;">
            <a class="btn" style="text-decoration:none" href="?edit_blocked=<?= (int) $b['id'] ?>#sperrtage">Bearbeiten</a>
            <form method="post" style="margin:0">
              <input type="hidden" name="do" value="delete_blocked_date">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button type="submit" class="btn btn--danger">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$blockedDates): ?><tr><td colspan="3" class="muted">Keine Sperrtage eingetragen.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <form class="inline" method="post">
      <input type="hidden" name="do" value="<?= $editBlocked ? 'update_blocked_date' : 'add_blocked_date' ?>">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <?php if ($editBlocked): ?><input type="hidden" name="id" value="<?= (int) $editBlocked['id'] ?>"><?php endif; ?>
      <label>Von <input type="date" name="date_from" value="<?= $editBlocked ? htmlspecialchars($editBlocked['date']) : '' ?>" required></label>
      <?php if (!$editBlocked): ?><label>Bis (optional, für mehrere Tage) <input type="date" name="date_to"></label><?php endif; ?>
      <label>Grund <input type="text" name="reason" placeholder="z. B. Urlaub" value="<?= $editBlocked ? htmlspecialchars((string) $editBlocked['reason']) : '' ?>"></label>
      <button type="submit" class="btn"><?= $editBlocked ? 'Speichern' : 'Sperren' ?></button>
      <?php if ($editBlocked): ?><a href="termin-admin.php#sperrtage" style="color:var(--ink-mute)">Abbrechen</a><?php endif; ?>
    </form>
  </section>

  <section class="card">
    <h2>Zeitraum blockieren</h2>
    <p class="muted">Für private Termine, die nicht über die Website gebucht wurden. Mit „Bis“ lässt sich dieselbe Uhrzeit über mehrere Tage blockieren (z. B. jeden Vormittag einer Fortbildungswoche).</p>
    <form class="inline" method="post">
      <input type="hidden" name="do" value="add_manual_block">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <label>Von <input type="date" name="date_from" required></label>
      <label>Bis (optional, für mehrere Tage) <input type="date" name="date_to"></label>
      <label>Uhrzeit von <input type="time" name="start_time" required></label>
      <label>Uhrzeit bis <input type="time" name="end_time" required></label>
      <label>Grund <input type="text" name="reason" placeholder="z. B. Fortbildung"></label>
      <button type="submit" class="btn">Blockieren</button>
    </form>
  </section>

  <section class="card">
    <h2>Kommende Termine</h2>
    <p class="muted">Zeigt echte Buchungen, manuell blockierte Zeiträume und ganztägige Sperrtage gemeinsam, chronologisch sortiert.</p>
    <table>
      <thead><tr><th>Datum</th><th>Zeit</th><th>Art</th><th>Klient*in / Grund</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($agenda as $entry): ?>
        <?php if ($entry['kind'] === 'blocked_date'): $b = $entry['data']; ?>
        <tr>
          <td><?= htmlspecialchars((new DateTimeImmutable($b['date']))->format('d.m.Y')) ?></td>
          <td>Ganztägig</td>
          <td><span class="badge">Gesperrt</span></td>
          <td><?= htmlspecialchars((string) $b['reason']) ?: '<span class="muted">–</span>' ?></td>
          <td><a class="btn" style="text-decoration:none" href="?edit_blocked=<?= (int) $b['id'] ?>#sperrtage">Bearbeiten</a></td>
        </tr>
        <?php else: $u = $entry['data']; ?>
        <tr>
          <td><?= htmlspecialchars((new DateTimeImmutable($u['date']))->format('d.m.Y')) ?></td>
          <td><?= htmlspecialchars($u['start_time']) ?>–<?= htmlspecialchars($u['end_time']) ?></td>
          <td>
            <?php if ($u['type'] === 'block'): ?>
              <span class="badge">Blockiert</span>
            <?php else: ?>
              <?= htmlspecialchars(Booking::TYPES[$u['type']]['label'] ?? $u['type']) ?>
            <?php endif; ?>
          </td>
          <td>
            <?= htmlspecialchars($u['name']) ?>
            <?php if ($u['email']): ?><br><span class="muted"><?= htmlspecialchars($u['email']) ?><?php if ($u['phone']): ?> · <?= htmlspecialchars($u['phone']) ?><?php endif; ?></span><?php endif; ?>
            <?php if ($u['message']): ?><br><span class="muted">„<?= htmlspecialchars($u['message']) ?>“</span><?php endif; ?>
          </td>
          <td>
            <form method="post" style="margin:0" onsubmit="return confirm('Diesen Termin wirklich stornieren? Der/die Klient*in wird nicht automatisch benachrichtigt.');">
              <input type="hidden" name="do" value="cancel_booking">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button type="submit" class="btn btn--danger">Stornieren</button>
            </form>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$agenda): ?><tr><td colspan="5" class="muted">Keine anstehenden Termine.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <p class="muted">Hinweis: Beim Stornieren durch Sie wird der/die Klient*in <strong>nicht</strong> automatisch per E-Mail informiert – bitte ggf. selbst Bescheid geben.</p>
  </section>

<?php endif; ?>
</main>
</body>
</html>
