#!/usr/bin/env bash
# Restauración de prueba (spec 009, A-45): la programa el scheduler el día 1
# de cada mes; este script permite lanzarla a mano. No toca la base real.
set -euo pipefail
cd "${1:-/srv/sunat/production/api/current}"
php artisan ops:restore-test
