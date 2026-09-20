<?php
declare(strict_types=1);

/**
 * Liefert das "Über mich"-Foto aus. Bewusst als eigene, kleine Datei statt
 * eingebettet im index.html-Bundle: so kann foto-admin.php das Foto jederzeit
 * austauschen, ohne das Bundle anzufassen (kein Risiko fuer die grosse
 * index.html, kein Konflikt mit dem naechsten Git-Deploy).
 *
 * data/about-photo.jpg (gitignored) hat Vorrang, falls vorhanden - das ist
 * das von foto-admin.php hochgeladene Foto. Existiert es (noch) nicht,
 * z. B. direkt nach einem frischen Deploy, wird der im Git versionierte
 * Standard aus assets/ ausgeliefert.
 */

$live = __DIR__ . '/data/about-photo.jpg';
$default = __DIR__ . '/assets/about-photo-default.jpg';
$file = is_file($live) ? $live : $default;

if (!is_file($file)) {
    http_response_code(404);
    exit;
}

$mtime = filemtime($file);
$size = filesize($file);
$etag = '"' . dechex((int) $mtime) . '-' . dechex((int) $size) . '"';

$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . $size);
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Cache-Control: public, max-age=3600, must-revalidate');
readfile($file);
