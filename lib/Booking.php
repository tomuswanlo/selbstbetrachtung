<?php
declare(strict_types=1);

/**
 * Termin-Buchungssystem: SQLite-Datenzugriff + Slot-Berechnung.
 *
 * Die Datenbankdatei liegt in data/termine.sqlite, außerhalb des
 * öffentlichen Zugriffs geschützt durch data/.htaccess (Require all denied).
 */
final class Booking
{
    /** Terminarten: Schlüssel => [Label, Dauer in Minuten] */
    public const TYPES = [
        'erstgespraech' => ['label' => 'Erstgespräch', 'duration' => 15],
        'folgetermin'   => ['label' => 'Folgetermin',  'duration' => 60],
    ];

    /** Mindestvorlauf, bevor ein Slot buchbar ist (Stunden) */
    public const MIN_LEAD_HOURS = 24;

    /** Wie weit im Voraus gebucht werden kann (Tage) */
    public const MAX_ADVANCE_DAYS = 90;

    /** Raster für Slot-Kandidaten (Minuten) */
    public const SLOT_GRID_MINUTES = 15;

    /** Pufferzeit vor/nach jedem bestehenden Termin zur Nachbereitung (Minuten) */
    public const BOOKING_BUFFER_MINUTES = 30;

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
        $dbFile = $dataDir . '/termine.sqlite';
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
            CREATE TABLE IF NOT EXISTS availability_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                weekday INTEGER NOT NULL,      -- 0=Sonntag .. 6=Samstag (wie PHP date(\'w\'))
                start_time TEXT NOT NULL,      -- \'HH:MM\'
                end_time TEXT NOT NULL,        -- \'HH:MM\'
                active INTEGER NOT NULL DEFAULT 1
            )
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS blocked_dates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date TEXT NOT NULL UNIQUE,     -- \'YYYY-MM-DD\', ganzer Tag gesperrt
                reason TEXT
            )
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS bookings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date TEXT NOT NULL,
                start_time TEXT NOT NULL,
                end_time TEXT NOT NULL,
                type TEXT NOT NULL,             -- erstgespraech | folgetermin | block
                name TEXT NOT NULL,             -- Klientenname, oder Sperr-Grund bei \'block\'
                email TEXT,
                phone TEXT,
                message TEXT,
                status TEXT NOT NULL DEFAULT \'confirmed\', -- confirmed | cancelled
                cancel_token TEXT UNIQUE,
                created_at TEXT NOT NULL,
                cancelled_at TEXT,
                cancelled_by TEXT               -- client | admin
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bookings_date ON bookings(date, status)');

        // group_id fasst mehrtägige Zeiträume (z.B. eine Urlaubswoche) zu einer Einheit
        // zusammen, damit sie als eine Zeile "Von–Bis" dargestellt und gemeinsam
        // bearbeitet/gelöscht werden können.
        self::ensureColumn($pdo, 'blocked_dates', 'group_id', 'TEXT');
        self::ensureColumn($pdo, 'bookings', 'group_id', 'TEXT');

