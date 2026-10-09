// Author: ramanpal singh | URL: https://kwebby.com
import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.tsx"],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    build: { chunkSizeWarningLimit: 2000 },
});
