<?php
declare(strict_types=1);

/**
 * mare-radio – JSON API
 *
 * Endpoints (alle GET und POST erlaubt – GET ist absichtlich okay,
 * damit man Sender per Webhook / Bookmarklet / Tasker / Homebridge
 * mit einem simplen URL-Aufruf starten kann):
 *
 *   GET  api.php?action=stations         → Liste aller Sender
 *   GET  api.php?action=status           → aktueller Sender (oder null) und
 *                                          laufender Titel aus dem Stream (track, oder null)
 *   GET  api.php?action=play&id=3        → Sender mit ID 3 starten
 *   GET  api.php?action=play&slug=swr3   → Sender per Slug starten (stabil)
 *   GET  api.php?action=play&slug=x&from=start
 *                                        → Podcast: neueste Folge von vorn
 *   GET  api.php?action=play&slug=x&from=resume
 *                                        → Podcast: dort fortsetzen, wo man war
 *                                          (Standard, auch ohne from)
 *   GET  api.php?action=stop             → Wiedergabe stoppen
 *   POST api.php?action=save             → Senderliste speichern (JSON-Body,
 *                                          nur POST mit application/json)
 *
 * Optional: &lang=en liefert Fehlermeldungen auf Englisch (Standard: Deutsch).
 *
 * Optional: &redirect=1 hängt sich an play/stop dran und leitet auf
 * index.html zurück – praktisch, wenn man den Link direkt im Browser
 * anklickt statt per Webhook aufzurufen.
 *
 * Sicherheit:
 *   - Stream-URLs werden beim Abspielen NIE aus dem Request übernommen,
 *     sondern per ID/Slug aus der CSV-Whitelist nachgeschlagen.
 *   - Podcasts: abgerufen wird nur die Feed-URL aus der CSV; die Audio-URL
 *     der Folge kommt aus dem Feed und muss http(s) sein.
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
// IPC-Socket von mpv (siehe radio.sh) – daraus kommt der Titel (ICY) des Streams
const MPV_SOCKET = '/tmp/st-radio-mpv.sock';
// Gemerkte Podcast-Positionen (pro Podcast: Folge, Sekunde, Dauer, fertig?)
const POS_FILE   = __DIR__ . '/.podcast-positions.json';
const FEED_CACHE_TTL = 1800; // Sekunden – Podcast-Feeds nicht bei jedem Klick neu laden

/** Fehlermeldungen in beiden Sprachen; Platzhalter in {geschweiften Klammern}. */
const MESSAGES = [
    'de' => [
        'invalid_row'    => 'Sender {nr}: ungültige Daten',
        'name_missing'   => 'Sender {nr}: Name fehlt',
        'name_chars'     => '„{name}“: Name braucht Buchstaben oder Ziffern',
        'slug_dup'       => 'Kennung „{slug}“ gibt es doppelt',
        'url_invalid'    => '„{name}“: Stream-URL muss mit http:// oder https:// beginnen',
        'need_one'       => 'Mindestens ein Sender wird gebraucht',
        'id_or_slug'     => 'id oder slug erforderlich',
        'not_found'      => 'Sender nicht gefunden',
        'post_json'      => 'Speichern nur per POST mit JSON',
        'bad_data'       => 'Ungültige Daten',
        'write_failed'   => 'radio.csv konnte nicht geschrieben werden',
        'feed_failed'    => 'Podcast-Feed konnte nicht geladen werden',
        'unknown_action' => 'Unbekannte action. Erlaubt: stations | status | play | stop | save',
    ],
    'en' => [
        'invalid_row'    => 'Station {nr}: invalid data',
        'name_missing'   => 'Station {nr}: name is missing',
        'name_chars'     => '“{name}”: name needs letters or digits',
        'slug_dup'       => 'ID “{slug}” is used twice',
        'url_invalid'    => '“{name}”: stream URL must start with http:// or https://',
        'need_one'       => 'At least one station is required',
        'id_or_slug'     => 'id or slug required',
        'not_found'      => 'Station not found',
        'post_json'      => 'Saving requires POST with JSON',
        'bad_data'       => 'Invalid data',
        'write_failed'   => 'Could not write radio.csv',
        'feed_failed'    => 'Could not load podcast feed',
        'unknown_action' => 'Unknown action. Allowed: stations | status | play | stop | save',
    ],
];

