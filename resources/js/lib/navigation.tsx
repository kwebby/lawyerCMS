// Author: ramanpal singh | URL: https://kwebby.com
import {
    createContext,
    useContext,
    useEffect,
    useRef,
    useState,
    type Dispatch,
    type ReactNode,
    type SetStateAction,
} from "react";
import { router, usePage } from "@inertiajs/react";
import type { RecordData } from "./types";

let pendingUrl: URL | null = null;
let queued = false;
function navigate(key: string, token: string | null, page: boolean) {
    const url = pendingUrl || new URL(window.location.href);
    const name = `${page ? "p" : "v"}_${key}`;
    url.searchParams.delete(name);
    if (token !== null) url.searchParams.set(name, token);
    const base = url.pathname.split("/").slice(0, 3).join("/");
    const currentPage = `p_${url.pathname.split("/")[3] || ""}`;
    const active =
        page && token !== null
            ? name
            : url.searchParams.has(currentPage)
              ? currentPage
              : [...url.searchParams.keys()]
                    .filter((k) => k.startsWith("p_"))
                    .at(-1);
    url.pathname = base + (active ? "/" + active.slice(2) : "");
    pendingUrl = url;
    if (queued) return;
    queued = true;
    queueMicrotask(() => {
        const destination = pendingUrl!;
        pendingUrl = null;
        queued = false;
        router.push({
            url: destination.pathname + destination.search,
            preserveState: true,
            preserveScroll: true,
        });
    });
}

/** URL state contains only record IDs and view choices; private form values stay in memory. */
export function usePageState<T>(
    key: string,
    initial: T,
    options: {
        records?: RecordData[];
        load?: (id: string) => Promise<T>;
        page?: boolean;
    } = {},
): [T, Dispatch<SetStateAction<T>>] {
    const { url } = usePage();
    const parameter = `${options.page === false ? "v" : "p"}_${key}`;
    const token = new URL(url, window.location.origin).searchParams.get(
        parameter,
    );
    const config = useRef(options);
    config.current = options;
    const fallback = useRef(initial);
    const decode = (value: string | null): T => {
        if (value === null) return fallback.current;
        if (typeof fallback.current === "boolean") return true as T;
        if (value === "new")
            return (fallback.current === undefined ? null : "new") as T;
        if (config.current.records || config.current.load) {
            return (config.current.records?.find((r) => r.id === value) ??
                fallback.current) as T;
        }
        return value as T;
    };
    const [value, setValue] = useState<T>(() => decode(token));
    const current = useRef(value);
    current.current = value;
    const previousToken = useRef(token);
    useEffect(() => {
        let cancelled = false;
        const changed = previousToken.current !== token;
        previousToken.current = token;
        const currentId =
            current.current && typeof current.current === "object"
                ? (current.current as unknown as RecordData).id
                : null;
        if (token === null || (changed && currentId !== token))
            setValue(decode(token));
        else if (config.current.records && token !== "new") {
            const found = config.current.records.find((r) => r.id === token);
            if (found && !config.current.load) setValue(found as T);
        }
        if (token && token !== "new" && config.current.load) {
            config.current
                .load(token)
                .then((loaded) => {
                    if (loaded === null || loaded === undefined)
                        throw new Error(
                            "This record is unavailable or you no longer have access.",
                        );
                    if (!cancelled) setValue(loaded);
                })
                .catch((error: Error) => {
                    if (!cancelled) {
                        setValue(fallback.current);
                        window.dispatchEvent(
                            new CustomEvent("workspace-page-error", {
                                detail: error.message,
                            }),
                        );
                    }
                });
        }
        return () => {
            cancelled = true;
        };
    }, [token, options.records]);
    const update: Dispatch<SetStateAction<T>> = (next) => {
        const resolved =
            typeof next === "function"
                ? (next as (value: T) => T)(current.current)
                : next;
        current.current = resolved;
        setValue(resolved);
        const isClosed =
            resolved === fallback.current ||
            resolved === false ||
            resolved === undefined;
        const identifier = isClosed
            ? null
            : resolved === null
              ? "new"
              : typeof resolved === "object"
                ? String((resolved as unknown as RecordData).id || "current")
                : resolved === true
                  ? "open"
                  : String(resolved);
        const latest = pendingUrl || new URL(window.location.href);
        if (latest.searchParams.get(parameter) !== identifier)
            navigate(key, identifier, options.page !== false);
    };
    return [value, update];
}

const PageContext = createContext<{
    target: HTMLDivElement | null;
    setTarget: (target: HTMLDivElement | null) => void;
    pages: { id: string; depth: number }[];
    register: (id: string, depth: number) => () => void;
} | null>(null);
export function WorkspacePages({ children }: { children: ReactNode }) {
    const [target, setTarget] = useState<HTMLDivElement | null>(null);
    const [pages, setPages] = useState<{ id: string; depth: number }[]>([]);
    const register = useRef((id: string, depth: number) => {
        setPages((current) =>
            [...current.filter((item) => item.id !== id), { id, depth }].sort(
                (a, b) => a.depth - b.depth,
            ),
        );
        return () =>
            setPages((current) => current.filter((item) => item.id !== id));
    }).current;
    return (
        <PageContext.Provider value={{ target, setTarget, pages, register }}>
            {children}
        </PageContext.Provider>
    );
}
export function useWorkspacePages() {
    const context = useContext(PageContext);
    if (!context)
        throw new Error("Workspace page must be inside WorkspacePages.");
    return context;
}
export function WorkspacePageOutlet({ children }: { children: ReactNode }) {
    const { setTarget, pages } = useWorkspacePages();
    const { url } = usePage();
    const [error, setError] = useState("");
    const route = new URL(url, window.location.origin);
    const requested = [...route.searchParams.keys()].some((key) =>
        key.startsWith("p_"),
    );
    useEffect(() => {
        setError("");
    }, [url]);
    useEffect(() => {
        const handle = (event: Event) =>
            setError((event as CustomEvent<string>).detail);
        window.addEventListener("workspace-page-error", handle);
        return () => window.removeEventListener("workspace-page-error", handle);
    }, []);
    return (
        <>
            <div hidden={pages.length > 0 || requested}>{children}</div>
            {error && pages.length > 0 && (
                <div className="alert alert-error" role="alert">
                    {error}
                </div>
            )}
            <div ref={setTarget} className="workspace-pages" />
            {requested && pages.length === 0 && (
                <section className="workflow-loading" role="status">
                    <h1>
                        {error
                            ? "Unable to open this page"
                            : "Opening workspace page…"}
                    </h1>
                    <p>
                        {error ||
                            "Loading the requested record and checking access."}
                    </p>
                    <a
                        className="button button-secondary"
                        href={route.pathname.split("/").slice(0, 3).join("/")}
                    >
                        Back to list
                    </a>
                </section>
            )}
        </>
    );
}
