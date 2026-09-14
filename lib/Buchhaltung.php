<?php
declare(strict_types=1);

/**
 * Praxis-Buchhaltung: SQLite-Datenzugriff für Einstellungen, Rechnungen und Ausgaben.
 *
 * Eigene Datenbankdatei (data/buchhaltung.sqlite), bewusst getrennt von
 * termine.sqlite: Klienten-Termindaten unterliegen dort einer 3-Monats-
 * Löschfrist (DSGVO, siehe Booking::purgeOldBookings()), Finanzunterlagen
 * dagegen einer gesetzlichen Aufbewahrungspflicht von i. d. R. 10 Jahren
 * (§147 AO, §14b UStG) und dürfen deshalb nicht denselben Lebenszyklus teilen.
 * Beim Anlegen einer Rechnung aus einem Termin (siehe buchhaltung-admin.php)
 * werden die nötigen Daten kopiert, nicht live referenziert – die Rechnung
 * bleibt also erhalten, auch wenn der Termin-Datensatz später gelöscht wird.
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

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_number TEXT NOT NULL UNIQUE,   -- fortlaufend, Format JJJJ-NNN
                client_name TEXT NOT NULL,
                client_address TEXT,
                session_date TEXT,              -- \'YYYY-MM-DD\', Datum der Leistung
                session_type TEXT,               -- freier Text, z.B. \'Erstgespräch\'
                description TEXT NOT NULL,
                amount_cents INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT \'offen\',  -- offen | bezahlt | storniert
                issued_at TEXT NOT NULL,         -- \'YYYY-MM-DD\', Rechnungsdatum
                paid_at TEXT,                    -- \'YYYY-MM-DD\', Zahlungseingang (Zufluss – zählt als Einnahme)
                notes TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoices_paid ON invoices(paid_at)');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date TEXT NOT NULL,              -- \'YYYY-MM-DD\'
                category TEXT NOT NULL,
                description TEXT NOT NULL,
                amount_cents INTEGER NOT NULL,
                payment_method TEXT,
                receipt_filename TEXT,           -- zufälliger Dateiname unter data/belege/, oder NULL
                receipt_original_name TEXT,       -- Original-Dateiname, nur für den Download-Namen
                created_at TEXT NOT NULL
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses(date)');
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

    /** Für Formular-Eingabefelder: \'123.45\' (Punkt, wie von <input type=text> mit deutschem Komma erwartet wird es beim Absenden per parseAmountToCents wieder eingelesen). */
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
    public const DEFAULT_KLEINUNTERNEHMER_HINWEIS = 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.';
    public const DEFAULT_PAYMENT_TERMS = 'Bitte überweisen Sie den Betrag innerhalb von 14 Tagen unter Angabe der Rechnungsnummer.';

    /** @return array<string,string> alle Einstellungen als key => value */
    public static function allSettings(PDO $pdo): array
    {
        $defaults = [
            'sender_name' => self::DEFAULT_SENDER_NAME,
            'sender_address' => self::DEFAULT_SENDER_ADDRESS,
            'sender_taxid' => self::DEFAULT_SENDER_TAXID,
            'sender_bank' => '',
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
    // Rechnungen
    // ------------------------------------------------------------------

    /**
     * Legt eine Rechnung an und vergibt dabei die nächste fortlaufende Nummer
     * innerhalb derselben Transaktion (BEGIN IMMEDIATE), damit zwei nahezu
     * gleichzeitige Aufrufe (z. B. zwei offene Browser-Tabs) niemals dieselbe
     * Nummer doppelt vergeben – analog zum Race-Condition-Schutz in
     * Booking::createBooking().
     *
     * @param array{client_name:string,client_address:?string,session_date:?string,session_type:?string,description:string,amount_cents:int,issued_at:string,notes:?string} $data
     * @return array{ok:bool,id?:int,invoice_number?:string,error?:string}
     */
    public static function createInvoice(PDO $pdo, array $data): array
    {
        $now = self::now()->format('Y-m-d H:i:s');
        $year = (int) substr($data['issued_at'], 0, 4);

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $number = self::nextInvoiceNumber($pdo, $year);
            $stmt = $pdo->prepare('
                INSERT INTO invoices (invoice_number, client_name, client_address, session_date, session_type, description, amount_cents, status, issued_at, paid_at, notes, created_at, updated_at)
                VALUES (:num, :name, :addr, :sdate, :stype, :desc, :amount, \'offen\', :issued, NULL, :notes, :created, :created)
            ');
            $stmt->execute([
                'num' => $number,
                'name' => $data['client_name'],
                'addr' => $data['client_address'],
                'sdate' => $data['session_date'],
                'stype' => $data['session_type'],
                'desc' => $data['description'],
                'amount' => $data['amount_cents'],
                'issued' => $data['issued_at'],
                'notes' => $data['notes'],
                'created' => $now,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->exec('COMMIT');
            return ['ok' => true, 'id' => $id, 'invoice_number' => $number];
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
        return $row ?: null;
    }

    /** @return array[] Alle Rechnungen, neueste zuerst. */
    public static function listInvoices(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM invoices ORDER BY issued_at DESC, id DESC')->fetchAll();
    }

    // ------------------------------------------------------------------
    // Ausgaben
    // ------------------------------------------------------------------

    public static function createExpense(
        PDO $pdo,
        string $date,
        string $category,
        string $description,
        int $amountCents,
        ?string $paymentMethod,
        ?string $receiptFilename,
        ?string $receiptOriginalName
    ): int {
        $stmt = $pdo->prepare('
            INSERT INTO expenses (date, category, description, amount_cents, payment_method, receipt_filename, receipt_original_name, created_at)
            VALUES (:date, :cat, :desc, :amount, :pm, :file, :orig, :created)
        ');
        $stmt->execute([
            'date' => $date,
            'cat' => $category,
            'desc' => $description,
            'amount' => $amountCents,
            'pm' => $paymentMethod,
            'file' => $receiptFilename,
            'orig' => $receiptOriginalName,
            'created' => self::now()->format('Y-m-d H:i:s'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function findExpense(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function deleteExpense(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('DELETE FROM expenses WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** @return array[] Alle Ausgaben, neueste zuerst. */
    public static function listExpenses(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM expenses ORDER BY date DESC, id DESC')->fetchAll();
    }

    // ------------------------------------------------------------------
    // Auswertung
    // ------------------------------------------------------------------

    /**
     * Jahresübersicht je Monat. Einnahmen zählen nach Zahlungseingang (paid_at),
     * nicht nach Rechnungsdatum – Zuflussprinzip der Einnahmen-Überschuss-Rechnung
     * (§4 Abs. 3 EStG): eine im Dezember gestellte, erst im Januar bezahlte
     * Rechnung zählt zum Folgejahr.
     *
     * @return array{months:array<int,array{income_cents:int,expense_cents:int}>,income_total:int,expense_total:int,open_cents:int}
     */
    public static function yearSummary(PDO $pdo, int $year): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['income_cents' => 0, 'expense_cents' => 0];
        }

        $stmt = $pdo->prepare("
            SELECT CAST(substr(paid_at, 6, 2) AS INTEGER) AS month, SUM(amount_cents) AS total
            FROM invoices WHERE status = 'bezahlt' AND substr(paid_at, 1, 4) = :y
            GROUP BY month
        ");
        $stmt->execute(['y' => (string) $year]);
        $incomeTotal = 0;
        foreach ($stmt->fetchAll() as $row) {
            $cents = (int) $row['total'];
            $months[(int) $row['month']]['income_cents'] = $cents;
            $incomeTotal += $cents;
        }

        $stmt = $pdo->prepare("
            SELECT CAST(substr(date, 6, 2) AS INTEGER) AS month, SUM(amount_cents) AS total
            FROM expenses WHERE substr(date, 1, 4) = :y
            GROUP BY month
        ");
        $stmt->execute(['y' => (string) $year]);
        $expenseTotal = 0;
        foreach ($stmt->fetchAll() as $row) {
            $cents = (int) $row['total'];
            $months[(int) $row['month']]['expense_cents'] = $cents;
            $expenseTotal += $cents;
        }

        $stmt = $pdo->query("SELECT COALESCE(SUM(amount_cents), 0) AS total FROM invoices WHERE status = 'offen'");
        $openCents = (int) $stmt->fetchColumn();

        return [
            'months' => $months,
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'open_cents' => $openCents,
        ];
    }

    /** Jahre, für die es Buchungen gibt (für die Jahresauswahl in Übersicht/Export), plus das laufende Jahr. */
    public static function availableYears(PDO $pdo): array
    {
        $years = [(int) self::now()->format('Y') => true];
        foreach ($pdo->query('SELECT DISTINCT substr(issued_at, 1, 4) AS y FROM invoices') as $r) {
            $years[(int) $r['y']] = true;
        }
        foreach ($pdo->query('SELECT DISTINCT substr(date, 1, 4) AS y FROM expenses') as $r) {
            $years[(int) $r['y']] = true;
        }
        $list = array_keys($years);
        rsort($list);
        return $list;
    }

    /**
     * CSV-Zeilen (Einnahmen nach Zahlungseingang + Ausgaben nach Datum) für ein Jahr,
     * chronologisch – Arbeitsgrundlage für den Steuerberater, keine amtliche Anlage EÜR.
     *
     * @return array<int,array{date:string,type:string,ref:string,description:string,amount_cents:int}>
     */
    public static function exportRows(PDO $pdo, int $year): array
    {
        $rows = [];
        $stmt = $pdo->prepare("SELECT invoice_number, paid_at, description, amount_cents FROM invoices WHERE status = 'bezahlt' AND substr(paid_at, 1, 4) = :y");
        $stmt->execute(['y' => (string) $year]);
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'date' => (string) $r['paid_at'],
                'type' => 'Einnahme',
                'ref' => (string) $r['invoice_number'],
                'description' => (string) $r['description'],
                'amount_cents' => (int) $r['amount_cents'],
            ];
        }
        $stmt = $pdo->prepare('SELECT id, date, category, description, amount_cents FROM expenses WHERE substr(date, 1, 4) = :y');
        $stmt->execute(['y' => (string) $year]);
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'date' => (string) $r['date'],
                'type' => 'Ausgabe',
                'ref' => (string) $r['category'],
                'description' => (string) $r['description'],
                'amount_cents' => (int) $r['amount_cents'],
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a['date'] <=> $b['date']);
        return $rows;
    }
}
