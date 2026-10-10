<?php
declare(strict_types=1);

/**
 * st-radio – JSON API
 *
 * Endpoints (alle GET und POST erlaubt – GET ist absichtlich okay,
 * damit man Sender per Webhook / Bookmarklet / Tasker / Homebridge
 * mit einem simplen URL-Aufruf starten kann):
 *
 *   GET  api.php?action=stations         → Liste aller Sender
 *   GET  api.php?action=status           → aktueller Sender (oder null)
 *   GET  api.php?action=play&id=3        → Sender mit ID 3 starten
 *   GET  api.php?action=play&slug=swr3   → Sender per Slug starten (stabil)
 *   GET  api.php?action=stop             → Wiedergabe stoppen
 *   POST api.php?action=save             → Senderliste speichern (JSON-Body,
 *                                          nur POST mit application/json)
 *
 * Optional: &redirect=1 hängt sich an play/stop dran und leitet auf
 * index.html zurück – praktisch, wenn man den Link direkt im Browser
 * anklickt statt per Webhook aufzurufen.
 *
 * Sicherheit:
 *   - Stream-URLs werden beim Abspielen NIE aus dem Request übernommen,
 *     sondern per ID/Slug aus der CSV-Whitelist nachgeschlagen.
 *   - Die Whitelist selbst ändert nur action=save: nur http(s)-URLs,
 *     und nur per POST mit JSON-Body – ein Link oder ein simples
 *     Formular von einer fremden Webseite kann sie so nicht ändern.
 *   - Alle Shell-Argumente laufen durch escapeshellarg().
 *   - Annahme: Pi läuft im Heimnetz. CSRF-Schutz ist hier bewusst
 *     nicht eingebaut, weil das den Webhook-Use-Case kaputtmachen würde.
 *     Wenn der Pi öffentlich erreichbar ist: HTTP-Basic-Auth in Apache
 *     davorhängen.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const CSV_FILE   = __DIR__ . '/radio.csv';
const STATE_FILE = __DIR__ . '/.radio-state.json';
const PLAY_SH    = __DIR__ . '/radio.sh';
const STOP_SH    = __DIR__ . '/ruhe.sh';

/** Erzeugt einen URL-tauglichen Slug aus dem Sendernamen. */
function make_slug(string $name): string {
    $s = mb_strtolower($name, 'UTF-8');
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/** Liest die radio.csv und gibt ein Array von Sendern zurück. */
function load_stations(): array {
    if (!is_readable(CSV_FILE)) {
        return [];
    }
    $stations = [];
    $fh = fopen(CSV_FILE, 'r');
    if ($fh === false) {
        return [];
    }
    $i = 0;
    while (($row = fgetcsv($fh, 4096, ';')) !== false) {
        // Leere Zeilen und Kommentar-/Header-Zeile überspringen
        if ($row === [null] || $row === [] || !isset($row[0]) || $row[0] === '') {
            continue;
        }
        if ($i === 0 && strcasecmp(trim($row[0]), 'name') === 0) {
            $i++; // Header
            continue;
        }
        $name = $row[0] ?? '';
        $stations[] = [
            'id'    => count($stations) + 1,
            'slug'  => make_slug($name),
            'name'  => $name,
            'url'   => $row[1] ?? '',
            'genre' => $row[2] ?? '',
        ];
        $i++;
    }
    fclose($fh);
    return $stations;
}

/** Entfernt Steuerzeichen, trimmt und kürzt ein Eingabefeld. */
function clean_field($value, int $max): string {
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '';
    return mb_substr(trim($s), 0, $max, 'UTF-8');
}

/**
 * Prüft die Senderliste aus dem Editor.
 * Gibt die bereinigten Zeilen [name, url, genre] zurück oder einen Fehlertext.
 *
 * @return array|string
 */
function validate_stations(array $input) {
    $rows  = [];
    $slugs = [];
    foreach (array_values($input) as $i => $row) {
        $nr = $i + 1;
        if (!is_array($row)) {
            return "Sender $nr: ungültige Daten";
        }
        $name  = clean_field($row['name']  ?? '', 60);
        $url   = clean_field($row['url']   ?? '', 500);
        $genre = clean_field($row['genre'] ?? '', 30);

        if ($name === '') {
            return "Sender $nr: Name fehlt";
        }
        $slug = make_slug($name);
        if ($slug === '') {
            return "„{$name}“: Name braucht Buchstaben oder Ziffern";
        }
        if (isset($slugs[$slug])) {
            return "„{$name}“ gibt es doppelt";
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            return "„{$name}“: Stream-URL muss mit http:// oder https:// beginnen";
        }
        $slugs[$slug] = true;
        $rows[] = [$name, $url, $genre];
    }
    if ($rows === []) {
        return 'Mindestens ein Sender wird gebraucht';
    }
    return $rows;
}

/** Schreibt die Senderliste atomar nach radio.csv (alte Version → radio.csv.bak). */
function save_stations(array $rows): bool {
    $tmp = CSV_FILE . '.tmp';
    $fh  = fopen($tmp, 'w');
    if ($fh === false) {
        return false;
    }
    fputcsv($fh, ['name', 'url', 'genre'], ';');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ';');
    }
    fclose($fh);
    if (is_file(CSV_FILE)) {
        @copy(CSV_FILE, CSV_FILE . '.bak');
    }
    return rename($tmp, CSV_FILE);
}

