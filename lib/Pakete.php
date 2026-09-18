<?php
declare(strict_types=1);

require_once __DIR__ . '/Buchhaltung.php';

/**
 * 5-Stunden-Pakete: Kauf, Bezahlt-Markierung, Verbrauchsprotokoll.
 *
 * Lebt in derselben Datenbank wie die Rechnungen (data/buchhaltung.sqlite) –
 * ein Paket ist wirtschaftlich eine Vorauszahlung, unterliegt derselben
 * Aufbewahrungspflicht wie eine Rechnung. Eine bezahlte Paket-Bestellung
 * erzeugt automatisch einen Rechnungseintrag (siehe markPaid()), damit sie
 * ganz normal in der Einnahmenübersicht auftaucht.
 */
final class Pakete
{
    public const HOURS_TOTAL_DEFAULT = 5;
    public const PRICE_CENTS_DEFAULT = 32500; // 325,00 €

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS packages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_name TEXT NOT NULL,
                client_email TEXT NOT NULL,
                client_phone TEXT,
                client_address TEXT,
                hours_total REAL NOT NULL DEFAULT 5,
                price_cents INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT \'offen\',   -- offen (Bestellung angelegt, noch nicht bezahlt) | bezahlt | storniert
                payment_provider TEXT,                    -- stripe | paypal
                payment_reference TEXT,                    -- Stripe Session-ID / PayPal Order-ID
                purchase_token TEXT UNIQUE,                -- an den Klienten gemailt, zum Einlösen einer Stunde bei künftiger Terminbuchung
                invoice_id INTEGER REFERENCES invoices(id),
                created_at TEXT NOT NULL,
                paid_at TEXT
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_packages_token ON packages(purchase_token)');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS package_usage_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                package_id INTEGER NOT NULL REFERENCES packages(id) ON DELETE CASCADE,
                used_hours REAL NOT NULL DEFAULT 1,
                booking_id TEXT,          -- optionale Referenz auf termine.sqlite (andere DB, kein FK), zur Nachvollziehbarkeit
                note TEXT,
                created_at TEXT NOT NULL
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_package_usage_package ON package_usage_log(package_id)');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    }

