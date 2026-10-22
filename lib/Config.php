<?php
declare(strict_types=1);

/**
 * Ablageort der Konfigurationsdateien mit Zugangsdaten
 * (smtp_config.php, stripe_config.php, paypal_config.php, termin_admin_config.php).
 *
 * Bevorzugt wird der Ordner "selbstbetrachtung-config" NEBEN dem Web-Root
 * (auf dem Server: eine Ebene ueber dem Domain-Ordner selbstbetrachtung-online.de,
 * also im Abonnement-Hauptordner) - dort kann kein Webserver-Abruf hinkommen,
 * egal wie der PHP-Handler gerade konfiguriert ist. Existiert die Datei dort nicht
 * (oder ist sie fuer PHP nicht lesbar, z. B. wegen open_basedir), wird der bisherige
 * Ort im Web-Root verwendet (dort per .htaccess gesperrt). So laeuft der Umzug ohne
 * Ausfall: erst Datei kopieren, dann pruefen, dann die Kopie im Web-Root loeschen.
 *
 * Die Dateien selbst sind gitignored und liegen nur auf dem Server bzw. lokal.
 */

if (!function_exists('appConfigDir')) {
    /** Ordner ausserhalb des Web-Roots, in dem die Konfigurationsdateien bevorzugt liegen. */
    function appConfigDir(): string
    {
        return dirname(__DIR__, 2) . '/selbstbetrachtung-config';
    }

    /** Voller Pfad der Konfigurationsdatei (auch wenn sie noch gar nicht existiert). */
    function appConfigFile(string $name): string
    {
        if (preg_match('/^[a-z_]+_config\.php$/', $name) !== 1) {
            throw new InvalidArgumentException('Ungueltiger Konfigurationsdateiname.');
        }
        $outside = appConfigDir() . '/' . $name;
        if (@is_file($outside) && @is_readable($outside)) {
            return $outside;
        }
        return dirname(__DIR__) . '/' . $name; // bisheriger Ort (Fallback)
    }

    /** 'ausserhalb' (bevorzugter Ort), 'webroot' (alter Ort, per .htaccess gesperrt) oder 'fehlt'. */
    function appConfigLocation(string $name): string
    {
        $path = appConfigFile($name);
        if (!is_file($path)) {
            return 'fehlt';
        }
        return str_starts_with($path, appConfigDir() . '/') ? 'ausserhalb' : 'webroot';
    }
}
