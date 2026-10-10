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
 *
 * Optional: &redirect=1 hängt sich an play/stop dran und leitet auf
 * index.html zurück – praktisch, wenn man den Link direkt im Browser
 * anklickt statt per Webhook aufzurufen.
 *
 * Sicherheit:
 *   - Stream-URLs werden NIE aus dem Request übernommen, sondern per
 *     ID/Slug aus der CSV-Whitelist nachgeschlagen.
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
        respond(['playing' => true, 'station' => $state['station'] ?? null]);
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

    default:
        respond(['error' => 'Unbekannte action. Erlaubt: stations | status | play | stop'], 400);
}
