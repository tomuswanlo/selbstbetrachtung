<?php
declare(strict_types=1);

/**
 * Gemeinsame Absicherung der drei Admin-Bereiche (termin-admin.php,
 * buchhaltung-admin.php, foto-admin.php – ein Passwort, eine Session):
 *
 *  1. startSession(): Session-Cookie mit HttpOnly, Secure (ausser lokal),
 *     SameSite=Lax und Strict-Mode (keine fremd vorgegebenen Session-IDs).
 *  2. Login-Drosselung pro Client-IP: MAX_FAILURES Fehlversuche innerhalb von
 *     WINDOW_SECONDS sperren weitere Login-Versuche dieser IP, bis der aelteste
 *     dieser Fehlversuche aus dem Zeitfenster faellt (gleitendes Fenster, also
 *     dauerhaft hoechstens MAX_FAILURES Versuche je Fenster). Ein erfolgreicher
 *     Login setzt den Zaehler zurueck. Gespeichert wird nur ein Hash der IP
 *     (IPv6 auf das /64-Praefix gekuerzt), Eintraege werden nach 24 h geloescht.
 *
 * Faellt die Zaehler-Datenbank aus (z. B. Schreibrechte), wird NICHT gesperrt
 * (Fail-open, damit sich der Inhaber nie selbst aussperrt) - der Fehler landet
 * im error_log, die 0,5-s-Verzoegerung der Login-Bloecke bleibt bestehen.
 */
final class AdminSecurity
{
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 900;      // 15 Minuten
    private const RETENTION_SECONDS = 86400; // 24 Stunden

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // Nur bei lokaler Entwicklung (http://localhost) ohne Secure-Flag; unbekannter Host => Secure.
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !$isLocal, // Live ist ausschliesslich HTTPS erreichbar
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Echte Client-IP. Die Seite laeuft hinter einem Reverse-Proxy (Plesk:
     * nginx vor Apache). Ist der direkte Gegenueber (REMOTE_ADDR) die Server-IP
     * selbst oder eine private/lokale Adresse, ist es der Proxy - dann zaehlt
     * der letzte X-Forwarded-For-Eintrag (den fuegt der Proxy selbst an, ein vom
     * Client mitgeschickter Wert steht weiter links). Kommt die Anfrage direkt
     * von einer oeffentlichen IP, wird X-Forwarded-For ignoriert (nicht faelschbar).
     * Null, wenn nur die Proxy-Adresse sichtbar ist: dann wird NICHT gedrosselt,
     * sonst teilten sich alle Besucher einen Zaehler und der Inhaber sperrte sich mit aus.
     */
    public static function clientIp(): ?string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $serverAddr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        $isProxyPeer = $remote === ''
            || $remote === $serverAddr
            || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        if (!$isProxyPeer) {
            return $remote;
        }
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            $candidate = end($parts);
            if ($candidate !== false && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        return null;
    }

    /** Zaehler-Schluessel der aktuellen IP, oder null, wenn die Client-IP nicht ermittelbar ist. */
    private static function currentKey(): ?string
    {
        $ip = self::clientIp();
        if ($ip === null) {
            error_log('AdminSecurity: Client-IP nicht ermittelbar (nur Proxy-Adresse sichtbar) - Login-Drosselung uebersprungen.');
            return null;
        }
        return self::ipKey($ip);
    }

    /** Sekunden, die die aktuelle IP noch gesperrt ist (0 = nicht gesperrt). */
    public static function blockedSeconds(): int
    {
        $key = self::currentKey();
        $pdo = $key === null ? null : self::db();
        if ($pdo === null) {
            return 0;
        }
        try {
            $now = time();
            $stmt = $pdo->prepare(
                'SELECT ts FROM login_failures WHERE ip_key = ? AND ts > ? ORDER BY ts DESC LIMIT ' . self::MAX_FAILURES
            );
            $stmt->execute([$key, $now - self::WINDOW_SECONDS]);
            $timestamps = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($timestamps) < self::MAX_FAILURES) {
                return 0;
            }
            // Der aelteste der letzten MAX_FAILURES Fehlversuche bestimmt das Ende der Sperre.
            return max(1, (int) end($timestamps) + self::WINDOW_SECONDS - $now);
        } catch (Throwable $e) {
            error_log('AdminSecurity::blockedSeconds: ' . $e->getMessage());
            return 0;
        }
    }

    public static function recordFailure(): void
    {
        $key = self::currentKey();
        $pdo = $key === null ? null : self::db();
        if ($pdo === null) {
            return;
        }
        try {
            $now = time();
            $pdo->prepare('INSERT INTO login_failures (ip_key, ts) VALUES (?, ?)')
                ->execute([$key, $now]);
            $pdo->prepare('DELETE FROM login_failures WHERE ts < ?')
                ->execute([$now - self::RETENTION_SECONDS]);
        } catch (Throwable $e) {
            error_log('AdminSecurity::recordFailure: ' . $e->getMessage());
        }
    }

    public static function clearFailures(): void
    {
        $key = self::currentKey();
        $pdo = $key === null ? null : self::db();
        if ($pdo === null) {
            return;
        }
        try {
            $pdo->prepare('DELETE FROM login_failures WHERE ip_key = ?')->execute([$key]);
        } catch (Throwable $e) {
            error_log('AdminSecurity::clearFailures: ' . $e->getMessage());
        }
    }

    public static function blockedMessage(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);
        return 'Zu viele Fehlversuche. Bitte in etwa ' . $minutes . ($minutes === 1 ? ' Minute' : ' Minuten') . ' erneut versuchen.';
    }

    /** Hash der IP; IPv6-Adressen werden auf das /64-Praefix gekuerzt (sonst waere die Sperre per Adresswechsel umgehbar). */
    private static function ipKey(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            $packed = substr($packed, 0, 8) . str_repeat("\0", 8);
        }
        return hash('sha256', 'admin-login|' . ($packed !== false ? bin2hex($packed) : $ip));
    }

    private static function db(): ?PDO
    {
        static $pdo = null;
        static $tried = false;
        if ($tried) {
            return $pdo;
        }
        $tried = true;
        try {
            $pdo = new PDO('sqlite:' . __DIR__ . '/../data/admin-login.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout = 3000');
            $pdo->exec('CREATE TABLE IF NOT EXISTS login_failures (ip_key TEXT NOT NULL, ts INTEGER NOT NULL)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_failures ON login_failures (ip_key, ts)');
        } catch (Throwable $e) {
            error_log('AdminSecurity::db: ' . $e->getMessage());
            $pdo = null;
        }
        return $pdo;
    }
}