        // Einmaliges Nachrüsten: bereits bestehende, zusammenhängende Einträge ohne
        // group_id (aus der Zeit vor dieser Funktion) rückwirkend zu Gruppen zusammenfassen.
        self::backfillBlockedDateGroups($pdo);
        self::backfillBlockGroups($pdo);
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $type): void
    {
        $cols = $pdo->query("PRAGMA table_info($table)")->fetchAll();
        foreach ($cols as $c) {
            if ($c['name'] === $column) {
                return;
            }
        }
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
    }

    private static function newGroupId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** Fasst zusammenhängende Tage ohne group_id (gleicher Grund, direkt aufeinanderfolgend) zusammen. */
    private static function backfillBlockedDateGroups(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT id, date, reason FROM blocked_dates WHERE group_id IS NULL ORDER BY date')->fetchAll();
        $run = [];
        foreach ($rows as $row) {
            if ($run) {
                $last = $run[count($run) - 1];
                $expected = (new DateTimeImmutable($last['date']))->modify('+1 day')->format('Y-m-d');
                if ($row['date'] !== $expected || $row['reason'] !== $last['reason']) {
                    self::assignGroup($pdo, 'blocked_dates', $run);
                    $run = [];
                }
            }
            $run[] = $row;
        }
        self::assignGroup($pdo, 'blocked_dates', $run);
    }

    /** Fasst zusammenhängende Zeitraum-Blocker (Buchungen vom Typ \'block\') ohne group_id zusammen. */
    private static function backfillBlockGroups(PDO $pdo): void
    {
        $rows = $pdo->query("SELECT id, date, start_time, end_time, name FROM bookings WHERE type = 'block' AND group_id IS NULL ORDER BY date, start_time")->fetchAll();
        $run = [];
        foreach ($rows as $row) {
            if ($run) {
                $last = $run[count($run) - 1];
                $expected = (new DateTimeImmutable($last['date']))->modify('+1 day')->format('Y-m-d');
                $matches = $row['date'] === $expected
                    && $row['start_time'] === $last['start_time']
                    && $row['end_time'] === $last['end_time']
                    && $row['name'] === $last['name'];
                if (!$matches) {
                    self::assignGroup($pdo, 'bookings', $run);
                    $run = [];
                }
            }
            $run[] = $row;
        }
        self::assignGroup($pdo, 'bookings', $run);
    }

    /** @param array<int,array{id:int}> $run */
    private static function assignGroup(PDO $pdo, string $table, array $run): void
    {
        if (count($run) < 2) {
            return; // Einzeltage brauchen keine gemeinsame Gruppe
        }
        $groupId = self::newGroupId();
        $stmt = $pdo->prepare("UPDATE $table SET group_id = :g WHERE id = :id");
        foreach ($run as $row) {
            $stmt->execute(['g' => $groupId, 'id' => $row['id']]);
        }
    }

    // ------------------------------------------------------------------
    // Öffentliche Slot-Berechnung
    // ------------------------------------------------------------------

    public static function typeDuration(string $type): ?int
    {
        return self::TYPES[$type]['duration'] ?? null;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    }

    private static function isDateBookable(string $date): bool
    {
        $now = self::now();
        $target = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Berlin'));
        if ($target === false) {
            return false;
        }
        $maxDate = $now->modify('+' . self::MAX_ADVANCE_DAYS . ' days');
        if ($target > $maxDate) {
            return false;
        }
        $todayMidnight = $now->setTime(0, 0, 0);
        return $target >= $todayMidnight; // exakte Mindestvorlaufzeit wird pro Slot in slotsForDate geprüft
    }

    /**
     * Liefert für ein Monat (year/month) die Menge der Tage (\'YYYY-MM-DD\'),
     * an denen mindestens ein Slot für $type frei ist.
     *
     * @return string[]
     */
    public static function availableDatesInMonth(PDO $pdo, int $year, int $month, string $type): array
    {
        $duration = self::typeDuration($type);
        if ($duration === null) {
            return [];
        }

        $first = DateTimeImmutable::createFromFormat('!Y-n-j', "$year-$month-1", new DateTimeZone('Europe/Berlin'));
        if ($first === false) {
            return [];
        }
        $daysInMonth = (int) $first->format('t');

        $rules = self::activeRulesByWeekday($pdo);
        if (!$rules) {
            return [];
        }

        $blocked = self::blockedDatesSet($pdo, $first->format('Y-m-01'), $first->format('Y-m-t'));

        $result = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = $first->setDate($year, $month, $d)->format('Y-m-d');
            if (!self::isDateBookable($date)) {
                continue;
            }
            if (isset($blocked[$date])) {
                continue;
            }
            $weekday = (int) (new DateTimeImmutable($date))->format('w');
            if (empty($rules[$weekday])) {
                continue;
            }
            $slots = self::slotsForDate($pdo, $date, $type);
            if ($slots) {
                $result[] = $date;
            }
        }

        return $result;
    }

    /**
     * Liefert die freien Slot-Startzeiten (\'HH:MM\') für ein Datum + Terminart.
     *
     * @return string[]
     */
    public static function slotsForDate(PDO $pdo, string $date, string $type): array
    {
        $duration = self::typeDuration($type);
        if ($duration === null || !self::isDateBookable($date)) {
            return [];
        }

        $blocked = self::blockedDatesSet($pdo, $date, $date);
        if (isset($blocked[$date])) {
            return [];
        }

        $weekday = (int) (new DateTimeImmutable($date))->format('w');
        $rules = self::activeRulesByWeekday($pdo)[$weekday] ?? [];
        if (!$rules) {
            return [];
        }

        // Bestehende belegte Intervalle an diesem Tag, inkl. Pufferzeit vor/nach jedem Termin
        // (BOOKING_BUFFER_MINUTES) zur Nachbereitung – gilt symmetrisch, damit jeder Termin,
        // egal in welcher Reihenfolge gebucht, seine Pufferzeit zum jeweils nächsten behält.
        $stmt = $pdo->prepare('SELECT start_time, end_time FROM bookings WHERE date = :date AND status = \'confirmed\'');
        $stmt->execute(['date' => $date]);
        $busyRanges = [];
        foreach ($stmt->fetchAll() as $b) {
            $busyRanges[] = [
                self::toMinutes($b['start_time']) - self::BOOKING_BUFFER_MINUTES,
                self::toMinutes($b['end_time']) + self::BOOKING_BUFFER_MINUTES,
            ];
        }

        $earliestStart = self::now()->modify('+' . self::MIN_LEAD_HOURS . ' hours');
        $earliestDateStr = $earliestStart->format('Y-m-d');
        if ($date < $earliestDateStr) {
            // Der ganze Tag liegt innerhalb der Mindestvorlaufzeit.
            return [];
        }
        $earliestOnThisDate = $date === $earliestDateStr ? self::toMinutes($earliestStart->format('H:i')) : null;

        $slots = [];
        foreach ($rules as $rule) {
            $cursor = self::toMinutes($rule['start_time']);
            $windowEnd = self::toMinutes($rule['end_time']);
            while ($cursor + $duration <= $windowEnd) {
                $candEnd = $cursor + $duration;

                $ok = true;
                if ($earliestOnThisDate !== null && $cursor < $earliestOnThisDate) {
                    $ok = false;
                }
                if ($ok) {
                    foreach ($busyRanges as [$bStart, $bEnd]) {
                        if (!($candEnd <= $bStart || $cursor >= $bEnd)) {
                            $ok = false;
                            break;
                        }
                    }
                }
                if ($ok) {
                    $slots[] = self::fromMinutes($cursor);
                }
                $cursor += self::SLOT_GRID_MINUTES;
            }
        }

        sort($slots);
        return $slots;
    }

    private static function toMinutes(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);
        return ((int) $h) * 60 + (int) $m;
    }

    private static function fromMinutes(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

    /** @return array<int, array<int,array{start_time:string,end_time:string}>> weekday => Regeln */
    private static function activeRulesByWeekday(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT weekday, start_time, end_time FROM availability_rules WHERE active = 1 ORDER BY weekday, start_time');
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['weekday']][] = $row;
        }
        return $out;
    }

    /** @return array<string,string> date => reason */
    private static function blockedDatesSet(PDO $pdo, string $from, string $to): array
    {
        $stmt = $pdo->prepare('SELECT date, reason FROM blocked_dates WHERE date BETWEEN :from AND :to');
        $stmt->execute(['from' => $from, 'to' => $to]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['date']] = (string) $row['reason'];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Buchung anlegen / stornieren
    // ------------------------------------------------------------------

    /**
     * @param array{date:string,start_time:string,type:string,name:string,email:?string,phone:?string,message:?string} $data
     * @return array{ok:bool,error?:string,conflict?:bool,booking?:array}
     */
    public static function createBooking(PDO $pdo, array $data): array
    {
        $duration = self::typeDuration($data['type']);
        if ($duration === null) {
            return ['ok' => false, 'error' => 'Ungültige Terminart.'];
        }
        $date = $data['date'];
        $start = $data['start_time'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $start)) {
            return ['ok' => false, 'error' => 'Ungültiges Datum/Uhrzeit.'];
        }
        $end = self::fromMinutes(self::toMinutes($start) + $duration);

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Slot innerhalb der Transaktion erneut prüfen (Race-Condition-Schutz)
            $freeSlots = self::slotsForDate($pdo, $date, $data['type']);
            if (!in_array($start, $freeSlots, true)) {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'error' => 'Dieser Termin ist leider inzwischen vergeben. Bitte wählen Sie einen anderen.', 'conflict' => true];
            }

            $token = bin2hex(random_bytes(24));
            $stmt = $pdo->prepare('
                INSERT INTO bookings (date, start_time, end_time, type, name, email, phone, message, status, cancel_token, created_at)
                VALUES (:date, :start_time, :end_time, :type, :name, :email, :phone, :message, \'confirmed\', :token, :created_at)
            ');
            $stmt->execute([
                'date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'type' => $data['type'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'message' => $data['message'] ?? null,
                'token' => $token,
                'created_at' => self::now()->format('Y-m-d H:i:s'),
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->exec('COMMIT');

            return [
                'ok' => true,
                'booking' => [
                    'id' => $id,
                    'date' => $date,
                    'start_time' => $start,
                    'end_time' => $end,
                    'type' => $data['type'],
                    'cancel_token' => $token,
                ],
            ];
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            return ['ok' => false, 'error' => 'Die Buchung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.'];
        }
    }

    public static function findByToken(PDO $pdo, string $token): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE cancel_token = :token');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function cancelByToken(PDO $pdo, string $token, string $cancelledBy = 'client'): array
    {
        $booking = self::findByToken($pdo, $token);
        if (!$booking) {
            return ['ok' => false, 'error' => 'Termin nicht gefunden.'];
        }
        if ($booking['status'] === 'cancelled') {
            return ['ok' => false, 'error' => 'Dieser Termin wurde bereits storniert.', 'booking' => $booking];
        }
        $stmt = $pdo->prepare('UPDATE bookings SET status = \'cancelled\', cancelled_at = :now, cancelled_by = :by WHERE id = :id');
        $stmt->execute(['now' => self::now()->format('Y-m-d H:i:s'), 'by' => $cancelledBy, 'id' => $booking['id']]);
        $booking['status'] = 'cancelled';
        return ['ok' => true, 'booking' => $booking];
    }

    public static function cancelById(PDO $pdo, int $id, string $cancelledBy = 'admin'): array
    {
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $booking = $stmt->fetch();
        if (!$booking) {
            return ['ok' => false, 'error' => 'Termin nicht gefunden.'];
        }
        $upd = $pdo->prepare('UPDATE bookings SET status = \'cancelled\', cancelled_at = :now, cancelled_by = :by WHERE id = :id');
        $upd->execute(['now' => self::now()->format('Y-m-d H:i:s'), 'by' => $cancelledBy, 'id' => $id]);
        $booking['status'] = 'cancelled';
        return ['ok' => true, 'booking' => $booking];
    }

    /** @return array[] Kommende, bestätigte Termine/Sperren, chronologisch */
    public static function listUpcoming(PDO $pdo): array
    {
        $today = self::now()->format('Y-m-d');
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE status = \'confirmed\' AND date >= :today ORDER BY date, start_time');
        $stmt->execute(['today' => $today]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Admin: Verfügbarkeitsregeln
    // ------------------------------------------------------------------

    public static function listRules(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM availability_rules ORDER BY weekday, start_time')->fetchAll();
    }

    public static function addRule(PDO $pdo, int $weekday, string $start, string $end): void
    {
        $stmt = $pdo->prepare('INSERT INTO availability_rules (weekday, start_time, end_time, active) VALUES (:w, :s, :e, 1)');
        $stmt->execute(['w' => $weekday, 's' => $start, 'e' => $end]);
    }

    public static function updateRule(PDO $pdo, int $id, int $weekday, string $start, string $end): void
    {
        $stmt = $pdo->prepare('UPDATE availability_rules SET weekday = :w, start_time = :s, end_time = :e WHERE id = :id');
        $stmt->execute(['w' => $weekday, 's' => $start, 'e' => $end, 'id' => $id]);
    }

    public static function deleteRule(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('DELETE FROM availability_rules WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // ------------------------------------------------------------------
    // Admin: Sperrtage
    // ------------------------------------------------------------------

    public static function listBlockedDates(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM blocked_dates ORDER BY date')->fetchAll();
    }

    /**
     * Fasst die einzelnen Tage aus listBlockedDates() zu Gruppen zusammen (ein Eintrag
     * pro group_id, als Von–Bis dargestellt; Einzeltage ohne Gruppe bleiben für sich).
     *
     * @return array<int,array{ids:int[],date_from:string,date_to:string,reason:string}>
     */
    public static function listBlockedDateGroups(PDO $pdo): array
    {
        return self::groupRows(self::listBlockedDates($pdo));
    }

    /** @param array[] $rows Zeilen mit id, date, reason (bereits nach date sortiert) */
    private static function groupRows(array $rows): array
    {
        $groups = [];
        $index = [];
        foreach ($rows as $row) {
            $gid = $row['group_id'] ?? ('single-' . $row['id']);
            if (!isset($index[$gid])) {
                $index[$gid] = count($groups);
                $groups[] = [
                    'ids' => [],
                    'date_from' => $row['date'],
                    'date_to' => $row['date'],
                    'reason' => $row['reason'],
                ];
            }
            $i = $index[$gid];
            $groups[$i]['ids'][] = (int) $row['id'];
            $groups[$i]['date_from'] = min($groups[$i]['date_from'], $row['date']);
            $groups[$i]['date_to'] = max($groups[$i]['date_to'], $row['date']);
        }
        return $groups;
    }

    public static function addBlockedDate(PDO $pdo, string $date, string $reason, ?string $groupId = null): void
    {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO blocked_dates (date, reason, group_id) VALUES (:d, :r, :g)');
        $stmt->execute(['d' => $date, 'r' => $reason, 'g' => $groupId]);
    }

    /**
     * Sperrt jeden Tag zwischen $dateFrom und $dateTo (inklusive) ganztägig, als
     * gemeinsame Gruppe (auch bei nur einem Tag, für einheitliches Bearbeiten/Löschen).
     *
     * @return array{ok:bool, added:int}
     */
    public static function addBlockedDateRange(PDO $pdo, string $dateFrom, string $dateTo, string $reason): array
    {
        $dates = self::dateRange($dateFrom, $dateTo);
        if (!$dates) {
            return ['ok' => false, 'added' => 0];
        }
        $groupId = self::newGroupId();
        foreach ($dates as $d) {
            self::addBlockedDate($pdo, $d, $reason, $groupId);
        }
        return ['ok' => true, 'added' => count($dates)];
    }

    /**
     * Löscht alle Tage der übergebenen IDs und legt den Zeitraum neu an
     * (einfacher als ein Datumsbereich zu verschieben/erweitern zu versuchen).
     * Validiert den neuen Zeitraum *vor* dem Löschen, damit bei ungültiger
     * Eingabe nichts verloren geht.
     *
     * @param int[] $ids
     * @return array{ok:bool,error?:string}
     */
    public static function updateBlockedGroup(PDO $pdo, array $ids, string $dateFrom, string $dateTo, string $reason): array
    {
        $dates = self::dateRange($dateFrom, $dateTo);
        if (!$dates) {
            return ['ok' => false, 'error' => 'Ungültiger Zeitraum.'];
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            self::deleteBlockedGroup($pdo, $ids);
            $groupId = self::newGroupId();
            foreach ($dates as $d) {
                self::addBlockedDate($pdo, $d, $reason, $groupId);
            }
            $pdo->exec('COMMIT');
            return ['ok' => true];
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            return ['ok' => false, 'error' => 'Konnte nicht gespeichert werden.'];
        }
    }

    /** @param int[] $ids */
    public static function deleteBlockedGroup(PDO $pdo, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM blocked_dates WHERE id IN ($placeholders)");
        $stmt->execute($ids);
    }

    /**
     * Liefert alle Tage von $from bis $to (inklusive) als \'YYYY-MM-DD\'-Strings.
     * Leeres Array bei ungültiger Eingabe oder einem Zeitraum über 366 Tagen (Schutz
     * vor versehentlichem Massen-Anlegen bei vertauschten/falschen Daten).
     *
     * @return string[]
     */
    private static function dateRange(string $from, string $to): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return [];
        }
        $tz = new DateTimeZone('Europe/Berlin');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
        if (!$start || !$end) {
            return [];
        }
        if ($end < $start) {
            // "Von" und "Bis" wurden vertauscht eingegeben – statt eines Fehlers einfach tauschen.
            [$start, $end] = [$end, $start];
        }
        if ($start->diff($end)->days > 366) {
            return [];
        }
        $dates = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        return $dates;
    }

    // ------------------------------------------------------------------
    // Admin: manuelle Sperr-Slots (z.B. private Termine)
    // ------------------------------------------------------------------

    /** Sentinel-Uhrzeiten für "keine Uhrzeit angegeben" = ganzer Tag blockiert. */
    public const FULLDAY_START = '00:00';
    public const FULLDAY_END = '24:00';

    public static function isFullDayBlock(string $start, string $end): bool
    {
        return $start === self::FULLDAY_START && $end === self::FULLDAY_END;
    }

    public static function addManualBlock(PDO $pdo, string $date, string $start, string $end, string $reason, ?string $groupId = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            return ['ok' => false, 'error' => 'Ungültiges Datum/Uhrzeit.'];
        }
        if ($end <= $start) {
            return ['ok' => false, 'error' => 'Ende muss nach dem Start liegen.'];
        }

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $startMin = self::toMinutes($start);
            $endMin = self::toMinutes($end);
            $stmt = $pdo->prepare('SELECT start_time, end_time FROM bookings WHERE date = :date AND status = \'confirmed\'');
            $stmt->execute(['date' => $date]);
            foreach ($stmt->fetchAll() as $b) {
                $bStart = self::toMinutes($b['start_time']) - self::BOOKING_BUFFER_MINUTES;
                $bEnd = self::toMinutes($b['end_time']) + self::BOOKING_BUFFER_MINUTES;
                if (!($endMin <= $bStart || $startMin >= $bEnd)) {
                    $pdo->exec('ROLLBACK');
                    return ['ok' => false, 'error' => 'Überschneidet sich mit einem bestehenden Termin (inkl. 30 Min. Pufferzeit).'];
                }
            }
            $token = bin2hex(random_bytes(24));
            $ins = $pdo->prepare('
                INSERT INTO bookings (date, start_time, end_time, type, name, email, phone, message, status, cancel_token, created_at, group_id)
                VALUES (:date, :start, :end, \'block\', :reason, NULL, NULL, NULL, \'confirmed\', :token, :created_at, :group_id)
            ');
            $ins->execute([
                'date' => $date,
                'start' => $start,
                'end' => $end,
                'reason' => $reason !== '' ? $reason : 'Blockiert',
                'token' => $token,
                'created_at' => self::now()->format('Y-m-d H:i:s'),
                'group_id' => $groupId,
            ]);
            $pdo->exec('COMMIT');
            return ['ok' => true];
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            return ['ok' => false, 'error' => 'Konnte nicht gespeichert werden.'];
        }
    }

    /**
     * Blockiert dieselbe Uhrzeit (oder den ganzen Tag, siehe FULLDAY_START/END) an jedem
     * Tag zwischen $dateFrom und $dateTo (inklusive), als gemeinsame Gruppe – z. B. jeden
     * Vormittag einer Fortbildungswoche. Tage, die sich mit einem bestehenden Termin
     * überschneiden, werden übersprungen und in \'failed\' gemeldet statt den ganzen
     * Zeitraum abzubrechen.
     *
     * @return array{ok:bool, added:int, failed:array<int,array{date:string,error:string}>}
     */
    public static function addManualBlockRange(PDO $pdo, string $dateFrom, string $dateTo, string $start, string $end, string $reason): array
    {
        $dates = self::dateRange($dateFrom, $dateTo);
        if (!$dates) {
            return ['ok' => false, 'added' => 0, 'failed' => [['date' => $dateFrom, 'error' => 'Ungültiger Zeitraum.']]];
        }
        $groupId = self::newGroupId();
        $added = 0;
        $failed = [];
        foreach ($dates as $d) {
            $result = self::addManualBlock($pdo, $d, $start, $end, $reason, $groupId);
            if ($result['ok']) {
                $added++;
            } else {
                $failed[] = ['date' => $d, 'error' => $result['error']];
            }
        }
        return ['ok' => $added > 0, 'added' => $added, 'failed' => $failed];
    }

    /**
     * Fasst Zeitraum-Blocker (Buchungen vom Typ \'block\') aus listUpcoming() zu
     * Gruppen zusammen (ein Eintrag pro group_id, als Von–Bis dargestellt).
     *
     * @param array[] $blockRows nur Zeilen mit type === \'block\'
     * @return array<int,array{ids:int[],date_from:string,date_to:string,start_time:string,end_time:string,reason:string}>
     */
    public static function groupBlockRows(array $blockRows): array
    {
        $groups = [];
        $index = [];
        foreach ($blockRows as $row) {
            $gid = $row['group_id'] ?? ('single-' . $row['id']);
            if (!isset($index[$gid])) {
                $index[$gid] = count($groups);
                $groups[] = [
                    'ids' => [],
                    'date_from' => $row['date'],
                    'date_to' => $row['date'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'reason' => $row['name'],
                ];
            }
            $i = $index[$gid];
            $groups[$i]['ids'][] = (int) $row['id'];
            $groups[$i]['date_from'] = min($groups[$i]['date_from'], $row['date']);
            $groups[$i]['date_to'] = max($groups[$i]['date_to'], $row['date']);
        }
        return $groups;
    }

    /** @param int[] $ids */
    public static function cancelGroup(PDO $pdo, array $ids, string $cancelledBy = 'admin'): void
    {
        foreach ($ids as $id) {
            self::cancelById($pdo, $id, $cancelledBy);
        }
    }

    public const WEEKDAYS = [0 => 'Sonntag', 1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag'];
}
