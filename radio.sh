#!/bin/sh
# mare-radio – Stream oder Podcast-Folge starten
# Aufruf: radio.sh <url> [start-sekunden]
# Wird von api.php nur mit URLs aus radio.csv bzw. aus deren Podcast-Feeds
# aufgerufen. Gibt die PID des Players auf stdout aus (für den Status-Endpoint).

set -eu

if [ "$#" -lt 1 ]; then
    echo "Usage: $0 <url> [start-seconds]" >&2
    exit 1
fi

URL="$1"
# Startposition (nur bei Podcasts zum Fortsetzen); alles außer Ziffern → 0
START="${2:-0}"
case "$START" in
    ''|*[!0-9]*) START=0 ;;
esac

# Falls noch ein alter Player läuft, zuerst beenden.
pkill -x mpv >/dev/null 2>&1 || true

# Starten, vom Terminal lösen, alles ins Nirvana umleiten.
# Über den IPC-Socket liest api.php Titel (ICY) und Podcast-Position aus mpv.
if [ "$START" -gt 0 ]; then
    nohup mpv --no-video --really-quiet --input-ipc-server=/tmp/st-radio-mpv.sock \
        --start="$START" -- "$URL" </dev/null >/dev/null 2>&1 &
else
    nohup mpv --no-video --really-quiet --input-ipc-server=/tmp/st-radio-mpv.sock \
        -- "$URL" </dev/null >/dev/null 2>&1 &
fi

echo $!
