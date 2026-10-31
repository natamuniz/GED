import { defineConfig } from "vitest/config";
import vue from "@vitejs/plugin-vue";

export default defineConfig({
  plugins: [vue()],
  server: {
    host: "0.0.0.0",
    port: 5173,
    strictPort: true,
    watch: { usePolling: true, interval: 500 },
  },
  test: {
    environment: "happy-dom",
    clearMocks: true,
    include: ["src/**/*.test.ts"],
  },
});
