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
#
# WHY chown -R (recursive) instead of just the directories:
# Files inside these directories (e.g., laravel.log, compiled views) may
# have been created by the host user (via composer commands or artisan)
# with a different UID. The container's www-data user needs to own both
# the directories AND the files inside them. Without -R, existing files
# like laravel.log remain owned by the host UID and www-data gets
# "Permission denied" when trying to append to the log file.
for dir in \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache; do
    mkdir -p "$dir"
    chown -R www-data:www-data "$dir"
    chmod -R 775 "$dir"
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
