<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

require __DIR__ . '/lib/Buchhaltung.php';
require __DIR__ . '/lib/Pakete.php';
require __DIR__ . '/lib/Vertrag.php';
require __DIR__ . '/lib/Payments.php';

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function field(string $name): string
{
    return trim((string) ($_POST[$name] ?? ''));
}

function baseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'selbstbetrachtung-online.de';
    return $scheme . '://' . $host;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'Methode nicht erlaubt.'], 405);
}

// --- Spam-Abwehr (wie contact.php/termin-api.php) -------------------------
$isHoneypotTriggered = field('hp_confirm2') !== '';
$renderedAt = (float) field('ts');
$isTooFast = $renderedAt > 0 && (microtime(true) * 1000 - $renderedAt) < 3000;
if ($isHoneypotTriggered || $isTooFast) {
    respond(['ok' => false, 'error' => 'Bitte versuchen Sie es erneut.'], 422);
}

$smtpConfigFile = __DIR__ . '/smtp_config.php';
if (file_exists($smtpConfigFile)) {
    require $smtpConfigFile;
}
if (defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '') {
    $token = field('cf-turnstile-response');
    $verified = false;
    if ($token !== '') {
        $payload = http_build_query([
            'secret' => TURNSTILE_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $payload,
            'timeout' => 8,
        ]]);
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

// --- Eingaben validieren ---------------------------------------------------
$name = field('name');
$strasse = field('strasse');
$plz = field('plz');
$ort = field('ort');
$email = field('email');
$phone = field('phone');
$geburtsdatum = field('geburtsdatum');
$consentVertrag = field('consent_vertrag') !== '';
$consentDatenschutz = field('consent_datenschutz') !== '';
$provider = field('provider');

$errors = [];
if ($name === '') { $errors[] = 'name'; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'email'; }
if (!$consentVertrag) { $errors[] = 'consent_vertrag'; }
if (!$consentDatenschutz) { $errors[] = 'consent_datenschutz'; }
if (!in_array($provider, ['stripe', 'paypal'], true)) { $errors[] = 'provider'; }
if ($errors) {
    respond(['ok' => false, 'error' => 'Bitte füllen Sie die markierten Felder aus.', 'fields' => $errors], 422);
}

$addressParts = array_filter([$strasse, trim($plz . ' ' . $ort)]);
$address = $addressParts ? implode("\n", $addressParts) : null;

$buchhaltungPdo = Buchhaltung::db();

$pending = Pakete::createPending($buchhaltungPdo, [
    'name' => $name,
    'email' => $email,
    'phone' => $phone !== '' ? $phone : null,
    'address' => $address,
]);

Vertrag::recordConsent($buchhaltungPdo, 'package', $pending['id'], [
    'name' => $name,
    'address' => $address,
    'email' => $email,
    'phone' => $phone !== '' ? $phone : null,
    'geburtsdatum' => $geburtsdatum !== '' ? $geburtsdatum : null,
]);

$description = Pakete::HOURS_TOTAL_DEFAULT . '-Stunden-Paket – Selbstbetrachtung';
$cancelUrl = baseUrl() . '/paket-kaufen.php';

if ($provider === 'stripe') {
    if (!file_exists(__DIR__ . '/stripe_config.php')) {
        respond(['ok' => false, 'error' => 'Online-Zahlung ist derzeit nicht verfügbar.'], 500);
    }
    require __DIR__ . '/stripe_config.php';
    $successUrl = baseUrl() . '/payment-erfolg.php?provider=stripe&kind=package&ref=' . $pending['id'] . '&session_id={CHECKOUT_SESSION_ID}';
    $result = Payments::createStripeCheckoutSession(
        Pakete::PRICE_CENTS_DEFAULT,
        $description,
        $successUrl,
        $cancelUrl,
        ['kind' => 'package', 'reference_id' => (string) $pending['id']],
        $email
    );
} else {
    if (!file_exists(__DIR__ . '/paypal_config.php')) {
        respond(['ok' => false, 'error' => 'Online-Zahlung ist derzeit nicht verfügbar.'], 500);
    }
    require __DIR__ . '/paypal_config.php';
    $returnUrl = baseUrl() . '/payment-erfolg.php?provider=paypal&kind=package&ref=' . $pending['id'];
    $result = Payments::createPaypalOrder(
        Pakete::PRICE_CENTS_DEFAULT,
        $description,
        $returnUrl,
        $cancelUrl,
        'package:' . $pending['id']
    );
}

if (!$result['ok']) {
    respond(['ok' => false, 'error' => $result['error'] ?? 'Die Zahlung konnte nicht gestartet werden.'], 502);
}

respond(['ok' => true, 'redirect_url' => $result['url']]);
