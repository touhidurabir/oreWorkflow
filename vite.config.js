import { resolve } from "path";
import { defineConfig } from "vite";

// https://vitejs.dev/config/
export default defineConfig({
  target: "es2016",
  plugins: [],
  build: {
    lib: {
      entry: resolve(__dirname, "resources/js/main.js"),
      name: "OreWorkflowPlugin",
      fileName: "build",
      formats: ["iife"],
    },
    outDir: resolve(__dirname, "public/build"),
    rollupOptions: {
      external: ["vue"],
      output: {
        globals: {
          vue: "pkp.modules.vue",
        },
      },
    },
  },
});
