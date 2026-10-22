#!/bin/sh
# Makes a fresh clone work with a plain `docker compose up`: dependencies and content are created on first start.
set -e

# Only when starting the server (default command), not for `docker compose run app composer …`.
if [ "${1#-}" != "$1" ]; then
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --no-progress
    fi
    # The editor may already have created an empty database file, so check for the schema, not the file.
    if ! php bin/console dbal:run-sql "SELECT 1 FROM article LIMIT 1" >/dev/null 2>&1; then
        php bin/console app:seed
    fi
fi

exec docker-php-entrypoint "$@"
