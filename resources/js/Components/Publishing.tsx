// Author: ramanpal singh | URL: https://kwebby.com
import { lazy, Suspense, useEffect, useRef, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import { usePageState } from "../lib/navigation";
import {
    FileText,
    Plus,
    Upload,
    ArrowUpRight,
    Save,
    Globe2,
    SearchCheck,
    Check,
    Download,
    MoreHorizontal,
    Palette,
    RotateCcw,
} from "lucide-react";
import type { RecordData } from "../lib/types";
import { api, dateLabel, download, patch, post } from "../lib/api";
import {
    AddButton,
    Alert,
    Badge,
    Button,
    DataTable,
    Empty,
    Field,
    DetailPage,
    SectionTitle,
} from "./ui";
const BlockEditor = lazy(() => import("./BlockEditor"));
export function Documents({
    records,
    reload,
    client = false,
}: {
    records: RecordData[];
    reload: () => Promise<void>;
    client?: boolean;
}) {
    const [edit, setEdit] = usePageState<RecordData | null | undefined>(
        "edit",
        undefined,
        {
            records,
            load: async (id) => (await api(`/api/v1/documents/${id}`)).data,
        },
    );
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const [uploadOpen, setUploadOpen] = usePageState("upload", false);
    const [pendingFile, setPendingFile] = useState<File | null>(null);
    const [uploadMatter, setUploadMatter] = useState("");
    const [uploadMatters, setUploadMatters] = useState<RecordData[]>([]);
    const [notice, setNotice] = useState("");
    useEffect(() => {
        api("/api/v1/records/matters")
            .then((r) => setUploadMatters(r.data))
            .catch(() => {});
    }, []);
    async function upload(file?: File) {
        if (!file) return;
        setBusy(true);
        setError("");
        const form = new FormData();
        form.append("file", file);
        if (uploadMatter) form.append("matter_id", uploadMatter);
        try {
            await api("/api/v1/files", { method: "POST", body: form });
            await reload();
            setPendingFile(null);
            setUploadOpen(false);
            setNotice(
                "Your document was submitted and is awaiting a security scan.",
            );
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <Alert>{error}</Alert>
            <Alert kind="success">{notice}</Alert>
            <div className="module-toolbar">
                <p className="subtle">Private documents and working drafts</p>
                <div className="button-row">
                    <Button
                        variant="secondary"
                        disabled={busy}
                        onClick={() => setUploadOpen(true)}
                    >
                        <Upload size={16} />
                        {busy ? "Uploading…" : "Upload file"}
                    </Button>
                    {!client && (
                        <AddButton onClick={() => setEdit(null)}>
                            New document
                        </AddButton>
                    )}
                </div>
            </div>
            <DataTable
                records={records}
                columns={[
                    {
                        key: "title",
                        label: "Document",
                        render: (r) => (
                            <span className="document-cell">
                                <FileText size={21} />
                                <span>
                                    {r.title || r.name}
                                    <small className="cell-sub">
                                        {r.kind === "upload"
                                            ? r.mime || "Uploaded file"
                                            : "Written document"}
                                    </small>
                                </span>
                            </span>
                        ),
                    },
                    {
                        key: "status",
                        label: "Status",
                        render: (r) => <Badge>{r.status || "draft"}</Badge>,
                    },
                    {
                        key: "version",
                        label: "Version",
                        render: (r) => `v${r.version || 1}`,
                    },
                    {
                        key: "updated_at",
                        label: "Updated",
                        render: (r) => dateLabel(r.updated_at),
                    },
                    {
                        key: "download",
                        label: "File",
                        render: (r) =>
                            r.kind === "upload" ? (
                                r.status === "clean" ? (
                                    <a
                                        href={`/api/v1/files/${r.id}/download`}
                                        className="text-link"
                                    >
                                        <Download size={15} />
                                        Download
                                    </a>
                                ) : (
                                    <span className="subtle">
                                        Awaiting scan
                                    </span>
                                )
                            ) : (
                                <button
                                    className="text-link"
                                    onClick={() => setEdit(r)}
                                >
                                    Open editor
                                </button>
                            ),
                    },
                ]}
                onOpen={(r) =>
                    r.kind === "upload"
                        ? setError(
                              r.status === "clean"
                                  ? "Use Download to open this file."
                                  : "This upload is quarantined until a healthy malware scanner clears it.",
                          )
                        : setEdit(r)
                }
                emptyTitle="Start your first document"
                emptyAction={
                    !client && (
                        <AddButton onClick={() => setEdit(null)}>
                            New document
                        </AddButton>
                    )
                }
            />
            {edit !== undefined && (
                <DocumentEditor
                    readOnly={client}
                    kind="documents"
                    initial={edit}
                    onCreated={setEdit}
                    onClose={() => setEdit(undefined)}
                    reload={reload}
                />
            )}{" "}
            {uploadOpen && (
                <DetailPage
                    open
                    title="Upload document"
                    description="Files stay quarantined until the malware scanner clears them."
                    onClose={() => {
                        setPendingFile(null);
                        setUploadOpen(false);
                    }}
                >
                    <div className="dialog-body form-stack">
                        <Alert>{error}</Alert>
                        <Field label="Document file">
                            <input
                                type="file"
                                onChange={(e) =>
                                    setPendingFile(e.target.files?.[0] || null)
                                }
                            />
                        </Field>
                        {pendingFile && (
                            <p className="subtle">
                                Selected: {pendingFile.name}
                            </p>
                        )}
                        <Field
                            label={client ? "Matter *" : "Matter (optional)"}
                        >
                            <select
                                value={uploadMatter}
                                onChange={(e) =>
                                    setUploadMatter(e.target.value)
                                }
                            >
                                <option value="">
                                    {client
                                        ? "Select your matter"
                                        : "General practice document"}
                                </option>
                                {uploadMatters.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.title}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>
                    <div className="dialog-footer">
                        <Button
                            variant="secondary"
                            onClick={() => {
                                setPendingFile(null);
                                setUploadOpen(false);
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                busy ||
                                !pendingFile ||
                                (client && !uploadMatter)
                            }
                            onClick={() => upload(pendingFile || undefined)}
                        >
                            {busy ? "Uploading…" : "Submit document"}
                        </Button>
                    </div>
                </DetailPage>
            )}
        </>
    );
}
export function Pages({
    records,
    reload,
}: {
    records: RecordData[];
    reload: () => Promise<void>;
}) {
    const [edit, setEdit] = usePageState<RecordData | null | undefined>(
        "edit",
        undefined,
        {
            records,
            load: async (id) => (await api(`/api/v1/pages/${id}`)).data,
        },
    );
    const [audit, setAudit] = usePageState<any>("audit", null, {
        load: async () => (await api("/api/v1/seo/audit")).data,
    });
    const [error, setError] = useState("");
    return (
        <>
            <Alert>{error}</Alert>
            <div className="module-toolbar">
                <div className="subtle">
                    Your firm’s public knowledge and service pages
                </div>
                <div className="button-row">
                    <Button
                        variant="secondary"
                        onClick={async () => {
                            try {
                                setAudit((await api("/api/v1/seo/audit")).data);
                            } catch (e) {
                                setError((e as Error).message);
                            }
                        }}
                    >
                        <SearchCheck size={16} />
                        SEO audit
                    </Button>
                    <AddButton onClick={() => setEdit(null)}>
                        New page
                    </AddButton>
                </div>
            </div>
            <DataTable
                records={records}
                columns={[
                    {
                        key: "title",
                        label: "Page",
                        render: (r) => (
                            <span>
                                {r.title}
                                <small className="cell-sub">/p/{r.slug}</small>
                            </span>
                        ),
                    },
                    { key: "type", label: "Content type" },
                    {
                        key: "status",
                        label: "Status",
                        render: (r) => <Badge>{r.status}</Badge>,
                    },
                    { key: "locale", label: "Language" },
                    {
                        key: "updated_at",
                        label: "Updated",
                        render: (r) => dateLabel(r.updated_at),
                    },
                ]}
                onOpen={setEdit}
                emptyTitle="Publish your first page"
                emptyAction={
                    <AddButton onClick={() => setEdit(null)}>
                        New page
                    </AddButton>
                }
            />
            {edit !== undefined && (
                <DocumentEditor
                    kind="pages"
                    initial={edit}
                    onCreated={setEdit}
                    onClose={() => setEdit(undefined)}
                    reload={reload}
                />
            )}{" "}
            {audit && (
                <DetailPage
                    open
                    title="Search health"
                    description="Current checks across your published content."
                    onClose={() => setAudit(null)}
                >
                    <div className="dialog-body">
                        <AuditResults value={audit} />
                    </div>
                </DetailPage>
            )}
        </>
    );
}
function AuditResults({ value }: { value: any }) {
    const issues = Array.isArray(value)
        ? value
        : value.issues || value.pages || [];
    return (
        <>
            <div className="status-summary">
                <SearchCheck size={25} />
                <div>
                    <strong>{issues.length} results</strong>
                    <p>Review page metadata, content and indexing checks.</p>
                </div>
            </div>
            {issues.length ? (
                issues.map((item: any, index: number) => (
                    <div className="audit-row" key={index}>
                        <strong>
                            {item.title ||
                                item.page_title ||
                                item.check ||
                                item.slug ||
                                "Page check"}
                        </strong>
                        <p>
                            {typeof item === "string"
                                ? item
                                : item.message ||
                                  item.issue ||
                                  (item.issues || []).join(", ") ||
                                  JSON.stringify(item)}
                        </p>
                    </div>
                ))
            ) : (
                <p>No issues were reported by the current checks.</p>
            )}
        </>
    );
}
function DocumentEditor({
    kind,
    initial,
    onClose,
    reload,
    onCreated,
    readOnly = false,
}: {
    kind: "pages" | "documents";
    initial: RecordData | null;
    onClose: () => void;
    onCreated: (record: RecordData) => void;
    reload: () => Promise<void>;
    readOnly?: boolean;
}) {
    const [record, setRecord] = useState<RecordData | null>(initial);
    const [title, setTitle] = useState(initial?.title || "");
    const [blocks, setBlocks] = useState<any[]>(initial?.blocks || []);
    const [loaded, setLoaded] = useState(!initial);
    const [loadAttempt, setLoadAttempt] = useState(0);
    const [dirty, setDirty] = useState(false);
    const [closing, setClosing] = useState(false);
    const [state, setState] = useState("All changes saved");
    const [error, setError] = useState("");
    const [tab, setTab] = usePageState("editor-tab", "write", { page: false });
    const currentUser = usePage<any>().props.user;
    const schemaAdmin = currentUser?.roles?.some((role: string) =>
        ["owner", "admin"].includes(role),
    );
    const [advancedSchema, setAdvancedSchema] = useState(
        initial?.seo?.advanced_schema
            ? JSON.stringify(initial.seo.advanced_schema, null, 2)
            : "",
    );
    const [seo, setSeo] = useState<Record<string, any>>(
        initial?.seo || {
            robots: "index,follow",
            twitter_card: "summary_large_image",
            schemas: [],
        },
    );
    const [meta, setMeta] = useState<Record<string, any>>({
        slug: initial?.slug || "",
        type: initial?.type || "page",
        locale: initial?.locale || "en",
        author_name: initial?.author_name || "",
        jurisdiction: initial?.jurisdiction || "",
        summary: initial?.summary || "",
        review_due_at: initial?.review_due_at || "",
        matter_id: initial?.matter_id || "",
        tool_slug: initial?.tool_slug || "",
        translation_group: initial?.translation_group || "",
        sources: initial?.sources || [],
    });
    const [revisions, setRevisions] = useState<RecordData[]>([]);
    const [comments, setComments] = useState<RecordData[]>([]);
    const [comment, setComment] = useState("");
    const [previewRevision, setPreviewRevision] =
        usePageState<RecordData | null>("revision", null, {
            load: async (id) =>
                (await api(`/api/v1/${kind}/${initial?.id}/revisions/${id}`))
                    .data,
            page: false,
        });
    const saving = useRef(false);
    const changed = useRef(0);
    const conflict = useRef(false);
    useEffect(() => {
        if (initial?.id === record?.id && loaded && loadAttempt === 0) return;
        let cancelled = false;
        setLoaded(false);
        setDirty(false);
        setClosing(false);
        setError("");
        conflict.current = false;
        const apply = (data: RecordData | null) => {
            if (cancelled) return;
            setRecord(data);
            setTitle(data?.title || "");
            setBlocks(data?.blocks || []);
            setSeo(
                data?.seo || {
                    robots: "index,follow",
                    twitter_card: "summary_large_image",
                    schemas: [],
                },
            );
            setAdvancedSchema(
                data?.seo?.advanced_schema
                    ? JSON.stringify(data.seo.advanced_schema, null, 2)
                    : "",
            );
            setMeta({
                slug: data?.slug || "",
                type: data?.type || "page",
                locale: data?.locale || "en",
                author_name: data?.author_name || "",
                jurisdiction: data?.jurisdiction || "",
                summary: data?.summary || "",
                review_due_at: data?.review_due_at || "",
                matter_id: data?.matter_id || "",
                tool_slug: data?.tool_slug || "",
                translation_group: data?.translation_group || "",
                sources: data?.sources || [],
            });
            setState("All changes saved");
            setLoaded(true);
        };
        if (initial)
            api(`/api/v1/${kind}/${initial.id}`)
                .then((result) => apply(result.data))
                .catch((e) => {
                    if (!cancelled) setError(e.message);
                });
        else apply(null);
        return () => {
            cancelled = true;
        };
    }, [initial?.id, kind, loadAttempt]);
    useEffect(() => {
        if (!record?.id || !["revisions", "review comments"].includes(tab))
            return;
        let cancelled = false;
        const resource = tab === "revisions" ? "revisions" : "comments";
        api(`/api/v1/${kind}/${record.id}/${resource}`)
            .then((result) => {
                if (!cancelled)
                    (resource === "revisions" ? setRevisions : setComments)(
                        result.data,
                    );
            })
            .catch((e) => {
                if (!cancelled) setError(e.message);
            });
        return () => {
            cancelled = true;
        };
    }, [tab, record?.id, record?.version, kind]);
    function mark() {
        setDirty(true);
        changed.current++;
        setState("Unsaved changes");
    }
    async function save() {
        if (
            readOnly ||
            !loaded ||
            saving.current ||
            !title.trim() ||
            conflict.current
        )
            return false;
        saving.current = true;
        setState("Saving…");
        setError("");
        const generation = changed.current;
        try {
            const body = {
                title,
                blocks,
                ...(kind === "pages"
                    ? {
                          ...meta,
                          tool_slug:
                              meta.type === "tool"
                                  ? meta.tool_slug || null
                                  : null,
                          seo: {
                              ...seo,
                              ...(schemaAdmin
                                  ? {
                                        advanced_schema: advancedSchema.trim()
                                            ? JSON.parse(advancedSchema)
                                            : null,
                                    }
                                  : {}),
                          },
                      }
                    : { matter_id: meta.matter_id || null }),
                ...(record ? { expected_version: record.version } : {}),
            };
            const result = record
                ? await patch(`/api/v1/${kind}/${record.id}`, body)
                : await post(`/api/v1/${kind}`, body);
            setRecord(result.data);
            if (!record) onCreated(result.data);
            if (generation === changed.current) {
                setDirty(false);
                setState("All changes saved");
            } else setState("Unsaved changes");
            await reload();
            return generation === changed.current;
        } catch (e) {
            setState("Save failed");
            setError((e as Error).message);
            if ((e as any).status === 409) conflict.current = true;
            return false;
        } finally {
            saving.current = false;
        }
    }
    useEffect(() => {
        if (!dirty || !loaded || !title || !record || conflict.current) return;
        const timer = setTimeout(() => {
            void save();
        }, 1400);
        return () => clearTimeout(timer);
    }, [
        title,
        blocks,
        meta,
        seo,
        advancedSchema,
        dirty,
        loaded,
        record?.id,
        record?.version,
    ]);
    useEffect(() => {
        function warn(e: BeforeUnloadEvent) {
            if (dirty) {
                e.preventDefault();
                e.returnValue = "";
            }
        }
        window.addEventListener("beforeunload", warn);
        return () => window.removeEventListener("beforeunload", warn);
    }, [dirty]);
    async function action(name: string) {
        setError("");
        try {
            if (!loaded || dirty || saving.current || conflict.current) return;
            if (!record) return;
            const result = await post(`/api/v1/${kind}/${record.id}/${name}`, {
                expected_version: record.version,
            });
            setRecord(result.data);
            await reload();
        } catch (e) {
            setError((e as Error).message);
        }
    }
    const leave = () => {
        setTab("write");
        setPreviewRevision(null);
        onClose();
    };
    const close = () => {
        if (dirty || saving.current) setClosing(true);
        else leave();
    };
    return (
        <DetailPage
            open
            wide
            title={kind === "pages" ? "Page editor" : "Document workspace"}
            description="Write, review and preserve each version of your work."
            onClose={close}
        >
            {closing && (
                <div className="dialog-body form-stack" role="status">
                    <h2>Save your changes before leaving?</h2>
                    <p>Your current edits have not all been saved.</p>
                    <div className="button-row">
                        <Button
                            disabled={
                                !loaded ||
                                saving.current ||
                                !title.trim() ||
                                conflict.current
                            }
                            onClick={async () => {
                                if (await save()) leave();
                            }}
                        >
                            Save and leave
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={saving.current}
                            onClick={leave}
                        >
                            Discard unsaved changes
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={() => setClosing(false)}
                        >
                            Keep editing
                        </Button>
                    </div>
                </div>
            )}
            <div className="editor-top">
                <div>
                    <span
                        className={`save-status ${state === "Save failed" ? "is-error" : ""}`}
                    >
                        <span className="status-dot" />
                        {state}
                    </span>
                    {record && <Badge>{record.status}</Badge>}
                </div>
                <div className="button-row">
                    {record && (
                        <Button
                            variant="ghost"
                            onClick={() =>
                                download(
                                    `/api/v1/${kind}/${record.id}/export/pdf`,
                                    `${record.title}.pdf`,
                                ).catch((e) => setError(e.message))
                            }
                        >
                            <Download size={14} />
                            PDF
                        </Button>
                    )}
                    {!readOnly && (
                        <Button
                            disabled={
                                !loaded ||
                                saving.current ||
                                !title ||
                                conflict.current
                            }
                            onClick={save}
                        >
                            <Save size={15} />
                            {record ? "Save" : "Create draft"}
                        </Button>
                    )}
                </div>
            </div>
            <div className="tabs editor-tabs">
                {(readOnly
                    ? ["write"]
                    : [
                          "write",
                          ...(kind === "pages"
                              ? ["search & social", "page details"]
                              : ["details"]),
                          "revisions",
                          "review comments",
                      ]
                ).map((t) => (
                    <button
                        key={t}
                        className={tab === t ? "active" : ""}
                        onClick={() => setTab(t)}
                    >
                        {t[0].toUpperCase() + t.slice(1)}
                    </button>
                ))}
            </div>
            <div className="dialog-body editor-body">
                <Alert>{error}</Alert>
                {!loaded && error && (
                    <Button
                        variant="secondary"
                        onClick={() => setLoadAttempt((attempt) => attempt + 1)}
                    >
                        Retry loading document
                    </Button>
                )}
                {tab === "write" && (
                    <>
                        <input
                            className="document-title"
                            aria-label="Document title"
                            placeholder="Untitled document"
                            readOnly={readOnly}
                            value={title}
                            onChange={(e) => {
                                setTitle(e.target.value);
                                mark();
                            }}
                        />
                        {loaded ? (
                            <div className="blocknote-wrap">
                                <Suspense
                                    fallback={
                                        <p className="subtle">
                                            Loading the writing tools…
                                        </p>
                                    }
                                >
                                    <BlockEditor
                                        editable={!readOnly}
                                        blocks={blocks}
                                        onChange={(value) => {
                                            setBlocks(value);
                                            mark();
                                        }}
                                    />
                                </Suspense>
                            </div>
                        ) : (
                            <p>Loading document…</p>
                        )}
                        <p className="editor-hint">
                            Type / to add headings, tables, lists and more.
                        </p>
                    </>
                )}
                {tab === "search & social" && (
                    <div className="form-stack">
                        <div className="search-preview">
                            <span>Search preview</span>
                            <p>
                                {window.location.origin}/p/
                                {meta.slug || "your-page"}
                            </p>
                            <h3>{seo.title || title || "Your page title"}</h3>
                            <p>
                                {seo.description ||
                                    meta.summary ||
                                    "Add a description that helps people understand what they’ll find on this page."}
                            </p>
                        </div>
                        <div className="form-grid">
                            {[
                                ["title", "SEO title"],
                                ["description", "Meta description"],
                                ["canonical", "Canonical URL"],
                                ["og_title", "Social title"],
                                ["og_description", "Social description"],
                                ["og_image", "Social image URL"],
                                ["og_image_alt", "Social image description"],
                                ["twitter_title", "X title"],
                                ["twitter_description", "X description"],
                                ["twitter_image", "X image URL"],
                                ["twitter_image_alt", "X image description"],
                            ].map(([key, label]) => (
                                <Field key={key} label={label}>
                                    <input
                                        value={seo[key] || ""}
                                        onChange={(e) => {
                                            setSeo({
                                                ...seo,
                                                [key]: e.target.value,
                                            });
                                            mark();
                                        }}
                                    />
                                </Field>
                            ))}
                            <Field label="Search indexing">
                                <select
                                    value={seo.robots}
                                    onChange={(e) => {
                                        setSeo({
                                            ...seo,
                                            robots: e.target.value,
                                        });
                                        mark();
                                    }}
                                >
                                    <option value="index,follow">
                                        Allow indexing
                                    </option>
                                    <option value="noindex,follow">
                                        Hide from search results
                                    </option>
                                </select>
                            </Field>
                            <Field label="X card">
                                <select
                                    value={
                                        seo.twitter_card ||
                                        "summary_large_image"
                                    }
                                    onChange={(e) => {
                                        setSeo({
                                            ...seo,
                                            twitter_card: e.target.value,
                                        });
                                        mark();
                                    }}
                                >
                                    <option>summary_large_image</option>
                                    <option>summary</option>
                                </select>
                            </Field>
                        </div>
                        <Field
                            label="Structured data types"
                            hint="Only select types represented by the visible page content."
                        >
                            <div className="schema-options">
                                {[
                                    "WebPage",
                                    "Article",
                                    "BlogPosting",
                                    "Service",
                                    "LegalService",
                                    "ProfilePage",
                                    "AboutPage",
                                    "ContactPage",
                                    "SoftwareApplication",
                                    "FAQPage",
                                    "HowTo",
                                ].map((type) => (
                                    <label key={type}>
                                        <input
                                            type="checkbox"
                                            checked={(
                                                seo.schemas || []
                                            ).includes(type)}
                                            onChange={(e) => {
                                                setSeo({
                                                    ...seo,
                                                    schemas: e.target.checked
                                                        ? [
                                                              ...(seo.schemas ||
                                                                  []),
                                                              type,
                                                          ]
                                                        : (
                                                              seo.schemas || []
                                                          ).filter(
                                                              (s: string) =>
                                                                  s !== type,
                                                          ),
                                                });
                                                mark();
                                            }}
                                        />
                                        {type}
                                    </label>
                                ))}
                            </div>
                        </Field>
                        {schemaAdmin && (
                            <Field
                                label="Advanced JSON-LD"
                                hint="Administrator only. Use factual structured data that describes the visible content; the server validates it before publication."
                            >
                                <textarea
                                    rows={8}
                                    spellCheck={false}
                                    value={advancedSchema}
                                    onChange={(event) => {
                                        setAdvancedSchema(event.target.value);
                                        mark();
                                    }}
                                    placeholder={
                                        '{"@context":"https://schema.org","@type":"WebPage"}'
                                    }
                                />
                            </Field>
                        )}
                    </div>
                )}
                {["page details", "details"].includes(tab) && (
                    <div className="form-grid">
                        {(kind === "pages"
                            ? [
                                  ["slug", "Page URL slug"],
                                  ["locale", "Language code"],
                                  ["translation_group", "Translation group"],
                                  ["author_name", "Author"],
                                  ["jurisdiction", "Jurisdiction"],
                                  ["review_due_at", "Review due date"],
                                  ["summary", "Summary"],
                              ]
                            : [["matter_id", "Matter ID"]]
                        ).map(([key, label]) => (
                            <Field key={key} label={label}>
                                <input
                                    type={
                                        key === "review_due_at"
                                            ? "date"
                                            : "text"
                                    }
                                    value={meta[key]}
                                    onChange={(e) => {
                                        setMeta({
                                            ...meta,
                                            [key]: e.target.value,
                                        });
                                        mark();
                                    }}
                                />
                            </Field>
                        ))}
                        {kind === "pages" && (
                            <Field
                                label="Legal reviewer"
                                hint="Recorded from the account that approves this version."
                            >
                                <input
                                    readOnly
                                    value={
                                        record?.reviewer_name ||
                                        "Not yet approved"
                                    }
                                />
                            </Field>
                        )}
                        {kind === "pages" && (
                            <Field label="Content type">
                                <select
                                    value={meta.type}
                                    onChange={(e) => {
                                        setMeta({
                                            ...meta,
                                            type: e.target.value,
                                            tool_slug:
                                                e.target.value === "tool"
                                                    ? meta.tool_slug
                                                    : "",
                                        });
                                        mark();
                                    }}
                                >
                                    {[
                                        "page",
                                        "article",
                                        "service",
                                        "profile",
                                        "office",
                                        "tool",
                                        "about",
                                        "contact",
                                    ].map((type) => (
                                        <option key={type}>{type}</option>
                                    ))}
                                </select>
                            </Field>
                        )}
                        {kind === "pages" && meta.type === "tool" && (
                            <Field
                                label="Public AI tool mapping"
                                hint="Use this page’s approved content and search settings on the selected tool."
                            >
                                <select
                                    value={meta.tool_slug}
                                    onChange={(event) => {
                                        setMeta({
                                            ...meta,
                                            tool_slug: event.target.value,
                                        });
                                        mark();
                                    }}
                                >
                                    <option value="">
                                        Ordinary tool information page
                                    </option>
                                    <option value="notice-explainer">
                                        Notice explainer
                                    </option>
                                    <option value="consultation-preparation">
                                        Consultation preparation
                                    </option>
                                    <option value="document-completeness">
                                        Document completeness checker
                                    </option>
                                </select>
                            </Field>
                        )}
                        {kind === "pages" && (
                            <div className="page-sources">
                                <div className="section-title">
                                    <h3>Sources and authorities</h3>
                                    <Button
                                        variant="secondary"
                                        onClick={() => {
                                            setMeta({
                                                ...meta,
                                                sources: [
                                                    ...meta.sources,
                                                    { title: "", url: "" },
                                                ],
                                            });
                                            mark();
                                        }}
                                    >
                                        <Plus size={14} />
                                        Add source
                                    </Button>
                                </div>
                                {meta.sources.map(
                                    (source: any, index: number) => (
                                        <div
                                            className="source-fields"
                                            key={index}
                                        >
                                            <Field label="Source title">
                                                <input
                                                    value={source.title}
                                                    onChange={(event) => {
                                                        setMeta({
                                                            ...meta,
                                                            sources:
                                                                meta.sources.map(
                                                                    (
                                                                        entry: any,
                                                                        i: number,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...entry,
                                                                                  title: event
                                                                                      .target
                                                                                      .value,
                                                                              }
                                                                            : entry,
                                                                ),
                                                        });
                                                        mark();
                                                    }}
                                                />
                                            </Field>
                                            <Field label="Source URL">
                                                <input
                                                    type="url"
                                                    value={source.url}
                                                    onChange={(event) => {
                                                        setMeta({
                                                            ...meta,
                                                            sources:
                                                                meta.sources.map(
                                                                    (
                                                                        entry: any,
                                                                        i: number,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...entry,
                                                                                  url: event
                                                                                      .target
                                                                                      .value,
                                                                              }
                                                                            : entry,
                                                                ),
                                                        });
                                                        mark();
                                                    }}
                                                />
                                            </Field>
                                            <Button
                                                variant="ghost"
                                                onClick={() => {
                                                    setMeta({
                                                        ...meta,
                                                        sources:
                                                            meta.sources.filter(
                                                                (
                                                                    _: any,
                                                                    i: number,
                                                                ) =>
                                                                    i !== index,
                                                            ),
                                                    });
                                                    mark();
                                                }}
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    ),
                                )}
                            </div>
                        )}
                    </div>
                )}
                {tab === "revisions" && (
                    <>
                        {revisions.length ? (
                            revisions.map((rev) => (
                                <div className="audit-row" key={rev.id}>
                                    <strong>
                                        Version{" "}
                                        {rev.number ||
                                            rev.revision ||
                                            rev.version}
                                    </strong>
                                    <p>
                                        {dateLabel(rev.created_at)} ·{" "}
                                        {rev.title || title}
                                    </p>
                                    <button
                                        className="text-link"
                                        onClick={() =>
                                            api(
                                                `/api/v1/${kind}/${record?.id}/revisions/${rev.id}`,
                                            )
                                                .then((r) =>
                                                    setPreviewRevision(r.data),
                                                )
                                                .catch((e) =>
                                                    setError(e.message),
                                                )
                                        }
                                    >
                                        View saved version
                                    </button>
                                </div>
                            ))
                        ) : (
                            <Empty title="No saved revisions yet">
                                Save this document to start its history.
                            </Empty>
                        )}
                    </>
                )}
                {tab === "review comments" && (
                    <div className="form-stack">
                        {comments.map((c) => (
                            <div className="audit-row" key={c.id}>
                                <strong>
                                    {c.actor_name || "Reviewer"} · version{" "}
                                    {c.document_version}
                                </strong>
                                <p>{c.body}</p>
                            </div>
                        ))}
                        {record ? (
                            <>
                                <Field label="Review comment">
                                    <textarea
                                        rows={3}
                                        value={comment}
                                        onChange={(e) =>
                                            setComment(e.target.value)
                                        }
                                    />
                                </Field>
                                <Button
                                    disabled={!comment.trim()}
                                    onClick={async () => {
                                        try {
                                            await post(
                                                `/api/v1/${kind}/${record.id}/comments`,
                                                {
                                                    body: comment,
                                                    document_version:
                                                        record.version,
                                                },
                                            );
                                            setComment("");
                                            setComments(
                                                (
                                                    await api(
                                                        `/api/v1/${kind}/${record.id}/comments`,
                                                    )
                                                ).data,
                                            );
                                        } catch (e) {
                                            setError((e as Error).message);
                                        }
                                    }}
                                >
                                    Add review comment
                                </Button>
                            </>
                        ) : (
                            <p className="subtle">
                                Create a draft before adding comments.
                            </p>
                        )}
                    </div>
                )}
                {previewRevision && (
                    <div className="revision-preview">
                        <div className="section-title">
                            <h3>Saved version {previewRevision.version}</h3>
                            <Button
                                variant="ghost"
                                onClick={() => setPreviewRevision(null)}
                            >
                                Close preview
                            </Button>
                        </div>
                        <Suspense fallback={<p>Loading…</p>}>
                            <BlockEditor
                                key={previewRevision.version}
                                blocks={previewRevision.blocks || []}
                                editable={false}
                                onChange={() => {}}
                            />
                        </Suspense>
                    </div>
                )}
            </div>
            <div className="dialog-footer">
                <Button variant="secondary" onClick={close}>
                    Close
                </Button>
                {record && (
                    <div className="button-row">
                        <button
                            className="text-link"
                            onClick={() =>
                                download(
                                    `/api/v1/${kind}/${record.id}/export/docx`,
                                    `${record.title}.docx`,
                                ).catch((e) => setError(e.message))
                            }
                        >
                            Export DOCX
                        </button>
                        {!readOnly && record.status === "draft" && (
                            <Button
                                disabled={dirty}
                                onClick={() => action("review")}
                            >
                                Send for review
                            </Button>
                        )}
                        {!readOnly &&
                            ["review", "in_review"].includes(record.status) && (
                                <Button
                                    disabled={dirty}
                                    onClick={() => action("approve")}
                                >
                                    Approve
                                </Button>
                            )}
                        {kind === "pages" && record.status === "approved" && (
                            <Button
                                disabled={dirty}
                                onClick={() => action("publish")}
                            >
                                <Globe2 size={15} />
                                Publish page
                            </Button>
                        )}
                        {kind === "pages" && record.status === "published" && (
                            <Button
                                variant="secondary"
                                onClick={() => action("unpublish")}
                            >
                                Unpublish
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </DetailPage>
    );
}
export function Themes({
    records: initialRecords,
    reload: parentReload,
}: {
    records: RecordData[];
    reload: () => Promise<void>;
}) {
    const [records, setThemeRecords] = useState<RecordData[]>(initialRecords);
    const [imports, setImports] = useState<RecordData[]>([]);
    async function reload() {
        await parentReload();
        const r = await api("/api/v1/themes");
        setThemeRecords(r.data || []);
        const u = await api("/api/v1/themes/uploads");
        setImports(u.data || []);
    }
    useEffect(() => {
        api("/api/v1/themes")
            .then((r) => setThemeRecords(r.data || []))
            .catch((e) => setError(e.message));
        api("/api/v1/themes/uploads")
            .then((r) => setImports(r.data || []))
            .catch(() => {});
    }, [initialRecords]);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [busy, setBusy] = useState(false);
    const [designer, setDesigner] = usePageState("design", false);
    const [uploadOpen, setUploadOpen] = usePageState("upload", false);
    const [pendingFile, setPendingFile] = useState<File | null>(null);
    async function act(path: string, body?: any) {
        setBusy(true);
        setError("");
        try {
            await post(path, body);
            await reload();
            setNotice("Theme settings updated.");
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    async function upload(file?: File) {
        if (!file) return;
        setBusy(true);
        const form = new FormData();
        form.append("theme", file);
        try {
            await api("/api/v1/themes/upload", { method: "POST", body: form });
            await reload();
            setPendingFile(null);
            setUploadOpen(false);
            setNotice(
                "Upload received. Files are quarantined until the configured scanner clears them.",
            );
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <Alert>{error}</Alert>
            <Alert kind="success">{notice}</Alert>
            <div className="module-toolbar">
                <p className="subtle">
                    Public website themes and brand settings
                </p>
                <div className="button-row">
                    <Button
                        disabled={busy}
                        variant="secondary"
                        onClick={() => act("/api/v1/themes/rollback")}
                    >
                        <RotateCcw size={15} />
                        Restore previous
                    </Button>
                    <Button
                        disabled={busy}
                        variant="secondary"
                        onClick={() => setUploadOpen(true)}
                    >
                        <Upload size={15} />
                        Upload ZIP
                    </Button>
                    <AddButton onClick={() => setDesigner(true)}>
                        Design a theme
                    </AddButton>
                </div>
            </div>
            <div className="theme-grid">
                {records.map((theme) => (
                    <article key={theme.id} className="theme-card">
                        <div
                            className="theme-preview"
                            style={
                                {
                                    "--preview-accent":
                                        theme.tokens?.accent || "#16736c",
                                    "--preview-ink":
                                        theme.tokens?.ink || "#142b40",
                                    "--preview-paper":
                                        theme.tokens?.paper || "#f7f9fa",
                                } as any
                            }
                        >
                            <div className="preview-nav">
                                <span>
                                    <ScaleIcon />
                                    Your practice
                                </span>
                                <i />
                                <i />
                            </div>
                            <div className="preview-copy">
                                <small>Thoughtful counsel.</small>
                                <h3>
                                    A clear way
                                    <br />
                                    forward.
                                </h3>
                                <p>
                                    Dedicated advice for the moments that
                                    matter.
                                </p>
                                <span>Talk to our team</span>
                            </div>
                            <div className="preview-columns">
                                <i />
                                <i />
                                <i />
                            </div>
                        </div>
                        <div className="theme-card-meta">
                            <div>
                                <h3>
                                    {theme.name ||
                                        theme.manifest?.name ||
                                        theme.id}
                                </h3>
                                <p>
                                    Version{" "}
                                    {theme.theme_version ||
                                        theme.manifest?.version ||
                                        "1.0.0"}
                                </p>
                            </div>
                            <Badge>
                                {theme.active || theme.status === "active"
                                    ? "Active"
                                    : "Available"}
                            </Badge>
                        </div>
                        <div className="theme-card-actions">
                            <a
                                className="button button-secondary"
                                href={`/api/v1/themes/${theme.id}/preview`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Preview <ArrowUpRight size={14} />
                            </a>
                            {!(theme.active || theme.status === "active") && (
                                <Button
                                    disabled={busy}
                                    onClick={() =>
                                        act(
                                            `/api/v1/themes/${theme.id}/activate`,
                                        )
                                    }
                                >
                                    Activate
                                </Button>
                            )}
                        </div>
                    </article>
                ))}
            </div>
            {!records.length && (
                <Empty
                    title="Make your website yours"
                    action={
                        <AddButton onClick={() => setDesigner(true)}>
                            Design your first theme
                        </AddButton>
                    }
                >
                    Create a theme with the visual designer, or upload a
                    compatible theme ZIP.
                </Empty>
            )}
            {imports.some((i) => i.status !== "accepted") && (
                <div className="section-space">
                    <h2 className="section-heading">Theme upload status</h2>
                    <DataTable
                        records={imports}
                        columns={[
                            {
                                key: "name",
                                label: "Upload",
                                render: (r) => r.name || "Theme ZIP",
                            },
                            {
                                key: "status",
                                label: "Status",
                                render: (r) => <Badge>{r.status}</Badge>,
                            },
                            { key: "message", label: "Details" },
                            {
                                key: "retry",
                                label: "Scan",
                                render: (r) =>
                                    r.status === "quarantined" && (
                                        <Button
                                            variant="secondary"
                                            onClick={() =>
                                                act(
                                                    `/api/v1/themes/uploads/${r.id}/retry`,
                                                )
                                            }
                                        >
                                            Retry scan
                                        </Button>
                                    ),
                            },
                        ]}
                    />
                </div>
            )}
            <div className="info-band">
                <Palette size={20} />
                <p>
                    <strong>A safe, portable theme format.</strong> Upload a ZIP
                    containing theme.json, tokens.json and declarative page
                    templates. Maximum 25 MB. Uploaded themes use trusted
                    website components.
                    <br />
                    For a site managed in Website settings, import the preset
                    there and publish the draft.{" "}
                    <Link
                        className="text-link"
                        href="/app/website?v_website-tab=brand"
                    >
                        Apply a theme to website settings{" "}
                        <ArrowUpRight size={14} />
                    </Link>
                </p>
            </div>
            {uploadOpen && (
                <DetailPage
                    open
                    title="Upload website theme"
                    description="Upload a declarative Theme API ZIP package. Maximum 25 MB."
                    onClose={() => {
                        setPendingFile(null);
                        setUploadOpen(false);
                    }}
                >
                    <div className="dialog-body form-stack">
                        <Alert>{error}</Alert>
                        <Field label="Theme ZIP package">
                            <input
                                type="file"
                                accept=".zip"
                                onChange={(e) =>
                                    setPendingFile(e.target.files?.[0] || null)
                                }
                            />
                        </Field>
                        <p className="subtle">
                            The archive is validated and scanned before it
                            becomes available to preview or activate.
                        </p>
                    </div>
                    <div className="dialog-footer">
                        <Button
                            variant="secondary"
                            onClick={() => {
                                setPendingFile(null);
                                setUploadOpen(false);
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={busy || !pendingFile}
                            onClick={() => upload(pendingFile || undefined)}
                        >
                            {busy ? "Uploading…" : "Submit theme"}
                        </Button>
                    </div>
                </DetailPage>
            )}
            {designer && (
                <ThemeDesigner
                    onClose={() => setDesigner(false)}
                    onSave={async (body) => {
                        await post("/api/v1/themes/designer", body);
                        await reload();
                        setDesigner(false);
                    }}
                />
            )}
        </>
    );
}
function ScaleIcon() {
    return <span className="mini-scale">C.</span>;
}
function ThemeDesigner({
    onClose,
    onSave,
}: {
    onClose: () => void;
    onSave: (body: any) => Promise<void>;
}) {
    const [name, setName] = useState("My practice theme");
    const [tokens, setTokens] = useState({
        accent: "#16736c",
        ink: "#142b40",
        paper: "#f7f9fa",
        font_family: "serif",
        radius: 8,
    });
    const [sections, setSections] = useState<any[]>([
        {
            type: "hero",
            heading: "A clear way forward.",
            text: "Experienced counsel for the moments that matter.",
        },
        { type: "content" },
        {
            type: "cta",
            heading: "Let’s discuss your next step.",
            button_label: "Contact our team",
            button_url: "/p/contact",
        },
    ]);
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    return (
        <DetailPage
            open
            wide
            title="Theme designer"
            description="Configure your brand and compose the page’s trusted sections."
            onClose={onClose}
        >
            <div className="dialog-body">
                <Alert>{error}</Alert>
                <div className="designer-grid">
                    <div className="form-stack">
                        <Field label="Theme name">
                            <input
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                            />
                        </Field>
                        <div className="color-fields">
                            {(["accent", "ink", "paper"] as const).map(
                                (key) => (
                                    <Field
                                        label={
                                            key[0].toUpperCase() + key.slice(1)
                                        }
                                        key={key}
                                    >
                                        <input
                                            type="color"
                                            value={tokens[key]}
                                            onChange={(e) =>
                                                setTokens({
                                                    ...tokens,
                                                    [key]: e.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                ),
                            )}
                        </div>
                        <Field label="Typography">
                            <select
                                value={tokens.font_family}
                                onChange={(e) =>
                                    setTokens({
                                        ...tokens,
                                        font_family: e.target.value,
                                    })
                                }
                            >
                                <option value="serif">Serif</option>
                                <option value="sans">Sans serif</option>
                                <option value="system">System font</option>
                            </select>
                        </Field>
                        <Field label="Corner radius">
                            <select
                                value={tokens.radius}
                                onChange={(e) =>
                                    setTokens({
                                        ...tokens,
                                        radius: Number(e.target.value),
                                    })
                                }
                            >
                                <option value="0">Square</option>
                                <option value="4">4 px</option>
                                <option value="8">8 px</option>
                                <option value="12">12 px</option>
                            </select>
                        </Field>
                    </div>
                    <div className="designer-sections">
                        {sections.map((section, index) => (
                            <div className="designer-section" key={index}>
                                <div className="designer-section-head">
                                    <Badge>{section.type}</Badge>
                                    <button
                                        className="text-link"
                                        onClick={() =>
                                            setSections(
                                                sections.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        Remove
                                    </button>
                                </div>
                                {section.type !== "content" && (
                                    <>
                                        <Field label="Heading">
                                            <input
                                                value={section.heading || ""}
                                                onChange={(e) =>
                                                    setSections(
                                                        sections.map((s, i) =>
                                                            i === index
                                                                ? {
                                                                      ...s,
                                                                      heading:
                                                                          e
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : s,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field label="Text">
                                            <textarea
                                                rows={2}
                                                value={section.text || ""}
                                                onChange={(e) =>
                                                    setSections(
                                                        sections.map((s, i) =>
                                                            i === index
                                                                ? {
                                                                      ...s,
                                                                      text: e
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : s,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                    </>
                                )}
                                {section.type === "content" && (
                                    <p className="subtle">
                                        Your published BlockNote content appears
                                        here.
                                    </p>
                                )}
                                {section.type === "cta" && (
                                    <div className="form-grid">
                                        <Field label="Button label">
                                            <input
                                                value={
                                                    section.button_label || ""
                                                }
                                                onChange={(e) =>
                                                    setSections(
                                                        sections.map((s, i) =>
                                                            i === index
                                                                ? {
                                                                      ...s,
                                                                      button_label:
                                                                          e
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : s,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field label="Button URL">
                                            <input
                                                value={section.button_url || ""}
                                                onChange={(e) =>
                                                    setSections(
                                                        sections.map((s, i) =>
                                                            i === index
                                                                ? {
                                                                      ...s,
                                                                      button_url:
                                                                          e
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : s,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>
                                )}
                            </div>
                        ))}
                        <div className="button-row">
                            {["hero", "content", "cta", "contact"].map(
                                (type) => (
                                    <Button
                                        key={type}
                                        variant="secondary"
                                        onClick={() =>
                                            setSections([...sections, { type }])
                                        }
                                    >
                                        <Plus size={14} />
                                        {type}
                                    </Button>
                                ),
                            )}
                        </div>
                    </div>
                </div>
            </div>
            <div className="dialog-footer">
                <Button variant="secondary" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    disabled={busy || !name}
                    onClick={async () => {
                        setBusy(true);
                        try {
                            await onSave({
                                name,
                                tokens,
                                templates: { page: { sections } },
                            });
                        } catch (e) {
                            setError((e as Error).message);
                            setBusy(false);
                        }
                    }}
                >
                    {busy ? "Saving…" : "Save theme version"}
                </Button>
            </div>
        </DetailPage>
    );
}
