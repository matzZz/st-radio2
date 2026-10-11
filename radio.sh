#!/bin/sh
# mare-radio – Stream starten
# Aufruf: radio.sh <stream-url>
# Wird von api.php nur mit URLs aus radio.csv aufgerufen (Whitelist).
# Gibt die PID des Players auf stdout aus (für den Status-Endpoint).

set -eu

if [ "$#" -lt 1 ]; then
    echo "Usage: $0 <stream-url>" >&2
    exit 1
fi

URL="$1"

# Falls noch ein alter Player läuft, zuerst beenden.
pkill -x mpv >/dev/null 2>&1 || true

# Stream starten, vom Terminal lösen, alles ins Nirvana umleiten.
# Über den IPC-Socket liest api.php den aktuellen Titel (ICY) aus mpv.
nohup mpv --no-video --really-quiet --input-ipc-server=/tmp/st-radio-mpv.sock -- "$URL" </dev/null >/dev/null 2>&1 &

echo $!