define('LANG', ($_GET['lang'] ?? '') === 'en' ? 'en' : 'de');

/** Übersetzt eine Fehlermeldung, z.B. t('name_missing', ['nr' => 3]). */
function t(string $key, array $vars = []): string {
    $msg = MESSAGES[LANG][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $msg = str_replace('{' . $k . '}', (string) $v, $msg);
    }
    return $msg;
}

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
        // Feste Kennung aus Spalte 4; ältere CSVs ohne die Spalte → aus dem Namen
        $slug = make_slug($row[3] ?? '');
        $stations[] = [
            'id'    => count($stations) + 1,
            'slug'  => $slug !== '' ? $slug : make_slug($name),
            'name'  => $name,
            'url'   => $row[1] ?? '',
            'genre' => $row[2] ?? '',
            // Spalte 5: „podcast“ = URL ist ein RSS-Feed (wird beim Speichern erkannt)
            'type'  => trim($row[4] ?? '') === 'podcast' ? 'podcast' : 'radio',
        ];
        $i++;
    }
    fclose($fh);

    // Podcasts: gemerkte Position mitliefern (für „… gehört“)
    $positions = read_positions();
    foreach ($stations as &$s) {
        if ($s['type'] === 'podcast') {
            $s['resume'] = resume_position($positions[$s['slug']] ?? null);
        }
    }
    unset($s);
    return $stations;
}

/** Entfernt Steuerzeichen, trimmt und kürzt ein Eingabefeld. */
function clean_field($value, int $max): string {
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '';
    return mb_substr(trim($s), 0, $max, 'UTF-8');
}

/**
 * Prüft die Senderliste aus dem Editor.
 * Gibt die bereinigten Zeilen [name, url, genre, slug] zurück oder einen Fehlertext.
 *
 * Bestehende Sender schicken ihre Kennung (slug) mit – die bleibt auch beim
 * Umbenennen gleich, damit gespeicherte Links (Shelly, Webhooks) weiter
 * funktionieren. Neue Sender bekommen eine Kennung aus dem Namen, bei
 * Kollision mit Suffix (-2, -3, …).
 *
 * @return array|string
 */
function validate_stations(array $input) {
    $rows  = [];
    $slugs = [];
    foreach (array_values($input) as $i => $row) {
        $nr = $i + 1;
        if (!is_array($row)) {
            return t('invalid_row', ['nr' => $nr]);
        }
        $name  = clean_field($row['name']  ?? '', 60);
        $url   = clean_field($row['url']   ?? '', 500);
        $genre = clean_field($row['genre'] ?? '', 30);
        $slug  = make_slug(clean_field($row['slug'] ?? '', 60));

        if ($name === '') {
            return t('name_missing', ['nr' => $nr]);
        }
        if ($slug === '' && make_slug($name) === '') {
            return t('name_chars', ['name' => $name]);
        }
        if ($slug !== '' && isset($slugs[$slug])) {
            return t('slug_dup', ['slug' => $slug]);
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
            return t('url_invalid', ['name' => $name]);
        }
        if ($slug !== '') {
            $slugs[$slug] = true;
        }
        $rows[] = [$name, $url, $genre, $slug, '']; // type setzt detect_podcasts()
    }
    if ($rows === []) {
        return t('need_one');
    }

    // Neue Sender: Kennung aus dem Namen, ohne bestehende zu überschreiben
    foreach ($rows as &$row) {
        if ($row[3] !== '') {
            continue;
        }
        $base = make_slug($row[0]);
        $slug = $base;
        for ($n = 2; isset($slugs[$slug]); $n++) {
            $slug = "$base-$n";
        }
        $slugs[$slug] = true;
        $row[3] = $slug;
    }
    unset($row);

    return $rows;
}

/** Schreibt die Senderliste atomar nach radio.csv (alte Version → radio.csv.bak). */
function save_stations(array $rows): bool {
    $tmp = CSV_FILE . '.tmp';
    $fh  = fopen($tmp, 'w');
    if ($fh === false) {
        return false;
    }
    fputcsv($fh, ['name', 'url', 'genre', 'slug', 'type'], ';');
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

/** Fragt mpv über den IPC-Socket nach den Stream-Metadaten (icy-title, icy-name …). */
function mpv_metadata(): ?array {
    $data = mpv_get('metadata');
    return is_array($data) ? array_change_key_case($data, CASE_LOWER) : null;
}

/** Bereinigt einen Text aus Stream oder Feed (UTF-8, ohne Steuerzeichen). */
function clean_text($value): string {
    $s = (string) $value;
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }
    return clean_field($s, 200);
}

