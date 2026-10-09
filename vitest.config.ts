// Author: ramanpal singh | URL: https://kwebby.com
import { defineConfig } from "vitest/config";

// Unit tests do not need Laravel's development-server plugin.
export default defineConfig({
    test: {
        environment: "node",
        include: ["resources/js/**/*.test.{ts,tsx}"],
    },
});
