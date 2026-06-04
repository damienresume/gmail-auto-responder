import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Standalone output produces a self-contained server.js that includes
  // only the dependencies needed at runtime. This is required by the
  // multi-stage Docker build (docker/frontend/Dockerfile) which copies
  // .next/standalone into a minimal Alpine image for production.
  output: 'standalone',
};

export default nextConfig;
