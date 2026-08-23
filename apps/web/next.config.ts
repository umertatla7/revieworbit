import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  allowedDevOrigins: ["revieworbit.test", "*.revieworbit.test"],
};

export default nextConfig;
