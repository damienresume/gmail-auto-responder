#!/bin/sh
# ============================================================================
# Laravel Container Entrypoint
# ============================================================================
# Runs as root on container start, fixes directory permissions, then
# drops privileges to www-data before executing the main process.
#
# Why an entrypoint instead of host-side chmod/chown:
#   - Portable: works on any developer's machine regardless of host UID
#   - Secure: permissions are set once at startup, not left open (no 777)
#   - Reproducible: fresh clone + docker compose up just works
# ============================================================================

set -e

# Ensure Laravel's writable directories exist and are owned by www-data.
# These directories must be writable for logging, caching, file uploads,
# and compiled views. 775 allows group write without world access.
for dir in \
    /var/www/html/storage \
    /var/www/html/storage/logs \
    /var/www/html/storage/framework \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/bootstrap/cache; do
    mkdir -p "$dir"
    chown www-data:www-data "$dir"
    chmod 775 "$dir"
done

# Generate APP_KEY if not already set in the environment.
# In production, APP_KEY should be set in .env before deployment.
# For local development, auto-generating avoids a manual setup step.
if [ -z "$APP_KEY" ]; then
    if [ -f /var/www/html/artisan ]; then
        su-exec www-data php artisan key:generate --force 2>/dev/null || true
    fi
fi

# Drop privileges and execute the main command as www-data.
# su-exec replaces the current process (no zombie parent),
# unlike su or sudo which leave a root process running.
exec su-exec www-data "$@"
