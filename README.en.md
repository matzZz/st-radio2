# st-radio (modernised)

🇩🇪 [Deutsche Version](README.md)

A modern rewrite of the Raspberry Pi web radio [matzZz/st-radio](https://github.com/matzZz/st-radio).

<p>
  <img src="docs/screenshot-desktop.png" alt="st-radio in the browser (desktop)" width="640">
  &nbsp;
  <img src="docs/screenshot-mobile.png" alt="st-radio on a smartphone" width="200">
</p>

## What changed

| Before                                      | Now                                          |
| ------------------------------------------- | -------------------------------------------- |
| HTML 4.01 + table layout + inline styles    | HTML5 + CSS custom properties + grid         |
| Full page reload on every click             | JSON API + `fetch()` without reload          |
| `$_GET['name']` flowed into `exec()`        | Whitelist by ID, `escapeshellarg()`, no user string in the shell |
| Manual CSV parsing with `explode`           | `fgetcsv()` with header detection            |
| No status sync                              | PID file + status endpoint, polling every 5 s |
| `apache2` running as `pi` (old README)      | Default `www-data` (see below)               |
| German only                                 | German and English, switchable               |

## Files

- `index.html` – single-page interface
- `api.php` – JSON API (`stations`, `status`, `play`, `stop`, `save`)
- `radio.sh` – starts `mpv` with the stream URL and prints its PID
- `ruhe.sh` – stops `mpv`
- `radio.php`, `ruhe.php` – compatibility for old links (see below)
- `radio.csv` – station list: `name;url;genre;slug` (header line optional)

## Setup

```sh
sudo apt-get install mpv apache2 php php-mbstring
# copy the project files to /var/www/html/
sudo chown -R www-data:www-data /var/www/html
sudo chmod +x /var/www/html/radio.sh /var/www/html/ruhe.sh
```

Apache runs as `www-data` by default – keep it that way (the old setup with `pi` as the Apache user was convenient but insecure). `www-data` can start `mpv` as is, because `mpv` doesn't need root. If there's no audio, check the group membership:

```sh
sudo usermod -aG audio www-data
```

## Language

The interface is available in German and English. Switch at the top right via **DE | EN** – the browser remembers your choice. On the first visit the browser language decides (English for an English browser, German otherwise). You can also link to it directly: `http://<pi>/?lang=en`.

The API returns error messages in German by default and in English with `&lang=en`.

## Security

The old version passed the HTTP parameter `name` into a shell call. This version works differently:

1. The client only sends a numeric **ID** (or a slug).
2. The station is looked up in `radio.csv`.
3. Only the URL from the CSV is passed to `radio.sh` – through `escapeshellarg()`.

So there is no path left for a web request to run an arbitrary shell command on the Pi.

## Managing stations

The easiest way is the browser: click **Edit** in the interface to add, rename, move and delete stations. When saving, `api.php` validates the input (name required, only `http://`/`https://` URLs) and keeps the previous list as `radio.csv.bak`.

Or by hand: `radio.csv` is semicolon-separated:

```
name;url;genre;slug
SWR3;https://liveradio.swr.de/sw282p3/swr3/play.mp3;Pop;swr3
```

A header line (`name;…`) is detected and skipped.

Every station has a fixed **slug** (column 4). It is derived from the name once when the station is created (e.g. `SWR3` → `swr3`, `detektor.fm Wort` → `detektor-fm-wort`) and stays the same afterwards – even if you rename the station in the editor. If the column is missing (older `radio.csv`), the slug is derived from the name and written on the next save. Use the slug for stable webhook URLs (see next section).

## Webhooks / direct links

`play` and `stop` deliberately accept **GET requests** – so you can start stations from a webhook, bookmarklet, Tasker, Homebridge, Home Assistant, Node-RED, Shortcuts or a plain browser bookmark:

```
http://<pi>/api.php?action=play&slug=swr3
http://<pi>/api.php?action=play&slug=deutschlandfunk
http://<pi>/api.php?action=play&id=3
http://<pi>/api.php?action=stop
```

**Slug or ID?** Use the **slug** whenever you store the URL somewhere – it stays stable when you reorder or rename stations. The ID is just the position in the list and changes when you reorder.

The response is JSON (HTTP 200 on success, 404 if the station isn't in the CSV). If you click the link in a browser and want to see the interface instead of JSON, append `&redirect=1`:

```
http://<pi>/api.php?action=play&slug=swr3&redirect=1
```

Next to each station in the web interface there's a small link icon – one click copies the matching webhook URL to the clipboard.

## Old links (upgrading from st-radio)

If you're coming from the old st-radio, existing calls (e.g. Shelly actions, Homebridge) can stay as they are:

```
http://<pi>/radio.php?name=2   → same as api.php?action=play&id=2
http://<pi>/ruhe.php           → same as api.php?action=stop
```

Both redirect to `index.html` afterwards. The order in `radio.csv` must stay the same for this (the header line doesn't count).

If Apache still runs as `pi` from the old setup, that's fine – the web directory must then belong to `pi` (`sudo chown -R pi:pi /var/www/html`) so `api.php` can write its state file.
