#!/bin/sh
# ============================================================================
# Next.js Frontend - Development Entrypoint
# ============================================================================
# Cross-platform permission handling for volume-mounted source code.
#
# The problem:
#   - On Linux, volume-mounted files retain host UIDs. If the host user
#     is UID 1000 but the container user is UID 1001, npm cannot write
#     to node_modules/.
#   - On macOS/Windows, Docker Desktop's VM layer maps UIDs transparently,
#     so any container user can read/write mounted files.
#
# The solution:
#   - Start as root (required to remap UIDs and fix ownership)
#   - Detect the UID that owns the mounted /app directory
#   - Remap the container's "node" user to match that UID
#   - Install dependencies with correct ownership
#   - Drop privileges to the remapped "node" user via su-exec
#
# Security principles:
#   - The application process (CMD) never runs as root
#   - su-exec replaces the shell process entirely (PID 1, no root parent)
#   - Only the entrypoint setup phase runs as root, and only to fix UIDs
#   - No 777 permissions anywhere; ownership is precise to one user
#   - Works identically on Linux, macOS, and Windows without host-side
#     configuration or manual chmod/chown
# ============================================================================

set -e

# --------------------------------------------------------------------------
# Step 1: Detect the UID/GID of the mounted working directory
# --------------------------------------------------------------------------
# On Linux, this is the host user's real UID (e.g. 1000).
# On macOS/Windows via Docker Desktop, this is a mapped UID that the VM
# already handles transparently, so remapping is effectively a no-op.
# stat -c is GNU (Linux), stat -f is BSD (macOS fallback inside container).
HOST_UID=$(stat -c '%u' /app 2>/dev/null || stat -f '%u' /app 2>/dev/null)
HOST_GID=$(stat -c '%g' /app 2>/dev/null || stat -f '%g' /app 2>/dev/null)

# --------------------------------------------------------------------------
# Step 2: Remap the "node" user's UID/GID to match the host
# --------------------------------------------------------------------------
# Skip remapping when:
#   - Mounted dir is owned by root (UID 0): happens in CI or production
#     builds where there is no volume mount
#   - UIDs already match: no action needed (common on macOS/Windows)
CURRENT_UID=$(id -u node)
if [ "$HOST_UID" != "0" ] && [ "$HOST_UID" != "$CURRENT_UID" ]; then
    # Install shadow utilities for usermod/groupmod if not present.
    # --no-cache avoids leaving a package index in the image layer.
    apk add --no-cache shadow >/dev/null 2>&1 || true

    # Remap GID first (groupmod), then UID (usermod).
    # This ensures npm, next, and all child processes can read/write
    # the mounted source code and node_modules without permission errors.
    groupmod -g "$HOST_GID" node 2>/dev/null || true
    usermod -u "$HOST_UID" -g "$HOST_GID" node 2>/dev/null || true

    # Fix ownership of node's home directory after UID change.
    # npm stores its cache here (~/.npm); wrong ownership breaks installs.
    chown -R node:node /home/node 2>/dev/null || true
fi

# --------------------------------------------------------------------------
# Step 3: Install dependencies as the non-root user
# --------------------------------------------------------------------------
# --prefer-offline uses the local npm cache first, making restarts fast.
# When node_modules is already in sync with package-lock.json this is
# effectively a no-op (sub-second).
su-exec node npm install --prefer-offline 2>&1

# --------------------------------------------------------------------------
# Step 4: Drop privileges and exec the main command
# --------------------------------------------------------------------------
# su-exec replaces this shell entirely (exec semantics), so the
# application runs as PID 1 under the "node" user. No root process
# remains. If the container is compromised, the attacker has only
# the permissions of the unprivileged "node" user.
exec su-exec node "$@"
