#!/bin/sh
# Baut lokal build/ghostlike-portal.zip (z. B. für die erste Installation per Upload).
set -e
cd "$(dirname "$0")/.."
rm -rf build && mkdir -p build/ghostlike-portal
rsync -a --exclude-from=.distignore ./ build/ghostlike-portal/
(cd build && zip -rq ghostlike-portal.zip ghostlike-portal)
echo "build/ghostlike-portal.zip"
