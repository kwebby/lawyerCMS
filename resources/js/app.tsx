// Author: ramanpal singh | URL: https://kwebby.com
import "../css/app.css";
import { createInertiaApp } from "@inertiajs/react";
import { createRoot } from "react-dom/client";
const pages = import.meta.glob<{ default: React.ComponentType<any> }>(
    "./Pages/**/*.tsx",
);
createInertiaApp({
    title: (title) => (title ? `${title} · LawyerCMS` : "LawyerCMS"),
    resolve: async (name) => {
        const loader = pages[`./Pages/${name}.tsx`];
        if (!loader) throw new Error(`Unknown page: ${name}`);
        return (await loader()).default;
    },
    setup({ el, App, props }) {
        createRoot(el!).render(<App {...props} />);
    },
    progress: { color: "#16736c" },
});
