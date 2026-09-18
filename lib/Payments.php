<?php
declare(strict_types=1);

/**
 * Online-Zahlungsanbindung (Stripe + PayPal), per REST/cURL angesprochen –
 * bewusst ohne Composer/Vendor-SDK, im selben Stil wie die bestehende
 * Cloudflare-Turnstile-Prüfung in contact.php/termin-api.php.
 *
 * Diese Klasse kennt nichts von "Terminen", "Paketen" oder "Rechnungen" im
 * fachlichen Sinn – sie schafft nur Checkout-Sitzungen/Bestellungen, prüft
 * Webhook-Signaturen und bietet mit recordSuccessfulPayment() EINE zentrale
 * Stelle, an der eine bestätigte Zahlung fachlich verbucht wird (Termin als
 * bezahlt markieren, Paket aktivieren, Rechnung auf "bezahlt" setzen) UND
 * automatisch einen "bezahlt"-Rechnungseintrag in der Buchhaltung anlegt,
 * damit Online-Zahlungen ganz normal in der Einnahmenübersicht/im CSV-Export
 * auftauchen – keine Parallel-Buchführung an der Buchhaltung vorbei.
 *
 * Konfiguration kommt aus stripe_config.php / paypal_config.php (Repo-Root,
 * beide gitignored, siehe smtp_config.php als Vorbild). Jede Funktion prüft
 * die benötigten Konstanten selbst und liefert im Fehlerfall ['ok'=>false,...]
 * statt eine halbkonfigurierte Zahlung zu versuchen.
 */
final class Payments
{
    // ------------------------------------------------------------------
    // Stripe
    // ------------------------------------------------------------------

    /**
     * Legt eine Stripe Checkout Session (mode=payment) an und liefert die
     * URL, zu der der Klient weitergeleitet werden soll.
     *
     * @param array<string,string> $metadata wird 1:1 an die Session gehängt und
     *   kommt im Webhook-Event zurück – so weiß recordSuccessfulPayment(), wofür
     *   bezahlt wurde (kind + zugehörige ID), ohne einen eigenen Zwischenspeicher.
     * @return array{ok:bool, url?:string, session_id?:string, error?:string}
     */
    public static function createStripeCheckoutSession(
        int $amountCents,
        string $description,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        ?string $customerEmail = null
    ): array {
        if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
            return ['ok' => false, 'error' => 'Stripe ist nicht konfiguriert.'];
        }
        if ($amountCents < 50) { // Stripe-Mindestbetrag (EUR ~0,50)
            return ['ok' => false, 'error' => 'Ungültiger Betrag.'];
        }

