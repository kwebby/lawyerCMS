// Author: ramanpal singh | URL: https://kwebby.com
import { useEffect, useMemo, useState, type ReactNode } from "react";
import { Link } from "@inertiajs/react";
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    ArrowUpRight,
    Check,
    CheckCircle2,
    Copy,
    Eye,
    FileText,
    Globe2,
    ImagePlus,
    MapPin,
    Palette,
    Plus,
    Save,
    Search,
    ShieldCheck,
    Trash2,
    Upload,
} from "lucide-react";
import { api, ApiError, dateLabel, patch, post } from "../lib/api";
import { usePageState } from "../lib/navigation";
import { Alert, Badge, Button, Empty, Field } from "./ui";
import {
    citationDifferences,
    contrastRatio,
    fullOfficeAddress,
    moveItem,
    newId,
    newSection,
    pagePackEntries,
    sectionNames,
    websiteChanges,
    type WebsiteCitation,
    type WebsiteDocument,
    type WebsiteFont,
    type WebsiteItem,
    type WebsiteMedia,
    type WebsiteOffice,
    type WebsiteSection,
    type WebsiteState,
} from "../lib/website";
import "../../css/website.css";

const tabs = [
    ["home", "Homepage", Globe2],
    ["brand", "Colors & fonts", Palette],
    ["media", "Public media", ImagePlus],
    ["offices", "Firm & offices", MapPin],
    ["citations", "Local listings", Search],
    ["navigation", "Navigation", ArrowUpRight],
    ["pages", "Page library", FileText],
    ["review", "Review & publish", ShieldCheck],
] as const;
type Setter<T> = (next: T) => void;

export function Website({
    canManageFonts = false,
}: {
    canManageFonts?: boolean;
}) {
    const [tab, setTab] = usePageState("website-tab", "home", { page: false });
    const [state, setState] = useState<WebsiteState | null>(null);
    const [document, setDocument] = useState<WebsiteDocument | null>(null);
    const [media, setMedia] = useState<WebsiteMedia[]>([]);
    const [fonts, setFonts] = useState<WebsiteFont[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [assetError, setAssetError] = useState("");
    const [conflict, setConflict] = useState(false);
    const [allowDiscard, setAllowDiscard] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);
    const dirty =
        !!document &&
        !!state &&
        JSON.stringify(document) !== JSON.stringify(state.draft);
    const canWrite = !!state?.capabilities.write;
    useEffect(() => {
        let cancelled = false;
        api<{ data: WebsiteState }>("/api/v1/website")
            .then((response) => {
                if (cancelled) return;
                setState(response.data);
                setDocument(response.data.draft);
                setConflict(false);
                setAllowDiscard(false);
                setError("");
            })
            .catch((failure: Error) => {
                if (!cancelled) setError(failure.message);
            });
        return () => {
            cancelled = true;
        };
    }, [reloadKey]);
    useEffect(() => {
        let cancelled = false;
        Promise.allSettled([
            api<{ data: WebsiteMedia[] }>("/api/v1/website/media"),
            api<{ data: WebsiteFont[] }>("/api/v1/website/fonts"),
        ]).then(([images, typography]) => {
            if (cancelled) return;
            if (images.status === "fulfilled") setMedia(images.value.data);
            if (typography.status === "fulfilled")
                setFonts(typography.value.data);
            const problems = [images, typography].filter(
                (result) => result.status === "rejected",
            );
            if (problems.length)
                setAssetError(
                    "Some media or font details could not load. Open their settings page to retry.",
                );
        });
        return () => {
            cancelled = true;
        };
    }, []);
    async function refreshMedia() {
        const response = await api<{ data: WebsiteMedia[] }>(
            "/api/v1/website/media",
        );
        setMedia(response.data);
        setAssetError("");
    }
    async function refreshFonts() {
        const response = await api<{ data: WebsiteFont[] }>(
            "/api/v1/website/fonts",
        );
        setFonts(response.data);
        setAssetError("");
    }
    function accept(next: WebsiteState) {
        setState(next);
        setDocument(next.draft);
        setConflict(false);
    }
    function reportFailure(failure: unknown) {
        if (failure instanceof ApiError) {
            const details = Object.values(failure.errors).flat().join(" ");
            setError(details || failure.message);
            if (failure.status === 409) setConflict(true);
        } else
            setError(
                (failure as Error).message ||
                    "This action could not be completed.",
            );
    }
    async function save() {
        if (!state || !document || busy) return;
        setBusy(true);
        setError("");
        setNotice("");
        try {
            const response = await patch<{ data: WebsiteState }>(
                "/api/v1/website/draft",
                { expected_version: state.version, document },
            );
            accept(response.data);
            setNotice(
                "Website draft saved. Your public website has not changed.",
            );
        } catch (failure) {
            reportFailure(failure);
        } finally {
            setBusy(false);
        }
    }
    async function transition(action: string, revisionId?: string) {
        if (!state || busy || dirty) return;
        setBusy(true);
        setError("");
        setNotice("");
        try {
            const response = await post<{ data: WebsiteState }>(
                `/api/v1/website/${action}`,
                {
                    expected_version: state.version,
                    ...(revisionId ? { revision_id: revisionId } : {}),
                },
            );
            accept(response.data);
            setNotice(
                (
                    {
                        review: "Draft submitted for review.",
                        approve: "Website draft approved.",
                        publish: "Website published.",
                        rollback: "Previous website revision restored.",
                    } as Record<string, string>
                )[action] || "Website updated.",
            );
        } catch (failure) {
            reportFailure(failure);
        } finally {
            setBusy(false);
        }
    }
    async function applyTheme(themeId: string) {
        if (!state || busy || dirty) return;
        setBusy(true);
        setError("");
        setNotice("");
        try {
            const response = await post<{ data: WebsiteState }>(
                "/api/v1/website/theme",
                { expected_version: state.version, theme_id: themeId },
            );
            accept(response.data);
            setNotice(
                "Theme colors, typography and navigation applied to your draft. Review before publishing.",
            );
        } catch (failure) {
            reportFailure(failure);
        } finally {
            setBusy(false);
        }
    }
    const fontCss = useMemo(
        () =>
            fonts
                .filter(
                    (font) =>
                        document &&
                        [
                            document.brand.heading_font,
                            document.brand.body_font,
                        ].includes(font.id),
                )
                .flatMap((font) =>
                    font.files.map(
                        (file) =>
                            `@font-face{font-family:${JSON.stringify(font.name)};src:url(${JSON.stringify(file.url)}) format("woff2");font-style:${file.style};font-weight:${file.weight};font-display:swap;${file.unicode_range ? `unicode-range:${file.unicode_range};` : ""}}`,
                    ),
                )
                .join("\n"),
        [fonts, document?.brand.heading_font, document?.brand.body_font],
    );
    if (!state || !document)
        return (
            <div className="website-workspace">
                <Alert>{error}</Alert>
                {error ? (
                    <Button
                        variant="secondary"
                        onClick={() => setReloadKey((key) => key + 1)}
                    >
                        Retry website settings
                    </Button>
                ) : (
                    <div className="website-loading" role="status">
                        Loading website settings…
                    </div>
                )}
            </div>
        );
    const updateDocument = (next: WebsiteDocument) => {
        setDocument(next);
        setNotice("");
    };
    return (
        <div className="website-workspace">
            {fontCss && <style>{fontCss}</style>}
            <div className="website-toolbar">
                <div className="website-save-state">
                    <span
                        className={`website-state-dot ${dirty ? "is-dirty" : ""}`}
                    />
                    <strong>
                        {dirty
                            ? "Unsaved changes"
                            : `Draft version ${state.version}`}
                    </strong>
                    <Badge>{state.status}</Badge>
                    <span>
                        {state.published
                            ? `Live since ${dateLabel(state.published.published_at)}`
                            : "Website settings have not been published"}
                    </span>
                </div>
                <div className="website-actions">
                    <a
                        className="button button-secondary"
                        href="/api/v1/website/preview"
                        target="_blank"
                        rel="noreferrer"
                    >
                        <Eye size={16} /> Preview saved draft{" "}
                        <ArrowUpRight size={14} />
                    </a>
                    <Button
                        disabled={!dirty || !canWrite || busy || conflict}
                        onClick={save}
                    >
                        <Save size={16} />
                        {busy ? "Working…" : "Save draft"}
                    </Button>
                </div>
            </div>
            <Alert>{error}</Alert>
            <Alert kind="success">{notice}</Alert>
            <Alert kind="info">{assetError}</Alert>
            {conflict && (
                <div className="website-conflict" role="alert">
                    <h3>A newer version is available</h3>
                    <p>
                        Your edits are still here. Copy your draft before
                        loading the current server version, then reapply the
                        changes you need.
                    </p>
                    <button
                        className="text-link"
                        onClick={() => {
                            const blob = new Blob(
                                [JSON.stringify(document, null, 2)],
                                { type: "application/json" },
                            );
                            const url = URL.createObjectURL(blob);
                            const anchor = window.document.createElement("a");
                            anchor.href = url;
                            anchor.download = "website-unsaved-draft.json";
                            anchor.click();
                            setTimeout(() => URL.revokeObjectURL(url), 1000);
                        }}
                    >
                        Download my unsaved draft
                    </button>
                    <label className="website-check">
                        <input
                            type="checkbox"
                            checked={allowDiscard}
                            onChange={(event) =>
                                setAllowDiscard(event.target.checked)
                            }
                        />{" "}
                        I have kept a copy of my changes.
                    </label>
                    <Button
                        variant="secondary"
                        disabled={!allowDiscard}
                        onClick={() => setReloadKey((key) => key + 1)}
                    >
                        Load current server version
                    </Button>
                </div>
            )}
            <nav className="website-tabs" aria-label="Website settings">
                {tabs.map(([key, label, Icon]) => (
                    <button
                        key={key}
                        type="button"
                        aria-current={tab === key ? "page" : undefined}
                        className={tab === key ? "is-active" : ""}
                        onClick={() => setTab(key)}
                    >
                        <Icon size={16} />
                        {label}
                    </button>
                ))}
            </nav>
            {!canWrite && (
                <Alert kind="info">
                    You can review these settings. Editing requires website
                    management permission.
                </Alert>
            )}
            <fieldset className="website-fieldset" disabled={busy || !canWrite}>
                <div hidden={tab !== "home"}>
                    <Homepage
                        document={document}
                        change={updateDocument}
                        media={media}
                        catalog={state.catalog}
                    />
                </div>
                <div hidden={tab !== "brand"}>
                    <Brand
                        document={document}
                        change={updateDocument}
                        fonts={fonts}
                        media={media}
                        dirty={dirty}
                        applyTheme={applyTheme}
                    />
                </div>
                <div hidden={tab !== "media"}>
                    <MediaLibrary media={media} refresh={refreshMedia} />
                </div>
                <div hidden={tab !== "offices"}>
                    <Offices document={document} change={updateDocument} />
                </div>
                <div hidden={tab !== "citations"}>
                    <Citations
                        document={document}
                        change={updateDocument}
                        statuses={state.catalog.citation_statuses}
                    />
                </div>
                <div hidden={tab !== "navigation"}>
                    <Navigation document={document} change={updateDocument} />
                </div>
                <div hidden={tab !== "pages"}>
                    <PageLibrary
                        state={state}
                        dirty={dirty}
                        accept={accept}
                        reportFailure={reportFailure}
                        setWorking={setBusy}
                    />
                </div>
            </fieldset>
            <div hidden={tab !== "brand"}>
                <FontCatalog
                    canManage={canManageFonts}
                    fonts={fonts}
                    refreshFonts={refreshFonts}
                />
            </div>
            {tab === "review" && (
                <PublishingReview
                    state={state}
                    document={document}
                    dirty={dirty}
                    busy={busy}
                    transition={transition}
                />
            )}
            <div className="website-bottom-note">
                <span>
                    {dirty
                        ? "Changes stay in this draft as you switch settings pages. Save before leaving Website settings."
                        : "Draft changes go live only after review and publication."}
                </span>
                <Link
                    href="/app/settings?v_tab=publishing"
                    className="text-link"
                >
                    Search, social & schema settings <ArrowUpRight size={14} />
                </Link>
            </div>
        </div>
    );
}

