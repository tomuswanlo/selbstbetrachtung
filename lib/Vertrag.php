<?php
declare(strict_types=1);

/**
 * Zustimmungs-Protokoll zum Beratervertrag (Textform gem. § 126b BGB).
 *
 * Ersetzt das Häkchen auf einem hin- und hergeschickten PDF durch einen
 * serverseitigen, zeitgestempelten Datenbank-Eintrag: Klientendaten + IP-
 * Adresse + User-Agent + Zeitpunkt, fest verknüpft mit dem Zahlungsvorgang
 * (Termin oder Paket), der ihn ausgelöst hat. Das ist der eigentliche
 * Nachweis – nicht die Unveränderlichkeit irgendeiner Datei.
 *
 * Lebt in derselben Datenbank wie die Rechnungen (data/buchhaltung.sqlite),
 * nicht in termine.sqlite: ein Vertragsschluss braucht dieselbe lange
 * Aufbewahrung wie eine Rechnung, nicht die 3-Monats-Löschfrist der
 * Termindaten (siehe Buchhaltung.php-Klassenkommentar).
 */
final class Vertrag
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS vertrag_zustimmungen (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                context_kind TEXT NOT NULL,     -- package | session
                context_ref TEXT,               -- package_id bzw. booking_id (aus der jeweils anderen DB, daher als Text, kein FK)
                client_name TEXT NOT NULL,
                client_address TEXT,
                client_email TEXT NOT NULL,
                client_phone TEXT,
                client_geburtsdatum TEXT,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT NOT NULL
            )
        ');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    }

    /**
     * @param array{name:string,address:?string,email:string,phone:?string,geburtsdatum:?string} $client
     */
    public static function recordConsent(PDO $pdo, string $contextKind, string|int|null $contextRef, array $client): int
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('
            INSERT INTO vertrag_zustimmungen (context_kind, context_ref, client_name, client_address, client_email, client_phone, client_geburtsdatum, ip_address, user_agent, created_at)
            VALUES (:kind, :ref, :name, :addr, :email, :phone, :geburtsdatum, :ip, :ua, :created)
        ');
        $stmt->execute([
            'kind' => $contextKind,
            'ref' => $contextRef !== null ? (string) $contextRef : null,
            'name' => $client['name'],
            'addr' => $client['address'] ?? null,
            'email' => $client['email'],
            'phone' => $client['phone'] ?? null,
            'geburtsdatum' => $client['geburtsdatum'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            'created' => self::now()->format('Y-m-d H:i:s'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function findByContext(PDO $pdo, string $contextKind, string|int $contextRef): ?array
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM vertrag_zustimmungen WHERE context_kind = :kind AND context_ref = :ref ORDER BY id DESC LIMIT 1');
        $stmt->execute(['kind' => $contextKind, 'ref' => (string) $contextRef]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