    /**
     * Legt eine noch unbezahlte Paket-Bestellung an (Status \'offen\'), BEVOR zur
     * Kasse weitergeleitet wird – die Zahlungs-Session braucht eine ID/einen
     * Token, auf den sie sich beziehen kann.
     *
     * @param array{name:string,email:string,phone:?string,address:?string} $client
     * @return array{id:int, purchase_token:string}
     */
    public static function createPending(PDO $pdo, array $client, int $priceCents = self::PRICE_CENTS_DEFAULT, float $hoursTotal = self::HOURS_TOTAL_DEFAULT): array
    {
        self::ensureSchema($pdo);
        $token = bin2hex(random_bytes(24));
        $stmt = $pdo->prepare('
            INSERT INTO packages (client_name, client_email, client_phone, client_address, hours_total, price_cents, status, purchase_token, created_at)
            VALUES (:name, :email, :phone, :addr, :hours, :price, \'offen\', :token, :created)
        ');
        $stmt->execute([
            'name' => $client['name'],
            'email' => $client['email'],
            'phone' => $client['phone'] ?? null,
            'addr' => $client['address'] ?? null,
            'hours' => $hoursTotal,
            'price' => $priceCents,
            'token' => $token,
            'created' => self::now()->format('Y-m-d H:i:s'),
        ]);
        return ['id' => (int) $pdo->lastInsertId(), 'purchase_token' => $token];
    }

    /**
     * Markiert eine Paket-Bestellung als bezahlt und legt automatisch eine
     * bereits bezahlte Rechnung an.
     *
     * Reihenfolge bewusst so gewählt (statt einer gemeinsamen Transaktion, die mit
     * der eigenen BEGIN IMMEDIATE-Transaktion in Buchhaltung::createInvoice() nicht
     * verschachtelbar wäre – SQLite kennt keine Transaktionen in Transaktionen ohne
     * Savepoints): zuerst das Paket per bedingtem UPDATE atomar auf 'bezahlt'
     * setzen (dient zugleich als Idempotenz-Sperre gegen einen erneut zugestellten
     * Stripe-Webhook), danach erst die Rechnung anlegen. Schlägt die Rechnungs-
     * anlage im Anschluss fehl, bleibt das Paket korrekt als bezahlt stehen und es
     * fehlt lediglich eine Rechnung dazu (im Log sichtbar, manuell nachtragbar) –
     * das ist dem Alternativrisiko einer doppelt angelegten Rechnung bei einem
     * erneuten Webhook-Versuch vorzuziehen.
     *
     * @return array{ok:bool, error?:string, already_processed?:bool}
     */
    public static function markPaid(PDO $pdo, int $packageId, string $provider, string $paymentReference): array
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM packages WHERE id = :id');
        $stmt->execute(['id' => $packageId]);
        $pkg = $stmt->fetch();
        if (!$pkg) {
            return ['ok' => false, 'error' => 'Paket nicht gefunden.'];
        }

        $upd = $pdo->prepare("
            UPDATE packages SET status = 'bezahlt', payment_provider = :prov, payment_reference = :ref, paid_at = :paid
            WHERE id = :id AND status != 'bezahlt'
        ");
        $upd->execute([
            'prov' => $provider,
            'ref' => $paymentReference,
            'paid' => self::now()->format('Y-m-d H:i:s'),
            'id' => $packageId,
        ]);
        if ($upd->rowCount() === 0) {
            // War schon 'bezahlt' – doppelt zugestellter Webhook oder paralleler Aufruf.
            return ['ok' => true, 'already_processed' => true];
        }

        $today = self::now()->format('Y-m-d');
        $invoiceResult = Buchhaltung::createInvoice($pdo, [
            'client_name' => $pkg['client_name'],
            'client_address' => $pkg['client_address'],
            'client_email' => $pkg['client_email'],
            'issued_at' => $today,
            'due_date' => null,
            'notes' => 'Online bezahlt über ' . ($provider === 'stripe' ? 'Stripe' : 'PayPal') . ' (Paket-Kauf, automatisch angelegt).',
        ], [[
            'description' => (int) $pkg['hours_total'] . '-Stunden-Paket',
            'detail' => 'Psychologische Beratung / Coaching',
            'item_date' => $today,
            'quantity' => 1,
            'unit_price_cents' => (int) $pkg['price_cents'],
        ]]);
        if (!$invoiceResult['ok']) {
            error_log('Pakete::markPaid: Paket ' . $packageId . ' als bezahlt markiert, aber Rechnung konnte nicht angelegt werden: ' . ($invoiceResult['error'] ?? ''));
            return ['ok' => true];
        }
        Buchhaltung::updateInvoiceStatus($pdo, $invoiceResult['id'], 'bezahlt');
        $link = $pdo->prepare('UPDATE packages SET invoice_id = :inv WHERE id = :id');
        $link->execute(['inv' => $invoiceResult['id'], 'id' => $packageId]);

        return ['ok' => true];
    }

    public static function findById(PDO $pdo, int $id): ?array
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM packages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Für die Einlösung bei einer Terminbuchung: nur bezahlte Pakete mit Restguthaben. */
    public static function findByToken(PDO $pdo, string $token): ?array
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare("SELECT * FROM packages WHERE purchase_token = :t AND status = 'bezahlt'");
        $stmt->execute(['t' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function hoursUsed(PDO $pdo, int $packageId): float
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(used_hours), 0) FROM package_usage_log WHERE package_id = :id');
        $stmt->execute(['id' => $packageId]);
        return (float) $stmt->fetchColumn();
    }

    public static function hoursRemaining(PDO $pdo, array $package): float
    {
        return max(0.0, (float) $package['hours_total'] - self::hoursUsed($pdo, (int) $package['id']));
    }

    /**
     * Bucht Stunden auf ein Paket ab (z. B. wenn ein Termin damit bezahlt wurde,
     * oder wenn die Praxis im Admin eine bereits stattgefundene Sitzung manuell
     * verrechnet). Prüft das Restguthaben, um nicht ins Minus zu verbrauchen.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function logUsage(PDO $pdo, int $packageId, float $usedHours, ?string $bookingId = null, ?string $note = null): array
    {
        $pkg = self::findById($pdo, $packageId);
        if (!$pkg || $pkg['status'] !== 'bezahlt') {
            return ['ok' => false, 'error' => 'Paket nicht gefunden oder nicht bezahlt.'];
        }
        if (self::hoursRemaining($pdo, $pkg) < $usedHours - 0.001) {
            return ['ok' => false, 'error' => 'Nicht genügend Restguthaben auf diesem Paket.'];
        }
        $stmt = $pdo->prepare('
            INSERT INTO package_usage_log (package_id, used_hours, booking_id, note, created_at)
            VALUES (:pid, :hours, :bid, :note, :created)
        ');
        $stmt->execute([
            'pid' => $packageId,
            'hours' => $usedHours,
            'bid' => $bookingId,
            'note' => $note,
            'created' => self::now()->format('Y-m-d H:i:s'),
        ]);
        return ['ok' => true];
    }

    public static function deleteUsageLogEntry(PDO $pdo, int $logId): void
    {
        $stmt = $pdo->prepare('DELETE FROM package_usage_log WHERE id = :id');
        $stmt->execute(['id' => $logId]);
    }

    /** @return array[] Verbrauchsbuchungen eines Pakets, neueste zuerst. */
    public static function usageLog(PDO $pdo, int $packageId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM package_usage_log WHERE package_id = :id ORDER BY created_at DESC, id DESC');
        $stmt->execute(['id' => $packageId]);
        return $stmt->fetchAll();
    }

    /** @return array[] Alle Pakete (für den Admin-Tab), neueste zuerst, inkl. verbrauchter/verbleibender Stunden. */
    public static function listAll(PDO $pdo): array
    {
        self::ensureSchema($pdo);
        $rows = $pdo->query('SELECT * FROM packages ORDER BY created_at DESC')->fetchAll();
        foreach ($rows as &$row) {
            $row['hours_used'] = self::hoursUsed($pdo, (int) $row['id']);
            $row['hours_remaining'] = max(0.0, (float) $row['hours_total'] - $row['hours_used']);
        }
        unset($row);
        return $rows;
    }
}
