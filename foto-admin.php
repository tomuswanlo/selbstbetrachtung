<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');
session_start();

$configFile = __DIR__ . '/termin_admin_config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Admin-Konfiguration fehlt (termin_admin_config.php). Siehe smtp_config.php als Vorbild.');
}
require $configFile;

const TARGET_W = 1080;
const TARGET_H = 1350; // 4:5, passend zu .about__photo { aspect-ratio: 4/5 }
const LIVE_PHOTO = __DIR__ . '/data/about-photo.jpg';

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
    header('Location: foto-admin.php');
    exit;
}

/**
 * Validiert + verarbeitet den Upload: mittiger Zuschnitt auf 4:5,
 * Skalierung auf TARGET_W x TARGET_H, atomarer Schreibvorgang
 * (erst .tmp schreiben, dann umbenennen - kein halb geschriebenes Foto
 * im Live-Pfad, egal wann der Prozess unterbrochen wird).
 *
 * @return true|string true bei Erfolg, sonst eine Fehlermeldung.
 */
function handleUpload(?array $file)
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Bitte eine Bilddatei auswählen.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload fehlgeschlagen (Fehlercode ' . $file['error'] . ').';
    }
    if ($file['size'] > 15 * 1024 * 1024) {
        return 'Datei zu groß (max. 15 MB).';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $loaders = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
    ];
    if (function_exists('imagecreatefromwebp')) {
        $loaders['image/webp'] = 'imagecreatefromwebp';
    }
    if (!isset($loaders[$mime])) {
        return 'Nicht unterstütztes Dateiformat (' . htmlspecialchars((string) $mime) . '). Bitte JPG oder PNG.';
    }

    $src = @($loaders[$mime])($file['tmp_name']);
    if ($src === false) {
        return 'Datei konnte nicht als Bild gelesen werden.';
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW < 500 || $srcH < 500) {
        imagedestroy($src);
        return 'Bild zu klein (mindestens 500 × 500 Pixel, besser deutlich größer).';
    }

    $targetAspect = TARGET_W / TARGET_H;
    $srcAspect = $srcW / $srcH;
    if ($srcAspect > $targetAspect) {
        $cropH = $srcH;
        $cropW = (int) round($srcH * $targetAspect);
        $cropX = (int) round(($srcW - $cropW) / 2);
        $cropY = 0;
    } else {
        $cropW = $srcW;
        $cropH = (int) round($srcW / $targetAspect);
        $cropX = 0;
        $cropY = (int) round(($srcH - $cropH) / 2);
    }

    $dst = imagecreatetruecolor(TARGET_W, TARGET_H);
    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, TARGET_W, TARGET_H, $cropW, $cropH);
    imagedestroy($src);

    if (!is_dir(__DIR__ . '/data')) {
        mkdir(__DIR__ . '/data', 0755, true);
    }
    $tmp = LIVE_PHOTO . '.tmp';
    $ok = imagejpeg($dst, $tmp, 90);
    imagedestroy($dst);
    if (!$ok) {
        return 'Speichern fehlgeschlagen.';
    }
    if (!rename($tmp, LIVE_PHOTO)) {
        @unlink($tmp);
        return 'Speichern fehlgeschlagen (rename).';
    }
    return true;
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
    header('Location: foto-admin.php');
    exit;
}

// --- Foto hochladen ------------------------------------------------------
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'upload' && checkCsrf()) {
    $result = handleUpload($_FILES['photo'] ?? null);
    if ($result === true) {
        $_SESSION['flash_success'] = 'Neues Foto gespeichert – live auf der Startseite sichtbar.';
    } else {
        $_SESSION['flash_error'] = $result;
    }
    redirectBack();
}

// --- Auf Standardfoto zurücksetzen ---------------------------------------
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'reset' && checkCsrf()) {
    if (is_file(LIVE_PHOTO)) {
        unlink(LIVE_PHOTO);
    }
    $_SESSION['flash_success'] = 'Zurückgesetzt auf das Standardfoto.';
    redirectBack();
}

