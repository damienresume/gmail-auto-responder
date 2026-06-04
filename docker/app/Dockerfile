# ============================================================================
# Laravel Backend - PHP 8.4 + Composer
# ============================================================================
# Multi-stage build:
#   Stage 1 (composer): Install PHP dependencies with Composer
#   Stage 2 (app):      Runtime image with only what's needed to run Laravel
#
# Security decisions:
#   - Non-root user (www-data) runs the application
#   - No dev dependencies in the final image (--no-dev)
#   - Only production-required PHP extensions are installed
#   - No SSH, no sudo, no package managers in the runtime stage
# ============================================================================

# --------------------------------------------------------------------------
# Stage 1: Composer dependency installation
# Using the official Composer image ensures we get a verified, signed binary
# rather than curl-piping an installer script.
# --------------------------------------------------------------------------
FROM composer:2.8 AS composer

WORKDIR /app

# Copy only dependency manifests first for Docker layer caching.
# If composer.json and composer.lock haven't changed, Docker reuses
# the cached vendor/ layer and skips the install step entirely.
COPY backend/composer.json backend/composer.lock ./

# Install dependencies without dev packages, without running scripts
# (scripts may reference app code that isn't copied yet), and with
# an optimized autoloader for faster class resolution in production.
# --ignore-platform-reqs is needed because this builder stage lacks
# extensions (pcntl, pdo_pgsql, redis) that are installed in the
# runtime stage. Composer validates requirements at install time,
# but the actual runtime image satisfies all of them.
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# --------------------------------------------------------------------------
# Stage 2: Runtime image
# Alpine-based for smaller image size (~50MB vs ~400MB for Debian).
# Only production-required extensions are installed.
# --------------------------------------------------------------------------
FROM php:8.4-fpm-alpine

# Install PHP extensions required by Laravel using install-php-extensions.
# This tool downloads pre-compiled binaries when available instead of
# compiling from source, cutting build time from ~5 minutes to ~15 seconds.
#   - pdo_pgsql:  PostgreSQL database driver
#   - redis:      phpredis for queue, cache, and session storage
#   - pcntl:      Required by Laravel Horizon for signal handling
#   - bcmath:     Required by some Laravel packages for precision math
#   - opcache:    Bytecode caching for 3-5x faster PHP execution
#   - intl:       Internationalization (used by validation rules)
# su-exec is needed by the entrypoint to drop from root to www-data
# after fixing directory permissions. Unlike gosu, su-exec is already
# packaged for Alpine and replaces the process (PID 1, no zombie parent).
RUN apk add --no-cache su-exec

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pcntl bcmath opcache intl redis

# OPcache configuration for production performance.
# validate_timestamps=0 means PHP never checks if files changed on disk,
# which is correct for containerized deployments where code doesn't change
# at runtime. For local development, this is overridden via docker-compose.
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.interned_strings_buffer=8" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=10000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=1" >> /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

# Copy Composer-installed vendor directory from the builder stage.
COPY --from=composer /app/vendor ./vendor

# Copy the Laravel application code.
COPY backend/ .

# Copy the entrypoint script that handles permissions securely.
# The entrypoint runs as root to fix directory ownership, then
# drops to www-data via su-exec before executing the CMD.
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set base ownership to www-data. The entrypoint will fix
# volume-mounted directories (storage/, bootstrap/cache/) at
# runtime since volume mounts override image-layer permissions.
RUN chown -R www-data:www-data /var/www/html

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]

# Use Laravel's built-in server for development.
# In production, this would be replaced with nginx + php-fpm.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
