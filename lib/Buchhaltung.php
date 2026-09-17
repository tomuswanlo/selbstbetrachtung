<?php
declare(strict_types=1);

/**
 * Praxis-Buchhaltung: SQLite-Datenzugriff für Einstellungen und Rechnungen
 * (Positionsrechnungen, mehrere Leistungen pro Rechnung).
 *
 * Eigene Datenbankdatei (data/buchhaltung.sqlite), bewusst getrennt von
 * termine.sqlite: Klienten-Termindaten unterliegen dort einer 3-Monats-
 * Löschfrist (DSGVO, siehe Booking::purgeOldBookings()), Finanzunterlagen
 * dagegen einer gesetzlichen Aufbewahrungspflicht von i. d. R. 10 Jahren
 * (§147 AO, §14b UStG) und dürfen deshalb nicht denselben Lebenszyklus teilen.
 * Beim Anlegen einer Rechnungsposition aus einem Termin (siehe
 * buchhaltung-admin.php) werden die nötigen Daten kopiert, nicht live
 * referenziert – die Rechnung bleibt also erhalten, auch wenn der
 * Termin-Datensatz später gelöscht wird.
 *
 * Ausgaben/Belege werden bewusst NICHT hier verwaltet, sondern als
 * Excel-Arbeitsmappe unter D:\Selbstbetrachtung\DOX\Belege\ gepflegt
 * (eingehende Rechnungen werden dort abgelegt und auf Zuruf eingelesen/
 * einsortiert) – siehe Projekt-Gedächtnis.
 *
 * Geldbeträge werden durchgängig als Ganzzahl-Cent gespeichert/übergeben,
 * um Float-Rundungsfehler zu vermeiden; nur an der Formular-Grenze wird
 * einmalig von/nach einer Dezimal-Zeichenkette umgerechnet.
 */
final class Buchhaltung
{
    private static ?PDO $pdo = null;

    public static function db(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dataDir = __DIR__ . '/../data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0775, true);
        }
        $dbFile = $dataDir . '/buchhaltung.sqlite';
        $isNew = !file_exists($dbFile);

        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$pdo = $pdo;
        self::ensureSchema($pdo);

        if ($isNew) {
            @chmod($dbFile, 0664);
        }

