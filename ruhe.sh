#!/bin/sh
# st-radio – Stream stoppen
set -u
pkill -x mplayer >/dev/null 2>&1 || true
echo "Gestoppt."