interface FontCatalogEntry {
    family: string;
    category: string;
    variants: string[];
    subsets: string[];
    installed: boolean;
    id?: string | null;
}
function FontCatalog({
    canManage,
    fonts,
    refreshFonts,
}: {
    canManage: boolean;
    fonts: WebsiteFont[];
    refreshFonts: () => Promise<void>;
}) {
    const [apiKey, setApiKey] = useState("");
    const [configured, setConfigured] = useState<boolean | null>(null);
    const [savingKey, setSavingKey] = useState(false);
    const [settingsError, setSettingsError] = useState("");
    const [settingsNotice, setSettingsNotice] = useState("");
    const [query, setQuery] = useState("");
    const [searched, setSearched] = useState(false);
    const [searching, setSearching] = useState(false);
    const [results, setResults] = useState<FontCatalogEntry[]>([]);
    const [catalogPage, setCatalogPage] = useState(0);
    const [catalogError, setCatalogError] = useState("");
    const [catalogNotice, setCatalogNotice] = useState("");
    const [installing, setInstalling] = useState("");
    const [cached, setCached] = useState(false);
    const catalogPageSize = 24;
    const catalogPageCount = Math.ceil(results.length / catalogPageSize);
    const visibleResults = results.slice(
        catalogPage * catalogPageSize,
        (catalogPage + 1) * catalogPageSize,
    );
    useEffect(() => {
        if (!canManage) return;
        let cancelled = false;
        api<{ data: { api_key_configured: boolean } }>(
            "/api/v1/website/fonts/settings",
        )
            .then((response) => {
                if (!cancelled) setConfigured(response.data.api_key_configured);
            })
            .catch((failure: Error) => {
                if (!cancelled) setSettingsError(failure.message);
            });
        return () => {
            cancelled = true;
        };
    }, [canManage]);
    async function saveKey() {
        if (!apiKey.trim()) return;
        setSavingKey(true);
        setSettingsError("");
        setSettingsNotice("");
        try {
            const response = await patch<{
                data: { api_key_configured: boolean };
            }>("/api/v1/website/fonts/settings", { api_key: apiKey.trim() });
            setConfigured(response.data.api_key_configured);
            setApiKey("");
            setSettingsNotice(
                "Google Fonts API key saved. Search the catalog to browse more fonts.",
            );
        } catch (failure) {
            setSettingsError((failure as Error).message);
        } finally {
            setSavingKey(false);
        }
    }
    async function search() {
        setSearching(true);
        setCatalogError("");
        setCatalogNotice("");
        try {
            const response = await api<{
                data: FontCatalogEntry[];
                meta: {
                    configured: boolean;
                    cached?: boolean;
                    source?: string;
                };
            }>(
                `/api/v1/website/fonts/catalog?q=${encodeURIComponent(query.trim())}`,
            );
            setResults(response.data);
            setCatalogPage(0);
            setCached(response.meta?.cached === true);
            setSearched(true);
            if (canManage) setConfigured(response.meta?.configured === true);
        } catch (failure) {
            setCatalogError((failure as Error).message);
        } finally {
            setSearching(false);
        }
    }
    async function install(family: string) {
        setInstalling(family);
        setCatalogError("");
        setCatalogNotice("");
        try {
            const response = await post<{ data: WebsiteFont }>(
                "/api/v1/website/fonts/install",
                { family },
            );
            setResults((current) =>
                current.map((font) =>
                    font.family === family
                        ? { ...font, installed: true, id: response.data.id }
                        : font,
                ),
            );
            await refreshFonts();
            setCatalogNotice(
                `${family} installed. Select it in the heading or body font controls above.`,
            );
        } catch (failure) {
            setCatalogError((failure as Error).message);
        } finally {
            setInstalling("");
        }
    }
    return (
        <section className="website-editor website-font-catalog form-stack">
            <div className="website-subheading">
                <div>
                    <h3>Explore the Google Fonts catalog</h3>
                    <p>
                        Search additional families and install the fonts your
                        website needs. Installed files are served from your own
                        server.
                    </p>
                </div>
            </div>
            {canManage && (
                <div className="website-font-connection">
                    <div className="website-subheading">
                        <h4>Catalog connection</h4>
                        {configured !== null && (
                            <Badge tone={configured ? "green" : "neutral"}>
                                {configured
                                    ? "API key saved"
                                    : "API key not configured"}
                            </Badge>
                        )}
                    </div>
                    <Alert>{settingsError}</Alert>
                    <Alert kind="success">{settingsNotice}</Alert>
                    <form
                        className="website-theme-controls"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void saveKey();
                        }}
                    >
                        <Field
                            label={
                                configured
                                    ? "Replace Google Fonts API key"
                                    : "Google Fonts API key"
                            }
                            hint="The saved key is kept on the server and is never displayed here."
                        >
                            <input
                                type="password"
                                autoComplete="new-password"
                                value={apiKey}
                                onChange={(event) =>
                                    setApiKey(event.target.value)
                                }
                                placeholder={
                                    configured
                                        ? "Enter a new key to replace the saved one"
                                        : "Enter your Google Fonts Developer API key"
                                }
                                disabled={savingKey}
                            />
                        </Field>
                        <Button
                            type="submit"
                            variant="secondary"
                            disabled={savingKey || !apiKey.trim()}
                        >
                            {savingKey ? "Saving key…" : "Save API key"}
                        </Button>
                    </form>
                    <p className="website-help">
                        The installed font library works without this
                        connection.{" "}
                        <a
                            className="text-link"
                            href="https://developers.google.com/fonts/docs/developer_api"
                            target="_blank"
                            rel="noreferrer"
                        >
                            Google Fonts API setup <ArrowUpRight size={13} />
                        </a>
                    </p>
                </div>
            )}
            {!canManage && (
                <p className="website-help">
                    An owner or administrator can configure the catalog
                    connection and install additional fonts.
                </p>
            )}
            <form
                className="website-theme-controls"
                onSubmit={(event) => {
                    event.preventDefault();
                    void search();
                }}
            >
                <Field label="Search the catalog">
                    <input
                        value={query}
                        maxLength={100}
                        placeholder="Family name, category or language"
                        onChange={(event) => setQuery(event.target.value)}
                        disabled={searching}
                    />
                </Field>
                <Button
                    type="submit"
                    variant="secondary"
                    disabled={searching || !!installing}
                >
                    <Search size={15} />
                    {searching ? "Searching…" : "Search catalog"}
                </Button>
            </form>
            <Alert>{catalogError}</Alert>
            <Alert kind="success">{catalogNotice}</Alert>
            <p className="website-help">
                Install up to 100 additional font families. New installs include
                available upright regular (400), semibold (600) and bold (700)
                styles.
            </p>
            {searched && (
                <>
                    <p className="website-help" role="status">
                        {results.length
                            ? `Showing ${catalogPage * catalogPageSize + 1}–${Math.min((catalogPage + 1) * catalogPageSize, results.length)} of ${results.length} matching families`
                            : "No matching families"}
                        {cached ? " from the saved catalog" : ""}.{" "}
                        {results.length
                            ? "Narrow the search to find a specific font."
                            : "Try another family name or language."}
                    </p>
                    <div className="website-font-grid">
                        {visibleResults.map((font) => {
                            const installed =
                                font.installed ||
                                fonts.some(
                                    (entry) =>
                                        entry.name.toLowerCase() ===
                                        font.family.toLowerCase(),
                                );
                            return (
                                <article
                                    className="website-font-card"
                                    key={font.family}
                                >
                                    <div>
                                        <h4>{font.family}</h4>
                                        <small>
                                            {font.category.replaceAll("-", " ")}{" "}
                                            · {(font.subsets || []).join(", ")}
                                        </small>
                                    </div>
                                    <small>
                                        {font.variants.length} styles in catalog
                                    </small>
                                    {installed ? (
                                        <Badge tone="green">Installed</Badge>
                                    ) : canManage ? (
                                        <Button
                                            variant="secondary"
                                            disabled={!!installing || searching}
                                            onClick={() => install(font.family)}
                                        >
                                            <Upload size={14} />
                                            {installing === font.family
                                                ? "Installing font…"
                                                : "Install font"}
                                        </Button>
                                    ) : (
                                        <span className="website-help">
                                            Administrator installation required
                                        </span>
                                    )}
                                </article>
                            );
                        })}
                    </div>
                    {catalogPageCount > 1 && (
                        <nav
                            className="website-subheading"
                            aria-label="Font catalog pages"
                        >
                            <Button
                                variant="secondary"
                                disabled={catalogPage === 0 || searching}
                                onClick={() =>
                                    setCatalogPage((page) =>
                                        Math.max(0, page - 1),
                                    )
                                }
                            >
                                Previous page
                            </Button>
                            <span className="website-help">
                                Page {catalogPage + 1} of {catalogPageCount}
                            </span>
                            <Button
                                variant="secondary"
                                disabled={
                                    catalogPage + 1 >= catalogPageCount ||
                                    searching
                                }
                                onClick={() =>
                                    setCatalogPage((page) =>
                                        Math.min(
                                            catalogPageCount - 1,
                                            page + 1,
                                        ),
                                    )
                                }
                            >
                                Next page
                            </Button>
                        </nav>
                    )}
                </>
            )}
        </section>
    );
}

