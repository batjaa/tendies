#!/bin/sh
set -eu

mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

gosu www-data php artisan config:cache
gosu www-data php artisan route:cache
gosu www-data php artisan view:cache

case "${1:-web}" in
    web)
        if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
            gosu www-data php artisan migrate --force --no-interaction
        fi
        php artisan storage:link --no-interaction
        exec apache2-foreground
        ;;
    worker)
        if [ "${QUEUE_WORKER_ENABLED:-true}" != "true" ]; then
            exec sleep infinity
        fi
        exec gosu www-data php artisan queue:work --sleep=3 --tries=3 --timeout=60 --memory=512 --max-time=3600
        ;;
    *) exec gosu www-data "$@" ;;
esac
