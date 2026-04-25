#!/usr/bin/env bash
# Empaqueta la app/ en grupos.zip listo para subir a Hostinger.
set -e
cd "$(dirname "$0")"
rm -f grupos.zip
( cd app && zip -r ../grupos.zip . -x 'config.php' )
echo "✔ grupos.zip generado ($(du -h grupos.zip | cut -f1))"