/**
 * Laufender Titel aus dem Stream (ICY-Metadaten über mpv).
 * Schickt der Sender dort nur seinen Namen, gibt es null.
 * @return array{title:string}|null
 */
function now_playing(array $station): ?array {
    $meta  = mpv_metadata() ?? [];
    $title = clean_text($meta['icy-title'] ?? '');
    $names = [mb_strtolower($station['name']), mb_strtolower(clean_text($meta['icy-name'] ?? ''))];
    if ($title === '' || in_array(mb_strtolower($title), $names, true)) {
        return null;
    }
    return ['title' => $title];
}

/** Cover-URLs nur als https übernehmen (landen im <img src>). */
function clean_cover($url): ?string {
    $url = (string) $url;
    return preg_match('#^https://[^\s"\'<>]+$#', $url) ? $url : null;
}

/** Prüft, ob der vermerkte mplayer-Prozess noch läuft. */
function is_playing(?array $state): bool {
    if ($state === null || empty($state['pid'])) {
        return false;
    }
    return file_exists('/proc/' . (int)$state['pid']);
}

/** Liest eine Eigenschaft aus dem laufenden mpv (z.B. time-pos, duration). */
function mpv_get(string $property) {
    $sock = @stream_socket_client('unix://' . MPV_SOCKET, $errno, $errstr, 1);
    if ($sock === false) {
        return null;
    }
    stream_set_timeout($sock, 1);
    fwrite($sock, json_encode(['command' => ['get_property', $property], 'request_id' => 1]) . "\n");
    $data = null;
    while (($line = fgets($sock)) !== false) {
        $msg = json_decode($line, true);
        if (is_array($msg) && ($msg['request_id'] ?? null) === 1) {
            $data = ($msg['error'] ?? '') === 'success' ? ($msg['data'] ?? null) : null;
            break;
        }
    }
    fclose($sock);
    return $data;
}

/** Prüft beim Speichern, ob eine URL ein RSS-Feed ist (liest nur den Anfang). */
function is_feed(string $url): bool {
    $ctx = stream_context_create(['http' => [
        'timeout' => 5,
        'header'  => "User-Agent: st-radio\r\n",
    ]]);
    $fh = @fopen($url, 'r', false, $ctx);
    if ($fh === false) {
        return false;
    }
    stream_set_timeout($fh, 5);
    $head = '';
    // Höchstens 4 KB – bei einem Audio-Stream würde sonst endlos gelesen
    while (strlen($head) < 4096 && !feof($fh)) {
        $chunk = fread($fh, 4096 - strlen($head));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $head .= $chunk;
    }
    fclose($fh);
    return (bool) preg_match('/<(rss|feed)[\s>]/i', $head);
}

/** Wandelt itunes:duration („1:02:03“, „23:06“ oder Sekunden) in Sekunden um. */
function parse_duration(string $value): int {
    $secs = 0;
    foreach (explode(':', trim($value)) as $part) {
        $secs = $secs * 60 + (int) $part;
    }
    return $secs;
}

/**
 * Lädt einen Podcast-Feed (zwischengespeichert) und gibt die neueste Folge zurück.
 * @return array{url:string,title:string,cover:?string,duration:int}|null
 */
