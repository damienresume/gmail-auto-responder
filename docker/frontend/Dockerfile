# ============================================================================
# Next.js 15 Frontend - Node 20 Alpine
# ============================================================================
# Multi-stage build:
#   Stage 1 (deps):    Install node_modules
#   Stage 2 (builder): Build the Next.js production bundle
#   Stage 3 (runner):  Minimal runtime with only the built output
#
# Security decisions:
#   - Non-root user (nextjs) runs the application
#   - node_modules and source code are not in the final image
#   - Only the standalone output directory is copied to the runtime stage
# ============================================================================

# --------------------------------------------------------------------------
# Stage 1: Install dependencies
# --------------------------------------------------------------------------
FROM node:20-alpine AS deps

WORKDIR /app

# Copy only package manifests for Docker layer caching.
COPY frontend/package.json frontend/package-lock.json* ./

RUN npm ci --ignore-scripts

# --------------------------------------------------------------------------
# Stage 2: Build the Next.js application
# --------------------------------------------------------------------------
FROM node:20-alpine AS builder

WORKDIR /app

COPY --from=deps /app/node_modules ./node_modules
COPY frontend/ .

# Next.js collects telemetry by default. Disable it for privacy.
ENV NEXT_TELEMETRY_DISABLED=1

RUN npm run build

# --------------------------------------------------------------------------
# Stage 3: Production runtime
# --------------------------------------------------------------------------
FROM node:20-alpine AS runner

WORKDIR /app

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

# su-exec is needed by the dev entrypoint to drop from root to the
# non-root user after fixing volume permissions. Unlike gosu, su-exec
# is native to Alpine and replaces the process (PID 1, no root parent).
RUN apk add --no-cache su-exec

# Create a non-root user for production.
# UID 1001 avoids conflicts with the base image's "node" user (UID 1000).
# In development, the entrypoint remaps "node" (UID 1000) to match the
# host UID, so this user is only used in production image runs.
RUN addgroup --system --gid 1001 nodejs \
    && adduser --system --uid 1001 nextjs

# Copy the cross-platform dev entrypoint.
# In production (no volume mount), the entrypoint detects UID 0 ownership
# and skips remapping, running as nextjs via the USER directive below.
COPY docker/frontend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Copy only the standalone build output and static assets.
# This excludes node_modules, source code, and build tooling
# from the final image, reducing attack surface and image size.
COPY --from=builder /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

USER nextjs

EXPOSE 3000

ENV PORT=3000
ENV HOSTNAME="0.0.0.0"

CMD ["node", "server.js"]
