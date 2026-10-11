#!/bin/sh
# mare-radio – Stream stoppen
set -u
pkill -x mpv >/dev/null 2>&1 || true
echo "Gestoppt."
