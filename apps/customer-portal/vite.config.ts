import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwind from "@tailwindcss/vite";
const proxy = {
  "/api": { target: "http://127.0.0.1:8000", changeOrigin: true },
};
export default defineConfig({
  plugins: [react(), tailwind()],
  server: { proxy },
  preview: { host: "127.0.0.1", port: 5173, proxy },
});