        $params = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            // Kein 'payment_method_types' angegeben: Checkout Session zeigt dann automatisch
            // die Zahlungsarten, die im Stripe-Dashboard unter "Zahlungsmethoden" aktiviert
            // sind (Karte, SEPA-Lastschrift, ...) - das ist der aktuelle empfohlene Weg,
            // 'automatic_payment_methods' (PaymentIntents-Parameter) gibt es hier nicht.
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => 'eur',
            'line_items[0][price_data][unit_amount]' => (string) $amountCents,
            'line_items[0][price_data][product_data][name]' => $description,
        ];
        if ($customerEmail !== null && $customerEmail !== '') {
            $params['customer_email'] = $customerEmail;
        }
        foreach ($metadata as $k => $v) {
            $params["metadata[$k]"] = (string) $v;
        }

        $result = self::stripeRequest('POST', '/v1/checkout/sessions', $params);
        if (!$result['ok']) {
            return $result;
        }
        $body = $result['body'];
        if (!isset($body['url'], $body['id'])) {
            return ['ok' => false, 'error' => 'Unerwartete Antwort von Stripe.'];
        }
        return ['ok' => true, 'url' => $body['url'], 'session_id' => $body['id']];
    }

    /** Liest eine Checkout Session zurück (z. B. für eine freundliche Statusanzeige, nicht für Buchungsentscheidungen). */
    public static function retrieveStripeSession(string $sessionId): array
    {
        if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
            return ['ok' => false, 'error' => 'Stripe ist nicht konfiguriert.'];
        }
        return self::stripeRequest('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), []);
    }

    /**
     * Verifiziert die "Stripe-Signature"-Kopfzeile eines Webhook-Requests gegen
     * STRIPE_WEBHOOK_SECRET (HMAC-SHA256 über "{timestamp}.{rawBody}", siehe
     * Stripe-Doku "Verify webhook signatures"). Zeitsicherer Vergleich per
     * hash_equals wie beim Passwort-/CSRF-Vergleich in termin-admin.php.
     * Zusätzlich wird der Zeitstempel auf max. 5 Minuten Abweichung geprüft
     * (Schutz gegen Replay eines alten, mitgeschnittenen Requests).
     */
    public static function verifyStripeWebhookSignature(string $rawBody, string $sigHeader): bool
    {
        if (!defined('STRIPE_WEBHOOK_SECRET') || STRIPE_WEBHOOK_SECRET === '') {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $sigHeader) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $signatures[] = substr($part, 3);
            }
        }
        if ($timestamp === null || !$signatures) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        // trim(): gleicher Grund wie bei STRIPE_SECRET_KEY in stripeRequest() – schützt
        // vor unsichtbaren Zeichen aus manuellem Copy-Paste in stripe_config.php.
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, trim(STRIPE_WEBHOOK_SECRET));
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{ok:bool, body?:array, error?:string} */
    private static function stripeRequest(string $method, string $path, array $formParams): array
    {
        $ch = curl_init('https://api.stripe.com' . $path);
        // trim(): schützt gegen unsichtbare Leerzeichen/Zeilenumbrüche, die beim manuellen
        // Einfügen des Keys in stripe_config.php (z.B. über den Plesk-Datei-Editor) leicht
        // mitkopiert werden und Stripe sonst mit "Invalid API Key provided" quittiert.
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERPWD => trim(STRIPE_SECRET_KEY) . ':',
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($formParams);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            error_log('Stripe-Request fehlgeschlagen: ' . $curlError);
            return ['ok' => false, 'error' => 'Stripe war nicht erreichbar.'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Unerwartete Antwort von Stripe.'];
        }
        if ($status >= 400) {
            error_log('Stripe-API-Fehler (' . $status . '): ' . $raw);
            return ['ok' => false, 'error' => $decoded['error']['message'] ?? 'Stripe-Fehler.'];
        }
        return ['ok' => true, 'body' => $decoded];
    }

    // ------------------------------------------------------------------
    // PayPal
    // ------------------------------------------------------------------

    private static ?string $paypalTokenCache = null;

    private static function paypalBaseUrl(): string
    {
        return (defined('PAYPAL_SANDBOX') && PAYPAL_SANDBOX)
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    /** @return array{ok:bool, token?:string, error?:string} */
    private static function paypalAccessToken(): array
    {
        if (self::$paypalTokenCache !== null) {
            return ['ok' => true, 'token' => self::$paypalTokenCache];
        }
        if (!defined('PAYPAL_CLIENT_ID') || !defined('PAYPAL_CLIENT_SECRET')) {
            return ['ok' => false, 'error' => 'PayPal ist nicht konfiguriert.'];
        }
        $ch = curl_init(self::paypalBaseUrl() . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERPWD => trim(PAYPAL_CLIENT_ID) . ':' . trim(PAYPAL_CLIENT_SECRET),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($status >= 400 || !is_array($decoded) || empty($decoded['access_token'])) {
            error_log('PayPal-OAuth fehlgeschlagen (' . $status . '): ' . (string) $raw);
            return ['ok' => false, 'error' => 'PayPal-Anmeldung fehlgeschlagen.'];
        }
        self::$paypalTokenCache = $decoded['access_token'];
        return ['ok' => true, 'token' => self::$paypalTokenCache];
    }

    /**
     * Legt eine PayPal-Order (intent=CAPTURE) an und liefert die Genehmigungs-URL,
     * zu der der Klient weitergeleitet wird. custom_id trägt dieselbe Kontext-
     * Information wie Stripes metadata (kind + ID, als "kind:id" kodiert, da
     * custom_id nur ein einzelner String ist).
     *
     * @return array{ok:bool, url?:string, order_id?:string, error?:string}
     */
    public static function createPaypalOrder(
        int $amountCents,
        string $description,
        string $returnUrl,
        string $cancelUrl,
        string $customId
    ): array {
        $auth = self::paypalAccessToken();
        if (!$auth['ok']) {
            return $auth;
        }
        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => 'EUR',
                    'value' => number_format($amountCents / 100, 2, '.', ''),
                ],
                'description' => mb_substr($description, 0, 127),
                'custom_id' => mb_substr($customId, 0, 127),
            ]],
            'application_context' => [
                'brand_name' => 'Selbstbetrachtung',
                'locale' => 'de-DE',
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING', // Dienstleistung, kein Versand
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ];
        $result = self::paypalRequest('POST', '/v2/checkout/orders', $auth['token'], $body);
        if (!$result['ok']) {
            return $result;
        }
        $approveUrl = null;
        foreach ($result['body']['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'approve') {
                $approveUrl = $link['href'];
                break;
            }
        }
        if ($approveUrl === null || empty($result['body']['id'])) {
            return ['ok' => false, 'error' => 'Unerwartete Antwort von PayPal.'];
        }
        return ['ok' => true, 'url' => $approveUrl, 'order_id' => $result['body']['id']];
    }

    /**
     * Erfasst die Zahlung einer zuvor genehmigten PayPal-Order (Klient wurde nach
     * Zustimmung auf die return_url zurückgeleitet). Das ist bei PayPal der
     * eigentliche, verbindliche Zahlungsvorgang – bis hierhin ist nur reserviert,
     * nicht bezahlt. Liefert bei Erfolg auch custom_id/amount zur Weiterverbuchung.
     *
     * @return array{ok:bool, status?:string, custom_id?:string, amount_cents?:int, error?:string}
     */
    public static function capturePaypalOrder(string $orderId): array
    {
        $auth = self::paypalAccessToken();
        if (!$auth['ok']) {
            return $auth;
        }
        $result = self::paypalRequest('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', $auth['token'], (object) []);
        if (!$result['ok']) {
            return $result;
        }
        $status = $result['body']['status'] ?? '';
        $unit = $result['body']['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? null;
        if ($status !== 'COMPLETED' || $capture === null || ($capture['status'] ?? '') !== 'COMPLETED') {
            error_log('PayPal-Capture nicht abgeschlossen: ' . json_encode($result['body']));
            return ['ok' => false, 'error' => 'Zahlung konnte nicht abgeschlossen werden.'];
        }
        $valueStr = $capture['amount']['value'] ?? '0';
        return [
            'ok' => true,
            'status' => $status,
            'custom_id' => (string) ($unit['custom_id'] ?? ''),
            'amount_cents' => (int) round(((float) $valueStr) * 100),
        ];
    }

    /**
     * PayPal empfiehlt keine lokale Signaturprüfung, sondern den serverseitigen
     * Verify-Aufruf mit den empfangenen Headern + Rohkörper + der eigenen
     * Webhook-ID (aus dem PayPal-Entwicklerportal, beim Anlegen des Webhooks
     * vergeben – PAYPAL_WEBHOOK_ID in paypal_config.php).
     *
     * @param array<string,string> $headers Original-HTTP-Header des eingehenden Requests
     */
    public static function verifyPaypalWebhook(array $headers, string $rawBody): bool
    {
        if (!defined('PAYPAL_WEBHOOK_ID') || PAYPAL_WEBHOOK_ID === '') {
            return false;
        }
        $auth = self::paypalAccessToken();
        if (!$auth['ok']) {
            return false;
        }
        $eventBody = json_decode($rawBody, true);
        if (!is_array($eventBody)) {
            return false;
        }
        $payload = [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => trim(PAYPAL_WEBHOOK_ID),
            'webhook_event' => $eventBody,
        ];
        $result = self::paypalRequest('POST', '/v1/notifications/verify-webhook-signature', $auth['token'], $payload);
        return $result['ok'] && (($result['body']['verification_status'] ?? '') === 'SUCCESS');
    }

    /** @return array{ok:bool, body?:array, error?:string} */
    private static function paypalRequest(string $method, string $path, string $token, array|object $jsonBody): array
    {
        $ch = curl_init(self::paypalBaseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($jsonBody),
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            error_log('PayPal-Request fehlgeschlagen: ' . $curlError);
            return ['ok' => false, 'error' => 'PayPal war nicht erreichbar.'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        if ($status >= 400) {
            error_log('PayPal-API-Fehler (' . $status . '): ' . $raw);
            return ['ok' => false, 'error' => $decoded['message'] ?? 'PayPal-Fehler.'];
        }
        return ['ok' => true, 'body' => $decoded];
    }

    // ------------------------------------------------------------------
    // Zentrale Verbuchung – EINE Stelle für alle drei Zahlungsanlässe
    // ------------------------------------------------------------------

    /**
     * Verbucht eine bestätigte Zahlung fachlich und legt automatisch einen
     * bezahlten Rechnungseintrag in der Buchhaltung an (Zuflussprinzip:
     * paid_at = jetzt). Muss idempotent sein, da Stripe Webhooks u. U.
     * mehrfach zustellt (Networking-Retries) – prüft deshalb jeweils vor dem
     * Schreiben, ob dieser Vorgang nicht bereits verbucht ist.
     *
     * @param array{kind:string, reference_id:int, provider:string, payment_reference:string, amount_cents:int} $payment
     * @return array{ok:bool, error?:string, already_processed?:bool}
     */
    public static function recordSuccessfulPayment(array $payment): array
    {
        $kind = $payment['kind'];
        $buchhaltungPdo = Buchhaltung::db();

        if ($kind === 'package') {
            require_once __DIR__ . '/Pakete.php';
            return Pakete::markPaid($buchhaltungPdo, $payment['reference_id'], $payment['provider'], $payment['payment_reference']);
        }

        if ($kind === 'session') {
            require_once __DIR__ . '/Booking.php';
            $bookingPdo = Booking::db();
            return Booking::markSessionPaid($bookingPdo, $buchhaltungPdo, $payment['reference_id'], $payment['provider'], $payment['payment_reference'], $payment['amount_cents']);
        }

        if ($kind === 'invoice') {
            $invoice = Buchhaltung::findInvoice($buchhaltungPdo, $payment['reference_id']);
            if ($invoice === null) {
                return ['ok' => false, 'error' => 'Rechnung nicht gefunden.'];
            }
            if ($invoice['status'] === 'bezahlt') {
                return ['ok' => true, 'already_processed' => true];
            }
            Buchhaltung::updateInvoiceStatus($buchhaltungPdo, $payment['reference_id'], 'bezahlt');
            return ['ok' => true];
        }

        return ['ok' => false, 'error' => 'Unbekannte Zahlungsart.'];
    }
}
