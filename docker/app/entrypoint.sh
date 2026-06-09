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

# --------------------------------------------------------------------------
# Step 1: Environment variables
# --------------------------------------------------------------------------
# Docker Compose's env_file directive passes all variables from the host
# .env as OS environment variables. Laravel's env() reads $_ENV and
# $_SERVER before any .env file, so no .env file is needed inside the
# container. This keeps the project clean — one .env at the project root,
# nothing created in backend/.
#
# Works identically on Linux, macOS, and Windows Docker Desktop.

# --------------------------------------------------------------------------
# Step 2: Fix directory permissions for Laravel's writable directories
# --------------------------------------------------------------------------
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

# --------------------------------------------------------------------------
# Step 3: Verify APP_KEY is set
# --------------------------------------------------------------------------
# APP_KEY is required for session encryption, CSRF tokens, and encrypted
# model attributes (OAuth tokens). Without it, the app runs but sessions
# and encryption silently fail.
#
# In development, the developer sets APP_KEY in the root .env file
# (see README Step 4). Docker's env_file passes it to the container.
# We log a warning if it's missing so the issue is immediately visible
# in container logs rather than causing silent failures.
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "WARNING: APP_KEY is not set. Run this command and add the output to your .env file:"
    echo "  docker compose exec app php artisan key:generate --show"
    echo ""
fi

# Drop privileges and execute the main command as www-data.
# su-exec replaces the current process (no zombie parent),
# unlike su or sudo which leave a root process running.
exec su-exec www-data "$@"