function latest_episode(string $feedUrl): ?array {
    $cache = sys_get_temp_dir() . '/st-radio-feed-' . md5($feedUrl) . '.xml';
    $xml   = false;
    if (is_readable($cache) && time() - (int) filemtime($cache) < FEED_CACHE_TTL) {
        $xml = file_get_contents($cache);
    }
    if (!$xml) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'header'  => "User-Agent: st-radio\r\n",
        ]]);
        $xml = @file_get_contents($feedUrl, false, $ctx);
        if ($xml) {
            @file_put_contents($cache, $xml);
        } elseif (is_readable($cache)) {
            $xml = file_get_contents($cache); // offline: lieber alter Feed als gar keiner
        }
    }
    if (!$xml || !class_exists('SimpleXMLElement')) {
        return null;
    }

    $prev = libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($feed === false || !isset($feed->channel)) {
        return null;
    }

    $itunes = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    $image  = function (SimpleXMLElement $node) use ($itunes): string {
        $img = $node->children($itunes)->image;
        return $img && $img->attributes() ? (string) $img->attributes()->href : '';
    };

    // Neueste Folge = jüngstes pubDate (die Reihenfolge im Feed ist nicht garantiert)
    $best = null;
    $bestTs = -1;
    $bestUrl = '';
    foreach ($feed->channel->item as $item) {
        $url = isset($item->enclosure) ? (string) $item->enclosure['url'] : '';
        if (!preg_match('#^https?://#i', $url)) {
            continue;
        }
        $ts = strtotime((string) $item->pubDate) ?: 0;
        if ($ts > $bestTs) {
            [$best, $bestTs, $bestUrl] = [$item, $ts, $url];
        }
    }
    if ($best === null) {
        return null;
    }

    $cover = $image($best) ?: $image($feed->channel) ?: (string) ($feed->channel->image->url ?? '');
    return [
        'url'      => $bestUrl,
        'title'    => clean_text($best->title),
        'cover'    => clean_cover($cover),
        'duration' => parse_duration((string) $best->children($itunes)->duration),
    ];
}

function read_positions(): array {
    $data = is_readable(POS_FILE) ? json_decode((string) file_get_contents(POS_FILE), true) : null;
    return is_array($data) ? $data : [];
}

