// Author: ramanpal singh | URL: https://kwebby.com
import {
    useState,
    createContext,
    useContext,
    useId,
    useLayoutEffect,
    useRef,
    type ReactNode,
    type ButtonHTMLAttributes,
    type FormEvent,
} from "react";
import { createPortal } from "react-dom";
import { useWorkspacePages } from "../lib/navigation";
import {
    ArrowLeft,
    Search,
    AlertCircle,
    Plus,
    ArrowUpRight,
} from "lucide-react";
import type { FieldSpec, RecordData } from "../lib/types";
import { display, ApiError } from "../lib/api";
export function Button({
    variant = "primary",
    className = "",
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: "primary" | "secondary" | "ghost" | "danger";
}) {
    return (
        <button
            className={`button button-${variant} ${className}`}
            {...props}
        />
    );
}
export function Badge({
    children,
    tone,
}: {
    children: ReactNode;
    tone?: string;
}) {
    const value = String(children ?? "").toLowerCase();
    const color =
        tone ||
        (/approved|active|clear|paid|completed|released|published|ready/.test(
            value,
        )
            ? "green"
            : /urgent|overdue|high|flagged|failed|rejected/.test(value)
              ? "red"
              : /pending|review|progress|awaiting/.test(value)
                ? "amber"
                : "neutral");
    return (
        <span className={`badge badge-${color}`}>
            {String(children || "Draft").replaceAll("_", " ")}
        </span>
    );
}
export function Alert({
    children,
    kind = "error",
}: {
    children?: ReactNode;
    kind?: string;
}) {
    return children ? (
        <div
            className={`alert alert-${kind}`}
            role={kind === "error" ? "alert" : "status"}
        >
            <AlertCircle size={17} />
            <div>{children}</div>
        </div>
    ) : null;
}
export function Empty({
    title,
    children,
    action,
}: {
    title: string;
    children?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="empty">
            <span className="empty-line" />
            <h3>{title}</h3>
            <p>{children}</p>
            {action}
        </div>
    );
}
const PageDepth = createContext(0);
export function DetailPage({
    open,
    onClose,
    title,
    description,
    children,
    wide = false,
    priority = 0,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children: ReactNode;
    wide?: boolean;
    priority?: number;
}) {
    const { target, pages, register } = useWorkspacePages();
    const id = useId();
    const depth = useContext(PageDepth);
    const heading = useRef<HTMLHeadingElement>(null);
    useLayoutEffect(() => {
        if (open) return register(id, depth + priority);
    }, [open, id, register, depth, priority]);
    const active = pages.at(-1)?.id === id;
    useLayoutEffect(() => {
        if (active && target) {
            heading.current?.focus({ preventScroll: true });
            window.scrollTo({ top: 0, behavior: "instant" });
        }
    }, [active, target]);
    if (!open || !target) return null;
    return createPortal(
        <PageDepth.Provider value={depth + 1}>
            <section
                className={`workflow-page ${wide ? "workflow-wide" : ""}`}
                hidden={!active}
                aria-labelledby={id}
            >
                <button
                    className="text-link workflow-back"
                    type="button"
                    onClick={onClose}
                >
                    <ArrowLeft size={17} /> Back
                </button>
                <header className="workflow-heading">
                    <h1 id={id} ref={heading} tabIndex={-1}>
                        {title}
                    </h1>
                    {description && <p>{description}</p>}
                </header>
                <div className="workflow-content">{children}</div>
            </section>
        </PageDepth.Provider>,
        target,
    );
}
export function Field({
    label,
    children,
    hint,
    error,
}: {
    label: string;
    children: ReactNode;
    hint?: string;
    error?: string;
}) {
    return (
        <label className="field">
            <span>{label}</span>
            {children}
            {hint && <small>{hint}</small>}
            {error && <small className="field-error">{error}</small>}
        </label>
    );
}
export function RecordForm({
    fields,
    initial,
    onSave,
    onClose,
    title,
}: {
    fields: FieldSpec[];
    initial?: RecordData;
    onSave: (data: any) => Promise<any>;
    onClose: () => void;
    title: string;
}) {
    const [values, setValues] = useState<Record<string, any>>(() =>
        Object.fromEntries(
            fields.map((field) => [field.name, initial?.[field.name] ?? ""]),
        ),
    );
    const [error, setError] = useState("");
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>(
        {},
    );
    const [busy, setBusy] = useState(false);
    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError("");
        try {
            await onSave({
                ...Object.fromEntries(
                    Object.entries(values)
                        .filter(
                            ([key, value]) =>
                                value !== "" ||
                                (initial &&
                                    [
                                        "email",
                                        "phone",
                                        "address",
                                        "safe_contact",
                                        "notes",
                                        "next_action",
                                        "next_action_at",
                                        "due_at",
                                        "hearing_at",
                                        "matter_id",
                                        "retention_until",
                                    ].includes(key)),
                        )
                        .map(([key, value]) => [
                            key,
                            value === "" ? null : value,
                        ]),
                ),
                ...(initial?.version
                    ? {
                          version: initial.version,
                          expected_version: initial.version,
                      }
                    : {}),
            });
            onClose();
        } catch (e) {
            setError((e as Error).message);
            setFieldErrors(e instanceof ApiError ? e.errors : {});
        } finally {
            setBusy(false);
        }
    }
    return (
        <DetailPage open onClose={onClose} title={title}>
            <form onSubmit={submit}>
                <div className="dialog-body">
                    <Alert>{error}</Alert>
                    <div className="form-grid">
                        {fields.map((field) => (
                            <Field
                                key={field.name}
                                label={`${field.label}${field.required ? " *" : ""}`}
                                hint={field.hint}
                                error={fieldErrors[field.name]?.[0]}
                            >
                                {field.type === "select" ? (
                                    <select
                                        value={values[field.name]}
                                        required={field.required}
                                        onChange={(e) =>
                                            setValues({
                                                ...values,
                                                [field.name]: e.target.value,
                                            })
                                        }
                                    >
                                        <option value="">
                                            Select {field.label.toLowerCase()}
                                        </option>
                                        {field.options?.map((option) => (
                                            <option key={option} value={option}>
                                                {option.replaceAll("_", " ")}
                                            </option>
                                        ))}
                                    </select>
                                ) : field.type === "textarea" ? (
                                    <textarea
                                        value={values[field.name]}
                                        required={field.required}
                                        rows={4}
                                        onChange={(e) =>
                                            setValues({
                                                ...values,
                                                [field.name]: e.target.value,
                                            })
                                        }
                                    />
                                ) : (
                                    <input
                                        type={field.type || "text"}
                                        value={values[field.name]}
                                        placeholder={field.placeholder}
                                        required={field.required}
                                        onChange={(e) =>
                                            setValues({
                                                ...values,
                                                [field.name]: e.target.value,
                                            })
                                        }
                                    />
                                )}
                            </Field>
                        ))}
                    </div>
                </div>
                <div className="dialog-footer">
                    <Button variant="secondary" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button disabled={busy} type="submit">
                        {busy ? "Saving…" : "Save changes"}
                    </Button>
                </div>
            </form>
        </DetailPage>
    );
}
export type Column = {
    key: string;
    label: string;
    render?: (record: RecordData) => ReactNode;
};
export function DataTable({
    records,
    columns,
    onOpen,
    emptyTitle = "No records yet",
    emptyAction,
}: {
    records: RecordData[];
    columns: Column[];
    onOpen?: (record: RecordData) => void;
    emptyTitle?: string;
    emptyAction?: ReactNode;
}) {
    const [query, setQuery] = useState("");
    const rows = records.filter((row) =>
        columns.some((column) =>
            display(row[column.key])
                .toLowerCase()
                .includes(query.toLowerCase()),
        ),
    );
    return (
        <div className="table-panel">
            <div className="table-toolbar">
                <label className="search-field">
                    <Search size={16} />
                    <input
                        aria-label="Search records"
                        placeholder="Search records…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                </label>
                <span className="subtle">
                    {rows.length} {rows.length === 1 ? "record" : "records"}
                </span>
            </div>
            {!rows.length ? (
                <Empty
                    title={query ? "No matching records" : emptyTitle}
                    action={!query && emptyAction}
                >
                    {query
                        ? "Try a different name, reference or status."
                        : "New records will appear here as your team adds them."}
                </Empty>
            ) : (
                <div className="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                {columns.map((column) => (
                                    <th key={column.key}>{column.label}</th>
                                ))}
                                {onOpen && (
                                    <th>
                                        <span className="sr-only">Open</span>
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((record) => (
                                <tr key={record.id}>
                                    {columns.map((column, index) => (
                                        <td key={column.key}>
                                            {index === 0 && onOpen ? (
                                                <button
                                                    className="table-link"
                                                    onClick={() =>
                                                        onOpen(record)
                                                    }
                                                >
                                                    {column.render
                                                        ? column.render(record)
                                                        : display(
                                                              record[
                                                                  column.key
                                                              ],
                                                          )}
                                                </button>
                                            ) : column.render ? (
                                                column.render(record)
                                            ) : (
                                                display(record[column.key])
                                            )}
                                        </td>
                                    ))}
                                    {onOpen && (
                                        <td>
                                            <button
                                                className="icon-button"
                                                aria-label={`Open ${record.title || record.name || "record"}`}
                                                onClick={() => onOpen(record)}
                                            >
                                                <ArrowUpRight size={16} />
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
export function SectionTitle({
    title,
    description,
    action,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="section-title">
            <div>
                <h2>{title}</h2>
                {description && <p>{description}</p>}
            </div>
            {action}
        </div>
    );
}
export function AddButton({
    onClick,
    children,
}: {
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <Button onClick={onClick}>
            <Plus size={16} />
            {children}
        </Button>
    );
}
