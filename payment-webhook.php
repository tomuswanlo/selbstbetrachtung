<?php
declare(strict_types=1);

/**
 * Server-zu-Server-Bestätigung von Stripe/PayPal – DIES ist die verbindliche
 * Zahlungsbestätigung, nicht der Redirect auf payment-erfolg.php (ein Klient
 * könnte diese URL auch ohne bezahlt zu haben manuell aufrufen). Deshalb wird
 * hier ausschließlich anhand einer geprüften Signatur gebucht.
 */

date_default_timezone_set('Europe/Berlin');
header('X-Robots-Tag: noindex, nofollow');

require __DIR__ . '/lib/Buchhaltung.php';
require __DIR__ . '/lib/Pakete.php';
require __DIR__ . '/lib/Vertrag.php';
require __DIR__ . '/lib/Booking.php';
require __DIR__ . '/lib/Payments.php';
require __DIR__ . '/lib/Notifications.php';

$provider = $_GET['provider'] ?? '';
$rawBody = file_get_contents('php://input') ?: '';

function parseContextRef(string $customId): ?array
{
    // "package:12" oder "session:34" oder "invoice:56" -> ['kind'=>..., 'id'=>...]
    if (!preg_match('/^(package|session|invoice):(\d+)$/', $customId, $m)) {
        return null;
    }
    return ['kind' => $m[1], 'id' => (int) $m[2]];
}

if ($provider === 'stripe') {
    $configFile = __DIR__ . '/stripe_config.php';
    if (!file_exists($configFile)) {
        http_response_code(500);
        exit;
    }
    require $configFile;

    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if ($sigHeader === '' || !Payments::verifyStripeWebhookSignature($rawBody, $sigHeader)) {
        error_log('Stripe-Webhook: ungültige Signatur.');
        http_response_code(400);
        exit;
    }

    $event = json_decode($rawBody, true);
    if (!is_array($event)) {
        http_response_code(400);
        exit;
    }

    if (($event['type'] ?? '') === 'checkout.session.completed') {
        $session = $event['data']['object'] ?? [];
        $metadata = $session['metadata'] ?? [];
        $kind = $metadata['kind'] ?? '';
        $referenceId = (int) ($metadata['reference_id'] ?? 0);
        $amountCents = (int) ($session['amount_total'] ?? 0);
        $sessionId = (string) ($session['id'] ?? '');

        if ($kind !== '' && $referenceId > 0 && $sessionId !== '') {
            $result = Payments::recordSuccessfulPayment([
                'kind' => $kind,
                'reference_id' => $referenceId,
                'provider' => 'stripe',
                'payment_reference' => $sessionId,
                'amount_cents' => $amountCents,
            ]);
            if (!$result['ok']) {
                error_log('Stripe-Webhook: Verbuchung fehlgeschlagen: ' . ($result['error'] ?? ''));
            } elseif ($kind === 'session' && empty($result['already_processed'])) {
                sendSessionPaidConfirmation($referenceId);
            } elseif ($kind === 'package' && empty($result['already_processed'])) {
                sendPackagePaidConfirmation($referenceId);
            }
        } else {
            error_log('Stripe-Webhook: unvollständige Metadaten in checkout.session.completed.');
        }
    }

    http_response_code(200);
    echo 'ok';
    exit;
}

if ($provider === 'paypal') {
    $configFile = __DIR__ . '/paypal_config.php';
    if (!file_exists($configFile)) {
        http_response_code(500);
        exit;
    }
    require $configFile;

    $headers = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_LOWER);
    if (!Payments::verifyPaypalWebhook($headers, $rawBody)) {
        error_log('PayPal-Webhook: ungültige Signatur.');
        http_response_code(400);
        exit;
    }

    $event = json_decode($rawBody, true);
    if (!is_array($event)) {
        http_response_code(400);
        exit;
    }

    if (($event['event_type'] ?? '') === 'PAYMENT.CAPTURE.COMPLETED') {
        $resource = $event['resource'] ?? [];
        $customId = (string) ($resource['custom_id'] ?? '');
        $ctx = parseContextRef($customId);
        $valueStr = $resource['amount']['value'] ?? '0';
        $captureId = (string) ($resource['id'] ?? '');

        if ($ctx !== null && $captureId !== '') {
            $result = Payments::recordSuccessfulPayment([
                'kind' => $ctx['kind'],
                'reference_id' => $ctx['id'],
                'provider' => 'paypal',
                'payment_reference' => $captureId,
                'amount_cents' => (int) round(((float) $valueStr) * 100),
            ]);
            if (!$result['ok']) {
                error_log('PayPal-Webhook: Verbuchung fehlgeschlagen: ' . ($result['error'] ?? ''));
            } elseif ($ctx['kind'] === 'session' && empty($result['already_processed'])) {
                sendSessionPaidConfirmation($ctx['id']);
            } elseif ($ctx['kind'] === 'package' && empty($result['already_processed'])) {
                sendPackagePaidConfirmation($ctx['id']);
            }
        } else {
            error_log('PayPal-Webhook: custom_id nicht auswertbar: ' . $customId);
        }
    }

    http_response_code(200);
    echo 'ok';
    exit;
}

http_response_code(400);
