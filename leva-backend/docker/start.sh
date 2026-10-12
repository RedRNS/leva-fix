#!/bin/sh
set -e

cd /var/www/html

# Render memberi port lewat $PORT
PORT="${PORT:-10000}"
export PORT
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY belum di-set. Isi di environment Render." >&2
    exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan leva:bootstrap

# Worker antrean jalan di container yang sama (paket gratis tidak punya background worker)
(
    while true; do
        su -s /bin/sh www-data -c "php artisan queue:work --tries=3 --timeout=120 --sleep=3 --max-time=3600" || true
        sleep 2
    done
) &

exec apache2-foreground
