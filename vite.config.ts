import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  root: "frontend",
  publicDir: false,
  plugins: [react()],
  build: { outDir: "../public", emptyOutDir: false },
  server: { proxy: { "/api.php": "http://127.0.0.1:8083" } },
});