function write_positions(array $positions): void {
    file_put_contents(POS_FILE, json_encode($positions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** Sekunde, an der ein Podcast fortgesetzt würde (0 = von vorn). */
function resume_position(?array $saved): int {
    if (!is_array($saved) || !empty($saved['finished'])) {
        return 0;
    }
    $pos = (int) ($saved['pos'] ?? 0);
    $dur = (int) ($saved['duration'] ?? 0);
    // Kurz vor Schluss zählt als fertig gehört
    return ($dur > 0 && $pos >= $dur - 30) ? 0 : $pos;
}

/**
 * Merkt sich die Position der Podcast-Folge aus $state.
 * Läuft mpv noch, kommt die Position direkt von dort. Ist er schon beendet
 * (Folge zu Ende), wird geschätzt, ob die Folge durchgelaufen ist.
 */
function save_podcast_position(?array $state): void {
    $slug    = $state['station']['slug'] ?? '';
    $episode = $state['episode'] ?? null;
    if (($state['station']['type'] ?? '') !== 'podcast' || !is_array($episode) || $slug === '') {
        return;
    }
    $positions = read_positions();
    $entry = $positions[$slug] ?? [];
    if (($entry['episode'] ?? '') !== $episode['url']) {
        $entry = ['episode' => $episode['url'], 'pos' => 0];
    }
    $entry['title']    = $episode['title'];
    $entry['duration'] = (int) ($episode['duration'] ?? 0);

    if (is_playing($state)) {
        $pos = mpv_get('time-pos');
        $dur = mpv_get('duration');
        if (!is_numeric($pos)) {
            return;
        }
        $entry['pos'] = (int) $pos;
        if (is_numeric($dur) && $dur > 0) {
            $entry['duration'] = (int) $dur;
        }
        $entry['finished'] = false;
    } else {
        // mpv hat sich selbst beendet: geschätzte Position = Start + verstrichene Zeit
        $estimate = (int) ($state['startPos'] ?? 0) + (time() - (int) ($state['startedAt'] ?? time()));
        if ($entry['duration'] > 0 && $estimate >= $entry['duration'] - 60) {
            $entry['finished'] = true;
        } else {
            return; // eher Abbruch (Netz o.ä.) – lieber die alte Position behalten
        }
    }
    $entry['updated'] = time();
    $positions[$slug] = $entry;
    write_positions($positions);
}

/** Anzeige-Daten einer Podcast-Folge im selben Format wie now_playing(). */
function episode_track(?array $episode): ?array {
    return is_array($episode)
        ? ['title' => $episode['title'], 'artist' => '', 'cover' => $episode['cover'] ?? null]
        : null;
}

/**
 * Erkennt beim Speichern, welche Einträge Podcast-Feeds sind (Spalte type).
 * Nur neue oder geänderte URLs werden abgerufen.
 */
function detect_podcasts(array $rows): array {
    $known = [];
    foreach (load_stations() as $s) {
        $known[$s['url']] = $s['type'] === 'podcast' ? 'podcast' : '';
    }
    foreach ($rows as &$row) {
        $row[4] = array_key_exists($row[1], $known)
            ? $known[$row[1]]
            : (is_feed($row[1]) ? 'podcast' : '');
    }
    unset($row);
    return $rows;
}

/** Stoppt den Player; eine laufende Podcast-Folge merkt sich vorher ihre Position. */
function stop_player(): void {
    save_podcast_position(read_state());
    @exec(escapeshellcmd(STOP_SH) . ' 2>&1');
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
            save_podcast_position($state); // Folge zu Ende gelaufen?
            write_state(null);
            respond(['playing' => false, 'station' => null, 'track' => null]);
        }
        $station = $state['station'] ?? null;
        // Wurde die Senderliste inzwischen bearbeitet, aktuelle ID/Name nachschlagen
        if (is_array($station)) {
            foreach (load_stations() as $s) {
                if ($s['slug'] === ($station['slug'] ?? null)) {
                    $station = $s;
                    break;
                }
            }
        }
        if (($station['type'] ?? '') === 'podcast') {
            save_podcast_position($state); // laufend mitschreiben, falls der Pi ausgeht
            $track = episode_track($state['episode'] ?? null);
        } else {
            $track = is_array($station) ? now_playing($station) : null;
        }
        respond(['playing' => true, 'station' => $station, 'track' => $track]);
    }

    case 'play': {
        // GET und POST beide erlaubt – siehe Webhook-Hinweis oben.
        $id   = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $id   = (is_int($id) && $id > 0) ? $id : null;
        $slug = isset($_GET['slug']) ? make_slug((string) $_GET['slug']) : null;

        if ($id === null && ($slug === null || $slug === '')) {
            respond(['error' => t('id_or_slug')], 400);
        }

        $stations = load_stations();
        $station  = find_station($stations, $id, $slug);
        if ($station === null) {
            respond(['error' => t('not_found')], 404);
        }

        // Podcast: neueste Folge aus dem Feed, ggf. an der gemerkten Stelle
        $url = $station['url'];
        $episode = null;
        $start = 0;
        if ($station['type'] === 'podcast') {
            $episode = latest_episode($station['url']);
            if ($episode === null) {
                respond(['error' => t('feed_failed')], 502);
            }
            $url = $episode['url'];
        }

        // Erst laufenden Player stoppen (merkt sich ggf. die Podcast-Position)
        stop_player();

        if ($episode !== null && ($_GET['from'] ?? '') !== 'start') {
            $saved = read_positions()[$station['slug']] ?? null;
            if (($saved['episode'] ?? '') === $episode['url']) {
                // ein paar Sekunden zurück, damit man wieder reinkommt
                $start = max(0, resume_position($saved) - 5);
            }
        }

        // Dann neu starten – URL aus Whitelist bzw. deren Feed, sauber escaped
        // radio.sh startet den Player im Hintergrund und gibt dessen PID aus
        $cmd = escapeshellcmd(PLAY_SH) . ' ' . escapeshellarg($url) . ' ' . (int) $start . ' 2>/dev/null';
        $pid = (int) trim((string) shell_exec($cmd));

        write_state([
            'pid'       => $pid,
            'station'   => $station,
            'startedAt' => time(),
            'startPos'  => $start,
            'episode'   => $episode,
        ]);

        maybe_redirect();
        respond(['playing' => true, 'station' => $station, 'track' => episode_track($episode)]);
    }

    case 'stop': {
        // GET und POST beide erlaubt
        stop_player();
        write_state(null);
        maybe_redirect();
        respond(['playing' => false, 'station' => null]);
    }

    case 'save': {
        // Nur POST mit JSON – siehe Sicherheitshinweis oben.
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $isJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0;
        if (!$isPost || !$isJson) {
            respond(['error' => t('post_json')], 405);
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || !isset($body['stations']) || !is_array($body['stations'])) {
            respond(['error' => t('bad_data')], 400);
        }
        $rows = validate_stations($body['stations']);
        if (is_string($rows)) {
            respond(['error' => $rows], 400);
        }
        $rows = detect_podcasts($rows);
        if (!save_stations($rows)) {
            respond(['error' => t('write_failed')], 500);
        }
        respond(['stations' => load_stations()]);
    }

    default:
        respond(['error' => t('unknown_action')], 400);
}