/** Sucht einen Sender per ID oder Slug aus der Whitelist. */
function find_station(array $stations, ?int $id, ?string $slug): ?array {
    foreach ($stations as $s) {
        if ($id !== null  && $s['id']   === $id)   return $s;
        if ($slug !== null && $s['slug'] === $slug) return $s;
    }
    return null;
}

function read_state(): ?array {
    if (!is_readable(STATE_FILE)) {
        return null;
    }
    $raw = file_get_contents(STATE_FILE);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function write_state(?array $state): void {
    if ($state === null) {
        @unlink(STATE_FILE);
        return;
    }
    file_put_contents(STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** Prüft, ob der vermerkte mplayer-Prozess noch läuft. */
function is_playing(?array $state): bool {
    if ($state === null || empty($state['pid'])) {
        return false;
    }
    return file_exists('/proc/' . (int)$state['pid']);
}

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

/** Wenn ?redirect=1 anhängt, leiten wir auf das UI zurück (für Klick im Browser). */
function maybe_redirect(): void {
    if (!empty($_GET['redirect'])) {
        header('Location: index.html');
        exit;
    }
}

switch ($action) {
    case 'stations': {
        respond(['stations' => load_stations()]);
    }

    case 'status': {
        $state = read_state();
        if (!is_playing($state)) {
            write_state(null);
            respond(['playing' => false, 'station' => null]);
        }
        $station = $state['station'] ?? null;
        // Wurde die Senderliste inzwischen bearbeitet, aktuelle ID/Name nachschlagen
        if (is_array($station)) {
            foreach (load_stations() as $s) {
                if ($s['url'] === ($station['url'] ?? null)) {
                    $station = $s;
                    break;
                }
            }
        }
        respond(['playing' => true, 'station' => $station]);
    }

    case 'play': {
        // GET und POST beide erlaubt – siehe Webhook-Hinweis oben.
        $id   = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $id   = (is_int($id) && $id > 0) ? $id : null;
        $slug = isset($_GET['slug']) ? make_slug((string) $_GET['slug']) : null;

        if ($id === null && ($slug === null || $slug === '')) {
            respond(['error' => 'id oder slug erforderlich'], 400);
        }

        $stations = load_stations();
        $station  = find_station($stations, $id, $slug);
        if ($station === null) {
            respond(['error' => 'Sender nicht gefunden'], 404);
        }

        // Erst laufenden Player stoppen
        @exec(escapeshellcmd(STOP_SH) . ' 2>&1');

        // Dann neuen Stream starten – URL aus Whitelist, sauber escaped
        // radio.sh startet den Player im Hintergrund und gibt dessen PID aus
        $cmd = escapeshellcmd(PLAY_SH) . ' ' . escapeshellarg($station['url']) . ' 2>/dev/null';
        $pid = (int) trim((string) shell_exec($cmd));

        write_state([
            'pid'       => $pid,
            'station'   => $station,
            'startedAt' => time(),
        ]);

        maybe_redirect();
        respond(['playing' => true, 'station' => $station]);
    }

    case 'stop': {
        // GET und POST beide erlaubt
        @exec(escapeshellcmd(STOP_SH) . ' 2>&1');
        write_state(null);
        maybe_redirect();
        respond(['playing' => false, 'station' => null]);
    }

    case 'save': {
        // Nur POST mit JSON – siehe Sicherheitshinweis oben.
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0;
        if (!$isPost || !$isJson) {
            respond(['error' => 'Speichern nur per POST mit JSON'], 405);
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['stations']) || !is_array($body['stations'])) {
            respond(['error' => 'Ungültige Daten'], 400);
        }
        $rows = validate_stations($body['stations']);
        if (is_string($rows)) {
            respond(['error' => $rows], 400);
        }
        if (!save_stations($rows)) {
            respond(['error' => 'radio.csv konnte nicht geschrieben werden'], 500);
        }
        respond(['stations' => load_stations()]);
    }

    default:
        respond(['error' => 'Unbekannte action. Erlaubt: stations | status | play | stop | save'], 400);
}