        return $pdo;
    }

    private static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ');

        // Migration von der allerersten Version (ein Betrag/Beschreibung pro Rechnung,
        // ohne due_date/client_email) auf das aktuelle Positionsrechnungs-Schema.
        // Muss VOR dem CREATE TABLE IF NOT EXISTS laufen, sonst bleibt eine bereits
        // bestehende Alt-Tabelle unverändert (kein automatisches ALTER durch SQLite).
        self::migrateLegacyInvoicesTable($pdo);

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_number TEXT NOT NULL UNIQUE,   -- fortlaufend, Format JJJJ-NNN
                client_name TEXT NOT NULL,             -- Person oder Firma
                client_address TEXT,
                client_email TEXT,
                amount_cents INTEGER NOT NULL,         -- Summen-Cache über invoice_items, siehe recalcInvoiceAmount()
                status TEXT NOT NULL DEFAULT \'offen\',  -- offen | bezahlt | storniert
                issued_at TEXT NOT NULL,         -- \'YYYY-MM-DD\', Rechnungsdatum
                due_date TEXT,                   -- \'YYYY-MM-DD\', Fälligkeitsdatum
                paid_at TEXT,                    -- \'YYYY-MM-DD\', Zahlungseingang (Zufluss – zählt als Einnahme)
                notes TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoices_paid ON invoices(paid_at)');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS invoice_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
                position INTEGER NOT NULL,       -- Sortierreihenfolge, 0-basiert
                description TEXT NOT NULL,       -- Bezeichnung der Leistung
                detail TEXT,                     -- Beschreibung/Zusatz
                item_date TEXT,                  -- \'YYYY-MM-DD\', Datum der Leistung
                quantity REAL NOT NULL DEFAULT 1,
                unit_price_cents INTEGER NOT NULL
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice ON invoice_items(invoice_id)');
    }

    /**
     * Migriert eine `invoices`-Tabelle aus der allerersten Version (ein Betrag/eine
     * Beschreibung pro Rechnung, Spalten `description`/`session_date`/`session_type`,
     * keine `due_date`/`client_email`) auf das aktuelle Schema. SQLite kann eine
     * NOT-NULL-Spalte nicht per ALTER TABLE entfernen, deshalb: Tabelle umbenennen,
     * neu anlegen, Daten spaltenweise übernehmen, alte Beschreibung/Betrag als
     * einzelne invoice_items-Position rekonstruieren, damit nichts verloren geht.
     * No-op, wenn die Tabelle nicht existiert (Erstinstallation) oder bereits aktuell ist.
     */
    private static function migrateLegacyInvoicesTable(PDO $pdo): void
    {
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='invoices'")->fetchColumn();
        if (!$exists) {
            return;
        }
        $cols = array_column($pdo->query('PRAGMA table_info(invoices)')->fetchAll(), 'name');
        if (in_array('due_date', $cols, true) && in_array('client_email', $cols, true)) {
            return; // schon aktuelles Schema
        }

        // WICHTIG: invoice_items darf während des Umbenennens nicht (mehr) existieren.
        // SQLite lässt beim ALTER TABLE ... RENAME die REFERENCES-Klausel abhängiger
        // Tabellen automatisch mitwandern (invoice_items würde danach dauerhaft auf
        // "invoices_legacy" statt "invoices" verweisen, auch nach COMMIT) – jede spätere
        // Rechnung würde dann mit "no such table: invoices_legacy" fehlschlagen. Ein
        // Zwischendeploy hat invoice_items evtl. schon (leer, per CREATE TABLE IF NOT
        // EXISTS) angelegt, deshalb hier vorsorglich droppen und ganz am Ende – nach dem
        // Umbenennen und Löschen von invoices_legacy, mit "invoices" in seiner
        // endgültigen Form – sauber neu anlegen. Alte Positionsdaten vorher nach PHP
        // auslesen, da invoices_legacy zu dem Zeitpunkt schon weg ist.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $legacyRows = $pdo->query('SELECT * FROM invoices')->fetchAll();

            $pdo->exec('DROP TABLE IF EXISTS invoice_items');
            $pdo->exec('ALTER TABLE invoices RENAME TO invoices_legacy');
            $pdo->exec('
                CREATE TABLE invoices (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    invoice_number TEXT NOT NULL UNIQUE,
                    client_name TEXT NOT NULL,
                    client_address TEXT,
                    client_email TEXT,
                    amount_cents INTEGER NOT NULL,
                    status TEXT NOT NULL DEFAULT \'offen\',
                    issued_at TEXT NOT NULL,
                    due_date TEXT,
                    paid_at TEXT,
                    notes TEXT,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )
            ');
            $legacyCols = array_column($pdo->query('PRAGMA table_info(invoices_legacy)')->fetchAll(), 'name');
            $expr = static fn(string $col): string => in_array($col, $legacyCols, true) ? $col : 'NULL';
            $pdo->exec('
                INSERT INTO invoices (id, invoice_number, client_name, client_address, client_email, amount_cents, status, issued_at, due_date, paid_at, notes, created_at, updated_at)
                SELECT id, invoice_number, client_name, client_address, ' . $expr('client_email') . ', amount_cents, status, issued_at, ' . $expr('due_date') . ', paid_at, notes, created_at, updated_at
                FROM invoices_legacy
            ');
            $pdo->exec('DROP TABLE invoices_legacy');

            // Erst jetzt, mit "invoices" in seiner endgültigen Form und ohne dass je eine
            // Umbenennung stattgefunden hätte, invoice_items mit sauberer FK anlegen.
            $pdo->exec('
                CREATE TABLE IF NOT EXISTS invoice_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
                    position INTEGER NOT NULL,
                    description TEXT NOT NULL,
                    detail TEXT,
                    item_date TEXT,
                    quantity REAL NOT NULL DEFAULT 1,
                    unit_price_cents INTEGER NOT NULL
                )
            ');

            // Alte Beschreibung/Betrag je Rechnung als eine invoice_items-Position rekonstruieren,
            // damit bestehende Rechnungen weiter eine (Mindest-)Position haben. Aus den vorher
            // ausgelesenen PHP-Zeilen, da invoices_legacy zu diesem Zeitpunkt schon weg ist.
            if ($legacyRows && array_key_exists('description', $legacyRows[0])) {
                $itemStmt = $pdo->prepare('
                    INSERT INTO invoice_items (invoice_id, position, description, detail, item_date, quantity, unit_price_cents)
                    VALUES (:iid, 0, :desc, :detail, :date, 1, :price)
                ');
                foreach ($legacyRows as $row) {
                    $itemStmt->execute([
                        'iid' => $row['id'],
                        'desc' => ($row['description'] ?? '') !== '' ? $row['description'] : 'Leistung',
                        'detail' => $row['session_type'] ?? null,
                        'date' => $row['session_date'] ?? null,
                        'price' => $row['amount_cents'],
                    ]);
                }
            }

            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    }

    // ------------------------------------------------------------------
    // Geldbeträge
    // ------------------------------------------------------------------

    /** Wandelt eine Formulareingabe (\'123,45\' oder \'123.45\') in Cent um, oder null bei ungültiger Eingabe. */
    public static function parseAmountToCents(string $input): ?int
    {
        $normalized = str_replace(',', '.', trim($input));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            return null;
        }
        return (int) round(((float) $normalized) * 100);
    }

    /** Für Formular-Eingabefelder: \'123.45\' (Punkt; beim Absenden per parseAmountToCents wieder eingelesen). */
    public static function centsToInputValue(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function formatEuro(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        return $sign . number_format(abs($cents) / 100, 2, ',', '.') . ' €';
    }

    // ------------------------------------------------------------------
    // Einstellungen
    // ------------------------------------------------------------------

    /** Vorbelegte Absenderdaten aus dem öffentlichen Impressum – nur Startwert, in den Einstellungen änderbar. */
    public const DEFAULT_SENDER_NAME = 'Gabriele Küppers';
    public const DEFAULT_SENDER_ADDRESS = "Dachsweg 27\n41189 Mönchengladbach";
    public const DEFAULT_SENDER_TAXID = '121/5225/6707';
    public const DEFAULT_KLEINUNTERNEHMER_HINWEIS = 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet (Kleinunternehmerregelung).';
    public const DEFAULT_PAYMENT_TERMS = 'Bitte überweisen Sie den Betrag innerhalb von 14 Tagen unter Angabe der Rechnungsnummer.';

    /** @return array<string,string> alle Einstellungen als key => value */
    public static function allSettings(PDO $pdo): array
    {
        $defaults = [
            'sender_name' => self::DEFAULT_SENDER_NAME,
            'sender_address' => self::DEFAULT_SENDER_ADDRESS,
            'sender_taxid' => self::DEFAULT_SENDER_TAXID,
            'sender_bank_inhaber' => self::DEFAULT_SENDER_NAME,
            'sender_bank_name' => '',
            'sender_bank_iban' => '',
            'sender_bank_bic' => '',
            'kleinunternehmer_hinweis' => self::DEFAULT_KLEINUNTERNEHMER_HINWEIS,
            'payment_terms_note' => self::DEFAULT_PAYMENT_TERMS,
            'price_erstgespraech_cents' => '',
            'price_folgetermin_cents' => '',
        ];
        $stmt = $pdo->query('SELECT key, value FROM settings');
        foreach ($stmt->fetchAll() as $row) {
            $defaults[$row['key']] = (string) $row['value'];
        }
        return $defaults;
    }

    public static function setSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute(['k' => $key, 'v' => $value]);
    }

    // ------------------------------------------------------------------
    // Rechnungsnummern
    // ------------------------------------------------------------------

    /** Nächste fortlaufende Rechnungsnummer im Format JJJJ-NNN (beginnt pro Jahr neu bei 001). */
    public static function nextInvoiceNumber(PDO $pdo, ?int $year = null): string
    {
        if ($year === null) {
            $year = (int) self::now()->format('Y');
        }
        $stmt = $pdo->prepare('SELECT invoice_number FROM invoices WHERE invoice_number LIKE :prefix ORDER BY invoice_number DESC LIMIT 1');
        $stmt->execute(['prefix' => $year . '-%']);
        $last = $stmt->fetchColumn();
        $next = 1;
        if ($last && preg_match('/^\d{4}-(\d+)$/', (string) $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }
        return sprintf('%d-%03d', $year, $next);
    }

    // ------------------------------------------------------------------
    // Rechnungen (Kopf + Positionen)
    // ------------------------------------------------------------------

    /**
     * Legt eine Rechnung mit ihren Positionen an. Vergibt die nächste fortlaufende
     * Nummer und berechnet die Summe innerhalb derselben Transaktion (BEGIN
     * IMMEDIATE), damit zwei nahezu gleichzeitige Aufrufe niemals dieselbe Nummer
     * doppelt vergeben – analog zum Race-Condition-Schutz in Booking::createBooking().
     *
     * @param array{client_name:string,client_address:?string,client_email:?string,issued_at:string,due_date:?string,notes:?string} $header
     * @param array<int,array{description:string,detail:?string,item_date:?string,quantity:float,unit_price_cents:int}> $items mindestens 1 Position
     * @return array{ok:bool,id?:int,invoice_number?:string,error?:string}
     */
    public static function createInvoice(PDO $pdo, array $header, array $items): array
    {
        if (!$items) {
            return ['ok' => false, 'error' => 'Eine Rechnung braucht mindestens eine Position.'];
        }

        $now = self::now()->format('Y-m-d H:i:s');
        $year = (int) substr($header['issued_at'], 0, 4);
        $amountCents = 0;
        foreach ($items as $item) {
            $amountCents += (int) round($item['quantity'] * $item['unit_price_cents']);
        }

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $number = self::nextInvoiceNumber($pdo, $year);
            $stmt = $pdo->prepare('
                INSERT INTO invoices (invoice_number, client_name, client_address, client_email, amount_cents, status, issued_at, due_date, paid_at, notes, created_at, updated_at)
                VALUES (:num, :name, :addr, :mail, :amount, \'offen\', :issued, :due, NULL, :notes, :created, :created)
            ');
            $stmt->execute([
                'num' => $number,
                'name' => $header['client_name'],
                'addr' => $header['client_address'],
                'mail' => $header['client_email'],
                'amount' => $amountCents,
                'issued' => $header['issued_at'],
                'due' => $header['due_date'],
                'notes' => $header['notes'],
                'created' => $now,
            ]);
            $invoiceId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO invoice_items (invoice_id, position, description, detail, item_date, quantity, unit_price_cents)
                VALUES (:iid, :pos, :desc, :detail, :date, :qty, :price)
            ');
            foreach (array_values($items) as $i => $item) {
                $itemStmt->execute([
                    'iid' => $invoiceId,
                    'pos' => $i,
                    'desc' => $item['description'],
                    'detail' => $item['detail'],
                    'date' => $item['item_date'],
                    'qty' => $item['quantity'],
                    'price' => $item['unit_price_cents'],
                ]);
            }

            $pdo->exec('COMMIT');
            return ['ok' => true, 'id' => $invoiceId, 'invoice_number' => $number];
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            return ['ok' => false, 'error' => 'Die Rechnung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.'];
        }
    }

    /**
     * Setzt den Status einer Rechnung. \'bezahlt\' trägt automatisch das heutige Datum als
     * Zahlungseingang (paid_at) ein – zählt als Zufluss für die Einnahmen-Überschuss-Rechnung.
     * Rechnungen werden absichtlich nie gelöscht (nur storniert), damit die fortlaufende
     * Rechnungsnummer lückenlos nachvollziehbar bleibt (GoBD).
     */
    public static function updateInvoiceStatus(PDO $pdo, int $id, string $status): void
    {
        if (!in_array($status, ['offen', 'bezahlt', 'storniert'], true)) {
            return;
        }
        $now = self::now()->format('Y-m-d H:i:s');
        if ($status === 'bezahlt') {
            $stmt = $pdo->prepare('UPDATE invoices SET status = :s, paid_at = :p, updated_at = :u WHERE id = :id');
            $stmt->execute(['s' => $status, 'p' => self::now()->format('Y-m-d'), 'u' => $now, 'id' => $id]);
        } else {
            $stmt = $pdo->prepare('UPDATE invoices SET status = :s, updated_at = :u WHERE id = :id');
            $stmt->execute(['s' => $status, 'u' => $now, 'id' => $id]);
        }
    }

    public static function findInvoice(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['items'] = self::listInvoiceItems($pdo, $id);
        return $row;
    }

    /** @return array[] Positionen einer Rechnung, in gespeicherter Reihenfolge. */
    public static function listInvoiceItems(PDO $pdo, int $invoiceId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY position');
        $stmt->execute(['id' => $invoiceId]);
        return $stmt->fetchAll();
    }

    /** @return array[] Alle Rechnungen, neueste zuerst. */
    public static function listInvoices(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM invoices ORDER BY issued_at DESC, id DESC')->fetchAll();
    }

    // ------------------------------------------------------------------
    // Auswertung
    // ------------------------------------------------------------------

    /**
     * Jahresübersicht je Monat: Einnahmen aus bezahlten Rechnungen, gezählt nach
     * Zahlungseingang (paid_at), nicht nach Rechnungsdatum – Zuflussprinzip der
     * Einnahmen-Überschuss-Rechnung (§4 Abs. 3 EStG): eine im Dezember gestellte,
     * erst im Januar bezahlte Rechnung zählt zum Folgejahr. Ausgaben werden
     * separat als Excel-Arbeitsmappe geführt (siehe Klassenkommentar) und
     * erscheinen deshalb hier nicht.
     *
     * @return array{months:array<int,int>,income_total:int,open_cents:int,overdue_cents:int}
     */
    public static function yearSummary(PDO $pdo, int $year): array
    {
        $months = array_fill(1, 12, 0);

        $stmt = $pdo->prepare("
            SELECT CAST(substr(paid_at, 6, 2) AS INTEGER) AS month, SUM(amount_cents) AS total
            FROM invoices WHERE status = 'bezahlt' AND substr(paid_at, 1, 4) = :y
            GROUP BY month
        ");
        $stmt->execute(['y' => (string) $year]);
        $incomeTotal = 0;
        foreach ($stmt->fetchAll() as $row) {
            $cents = (int) $row['total'];
            $months[(int) $row['month']] = $cents;
            $incomeTotal += $cents;
        }

        $openCents = (int) $pdo->query("SELECT COALESCE(SUM(amount_cents), 0) FROM invoices WHERE status = 'offen'")->fetchColumn();

        $today = self::now()->format('Y-m-d');
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM invoices WHERE status = 'offen' AND due_date IS NOT NULL AND due_date < :today");
        $stmt->execute(['today' => $today]);
        $overdueCents = (int) $stmt->fetchColumn();

        return [
            'months' => $months,
            'income_total' => $incomeTotal,
            'open_cents' => $openCents,
            'overdue_cents' => $overdueCents,
        ];
    }

    /** Jahre, für die es Rechnungen gibt (für die Jahresauswahl in Übersicht/Export), plus das laufende Jahr. */
    public static function availableYears(PDO $pdo): array
    {
        $years = [(int) self::now()->format('Y') => true];
        foreach ($pdo->query('SELECT DISTINCT substr(issued_at, 1, 4) AS y FROM invoices') as $r) {
            $years[(int) $r['y']] = true;
        }
        $list = array_keys($years);
        rsort($list);
        return $list;
    }

    /**
     * CSV-Zeilen der Einnahmen (bezahlte Rechnungen, nach Zahlungseingang) für ein
     * Jahr – Arbeitsgrundlage für den Steuerberater bzw. zur Zusammenführung mit
     * der Ausgaben-Arbeitsmappe, keine amtliche Anlage EÜR.
     *
     * @return array<int,array{date:string,invoice_number:string,client_name:string,amount_cents:int}>
     */
    public static function exportRows(PDO $pdo, int $year): array
    {
        $stmt = $pdo->prepare("
            SELECT invoice_number, paid_at, client_name, amount_cents FROM invoices
            WHERE status = 'bezahlt' AND substr(paid_at, 1, 4) = :y ORDER BY paid_at
        ");
        $stmt->execute(['y' => (string) $year]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'date' => (string) $r['paid_at'],
                'invoice_number' => (string) $r['invoice_number'],
                'client_name' => (string) $r['client_name'],
                'amount_cents' => (int) $r['amount_cents'],
            ];
        }
        return $rows;
    }
}