$flashError = $_SESSION['flash_error'] ?? null;
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);
$csrf = csrfToken();
$hasCustomPhoto = is_file(LIVE_PHOTO);
$currentPhotoUrl = '/about-photo.php?v=' . time(); // Cache-Buster, damit die Vorschau hier immer aktuell ist
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Foto verwalten – Selbstbetrachtung</title>
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
  .btn{ border:none; border-radius:999px; padding:.5rem 1.1rem; font-weight:700; cursor:pointer; font-family:inherit; background:var(--gold); color:#fff; font-size:.88rem; }
  .btn:hover{ background:var(--gold-dark); }
  .btn--ghost{ background:none; color:var(--ink-mute); border:1.5px solid var(--cream-dark); }
  .btn--ghost:hover{ border-color:var(--ink-mute); }
  .login-card{ max-width:340px; margin:3rem auto; }
  .login-card input[type=password]{ width:100%; margin-bottom:1rem; }
  input[type=password]{ font-family:inherit; font-size:.95rem; padding:.45rem .6rem; border:1.5px solid var(--cream-dark); border-radius:8px; background:#fff; color:var(--ink); }
  .error{ color:var(--danger); font-weight:600; }
  .success{ color:#3E7A56; font-weight:600; }
  .muted{ color:var(--ink-mute); font-size:.85rem; }
  .preview{ display:flex; gap:1.75rem; align-items:flex-start; flex-wrap:wrap; }
  .preview img{ width:220px; aspect-ratio:4/5; object-fit:cover; border-radius:12px; box-shadow:0 10px 24px -14px rgba(46,52,57,.5); }
  .dropzone{ border:2px dashed var(--cream-dark); border-radius:12px; padding:1.5rem; text-align:center; color:var(--ink-mute); }
  .dropzone input[type=file]{ margin-top:.75rem; }
  .note{ background:#fff; border-left:3px solid var(--gold); border-radius:8px; padding:.9rem 1.1rem; font-size:.9rem; color:var(--ink-mute); margin-top:1.1rem; }
</style>
</head>
<body>
<header>
  <strong>Foto verwalten</strong>
  <?php if ($isLoggedIn): ?>
    <div style="display:flex; gap:1.25rem; align-items:center;">
      <a href="termin-admin.php">Terminverwaltung</a>
      <a href="buchhaltung-admin.php">Buchhaltung</a>
      <form method="post" style="margin:0"><input type="hidden" name="do" value="logout"><button type="submit" class="btn" style="background:none;color:var(--ink-mute)">Abmelden</button></form>
    </div>
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

  <h1>„Über mich"-Foto</h1>
  <?php if ($flashError): ?><p class="error"><?= htmlspecialchars($flashError) ?></p><?php endif; ?>
  <?php if ($flashSuccess): ?><p class="success"><?= htmlspecialchars($flashSuccess) ?></p><?php endif; ?>

  <section class="card">
    <h2>Aktuelles Foto</h2>
    <div class="preview">
      <img src="<?= htmlspecialchars($currentPhotoUrl) ?>" alt="Aktuelles Foto in der Über-mich-Sektion">
      <div style="flex:1; min-width:240px;">
        <p class="muted">So erscheint das Foto (Ausschnitt 4:5) aktuell auf der Startseite im Abschnitt „Über mich".</p>
        <p class="muted"><?= $hasCustomPhoto ? 'Eigenes, hochgeladenes Foto ist aktiv.' : 'Es wird aktuell das Standardfoto angezeigt.' ?></p>
        <?php if ($hasCustomPhoto): ?>
        <form method="post" onsubmit="return confirm('Wirklich auf das Standardfoto zurücksetzen?');">
          <input type="hidden" name="do" value="reset">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit" class="btn btn--ghost">Auf Standardfoto zurücksetzen</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Neues Foto hochladen</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="do" value="upload">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <div class="dropzone">
        <p>JPG oder PNG · mindestens 500 × 500 Pixel · wird automatisch mittig auf das Hochformat 4:5 zugeschnitten</p>
        <input type="file" name="photo" accept="image/jpeg,image/png" required>
      </div>
      <p style="margin-top:1rem"><button type="submit" class="btn">Foto speichern</button></p>
    </form>
    <div class="note">
      Hinweis: Falls der Hintergrund des neuen Fotos KI-generiert ist, muss der Text-Hinweis unterhalb des Fotos auf der Startseite („Hinweis: Der Bildhintergrund wurde mit KI erstellt.") von Hand angepasst werden – das übernimmt diese Seite hier nicht automatisch. Bitte in diesem Fall kurz Bescheid geben.
    </div>
  </section>

<?php endif; ?>
</main>
</body>
</html>