function TextInput({
    label,
    value,
    change,
    hint,
    multiline = false,
    type = "text",
    maxLength,
    placeholder,
}: {
    label: string;
    value?: string;
    change: Setter<string>;
    hint?: string;
    multiline?: boolean;
    type?: string;
    maxLength?: number;
    placeholder?: string;
}) {
    return (
        <Field label={label} hint={hint}>
            {multiline ? (
                <textarea
                    value={value || ""}
                    onChange={(event) => change(event.target.value)}
                    rows={4}
                    maxLength={maxLength ?? 5000}
                    placeholder={placeholder}
                />
            ) : (
                <input
                    type={type}
                    value={value || ""}
                    onChange={(event) => change(event.target.value)}
                    maxLength={maxLength ?? 500}
                    placeholder={placeholder}
                />
            )}
        </Field>
    );
}
function PageHeading({
    title,
    description,
    action,
}: {
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <header className="website-page-heading">
            <div>
                <h2>{title}</h2>
                <p>{description}</p>
            </div>
            {action}
        </header>
    );
}
function ImagePicker({
    label = "Image",
    value,
    change,
    media,
}: {
    label?: string;
    value: string;
    change: Setter<string>;
    media: WebsiteMedia[];
}) {
    const ready = media.filter((asset) => asset.status === "ready");
    const selected = media.find((asset) => asset.id === value);
    return (
        <div className="website-image-picker">
            <Field
                label={label}
                hint="Only scanned public media can be selected."
            >
                <select
                    value={value || ""}
                    onChange={(event) => change(event.target.value)}
                >
                    <option value="">No image</option>
                    {value && !ready.some((asset) => asset.id === value) && (
                        <option value={value}>
                            Unavailable image — replace before publishing
                        </option>
                    )}
                    {ready.map((asset) => (
                        <option key={asset.id} value={asset.id}>
                            {asset.name}
                        </option>
                    ))}
                </select>
            </Field>
            {selected?.preview_url && (
                <img
                    src={selected.preview_url}
                    alt={selected.alt || "Selected image preview"}
                    width={selected.width}
                    height={selected.height}
                    style={{
                        objectPosition: `${selected.focal_x ?? 50}% ${selected.focal_y ?? 50}%`,
                    }}
                />
            )}
        </div>
    );
}
function Homepage({
    document,
    change,
    media,
    catalog,
}: {
    document: WebsiteDocument;
    change: Setter<WebsiteDocument>;
    media: WebsiteMedia[];
    catalog: WebsiteState["catalog"];
}) {
    const [selected, setSelected] = usePageState("website-section", "", {
        page: false,
    });
    const [addType, setAddType] = useState("content");
    const [removed, setRemoved] = useState<{
        section: WebsiteSection;
        index: number;
    } | null>(null);
    const sections = document.home.sections;
    const current =
        sections.find((section) => section.id === selected) || sections[0];
    const replace = (next: WebsiteSection[]) =>
        change({ ...document, home: { ...document.home, sections: next } });
    const update = (next: WebsiteSection) =>
        replace(
            sections.map((section) =>
                section.id === next.id ? next : section,
            ),
        );
    function add() {
        const section = newSection(addType);
        replace([...sections, section]);
        setSelected(section.id);
    }
    return (
        <>
            <PageHeading
                title="Build your homepage"
                description="Guide visitors from their legal question to the right person. Edit, reorder or hide each section."
                action={
                    <span className="website-count">
                        {sections.filter((section) => section.visible).length}{" "}
                        visible sections
                    </span>
                }
            />
            <div className="website-home-meta form-grid">
                <TextInput
                    label="Homepage title"
                    value={document.home.title}
                    change={(title) =>
                        change({
                            ...document,
                            home: { ...document.home, title },
                        })
                    }
                    maxLength={200}
                />
                <TextInput
                    label="Search description"
                    value={document.home.description}
                    change={(description) =>
                        change({
                            ...document,
                            home: { ...document.home, description },
                        })
                    }
                    maxLength={500}
                />
            </div>
            {removed && (
                <div className="website-inline-notice" role="status">
                    “{removed.section.heading}” removed from this draft.{" "}
                    <button
                        className="text-link"
                        onClick={() => {
                            const next = [...sections];
                            next.splice(
                                Math.min(removed.index, next.length),
                                0,
                                removed.section,
                            );
                            replace(next);
                            setRemoved(null);
                        }}
                    >
                        Undo removal
                    </button>
                </div>
            )}
            <div className="website-builder">
                <aside className="website-outline">
                    <h3>Page sections</h3>
                    <ol>
                        {sections.map((section, index) => (
                            <li
                                key={section.id}
                                className={`${current?.id === section.id ? "is-selected" : ""} ${!section.visible ? "is-hidden" : ""}`}
                            >
                                <button
                                    className="website-section-select"
                                    onClick={() => setSelected(section.id)}
                                >
                                    <span className="website-order">
                                        {index + 1}
                                    </span>
                                    <span>
                                        <strong>
                                            {section.heading ||
                                                sectionNames[section.type]}
                                        </strong>
                                        <small>
                                            {sectionNames[section.type]}
                                            {!section.visible && " · Hidden"}
                                        </small>
                                    </span>
                                </button>
                                <div className="website-outline-controls">
                                    <button
                                        type="button"
                                        disabled={index === 0}
                                        aria-label={`Move ${section.heading} up`}
                                        onClick={() =>
                                            replace(
                                                moveItem(sections, index, -1),
                                            )
                                        }
                                    >
                                        <ArrowUp size={14} />
                                    </button>
                                    <button
                                        type="button"
                                        disabled={index === sections.length - 1}
                                        aria-label={`Move ${section.heading} down`}
                                        onClick={() =>
                                            replace(
                                                moveItem(sections, index, 1),
                                            )
                                        }
                                    >
                                        <ArrowDown size={14} />
                                    </button>
                                    <label title="Show section">
                                        <input
                                            aria-label={`Show ${section.heading}`}
                                            type="checkbox"
                                            checked={section.visible}
                                            onChange={(event) =>
                                                update({
                                                    ...section,
                                                    visible:
                                                        event.target.checked,
                                                })
                                            }
                                        />
                                    </label>
                                </div>
                            </li>
                        ))}
                    </ol>
                    <div className="website-add-section">
                        <Field label="Add a section">
                            <select
                                value={addType}
                                onChange={(event) =>
                                    setAddType(event.target.value)
                                }
                            >
                                {catalog.section_types.map((type) => (
                                    <option key={type} value={type}>
                                        {sectionNames[type] || type}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Button variant="secondary" onClick={add}>
                            <Plus size={15} />
                            Add section
                        </Button>
                    </div>
                </aside>
                {current ? (
                    <section className="website-editor" key={current.id}>
                        <div className="website-editor-heading">
                            <div>
                                <small>{sectionNames[current.type]}</small>
                                <h3>{current.heading || "Untitled section"}</h3>
                            </div>
                            <div className="website-actions">
                                <Button
                                    variant="ghost"
                                    title="Duplicate section"
                                    onClick={() => {
                                        const copy = {
                                            ...current,
                                            id: newId("section"),
                                            items: current.items.map(
                                                (item) => ({
                                                    ...item,
                                                    id: newId("item"),
                                                }),
                                            ),
                                        };
                                        replace([...sections, copy]);
                                        setSelected(copy.id);
                                    }}
                                >
                                    <Copy size={16} />
                                    <span className="sr-only">
                                        Duplicate section
                                    </span>
                                </Button>
                                <Button
                                    variant="ghost"
                                    title="Remove section"
                                    onClick={() => {
                                        setRemoved({
                                            section: current,
                                            index: sections.findIndex(
                                                (section) =>
                                                    section.id === current.id,
                                            ),
                                        });
                                        replace(
                                            sections.filter(
                                                (section) =>
                                                    section.id !== current.id,
                                            ),
                                        );
                                        setSelected("");
                                    }}
                                >
                                    <Trash2 size={16} />
                                    <span className="sr-only">
                                        Remove section
                                    </span>
                                </Button>
                            </div>
                        </div>
                        <div className="form-stack">
                            <div className="form-grid">
                                <TextInput
                                    label="Heading"
                                    value={current.heading}
                                    change={(heading) =>
                                        update({ ...current, heading })
                                    }
                                    maxLength={200}
                                />
                                <Field label="Layout">
                                    <select
                                        value={current.layout}
                                        onChange={(event) =>
                                            update({
                                                ...current,
                                                layout: event.target.value,
                                            })
                                        }
                                    >
                                        {catalog.layouts.map((layout) => (
                                            <option key={layout} value={layout}>
                                                {layout
                                                    .charAt(0)
                                                    .toUpperCase() +
                                                    layout.slice(1)}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </div>
                            <TextInput
                                label="Body text"
                                value={current.text}
                                change={(text) => update({ ...current, text })}
                                multiline
                                maxLength={5000}
                            />
                            <div className="form-grid">
                                <ImagePicker
                                    value={current.image_id}
                                    change={(image_id) =>
                                        update({
                                            ...current,
                                            image_id,
                                            image_alt:
                                                media.find(
                                                    (asset) =>
                                                        asset.id === image_id,
                                                )?.alt || "",
                                        })
                                    }
                                    media={media}
                                />
                                <TextInput
                                    label="Image alternative text"
                                    value={current.image_alt}
                                    change={(image_alt) =>
                                        update({ ...current, image_alt })
                                    }
                                    hint="Describe the meaningful content of the image."
                                />
                            </div>
                            <div className="website-subheading">
                                <h4>Buttons</h4>
                                <p>
                                    Use a page path, an HTTPS destination, a
                                    phone or an email link.
                                </p>
                            </div>
                            <div className="form-grid">
                                <TextInput
                                    label="Primary button label"
                                    value={current.button_label}
                                    change={(button_label) =>
                                        update({ ...current, button_label })
                                    }
                                />
                                <TextInput
                                    label="Primary destination"
                                    value={current.button_url}
                                    change={(button_url) =>
                                        update({ ...current, button_url })
                                    }
                                    placeholder="/contact or tel:+…"
                                />
                                <TextInput
                                    label="Secondary button label"
                                    value={current.secondary_label}
                                    change={(secondary_label) =>
                                        update({ ...current, secondary_label })
                                    }
                                />
                                <TextInput
                                    label="Secondary destination"
                                    value={current.secondary_url}
                                    change={(secondary_url) =>
                                        update({ ...current, secondary_url })
                                    }
                                    placeholder="/practices"
                                />
                            </div>
                            {current.type === "locations" && (
                                <div className="website-office-choices">
                                    <h4>Office records to display</h4>
                                    {document.offices.length ? (
                                        document.offices.map((office) => (
                                            <label
                                                className="website-check"
                                                key={office.id}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={current.office_ids.includes(
                                                        office.id,
                                                    )}
                                                    onChange={(event) =>
                                                        update({
                                                            ...current,
                                                            office_ids: event
                                                                .target.checked
                                                                ? [
                                                                      ...current.office_ids,
                                                                      office.id,
                                                                  ]
                                                                : current.office_ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          office.id,
                                                                  ),
                                                        })
                                                    }
                                                />
                                                {office.name ||
                                                    "Unnamed office"}
                                                {!office.published && (
                                                    <Badge>Hidden</Badge>
                                                )}
                                            </label>
                                        ))
                                    ) : (
                                        <p>
                                            Add firm offices in Firm & offices
                                            first.
                                        </p>
                                    )}
                                </div>
                            )}
                            {["testimonials", "results", "awards"].includes(
                                current.type,
                            ) && (
                                <div className="website-proof">
                                    <h4>Evidence for publication</h4>
                                    <p>
                                        Record the source and permission or
                                        context for these claims. The publishing
                                        reviewer must approve the content.
                                    </p>
                                    <TextInput
                                        label="Evidence source URL"
                                        value={current.proof_source_url}
                                        change={(proof_source_url) =>
                                            update({
                                                ...current,
                                                proof_source_url,
                                            })
                                        }
                                    />
                                    <TextInput
                                        label="Verification / permission notes"
                                        value={current.proof_note}
                                        change={(proof_note) =>
                                            update({ ...current, proof_note })
                                        }
                                        multiline
                                    />
                                </div>
                            )}
                            <div className="website-subheading">
                                <div>
                                    <h4>
                                        {current.type === "faq"
                                            ? "Questions & answers"
                                            : "Cards & items"}
                                    </h4>
                                    <p>
                                        Use these for services, people, process
                                        steps or linked resources.
                                    </p>
                                </div>
                                <Button
                                    variant="secondary"
                                    onClick={() =>
                                        update({
                                            ...current,
                                            items: [
                                                ...current.items,
                                                {
                                                    id: newId("item"),
                                                    title: "",
                                                    text: "",
                                                    image_id: "",
                                                    image_alt: "",
                                                    url: "",
                                                },
                                            ],
                                        })
                                    }
                                >
                                    <Plus size={15} />
                                    Add item
                                </Button>
                            </div>
                            {current.items.map((item, index) => (
                                <ItemEditor
                                    key={item.id}
                                    item={item}
                                    index={index}
                                    count={current.items.length}
                                    media={media}
                                    change={(next) =>
                                        update({
                                            ...current,
                                            items: current.items.map((entry) =>
                                                entry.id === item.id
                                                    ? next
                                                    : entry,
                                            ),
                                        })
                                    }
                                    move={(direction) =>
                                        update({
                                            ...current,
                                            items: moveItem(
                                                current.items,
                                                index,
                                                direction,
                                            ),
                                        })
                                    }
                                    remove={() =>
                                        update({
                                            ...current,
                                            items: current.items.filter(
                                                (entry) => entry.id !== item.id,
                                            ),
                                        })
                                    }
                                />
                            ))}
                        </div>
                    </section>
                ) : (
                    <Empty
                        title="Start your homepage"
                        action={
                            <Button onClick={add}>Add the first section</Button>
                        }
                    >
                        Choose a section type from the outline.
                    </Empty>
                )}
            </div>
        </>
    );
}
function ItemEditor({
    item,
    index,
    count,
    change,
    move,
    remove,
    media,
}: {
    item: WebsiteItem;
    index: number;
    count: number;
    change: Setter<WebsiteItem>;
    move: (direction: -1 | 1) => void;
    remove: () => void;
    media: WebsiteMedia[];
}) {
    return (
        <div className="website-item-editor">
            <div className="website-subheading">
                <h4>{item.title || `Item ${index + 1}`}</h4>
                <div className="website-actions">
                    <Button
                        variant="ghost"
                        aria-label={`Move item ${index + 1} up`}
                        disabled={index === 0}
                        onClick={() => move(-1)}
                    >
                        <ArrowUp size={14} />
                    </Button>
                    <Button
                        variant="ghost"
                        aria-label={`Move item ${index + 1} down`}
                        disabled={index === count - 1}
                        onClick={() => move(1)}
                    >
                        <ArrowDown size={14} />
                    </Button>
                    <Button
                        variant="ghost"
                        aria-label={`Remove item ${index + 1}`}
                        onClick={remove}
                    >
                        <Trash2 size={14} />
                    </Button>
                </div>
            </div>
            <div className="form-grid">
                <TextInput
                    label="Title / question"
                    value={item.title}
                    change={(title) => change({ ...item, title })}
                    maxLength={200}
                />
                <TextInput
                    label="Link destination"
                    value={item.url}
                    change={(url) => change({ ...item, url })}
                />
            </div>
            <TextInput
                label="Description / answer"
                value={item.text}
                change={(text) => change({ ...item, text })}
                multiline
                maxLength={2000}
            />
            <div className="form-grid">
                <ImagePicker
                    value={item.image_id}
                    change={(image_id) =>
                        change({
                            ...item,
                            image_id,
                            image_alt:
                                media.find((asset) => asset.id === image_id)
                                    ?.alt || "",
                        })
                    }
                    media={media}
                />
                <TextInput
                    label="Image alternative text"
                    value={item.image_alt}
                    change={(image_alt) => change({ ...item, image_alt })}
                />
            </div>
        </div>
    );
}

function Brand({
    document,
    change,
    fonts,
    media,
    dirty,
    applyTheme,
}: {
    document: WebsiteDocument;
    change: Setter<WebsiteDocument>;
    fonts: WebsiteFont[];
    media: WebsiteMedia[];
    dirty: boolean;
    applyTheme: (themeId: string) => Promise<void>;
}) {
    const [search, setSearch] = useState("");
    const [themes, setThemes] = useState<
        {
            id: string;
            name?: string;
            theme_version?: string;
            manifest?: { name?: string; version?: string };
        }[]
    >([]);
    const [themeId, setThemeId] = useState("");
    const [themeError, setThemeError] = useState("");
    useEffect(() => {
        let cancelled = false;
        api<{ data: typeof themes }>("/api/v1/themes")
            .then((response) => {
                if (!cancelled) setThemes(response.data || []);
            })
            .catch((failure: Error) => {
                if (!cancelled) setThemeError(failure.message);
            });
        return () => {
            cancelled = true;
        };
    }, []);
    const brand = document.brand;
    const update = (next: Partial<WebsiteDocument["brand"]>) =>
        change({ ...document, brand: { ...brand, ...next } });
    const contrast = contrastRatio(brand.colors.text, brand.colors.background);
    const buttonContrast = contrastRatio(
        brand.colors.primary_text,
        brand.colors.primary,
    );
    const headingFont = fonts.find((font) => font.id === brand.heading_font);
    const bodyFont = fonts.find((font) => font.id === brand.body_font);
    const filtered = fonts.filter((font) =>
        [font.name, font.category, ...(font.languages || [])]
            .join(" ")
            .toLowerCase()
            .includes(search.toLowerCase()),
    );
    return (
        <>
            <PageHeading
                title="Your firm’s visual identity"
                description="Choose an accessible palette and readable typography. These settings apply to the public website."
            />
            <div className="website-editor website-theme-preset form-stack">
                <div className="website-subheading">
                    <div>
                        <h3>Start from a theme</h3>
                        <p>
                            Apply its palette, font preset, page templates and
                            navigation to this draft.
                        </p>
                    </div>
                    <Link href="/app/themes" className="text-link">
                        Design or upload a theme <ArrowUpRight size={14} />
                    </Link>
                </div>
                <Alert>{themeError}</Alert>
                <div className="website-theme-controls">
                    <Field label="Installed theme">
                        <select
                            value={themeId}
                            onChange={(event) => setThemeId(event.target.value)}
                        >
                            <option value="">Select a theme</option>
                            {themes.map((theme) => (
                                <option value={theme.id} key={theme.id}>
                                    {theme.name ||
                                        theme.manifest?.name ||
                                        theme.id}
                                    {theme.theme_version ||
                                    theme.manifest?.version
                                        ? ` — ${theme.theme_version || theme.manifest?.version}`
                                        : ""}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Button
                        variant="secondary"
                        disabled={dirty || !themeId}
                        onClick={() => applyTheme(themeId)}
                    >
                        Apply to draft
                    </Button>
                </div>
                {dirty && (
                    <p className="website-help">
                        Save your changes before applying a theme preset.
                    </p>
                )}
            </div>
            <div className="website-brand-layout">
                <div className="website-editor form-stack">
                    <h3>Logo & colors</h3>
                    <ImagePicker
                        label="Firm logo"
                        value={document.organization.logo_id}
                        change={(logo_id) =>
                            change({
                                ...document,
                                organization: {
                                    ...document.organization,
                                    logo_id,
                                },
                            })
                        }
                        media={media}
                    />
                    <div className="website-colors">
                        {Object.entries(brand.colors).map(([key, value]) => (
                            <Field key={key} label={key.replaceAll("_", " ")}>
                                <div className="website-color-input">
                                    <input
                                        type="color"
                                        aria-label={`${key} color picker`}
                                        value={
                                            /^#[a-f0-9]{6}$/i.test(value)
                                                ? value
                                                : "#000000"
                                        }
                                        onChange={(event) =>
                                            update({
                                                colors: {
                                                    ...brand.colors,
                                                    [key]: event.target.value,
                                                },
                                            })
                                        }
                                    />
                                    <input
                                        aria-label={`${key} hexadecimal color`}
                                        value={value}
                                        maxLength={7}
                                        onChange={(event) =>
                                            update({
                                                colors: {
                                                    ...brand.colors,
                                                    [key]: event.target.value,
                                                },
                                            })
                                        }
                                    />
                                </div>
                            </Field>
                        ))}
                    </div>
                    <div className="website-contrast">
                        <span>
                            <strong>Body text</strong>{" "}
                            {contrast
                                ? `${contrast.toFixed(2)}:1`
                                : "Invalid color"}{" "}
                            <Badge
                                tone={
                                    contrast && contrast >= 4.5
                                        ? "green"
                                        : "amber"
                                }
                            >
                                {contrast && contrast >= 4.5
                                    ? "AA contrast"
                                    : "Needs adjustment"}
                            </Badge>
                        </span>
                        <span>
                            <strong>Button text</strong>{" "}
                            {buttonContrast
                                ? `${buttonContrast.toFixed(2)}:1`
                                : "Invalid color"}{" "}
                            <Badge
                                tone={
                                    buttonContrast && buttonContrast >= 4.5
                                        ? "green"
                                        : "amber"
                                }
                            >
                                {buttonContrast && buttonContrast >= 4.5
                                    ? "AA contrast"
                                    : "Needs adjustment"}
                            </Badge>
                        </span>
                    </div>
                    <div className="form-grid">
                        <Field label="Corner radius (px)">
                            <input
                                type="number"
                                min={0}
                                max={24}
                                value={brand.radius}
                                onChange={(event) =>
                                    update({
                                        radius: Number(event.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field label="Content width (px)">
                            <input
                                type="number"
                                min={720}
                                max={1440}
                                step={20}
                                value={brand.width}
                                onChange={(event) =>
                                    update({
                                        width: Number(event.target.value),
                                    })
                                }
                            />
                        </Field>
                    </div>
                </div>
                <div
                    className="website-brand-preview"
                    style={{
                        background: brand.colors.background,
                        color: brand.colors.text,
                        borderColor: brand.colors.border,
                        fontFamily: bodyFont?.css_family || "sans-serif",
                    }}
                >
                    <div className="website-preview-bar">
                        Website style preview
                    </div>
                    <div className="website-preview-content">
                        <span style={{ color: brand.colors.muted }}>
                            {document.organization.name || "Your law firm"}
                        </span>
                        <h2
                            style={{
                                fontFamily: headingFont?.css_family || "serif",
                            }}
                        >
                            Clear advice for your next step.
                        </h2>
                        <p>
                            Speak with a team that understands your priorities.
                            Explore our services and learn how we can help.
                        </p>
                        <span
                            className="website-preview-button"
                            style={{
                                background: brand.colors.primary,
                                color: brand.colors.primary_text,
                                borderRadius: brand.radius,
                            }}
                        >
                            Contact our team
                        </span>
                        <div
                            className="website-preview-tile"
                            style={{
                                background: brand.colors.surface,
                                borderColor: brand.colors.border,
                                borderRadius: brand.radius,
                            }}
                        >
                            <strong
                                style={{
                                    fontFamily:
                                        headingFont?.css_family || "serif",
                                }}
                            >
                                Thoughtful, practical guidance
                            </strong>
                            <p style={{ color: brand.colors.muted }}>
                                A sample content card using your chosen surface
                                and text colors.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div className="website-editor website-font-settings form-stack">
                <div className="website-subheading">
                    <div>
                        <h3>Google Fonts library</h3>
                        <p>
                            {fonts.length} installed families, served from your
                            own server. No visitor request to Google is needed.
                        </p>
                    </div>
                </div>
                <div className="form-grid">
                    {(["heading_font", "body_font"] as const).map((role) => (
                        <Field
                            key={role}
                            label={
                                role === "heading_font"
                                    ? "Heading font"
                                    : "Body font"
                            }
                        >
                            <select
                                value={brand[role]}
                                onChange={(event) =>
                                    update({ [role]: event.target.value })
                                }
                            >
                                {!fonts.some(
                                    (font) => font.id === brand[role],
                                ) && (
                                    <option value={brand[role]}>
                                        {brand[role] || "Choose a font"}
                                    </option>
                                )}
                                {fonts.map((font) => (
                                    <option key={font.id} value={font.id}>
                                        {font.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    ))}
                </div>
                <TextInput
                    label="Find a font"
                    value={search}
                    change={setSearch}
                    placeholder="Name, category or language"
                />
                <div className="website-font-grid">
                    {filtered.map((font) => (
                        <article className="website-font-card" key={font.id}>
                            <div>
                                <h4>{font.name}</h4>
                                <small>
                                    {font.category} ·{" "}
                                    {(font.languages || []).join(", ")}
                                </small>
                            </div>
                            <div className="website-font-actions">
                                <Button
                                    variant={
                                        brand.heading_font === font.id
                                            ? "primary"
                                            : "secondary"
                                    }
                                    onClick={() =>
                                        update({ heading_font: font.id })
                                    }
                                >
                                    {brand.heading_font === font.id && (
                                        <Check size={13} />
                                    )}
                                    Headings
                                </Button>
                                <Button
                                    variant={
                                        brand.body_font === font.id
                                            ? "primary"
                                            : "secondary"
                                    }
                                    onClick={() =>
                                        update({ body_font: font.id })
                                    }
                                >
                                    {brand.body_font === font.id && (
                                        <Check size={13} />
                                    )}
                                    Body
                                </Button>
                            </div>
                            <small>
                                Weights {font.weights.join(", ")} ·{" "}
                                <a
                                    href={font.license_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-link"
                                >
                                    Font license
                                </a>
                            </small>
                        </article>
                    ))}
                </div>
                {filtered.length === 0 && (
                    <p>No installed fonts match this search.</p>
                )}
                <p className="website-help">
                    Check the selected font with your actual language and
                    content. Font licenses stay with the bundled files.
                </p>
            </div>
        </>
    );
}

function MediaLibrary({
    media,
    refresh,
}: {
    media: WebsiteMedia[];
    refresh: () => Promise<void>;
}) {
    const [selected, setSelected] = usePageState("website-media", "", {
        page: false,
    });
    const [file, setFile] = useState<File | null>(null);
    const [alt, setAlt] = useState("");
    const [caption, setCaption] = useState("");
    const [rights, setRights] = useState("");
    const [editing, setEditing] = useState<WebsiteMedia | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [deleteAcknowledged, setDeleteAcknowledged] = useState(false);
    const asset = media.find((entry) => entry.id === selected);
    useEffect(() => {
        setEditing(asset ? { ...asset } : null);
        setDeleteAcknowledged(false);
    }, [asset]);
    async function run(action: () => Promise<unknown>, success: string) {
        setBusy(true);
        setError("");
        setNotice("");
        try {
            await action();
            await refresh();
            setNotice(success);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <PageHeading
                title={asset ? "Edit public image" : "Public media library"}
                description="Upload images you are authorized to publish. Scanning finishes before images become selectable."
                action={
                    <Button
                        variant="secondary"
                        disabled={busy}
                        onClick={() => run(refresh, "Media status refreshed.")}
                    >
                        Refresh status
                    </Button>
                }
            />
            <Alert>{error}</Alert>
            <Alert kind="success">{notice}</Alert>
            {asset && editing ? (
                <div className="website-media-detail">
                    <button
                        className="text-link"
                        onClick={() => setSelected("")}
                    >
                        <ArrowLeft size={16} />
                        All public media
                    </button>
                    <div className="website-brand-layout">
                        <div className="website-editor form-stack">
                            <h3>{asset.name}</h3>
                            <Badge>{asset.status}</Badge>
                            {asset.message && <p>{asset.message}</p>}
                            <TextInput
                                label="Alternative text"
                                value={editing.alt}
                                change={(value) =>
                                    setEditing({ ...editing, alt: value })
                                }
                            />
                            <TextInput
                                label="Caption"
                                value={editing.caption}
                                change={(value) =>
                                    setEditing({ ...editing, caption: value })
                                }
                            />
                            <TextInput
                                label="Rights / permission record"
                                value={editing.rights}
                                change={(value) =>
                                    setEditing({ ...editing, rights: value })
                                }
                                multiline
                            />
                            <div className="form-grid">
                                <Field
                                    label={`Horizontal focus: ${editing.focal_x ?? 50}%`}
                                >
                                    <input
                                        type="range"
                                        min={0}
                                        max={100}
                                        value={editing.focal_x ?? 50}
                                        onChange={(event) =>
                                            setEditing({
                                                ...editing,
                                                focal_x: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                    />
                                </Field>
                                <Field
                                    label={`Vertical focus: ${editing.focal_y ?? 50}%`}
                                >
                                    <input
                                        type="range"
                                        min={0}
                                        max={100}
                                        value={editing.focal_y ?? 50}
                                        onChange={(event) =>
                                            setEditing({
                                                ...editing,
                                                focal_y: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                    />
                                </Field>
                            </div>
                            <p className="website-help">
                                Choose the part of the image to keep visible
                                when a layout crops it.
                            </p>
                            <div className="website-actions">
                                <Button
                                    disabled={busy}
                                    onClick={() =>
                                        run(
                                            () =>
                                                patch(
                                                    `/api/v1/website/media/${asset.id}`,
                                                    {
                                                        expected_version:
                                                            asset.version,
                                                        alt: editing.alt,
                                                        caption:
                                                            editing.caption,
                                                        rights: editing.rights,
                                                        focal_x:
                                                            editing.focal_x ??
                                                            50,
                                                        focal_y:
                                                            editing.focal_y ??
                                                            50,
                                                    },
                                                ),
                                            "Image details saved.",
                                        )
                                    }
                                >
                                    Save image details
                                </Button>
                                {asset.status !== "ready" && (
                                    <Button
                                        disabled={busy}
                                        variant="secondary"
                                        onClick={() =>
                                            run(
                                                () =>
                                                    post(
                                                        `/api/v1/website/media/${asset.id}/retry`,
                                                    ),
                                                "Image queued for another scan.",
                                            )
                                        }
                                    >
                                        Retry scan
                                    </Button>
                                )}
                            </div>
                            <div className="website-delete">
                                <h4>Remove this image</h4>
                                <p>
                                    Images used by a published website must be
                                    removed from the website before they can be
                                    deleted.
                                </p>
                                <label className="website-check">
                                    <input
                                        type="checkbox"
                                        checked={deleteAcknowledged}
                                        onChange={(event) =>
                                            setDeleteAcknowledged(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Delete this public media file
                                </label>
                                <Button
                                    variant="danger"
                                    disabled={!deleteAcknowledged || busy}
                                    onClick={() =>
                                        run(async () => {
                                            await api(
                                                `/api/v1/website/media/${asset.id}`,
                                                {
                                                    method: "DELETE",
                                                    body: JSON.stringify({
                                                        expected_version:
                                                            asset.version,
                                                    }),
                                                },
                                            );
                                            setSelected("");
                                        }, "Public image deleted.")
                                    }
                                >
                                    Delete image
                                </Button>
                            </div>
                        </div>
                        <div className="website-media-large">
                            {asset.preview_url ? (
                                <img
                                    className="website-focal-preview"
                                    src={asset.preview_url}
                                    alt={asset.alt || "Image preview"}
                                    width={asset.width}
                                    height={asset.height}
                                    style={{
                                        objectPosition: `${editing.focal_x ?? 50}% ${editing.focal_y ?? 50}%`,
                                    }}
                                />
                            ) : (
                                <div>
                                    <ImagePlus size={36} />
                                    <p>
                                        Preview available after a successful
                                        scan.
                                    </p>
                                </div>
                            )}
                            <small>
                                {asset.width} × {asset.height} ·{" "}
                                {(asset.bytes / 1024).toFixed(0)} KB ·{" "}
                                {asset.mime}
                                {asset.sources?.length
                                    ? ` · ${asset.sources.length} responsive sizes`
                                    : ""}
                            </small>
                        </div>
                    </div>
                </div>
            ) : (
                <>
                    <form
                        className="website-upload"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (!file) return;
                            const body = new FormData();
                            body.append("image", file);
                            body.append("alt", alt);
                            body.append("caption", caption);
                            body.append("rights", rights);
                            void run(async () => {
                                await api("/api/v1/website/media", {
                                    method: "POST",
                                    body,
                                });
                                setAlt("");
                                setCaption("");
                                setRights("");
                                setFile(null);
                                const input = window.document.getElementById(
                                    "website-upload-image",
                                ) as HTMLInputElement | null;
                                if (input) input.value = "";
                            }, "Image uploaded. Its scanning status is shown below.");
                        }}
                    >
                        <div className="website-upload-intro">
                            <Upload size={26} />
                            <h3>Add a public image</h3>
                            <p>JPEG, PNG or WebP. Maximum 10 MB.</p>
                        </div>
                        <div className="form-stack">
                            <Field label="Image file">
                                <input
                                    id="website-upload-image"
                                    required
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    onChange={(event) =>
                                        setFile(event.target.files?.[0] || null)
                                    }
                                />
                            </Field>
                            <div className="form-grid">
                                <TextInput
                                    label="Alternative text"
                                    value={alt}
                                    change={setAlt}
                                />
                                <TextInput
                                    label="Caption"
                                    value={caption}
                                    change={setCaption}
                                />
                            </div>
                            <TextInput
                                label="Rights / permission record"
                                value={rights}
                                change={setRights}
                                placeholder="Owned by the firm, photographer permission, or license source"
                            />
                            <Button type="submit" disabled={!file || busy}>
                                <Upload size={15} />
                                {busy ? "Uploading…" : "Upload image"}
                            </Button>
                        </div>
                    </form>
                    <div className="website-media-grid">
                        {media.map((entry) => (
                            <button
                                key={entry.id}
                                className="website-media-card"
                                onClick={() => setSelected(entry.id)}
                            >
                                {entry.preview_url ? (
                                    <img
                                        src={entry.preview_url}
                                        alt={entry.alt || entry.name}
                                        width={entry.width}
                                        height={entry.height}
                                    />
                                ) : (
                                    <div className="website-media-placeholder">
                                        <ImagePlus size={28} />
                                        <span>Awaiting approved preview</span>
                                    </div>
                                )}
                                <div>
                                    <strong>{entry.name}</strong>
                                    <Badge>{entry.status}</Badge>
                                    <small>
                                        {entry.width} × {entry.height} ·{" "}
                                        {entry.alt
                                            ? "Alt text added"
                                            : "Needs alt text"}
                                    </small>
                                </div>
                            </button>
                        ))}
                    </div>
                    {media.length === 0 && (
                        <Empty title="Your public image library is empty">
                            Upload your logo, team portraits and office
                            photographs to use throughout the website.
                        </Empty>
                    )}
                </>
            )}
        </>
    );
}

function Offices({
    document,
    change,
}: {
    document: WebsiteDocument;
    change: Setter<WebsiteDocument>;
}) {
    const [selected, setSelected] = usePageState("website-office", "", {
        page: false,
    });
    const [deleteAcknowledged, setDeleteAcknowledged] = useState(false);
    const office = document.offices.find((entry) => entry.id === selected);
    const update = (next: WebsiteOffice) =>
        change({
            ...document,
            offices: document.offices.map((entry) =>
                entry.id === next.id ? next : entry,
            ),
        });
    function add() {
        const id = newId("office");
        const next: WebsiteOffice = {
            id,
            name: "New office",
            address: "",
            city: "",
            region: "",
            postal_code: "",
            country: "",
            phone: "",
            email: "",
            hours: "",
            directions_url: "",
            kind: "physical",
            published: false,
            verified_at: "",
            page_slug: id,
        };
        change({ ...document, offices: [...document.offices, next] });
        setSelected(next.id);
    }
    useEffect(() => setDeleteAcknowledged(false), [selected]);
    return (
        <>
            <PageHeading
                title={
                    office
                        ? office.name || "Office details"
                        : "Firm identity & offices"
                }
                description="Keep a single approved record for names, addresses and phone numbers across your website."
                action={
                    !office && (
                        <Button variant="secondary" onClick={add}>
                            <Plus size={15} />
                            Add office
                        </Button>
                    )
                }
            />
            {office ? (
                <>
                    <button
                        className="text-link website-back"
                        onClick={() => setSelected("")}
                    >
                        <ArrowLeft size={16} />
                        Firm & offices
                    </button>
                    <div className="website-editor form-stack">
                        <div className="form-grid">
                            <TextInput
                                label="Public office / business name"
                                value={office.name}
                                change={(name) => update({ ...office, name })}
                                maxLength={200}
                            />
                            <Field label="Service delivery">
                                <select
                                    value={office.kind}
                                    onChange={(event) =>
                                        update({
                                            ...office,
                                            kind: event.target
                                                .value as WebsiteOffice["kind"],
                                        })
                                    }
                                >
                                    <option value="physical">
                                        Physical office
                                    </option>
                                    <option value="remote">
                                        Remote service
                                    </option>
                                    <option value="service_area">
                                        Service area
                                    </option>
                                </select>
                            </Field>
                        </div>
                        <p className="website-help">
                            Publish a visiting address only for a genuine
                            office. Remote services and areas served must not be
                            represented as staffed offices.
                        </p>
                        <TextInput
                            label="Street address"
                            value={office.address}
                            change={(address) => update({ ...office, address })}
                            multiline
                        />
                        <div className="form-grid">
                            <TextInput
                                label="City"
                                value={office.city}
                                change={(city) => update({ ...office, city })}
                            />
                            <TextInput
                                label="Region / state"
                                value={office.region}
                                change={(region) =>
                                    update({ ...office, region })
                                }
                            />
                            <TextInput
                                label="Postal code"
                                value={office.postal_code}
                                change={(postal_code) =>
                                    update({ ...office, postal_code })
                                }
                            />
                            <TextInput
                                label="Country"
                                value={office.country}
                                change={(country) =>
                                    update({ ...office, country })
                                }
                            />
                            <TextInput
                                label="Phone"
                                value={office.phone}
                                change={(phone) => update({ ...office, phone })}
                                type="tel"
                            />
                            <TextInput
                                label="Email"
                                value={office.email}
                                change={(email) => update({ ...office, email })}
                                type="email"
                            />
                            <TextInput
                                label="Directions URL"
                                value={office.directions_url}
                                change={(directions_url) =>
                                    update({ ...office, directions_url })
                                }
                            />
                            <TextInput
                                label="Office page slug"
                                value={office.page_slug}
                                change={(page_slug) =>
                                    update({ ...office, page_slug })
                                }
                                hint="Used when generating your page pack. Example: london-office"
                                maxLength={120}
                            />
                        </div>
                        <TextInput
                            label="Hours & appointment arrangements"
                            value={office.hours}
                            change={(hours) => update({ ...office, hours })}
                            multiline
                        />
                        <TextInput
                            label="Details last verified"
                            value={office.verified_at?.slice(0, 10)}
                            change={(verified_at) =>
                                update({ ...office, verified_at })
                            }
                            type="date"
                        />
                        <label className="website-check">
                            <input
                                type="checkbox"
                                checked={office.published}
                                onChange={(event) =>
                                    update({
                                        ...office,
                                        published: event.target.checked,
                                    })
                                }
                            />
                            Include this office in the next website publication
                        </label>
                        <div className="website-nap-preview">
                            <h4>Shared public details</h4>
                            <strong>{office.name}</strong>
                            <p>{fullOfficeAddress(office)}</p>
                            <p>
                                {office.phone} {office.email}
                            </p>
                            <small>
                                {office.kind.replaceAll("_", " ")} ·{" "}
                                {office.verified_at
                                    ? `Verified ${dateLabel(office.verified_at)}`
                                    : "Not yet verified"}
                            </small>
                        </div>
                        <div className="website-delete">
                            <label className="website-check">
                                <input
                                    type="checkbox"
                                    checked={deleteAcknowledged}
                                    onChange={(event) =>
                                        setDeleteAcknowledged(
                                            event.target.checked,
                                        )
                                    }
                                />
                                Remove this office and its draft listing
                                observations
                            </label>
                            <Button
                                variant="danger"
                                disabled={!deleteAcknowledged}
                                onClick={() => {
                                    change({
                                        ...document,
                                        offices: document.offices.filter(
                                            (entry) => entry.id !== office.id,
                                        ),
                                        citations: document.citations.filter(
                                            (entry) =>
                                                entry.office_id !== office.id,
                                        ),
                                        home: {
                                            ...document.home,
                                            sections:
                                                document.home.sections.map(
                                                    (section) => ({
                                                        ...section,
                                                        office_ids:
                                                            section.office_ids.filter(
                                                                (id) =>
                                                                    id !==
                                                                    office.id,
                                                            ),
                                                    }),
                                                ),
                                        },
                                    });
                                    setSelected("");
                                }}
                            >
                                Remove office
                            </Button>
                        </div>
                    </div>
                </>
            ) : (
                <>
                    <div className="website-editor form-stack">
                        <h3>Public firm identity</h3>
                        <div className="form-grid">
                            <TextInput
                                label="Public firm name"
                                value={document.organization.name}
                                change={(name) =>
                                    change({
                                        ...document,
                                        organization: {
                                            ...document.organization,
                                            name,
                                        },
                                    })
                                }
                                maxLength={200}
                            />
                            <TextInput
                                label="Main contact phone"
                                value={document.organization.phone}
                                change={(phone) =>
                                    change({
                                        ...document,
                                        organization: {
                                            ...document.organization,
                                            phone,
                                        },
                                    })
                                }
                                type="tel"
                            />
                            <TextInput
                                label="Main contact email"
                                value={document.organization.email}
                                change={(email) =>
                                    change({
                                        ...document,
                                        organization: {
                                            ...document.organization,
                                            email,
                                        },
                                    })
                                }
                                type="email"
                            />
                        </div>
                        <TextInput
                            label="Firm description"
                            value={document.organization.description}
                            change={(description) =>
                                change({
                                    ...document,
                                    organization: {
                                        ...document.organization,
                                        description,
                                    },
                                })
                            }
                            multiline
                        />
                    </div>
                    <div className="website-office-grid">
                        {document.offices.map((entry) => (
                            <button
                                key={entry.id}
                                className="website-office-card"
                                onClick={() => setSelected(entry.id)}
                            >
                                <MapPin size={22} />
                                <div>
                                    <h3>{entry.name}</h3>
                                    <p>
                                        {fullOfficeAddress(entry) ||
                                            "Address not added"}
                                    </p>
                                    <p>{entry.phone || "Phone not added"}</p>
                                    <small>
                                        {entry.kind.replaceAll("_", " ")} ·{" "}
                                        {entry.verified_at
                                            ? `Verified ${dateLabel(entry.verified_at)}`
                                            : "Needs verification"}
                                    </small>
                                </div>
                                <Badge>
                                    {entry.published ? "Included" : "Hidden"}
                                </Badge>
                            </button>
                        ))}
                    </div>
                    {!document.offices.length && (
                        <Empty
                            title="Add your real offices or service arrangements"
                            action={
                                <Button onClick={add}>Add an office</Button>
                            }
                        >
                            These records feed location sections and office
                            pages.
                        </Empty>
                    )}
                </>
            )}
        </>
    );
}

function Citations({
    document,
    change,
    statuses,
}: {
    document: WebsiteDocument;
    change: Setter<WebsiteDocument>;
    statuses: string[];
}) {
    const [selected, setSelected] = usePageState("website-citation", "", {
        page: false,
    });
    const [removeAcknowledged, setRemoveAcknowledged] = useState(false);
    const citation = document.citations.find((entry) => entry.id === selected);
    const office = document.offices.find(
        (entry) => entry.id === citation?.office_id,
    );
    const update = (next: WebsiteCitation) =>
        change({
            ...document,
            citations: document.citations.map((entry) =>
                entry.id === next.id ? next : entry,
            ),
        });
    const checked = document.citations.filter(
        (entry) => !["not_audited", "unable_to_verify"].includes(entry.status),
    ).length;
    useEffect(() => setRemoveAcknowledged(false), [selected]);
    function add() {
        const next: WebsiteCitation = {
            id: newId("citation"),
            office_id: document.offices[0]?.id || "",
            provider: "",
            url: "",
            name: "",
            address: "",
            phone: "",
            status: "not_audited",
            observed_at: "",
            notes: "",
        };
        change({ ...document, citations: [...document.citations, next] });
        setSelected(next.id);
    }
    return (
        <>
            <PageHeading
                title={
                    citation
                        ? "Listing observation"
                        : "Local listing consistency"
                }
                description="Record what is actually shown in each external directory, then compare it with the approved office details."
                action={
                    !citation && (
                        <Button
                            variant="secondary"
                            disabled={!document.offices.length}
                            onClick={add}
                        >
                            <Plus size={15} />
                            Add listing
                        </Button>
                    )
                }
            />
            {citation ? (
                <>
                    <button
                        className="text-link website-back"
                        onClick={() => setSelected("")}
                    >
                        <ArrowLeft size={16} />
                        All listing observations
                    </button>
                    <div className="website-brand-layout">
                        <div className="website-editor form-stack">
                            <div className="form-grid">
                                <Field label="Office">
                                    <select
                                        value={citation.office_id}
                                        onChange={(event) =>
                                            update({
                                                ...citation,
                                                office_id: event.target.value,
                                            })
                                        }
                                    >
                                        <option value="">
                                            Select an office
                                        </option>
                                        {document.offices.map((entry) => (
                                            <option
                                                value={entry.id}
                                                key={entry.id}
                                            >
                                                {entry.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <TextInput
                                    label="Directory / provider"
                                    value={citation.provider}
                                    change={(provider) =>
                                        update({ ...citation, provider })
                                    }
                                    placeholder="Google Business Profile, Bing Places…"
                                />
                                <TextInput
                                    label="Listing URL"
                                    value={citation.url}
                                    change={(url) =>
                                        update({ ...citation, url })
                                    }
                                />
                                <Field label="Review status">
                                    <select
                                        value={citation.status}
                                        onChange={(event) =>
                                            update({
                                                ...citation,
                                                status: event.target.value,
                                            })
                                        }
                                    >
                                        {statuses.map((status) => (
                                            <option value={status} key={status}>
                                                {status.replaceAll("_", " ")}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </div>
                            <h4>Observed details</h4>
                            <TextInput
                                label="Business name shown"
                                value={citation.name}
                                change={(name) => update({ ...citation, name })}
                            />
                            <TextInput
                                label="Full address shown"
                                value={citation.address}
                                change={(address) =>
                                    update({ ...citation, address })
                                }
                                multiline
                            />
                            <TextInput
                                label="Phone shown"
                                value={citation.phone}
                                change={(phone) =>
                                    update({ ...citation, phone })
                                }
                            />
                            <TextInput
                                label="Observation / recheck date"
                                value={citation.observed_at?.slice(0, 10)}
                                change={(observed_at) =>
                                    update({ ...citation, observed_at })
                                }
                                type="date"
                            />
                            <TextInput
                                label="Evidence, corrections & follow-up notes"
                                value={citation.notes}
                                change={(notes) =>
                                    update({ ...citation, notes })
                                }
                                multiline
                            />
                            <div className="website-delete">
                                <label className="website-check">
                                    <input
                                        type="checkbox"
                                        checked={removeAcknowledged}
                                        onChange={(event) =>
                                            setRemoveAcknowledged(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Remove this draft observation
                                </label>
                                <Button
                                    variant="danger"
                                    disabled={!removeAcknowledged}
                                    onClick={() => {
                                        change({
                                            ...document,
                                            citations:
                                                document.citations.filter(
                                                    (entry) =>
                                                        entry.id !==
                                                        citation.id,
                                                ),
                                        });
                                        setSelected("");
                                    }}
                                >
                                    Remove observation
                                </Button>
                            </div>
                        </div>
                        <aside className="website-editor form-stack">
                            <h3>Compare with your office record</h3>
                            {office ? (
                                <>
                                    <strong>{office.name}</strong>
                                    <p>{fullOfficeAddress(office)}</p>
                                    <p>{office.phone}</p>
                                    <small>
                                        Last verified:{" "}
                                        {dateLabel(office.verified_at)}
                                    </small>
                                    <h4>Fields to check</h4>
                                    {citationDifferences(citation, office)
                                        .length ? (
                                        citationDifferences(
                                            citation,
                                            office,
                                        ).map((field) => (
                                            <span
                                                key={field}
                                                className="website-difference"
                                            >
                                                {field}
                                            </span>
                                        ))
                                    ) : (
                                        <p className="website-match">
                                            <CheckCircle2 size={16} />
                                            Observed name, address and phone
                                            match.
                                        </p>
                                    )}
                                    <p className="website-help">
                                        This comparison ignores basic case,
                                        whitespace and punctuation. Verify the
                                        live listing before marking a correction
                                        resolved.
                                    </p>
                                </>
                            ) : (
                                <p>Select an office to compare its details.</p>
                            )}
                        </aside>
                    </div>
                </>
            ) : (
                <>
                    <div className="website-inline-notice">
                        <strong>
                            {checked} checked of {document.citations.length}{" "}
                            known listings.
                        </strong>
                        <span>
                            {" "}
                            An unchecked listing is not counted as a match.
                            These records track your manual checks.
                        </span>
                    </div>
                    <div className="website-table-wrap">
                        <table className="website-table">
                            <thead>
                                <tr>
                                    <th>Provider / office</th>
                                    <th>Fields to check</th>
                                    <th>Status shortcut</th>
                                    <th>Last observed</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {document.citations.map((entry) => {
                                    const linkedOffice = document.offices.find(
                                        (item) => item.id === entry.office_id,
                                    );
                                    const differences = citationDifferences(
                                        entry,
                                        linkedOffice,
                                    );
                                    return (
                                        <tr key={entry.id}>
                                            <td>
                                                <strong>
                                                    {entry.provider ||
                                                        "New listing"}
                                                </strong>
                                                <small>
                                                    {linkedOffice?.name ||
                                                        "Office not selected"}
                                                </small>
                                            </td>
                                            <td>
                                                {entry.status === "not_audited"
                                                    ? "Not checked"
                                                    : differences.join(", ") ||
                                                      "No differences"}
                                            </td>
                                            <td>
                                                <select
                                                    aria-label={`Status for ${entry.provider || "new listing"}`}
                                                    value={entry.status}
                                                    onChange={(event) =>
                                                        update({
                                                            ...entry,
                                                            status: event.target
                                                                .value,
                                                        })
                                                    }
                                                >
                                                    {statuses.map((status) => (
                                                        <option
                                                            key={status}
                                                            value={status}
                                                        >
                                                            {status.replaceAll(
                                                                "_",
                                                                " ",
                                                            )}
                                                        </option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td>
                                                {dateLabel(entry.observed_at)}
                                            </td>
                                            <td>
                                                <button
                                                    className="text-link"
                                                    onClick={() =>
                                                        setSelected(entry.id)
                                                    }
                                                >
                                                    Review
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    {!document.citations.length && (
                        <Empty
                            title="Track the listings you actually use"
                            action={
                                document.offices.length ? (
                                    <Button onClick={add}>
                                        Add your first listing
                                    </Button>
                                ) : undefined
                            }
                        >
                            {document.offices.length
                                ? "Start with your business profile and professional directories."
                                : "Add an office record before recording a listing observation."}
                        </Empty>
                    )}
                </>
            )}
        </>
    );
}

function Navigation({
    document,
    change,
}: {
    document: WebsiteDocument;
    change: Setter<WebsiteDocument>;
}) {
    return (
        <>
            <PageHeading
                title="Navigation & footer"
                description="Give visitors clear routes to your services, people, offices and contact page."
            />
            <LinkEditor
                title="Header navigation"
                links={document.navigation}
                change={(navigation) => change({ ...document, navigation })}
            />
            <div className="website-editor form-stack">
                <TextInput
                    label="Footer text"
                    value={document.footer.text}
                    change={(text) =>
                        change({
                            ...document,
                            footer: { ...document.footer, text },
                        })
                    }
                    multiline
                />
            </div>
            <LinkEditor
                title="Footer links"
                links={document.footer.links}
                change={(links) =>
                    change({
                        ...document,
                        footer: { ...document.footer, links },
                    })
                }
            />
            <p className="website-help">
                Public office details are generated from Firm & offices so the
                same names, addresses and phone numbers stay consistent.
            </p>
        </>
    );
}
function LinkEditor({
    title,
    links,
    change,
}: {
    title: string;
    links: { label: string; url: string }[];
    change: Setter<{ label: string; url: string }[]>;
}) {
    return (
        <section className="website-editor form-stack">
            <div className="website-subheading">
                <h3>{title}</h3>
                <Button
                    variant="secondary"
                    onClick={() => change([...links, { label: "", url: "" }])}
                >
                    <Plus size={15} />
                    Add link
                </Button>
            </div>
            {links.map((link, index) => (
                <div className="website-link-row" key={index}>
                    <TextInput
                        label="Label"
                        value={link.label}
                        change={(label) =>
                            change(
                                links.map((entry, position) =>
                                    index === position
                                        ? { ...entry, label }
                                        : entry,
                                ),
                            )
                        }
                    />
                    <TextInput
                        label="Destination"
                        value={link.url}
                        change={(url) =>
                            change(
                                links.map((entry, position) =>
                                    index === position
                                        ? { ...entry, url }
                                        : entry,
                                ),
                            )
                        }
                        placeholder="/contact"
                    />
                    <div className="website-actions">
                        <Button
                            variant="ghost"
                            aria-label={`Move link ${index + 1} up`}
                            disabled={index === 0}
                            onClick={() => change(moveItem(links, index, -1))}
                        >
                            <ArrowUp size={14} />
                        </Button>
                        <Button
                            variant="ghost"
                            aria-label={`Move link ${index + 1} down`}
                            disabled={index === links.length - 1}
                            onClick={() => change(moveItem(links, index, 1))}
                        >
                            <ArrowDown size={14} />
                        </Button>
                        <Button
                            variant="ghost"
                            aria-label={`Remove link ${index + 1}`}
                            onClick={() =>
                                change(
                                    links.filter(
                                        (_, position) => position !== index,
                                    ),
                                )
                            }
                        >
                            <Trash2 size={14} />
                        </Button>
                    </div>
                </div>
            ))}
        </section>
    );
}

function PageLibrary({
    state,
    dirty,
    accept,
    reportFailure,
    setWorking,
}: {
    state: WebsiteState;
    dirty: boolean;
    accept: Setter<WebsiteState>;
    reportFailure: (failure: unknown) => void;
    setWorking: Setter<boolean>;
}) {
    const [practices, setPractices] = useState("");
    const [people, setPeople] = useState("");
    const [guides, setGuides] = useState("");
    const [includeTools, setIncludeTools] = useState(false);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<{
        created: { id: string; title: string; slug: string; type: string }[];
        skipped: { slug: string; reason: string }[];
    } | null>(null);
    const physicalOffices = state.draft.offices.filter(
        (office) => office.kind === "physical",
    );
    const total =
        11 +
        pagePackEntries(practices).length +
        pagePackEntries(people).length +
        pagePackEntries(guides).length +
        physicalOffices.length +
        (includeTools ? 3 : 0);
    async function generate() {
        setBusy(true);
        setWorking(true);
        try {
            const response = await post<{
                data: WebsiteState;
                created: {
                    id: string;
                    title: string;
                    slug: string;
                    type: string;
                }[];
                skipped: { slug: string; reason: string }[];
            }>("/api/v1/website/page-pack", {
                expected_version: state.version,
                practices: pagePackEntries(practices),
                people: pagePackEntries(people),
                guides: pagePackEntries(guides),
                include_tools: includeTools,
            });
            accept(response.data);
            setResult({ created: response.created, skipped: response.skipped });
        } catch (failure) {
            reportFailure(failure);
        } finally {
            setBusy(false);
            setWorking(false);
        }
    }
    return (
        <>
            <PageHeading
                title="Build a useful page library"
                description="Generate editable drafts for your real services, lawyers and offices. Review the content before publishing."
                action={
                    <Link href="/app/pages" className="button button-secondary">
                        Open content pages <ArrowUpRight size={15} />
                    </Link>
                }
            />
            <div className="website-pagepack-layout">
                <div className="website-editor form-stack">
                    <h3>What should your website cover?</h3>
                    <TextInput
                        label="Practice areas"
                        value={practices}
                        change={setPractices}
                        multiline
                        placeholder={"Business law\nFamily law\nProperty law"}
                        hint="One title per line. Optional custom slug: Family law | family-law"
                    />
                    <TextInput
                        label="Lawyers / public team biographies"
                        value={people}
                        change={setPeople}
                        multiline
                        placeholder={"Asha Patel\nJames Wilson"}
                    />
                    <TextInput
                        label="Useful guides"
                        value={guides}
                        change={setGuides}
                        multiline
                        placeholder={"Preparing for your first consultation"}
                    />
                    <label className="website-check">
                        <input
                            type="checkbox"
                            checked={includeTools}
                            onChange={(event) =>
                                setIncludeTools(event.target.checked)
                            }
                        />
                        Include three explanatory AI tool pages
                    </label>
                    <p className="website-help">
                        AI tools need jurisdiction and processing review before
                        public promotion.
                    </p>
                    {dirty && (
                        <Alert kind="info">
                            Save your website draft before generating pages so
                            the current office records are included.
                        </Alert>
                    )}
                    <Button disabled={dirty || busy} onClick={generate}>
                        <Plus size={16} />
                        {busy ? "Creating page drafts…" : "Create page drafts"}
                    </Button>
                </div>
                <aside className="website-pagepack-summary">
                    <h3>Your starter website</h3>
                    <div className="website-page-total">
                        <strong>{total}</strong>
                        <span>planned pages including home</span>
                    </div>
                    <dl>
                        <div>
                            <dt>Core pages</dt>
                            <dd>11</dd>
                        </div>
                        <div>
                            <dt>Practice areas</dt>
                            <dd>{pagePackEntries(practices).length}</dd>
                        </div>
                        <div>
                            <dt>Lawyer biographies</dt>
                            <dd>{pagePackEntries(people).length}</dd>
                        </div>
                        <div>
                            <dt>Physical office drafts</dt>
                            <dd>{physicalOffices.length}</dd>
                        </div>
                        <div>
                            <dt>Guides</dt>
                            <dd>{pagePackEntries(guides).length}</dd>
                        </div>
                        <div>
                            <dt>AI tool pages</dt>
                            <dd>{includeTools ? 3 : 0}</dd>
                        </div>
                    </dl>
                    <p>
                        The core pack covers Home, About, Practices, People,
                        Locations, Contact, Resources, Privacy, Terms,
                        Accessibility and Professional disclaimer.
                    </p>
                    <p>
                        Existing slugs are skipped. Page counts describe the
                        proposed pack, not the final size of your website.
                    </p>
                </aside>
            </div>
            {result && (
                <div className="website-editor form-stack" role="status">
                    <h3>{result.created.length} page drafts created</h3>
                    <p>
                        {result.skipped.length} existing or conflicting slugs
                        skipped.
                    </p>
                    <div className="website-table-wrap">
                        <table className="website-table">
                            <thead>
                                <tr>
                                    <th>Page</th>
                                    <th>Slug</th>
                                    <th>State</th>
                                </tr>
                            </thead>
                            <tbody>
                                {result.created.map((page) => (
                                    <tr key={page.id}>
                                        <td>{page.title}</td>
                                        <td>/{page.slug}</td>
                                        <td>
                                            <Badge>Draft</Badge>
                                        </td>
                                    </tr>
                                ))}
                                {result.skipped.map((page) => (
                                    <tr key={page.slug}>
                                        <td>Existing page</td>
                                        <td>/{page.slug}</td>
                                        <td>{page.reason}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Link className="button button-primary" href="/app/pages">
                        Edit generated content <ArrowUpRight size={15} />
                    </Link>
                </div>
            )}
        </>
    );
}

function PublishingReview({
    state,
    document,
    dirty,
    busy,
    transition,
}: {
    state: WebsiteState;
    document: WebsiteDocument;
    dirty: boolean;
    busy: boolean;
    transition: (action: string, revisionId?: string) => Promise<void>;
}) {
    const [revision, setRevision] = useState("");
    const [acknowledged, setAcknowledged] = useState(false);
    const changes = websiteChanges(document, state.published?.document);
    const visible = document.home.sections.filter((section) => section.visible);
    const proof = visible.filter((section) =>
        ["testimonials", "results", "awards"].includes(section.type),
    );
    const completeProof = proof.every(
        (section) => !!section.proof_source_url && !!section.proof_note,
    );
    const allHaveHeadings = visible.every(
        (section) => !!section.heading.trim(),
    );
    const noPendingOffices = document.offices
        .filter((office) => office.published)
        .every((office) => !!office.verified_at);
    return (
        <>
            <PageHeading
                title="Review & publish"
                description="Preview the saved draft, verify its claims and contact details, then release a version you can restore later."
            />
            <div className="website-review-layout">
                <div className="website-editor form-stack">
                    <h3>Changes since the live version</h3>
                    {changes.length ? (
                        <div className="website-change-list">
                            {changes.map((label) => (
                                <span key={label}>
                                    <FileText size={16} />
                                    {label}
                                </span>
                            ))}
                        </div>
                    ) : (
                        <p>No changes from the current published version.</p>
                    )}
                    <h3>Review checklist</h3>
                    <CheckItem
                        good={allHaveHeadings}
                        label={`${visible.length} visible homepage sections have headings`}
                    />
                    <CheckItem
                        good={
                            !!document.organization.name &&
                            !!document.organization.email
                        }
                        label="Public firm name and contact email are present"
                    />
                    <CheckItem
                        good={noPendingOffices}
                        label="Included office records have a verification date"
                    />
                    <CheckItem
                        good={completeProof}
                        label="Visible reviews, results and awards have source and review notes"
                    />
                    <p className="website-help">
                        These checks assist review. Publication also validates
                        safe links, public media, fonts, contrast and required
                        content on the server.
                    </p>
                    <a
                        href="/api/v1/website/preview"
                        target="_blank"
                        rel="noreferrer"
                        className="button button-secondary"
                    >
                        <Eye size={16} />
                        Open saved draft preview <ArrowUpRight size={14} />
                    </a>
                </div>
                <aside className="website-publish-panel">
                    <ShieldCheck size={28} />
                    <h3>Publish with review</h3>
                    <Badge>{state.status}</Badge>
                    <p>
                        Saving any content change returns the website to draft
                        so it can be reviewed again.
                    </p>
                    {dirty && (
                        <Alert kind="info">
                            Save your unsaved changes before continuing.
                        </Alert>
                    )}
                    <div className="website-publish-actions">
                        {state.capabilities.review && (
                            <Button
                                variant="secondary"
                                disabled={
                                    busy || dirty || state.status !== "draft"
                                }
                                onClick={() => transition("review")}
                            >
                                Submit for review
                            </Button>
                        )}
                        {state.capabilities.approve && (
                            <Button
                                variant="secondary"
                                disabled={
                                    busy ||
                                    dirty ||
                                    ![
                                        "review",
                                        "in_review",
                                        "pending_review",
                                    ].includes(state.status)
                                }
                                onClick={() => transition("approve")}
                            >
                                Approve draft
                            </Button>
                        )}
                        {state.capabilities.publish && (
                            <Button
                                disabled={
                                    busy || dirty || state.status !== "approved"
                                }
                                onClick={() => transition("publish")}
                            >
                                <Globe2 size={16} />
                                Publish website
                            </Button>
                        )}
                    </div>
                    <a
                        className="text-link"
                        href="/"
                        target="_blank"
                        rel="noreferrer"
                    >
                        View public website <ArrowUpRight size={14} />
                    </a>
                </aside>
            </div>
            <section className="website-editor form-stack">
                <h3>Publication history</h3>
                {state.history.length ? (
                    <>
                        <div className="website-table-wrap">
                            <table className="website-table">
                                <thead>
                                    <tr>
                                        <th>Version</th>
                                        <th>Action</th>
                                        <th>Published</th>
                                        <th>Restore</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {state.history.map((entry) => (
                                        <tr key={entry.id}>
                                            <td>
                                                Version {entry.version}
                                                {entry.id ===
                                                    state.published
                                                        ?.revision_id && (
                                                    <Badge>Live</Badge>
                                                )}
                                            </td>
                                            <td>
                                                {entry.action?.replaceAll(
                                                    "_",
                                                    " ",
                                                )}
                                            </td>
                                            <td>
                                                {dateLabel(entry.published_at)}
                                            </td>
                                            <td>
                                                <input
                                                    aria-label={`Select version ${entry.version} to restore`}
                                                    type="radio"
                                                    name="website-rollback"
                                                    disabled={
                                                        busy ||
                                                        dirty ||
                                                        !state.capabilities
                                                            .publish ||
                                                        entry.id ===
                                                            state.published
                                                                ?.revision_id
                                                    }
                                                    checked={
                                                        revision === entry.id
                                                    }
                                                    onChange={() => {
                                                        setRevision(entry.id);
                                                        setAcknowledged(false);
                                                    }}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {revision && (
                            <div className="website-rollback">
                                <label className="website-check">
                                    <input
                                        type="checkbox"
                                        checked={acknowledged}
                                        onChange={(event) =>
                                            setAcknowledged(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Restore this version as the public website
                                    and current draft.
                                </label>
                                <Button
                                    variant="secondary"
                                    disabled={!acknowledged || busy || dirty}
                                    onClick={async () => {
                                        await transition("rollback", revision);
                                        setRevision("");
                                        setAcknowledged(false);
                                    }}
                                >
                                    Restore selected version
                                </Button>
                            </div>
                        )}
                    </>
                ) : (
                    <p>Your first publication will appear here.</p>
                )}
            </section>
        </>
    );
}
function CheckItem({ good, label }: { good: boolean; label: string }) {
    return (
        <div className={`website-check-item ${good ? "is-complete" : ""}`}>
            <span>{good ? <Check size={14} /> : "!"}</span>
            {label}
        </div>
    );
}
