// Author: ramanpal singh | URL: https://kwebby.com
import { usePageState } from "../lib/navigation";
import { useEffect, useState } from "react";
import { Clock3, ReceiptText } from "lucide-react";
import type { RecordData } from "../lib/types";
import { api, dateLabel, money, patch, post, toMinor } from "../lib/api";
import {
    AddButton,
    Alert,
    Badge,
    Button,
    DataTable,
    Field,
    DetailPage,
} from "./ui";

const currencies = ["USD", "INR", "GBP", "EUR", "CAD", "AUD", "AED", "JPY"];
const today = () => new Date().toLocaleDateString("en-CA");
const decimal = (value: string | undefined, currency: string) =>
    value ? money(value, currency).split(" ")[1].replaceAll(",", "") : "";
const localDateTime = (value?: string) => {
    if (!value) return "";
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
};

export function BillingWork({
    kind,
    onInvoice,
}: {
    kind: "time-entries" | "expenses";
    onInvoice: () => Promise<void>;
}) {
    const [records, setRecords] = useState<RecordData[]>([]);
    const [matters, setMatters] = useState<RecordData[]>([]);
    const [selected, setSelected] = usePageState<RecordData | null>(
        `${kind}-entry`,
        null,
        {
            records,
            load: async (id) => (await api(`/api/v1/${kind}/${id}`)).data,
        },
    );
    const [form, setForm] = usePageState<RecordData | "new" | null>(
        `${kind}-edit`,
        null,
        {
            records,
            load: async (id) => (await api(`/api/v1/${kind}/${id}`)).data,
        },
    );
    const [invoice, setInvoice] = usePageState("invoice-approved-work", false);
    const [error, setError] = useState("");
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [reason, setReason] = useState("");
    const time = kind === "time-entries";
    async function load() {
        const results = await Promise.allSettled([
            api(`/api/v1/${kind}`),
            api("/api/v1/records/matters"),
        ]);
        const failures = results
            .filter((r) => r.status === "rejected")
            .map((r) => (r as PromiseRejectedResult).reason.message);
        if (results[0].status === "fulfilled")
            setRecords(results[0].value.data);
        if (results[1].status === "fulfilled")
            setMatters(results[1].value.data);
        if (failures.length) throw new Error(failures.join(" "));
    }
    useEffect(() => {
        load()
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [kind]);
    async function transition(action: string) {
        if (!selected) return;
        setBusy(true);
        setError("");
        try {
            const result =
                action === "archive"
                    ? await api(`/api/v1/${kind}/${selected.id}`, {
                          method: "DELETE",
                          body: JSON.stringify({ version: selected.version }),
                      })
                    : await post(
                          `/api/v1/${kind}/${selected.id}/${action}`,
                          action === "reject" ? { reason } : {},
                      );
            setSelected(action === "archive" ? null : result.data);
            setReason("");
            await load();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <Alert>{error}</Alert>
            <div className="module-toolbar">
                <p className="subtle">
                    {time
                        ? "Record time against a matter, then submit it for billing review."
                        : "Record matter expenses and approved receipts before invoicing."}
                </p>
                <div className="button-row">
                    <Button
                        variant="secondary"
                        onClick={() => setInvoice(true)}
                    >
                        Invoice approved work
                    </Button>
                    <AddButton onClick={() => setForm("new")}>
                        {time ? "Record time" : "Add expense"}
                    </AddButton>
                </div>
            </div>
            {loading ? (
                <p className="subtle" role="status">
                    Loading billing work…
                </p>
            ) : (
                <DataTable
                    records={records.filter((r) => r.status !== "archived")}
                    onOpen={(r) => {
                        setSelected(r);
                        setReason("");
                        setError("");
                    }}
                    columns={[
                        {
                            key: "description",
                            label: time ? "Work recorded" : "Expense",
                            render: (r) => (
                                <span>
                                    {r.description}
                                    <small className="cell-sub">
                                        {matters.find(
                                            (m) => m.id === r.matter_id,
                                        )?.title || "Matter"}
                                    </small>
                                </span>
                            ),
                        },
                        {
                            key: "work_date",
                            label: "Date",
                            render: (r) => dateLabel(r.work_date),
                        },
                        ...(time
                            ? [
                                  {
                                      key: "minutes",
                                      label: "Duration",
                                      render: (r: RecordData) =>
                                          `${r.minutes} minutes`,
                                  },
                              ]
                            : []),
                        {
                            key: "amount_minor",
                            label: "Amount",
                            render: (r) => money(r.amount_minor, r.currency),
                        },
                        {
                            key: "billable",
                            label: "Billing",
                            render: (r) =>
                                r.billable ? "Billable" : "Non-billable",
                        },
                        {
                            key: "status",
                            label: "Status",
                            render: (r) => (
                                <div className="button-row">
                                    <Badge>{r.status}</Badge>
                                    {r.status === "draft" && (
                                        <Button
                                            variant="ghost"
                                            disabled={busy}
                                            onClick={async (event) => {
                                                event.stopPropagation();
                                                setBusy(true);
                                                setError("");
                                                try {
                                                    await post(
                                                        `/api/v1/${kind}/${r.id}/submit`,
                                                    );
                                                    await load();
                                                } catch (error) {
                                                    setError(
                                                        (error as Error)
                                                            .message,
                                                    );
                                                } finally {
                                                    setBusy(false);
                                                }
                                            }}
                                        >
                                            Submit
                                        </Button>
                                    )}
                                    {r.status === "submitted" && (
                                        <Button
                                            variant="ghost"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                setSelected(r);
                                            }}
                                        >
                                            Review
                                        </Button>
                                    )}
                                </div>
                            ),
                        },
                    ]}
                    emptyTitle={
                        time ? "No time recorded" : "No expenses recorded"
                    }
                    emptyAction={
                        <AddButton onClick={() => setForm("new")}>
                            {time ? "Record time" : "Add expense"}
                        </AddButton>
                    }
                />
            )}
            {form && (
                <WorkForm
                    key={typeof form === "string" ? "new" : form.id}
                    kind={kind}
                    initial={typeof form === "string" ? undefined : form}
                    matters={matters}
                    onClose={() => setForm(null)}
                    onSave={async (body) => {
                        const result =
                            typeof form === "string"
                                ? await post(`/api/v1/${kind}`, body)
                                : await patch(
                                      `/api/v1/${kind}/${form.id}`,
                                      body,
                                  );
                        await load();
                        setForm(null);
                        setSelected(result.data);
                    }}
                />
            )}
            {selected && !form && (
                <DetailPage
                    open
                    title={time ? "Time entry" : "Matter expense"}
                    description="Review internal work notes and the separate wording used on the client invoice."
                    onClose={() => setSelected(null)}
                >
                    <div className="dialog-body form-stack">
                        <Alert>{error}</Alert>
                        <div className="button-row">
                            <Badge>{selected.status}</Badge>
                            <strong>
                                {money(
                                    selected.amount_minor,
                                    selected.currency,
                                )}
                            </strong>
                            <span>{dateLabel(selected.work_date)}</span>
                        </div>
                        <Field label="Internal work notes">
                            <p>{selected.description}</p>
                        </Field>
                        <Field label="Client invoice description">
                            <p>{selected.billing_description}</p>
                        </Field>
                        {selected.review_notes && (
                            <Alert kind="info">
                                Review notes: {selected.review_notes}
                            </Alert>
                        )}
                        {selected.invoice_id && (
                            <Alert kind="info">
                                Included in invoice {selected.invoice_id}.
                            </Alert>
                        )}
                        {selected.status === "submitted" && (
                            <Field
                                label="Correction reason"
                                hint="Required when returning this entry for correction."
                            >
                                <textarea
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                            </Field>
                        )}
                    </div>
                    <div className="dialog-footer">
                        <Button
                            variant="secondary"
                            onClick={() => setSelected(null)}
                        >
                            Close
                        </Button>
                        <div className="button-row">
                            {selected.status === "draft" && (
                                <>
                                    <Button
                                        variant="danger"
                                        disabled={busy}
                                        onClick={() => transition("archive")}
                                    >
                                        Archive
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        onClick={() => setForm(selected)}
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        disabled={busy}
                                        onClick={() => transition("submit")}
                                    >
                                        Submit for review
                                    </Button>
                                </>
                            )}
                            {selected.status === "submitted" && (
                                <>
                                    <Button
                                        variant="secondary"
                                        disabled={busy || !reason.trim()}
                                        onClick={() => transition("reject")}
                                    >
                                        Return for correction
                                    </Button>
                                    <Button
                                        disabled={busy}
                                        onClick={() => transition("approve")}
                                    >
                                        Approve
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                </DetailPage>
            )}
            {invoice && (
                <WorkInvoice
                    matters={matters}
                    onClose={() => setInvoice(false)}
                    onCreated={async () => {
                        setInvoice(false);
                        await load();
                        await onInvoice();
                    }}
                />
            )}
        </>
    );
}

function WorkForm({
    kind,
    initial,
    matters,
    onSave,
    onClose,
}: {
    kind: string;
    initial?: RecordData;
    matters: RecordData[];
    onSave: (body: any) => Promise<void>;
    onClose: () => void;
}) {
    const time = kind === "time-entries";
    const [value, setValue] = useState({
        matter_id: initial?.matter_id || "",
        description: initial?.description || "",
        billing_description:
            initial?.billing_description ||
            (time ? "Professional services" : "Approved expense"),
        work_date: initial?.work_date || today(),
        minutes: initial?.minutes || 60,
        currency: initial?.currency || "USD",
        amount: decimal(
            time ? initial?.rate_minor : initial?.amount_minor,
            initial?.currency || "USD",
        ),
        tax: String((initial?.tax_bps || 0) / 100),
        billable: initial?.billable ?? true,
        receipt_id: initial?.receipt_id || "",
    });
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const set = (key: string, v: any) => setValue({ ...value, [key]: v });
    return (
        <DetailPage
            open
            wide
            title={`${initial ? "Edit" : "New"} ${time ? "time entry" : "expense"}`}
            onClose={onClose}
        >
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError("");
                    try {
                        await onSave({
                            ...(initial ? { version: initial.version } : {}),
                            matter_id: value.matter_id,
                            description: value.description,
                            billing_description: value.billing_description,
                            work_date: value.work_date,
                            currency: value.currency,
                            billable: value.billable,
                            tax_bps: Math.round(Number(value.tax) * 100),
                            ...(time
                                ? {
                                      minutes: Number(value.minutes),
                                      rate_minor: toMinor(
                                          value.amount,
                                          value.currency,
                                      ),
                                  }
                                : {
                                      amount_minor: toMinor(
                                          value.amount,
                                          value.currency,
                                      ),
                                      receipt_id: value.receipt_id || null,
                                  }),
                        });
                    } catch (e) {
                        setError((e as Error).message);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <div className="dialog-body form-stack">
                    <Alert>{error}</Alert>
                    <div className="form-grid">
                        <Field label="Matter *">
                            <select
                                required
                                value={value.matter_id}
                                onChange={(e) =>
                                    set("matter_id", e.target.value)
                                }
                            >
                                <option value="">Choose a matter</option>
                                {matters
                                    .filter(
                                        (m) =>
                                            !["closed", "archived"].includes(
                                                m.status,
                                            ),
                                    )
                                    .map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.title}
                                        </option>
                                    ))}
                            </select>
                        </Field>
                        <Field label="Work date *">
                            <input
                                required
                                type="date"
                                max={today()}
                                value={value.work_date}
                                onChange={(e) =>
                                    set("work_date", e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <Field
                        label="Internal work notes *"
                        hint="These notes remain internal. The invoice uses the description below."
                    >
                        <textarea
                            required
                            maxLength={2000}
                            value={value.description}
                            onChange={(e) => set("description", e.target.value)}
                        />
                    </Field>
                    <Field label="Client invoice description *">
                        <input
                            required
                            maxLength={1000}
                            value={value.billing_description}
                            onChange={(e) =>
                                set("billing_description", e.target.value)
                            }
                        />
                    </Field>
                    <div className="form-grid">
                        {time && (
                            <Field label="Minutes *">
                                <input
                                    required
                                    type="number"
                                    min={1}
                                    max={1440}
                                    value={value.minutes}
                                    onChange={(e) =>
                                        set("minutes", e.target.value)
                                    }
                                />
                            </Field>
                        )}
                        <Field label="Currency">
                            <select
                                value={value.currency}
                                onChange={(e) =>
                                    set("currency", e.target.value)
                                }
                            >
                                {currencies.map((c) => (
                                    <option key={c}>{c}</option>
                                ))}
                            </select>
                        </Field>
                        <Field
                            label={`${time ? "Hourly rate" : "Amount"} (${value.currency}) *`}
                        >
                            <input
                                required
                                inputMode="decimal"
                                value={value.amount}
                                onChange={(e) => set("amount", e.target.value)}
                            />
                        </Field>
                        <Field label="Tax %">
                            <input
                                type="number"
                                min={0}
                                max={100}
                                step="0.01"
                                value={value.tax}
                                onChange={(e) => set("tax", e.target.value)}
                            />
                        </Field>
                    </div>
                    {!time && (
                        <Field
                            label="Receipt document ID"
                            hint="Use a clean, scanned receipt from this matter’s documents."
                        >
                            <input
                                value={value.receipt_id}
                                onChange={(e) =>
                                    set("receipt_id", e.target.value)
                                }
                            />
                        </Field>
                    )}
                    <label className="check-label">
                        <input
                            type="checkbox"
                            checked={value.billable}
                            onChange={(e) => set("billable", e.target.checked)}
                        />
                        Billable to the client
                    </label>
                </div>
                <div className="dialog-footer">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button disabled={busy}>
                        {busy ? "Saving…" : "Save draft"}
                    </Button>
                </div>
            </form>
        </DetailPage>
    );
}

function WorkInvoice({
    matters,
    onCreated,
    onClose,
}: {
    matters: RecordData[];
    onCreated: () => Promise<void>;
    onClose: () => void;
}) {
    const [matter, setMatter] = useState("");
    const [currency, setCurrency] = useState("USD");
    const [entries, setEntries] = useState<RecordData[]>([]);
    const [selected, setSelected] = useState<string[]>([]);
    const [clients, setClients] = useState<RecordData[]>([]);
    const [granted, setGranted] = useState<string[]>([]);
    const [recipient, setRecipient] = useState({
        name: "",
        email: "",
        address: "",
    });
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const [idempotency] = useState(crypto.randomUUID());
    useEffect(() => {
        let active = true;
        setEntries([]);
        setSelected([]);
        setClients([]);
        setGranted([]);
        setError("");
        if (matter)
            Promise.allSettled([
                api(
                    `/api/v1/time-entries?matter_id=${encodeURIComponent(matter)}&status=approved`,
                ),
                api(
                    `/api/v1/expenses?matter_id=${encodeURIComponent(matter)}&status=approved`,
                ),
                api(`/api/v1/people?matter_id=${encodeURIComponent(matter)}`),
            ]).then((results) => {
                if (!active) return;
                const failures = results.filter((r) => r.status === "rejected");
                if (failures.length)
                    setError(
                        failures
                            .map(
                                (r) =>
                                    (r as PromiseRejectedResult).reason.message,
                            )
                            .join(" "),
                    );
                setEntries(
                    results.slice(0, 2).flatMap((r, i) =>
                        r.status === "fulfilled"
                            ? r.value.data.map((v: RecordData) => ({
                                  ...v,
                                  source: i === 0 ? "time" : "expense",
                              }))
                            : [],
                    ),
                );
                if (results[2].status === "fulfilled")
                    setClients(
                        results[2].value.data.filter((p: RecordData) =>
                            (p.roles || []).some((r: string) =>
                                ["client", "prospect"].includes(r),
                            ),
                        ),
                    );
            });
        return () => {
            active = false;
        };
    }, [matter]);
    const eligible = entries.filter(
        (r) => r.billable && r.currency === currency,
    );
    return (
        <DetailPage
            open
            wide
            title="Invoice approved work"
            description="Choose reviewed time and expenses for one matter and currency. A draft invoice is created for your review."
            onClose={onClose}
        >
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError("");
                    try {
                        const chosen = eligible.filter((r) =>
                            selected.includes(r.id),
                        );
                        await post("/api/v1/billing/draft-invoice", {
                            matter_id: matter,
                            currency,
                            recipient,
                            client_ids: granted,
                            time_entry_ids: chosen
                                .filter((r) => r.source === "time")
                                .map((r) => r.id),
                            expense_ids: chosen
                                .filter((r) => r.source === "expense")
                                .map((r) => r.id),
                            idempotency_key: idempotency,
                        });
                        await onCreated();
                    } catch (e) {
                        setError((e as Error).message);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <div className="dialog-body form-stack">
                    <Alert>{error}</Alert>
                    <div className="form-grid">
                        <Field label="Matter *">
                            <select
                                required
                                value={matter}
                                onChange={(e) => setMatter(e.target.value)}
                            >
                                <option value="">Choose matter</option>
                                {matters.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.title}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Currency">
                            <select
                                value={currency}
                                onChange={(e) => {
                                    setCurrency(e.target.value);
                                    setSelected([]);
                                }}
                            >
                                {currencies.map((c) => (
                                    <option key={c}>{c}</option>
                                ))}
                            </select>
                        </Field>
                    </div>
                    {matter && (
                        <div className="billing-choices">
                            {eligible.length ? (
                                eligible.map((r) => (
                                    <label
                                        className="billing-choice"
                                        key={r.id}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(r.id)}
                                            onChange={(e) =>
                                                setSelected(
                                                    e.target.checked
                                                        ? [...selected, r.id]
                                                        : selected.filter(
                                                              (id) =>
                                                                  id !== r.id,
                                                          ),
                                                )
                                            }
                                        />
                                        {r.source === "time" ? (
                                            <Clock3 size={16} />
                                        ) : (
                                            <ReceiptText size={16} />
                                        )}
                                        <span>
                                            {r.billing_description}
                                            <small>
                                                {dateLabel(r.work_date)}
                                            </small>
                                        </span>
                                        <strong>
                                            {money(r.amount_minor, r.currency)}
                                        </strong>
                                    </label>
                                ))
                            ) : (
                                <p className="subtle">
                                    No approved, billable entries in {currency}{" "}
                                    for this matter.
                                </p>
                            )}
                        </div>
                    )}
                    <div className="form-grid">
                        <Field label="Recipient name *">
                            <input
                                required
                                value={recipient.name}
                                onChange={(e) =>
                                    setRecipient({
                                        ...recipient,
                                        name: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field label="Recipient email">
                            <input
                                type="email"
                                value={recipient.email}
                                onChange={(e) =>
                                    setRecipient({
                                        ...recipient,
                                        email: e.target.value,
                                    })
                                }
                            />
                        </Field>
                    </div>
                    <Field label="Billing address">
                        <textarea
                            value={recipient.address}
                            onChange={(e) =>
                                setRecipient({
                                    ...recipient,
                                    address: e.target.value,
                                })
                            }
                        />
                    </Field>
                    {clients.length > 0 && (
                        <fieldset className="sharing-fieldset">
                            <legend>
                                Share invoice in these client portals
                            </legend>
                            {clients.map((person) => (
                                <label className="check-label" key={person.id}>
                                    <input
                                        type="checkbox"
                                        checked={granted.includes(person.id)}
                                        onChange={(e) =>
                                            setGranted(
                                                e.target.checked
                                                    ? [...granted, person.id]
                                                    : granted.filter(
                                                          (id) =>
                                                              id !== person.id,
                                                      ),
                                            )
                                        }
                                    />
                                    {person.name} · {person.email}
                                </label>
                            ))}
                        </fieldset>
                    )}
                </div>
                <div className="dialog-footer">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button disabled={busy || !selected.length}>
                        {busy ? "Creating…" : "Create draft invoice"}
                    </Button>
                </div>
            </form>
        </DetailPage>
    );
}

export function RecurringInvoices({ invoices }: { invoices: RecordData[] }) {
    const [records, setRecords] = useState<RecordData[]>([]);
    const [form, setForm] = usePageState<RecordData | "new" | null>(
        "recurring-edit",
        null,
        {
            records,
            load: async (id) =>
                (await api(`/api/v1/recurring-invoices/${id}`)).data,
        },
    );
    const [selected, setSelected] = usePageState<RecordData | null>(
        "recurring-schedule",
        null,
        {
            records,
            load: async (id) =>
                (await api(`/api/v1/recurring-invoices/${id}`)).data,
        },
    );
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    async function load() {
        const result = await api("/api/v1/recurring-invoices");
        setRecords(result.data);
    }
    useEffect(() => {
        load().catch((e) => setError(e.message));
    }, []);
    async function transition(action: string) {
        if (!selected) return;
        setBusy(true);
        setError("");
        try {
            const result = await post(
                `/api/v1/recurring-invoices/${selected.id}/${action}`,
            );
            setSelected(result.data);
            await load();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    const name = (r: RecordData) =>
        invoices.find((i) => i.id === r.invoice_id)?.recipient?.name ||
        r.approved_template?.recipient?.name ||
        "Invoice template";
    return (
        <>
            <Alert>{error}</Alert>
            <div className="module-toolbar">
                <p className="subtle">
                    Approved schedules prepare draft invoices. Review and issue
                    each invoice before collecting payment.
                </p>
                <AddButton onClick={() => setForm("new")}>
                    New schedule
                </AddButton>
            </div>
            <DataTable
                records={records}
                onOpen={setSelected}
                columns={[
                    { key: "invoice_id", label: "Template", render: name },
                    { key: "cadence", label: "Frequency" },
                    {
                        key: "next_run_at",
                        label: "Next draft",
                        render: (r) => dateLabel(r.next_run_at),
                    },
                    { key: "generation_count", label: "Drafts created" },
                    {
                        key: "status",
                        label: "Status",
                        render: (r) => (
                            <div className="button-row">
                                <Badge>{r.status}</Badge>
                                {r.status !== "completed" && (
                                    <Button
                                        variant="ghost"
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            setSelected(r);
                                        }}
                                    >
                                        {r.status === "active"
                                            ? "Manage schedule"
                                            : "Review schedule"}
                                    </Button>
                                )}
                            </div>
                        ),
                    },
                ]}
                emptyTitle="No recurring invoice schedules"
            />
            {form && (
                <RecurringForm
                    initial={typeof form === "string" ? undefined : form}
                    invoices={invoices}
                    onClose={() => setForm(null)}
                    onSave={async (body) => {
                        const result =
                            typeof form === "string"
                                ? await post("/api/v1/recurring-invoices", body)
                                : await patch(
                                      `/api/v1/recurring-invoices/${form.id}`,
                                      body,
                                  );
                        await load();
                        setForm(null);
                        setSelected(result.data);
                    }}
                />
            )}
            {selected && !form && (
                <DetailPage
                    open
                    title={name(selected)}
                    description="Approval freezes the invoice lines used by future scheduled drafts."
                    onClose={() => setSelected(null)}
                >
                    <div className="dialog-body form-stack">
                        <Alert>{error}</Alert>
                        <Badge>{selected.status}</Badge>
                        <p>
                            {selected.cadence} · Next draft:{" "}
                            {dateLabel(selected.next_run_at)} ·{" "}
                            {selected.timezone}
                        </p>
                        {selected.pause_reason && (
                            <Alert kind="info">{selected.pause_reason}</Alert>
                        )}
                        <p>
                            {selected.generation_count || 0} draft invoices
                            prepared.
                        </p>
                        <small className="subtle">
                            Changing the original invoice will not change an
                            approved schedule. Pause and reapprove the schedule
                            to adopt an updated template.
                        </small>
                    </div>
                    <div className="dialog-footer">
                        <Button
                            variant="secondary"
                            onClick={() => setSelected(null)}
                        >
                            Close
                        </Button>
                        <div className="button-row">
                            {["draft", "paused"].includes(selected.status) && (
                                <>
                                    <Button
                                        variant="secondary"
                                        onClick={() => setForm(selected)}
                                    >
                                        Edit schedule
                                    </Button>
                                    <Button
                                        disabled={busy}
                                        onClick={() => transition("approve")}
                                    >
                                        Approve schedule
                                    </Button>
                                </>
                            )}
                            {selected.status === "active" && (
                                <Button
                                    disabled={busy}
                                    variant="secondary"
                                    onClick={() => transition("pause")}
                                >
                                    Pause schedule
                                </Button>
                            )}
                        </div>
                    </div>
                </DetailPage>
            )}
        </>
    );
}
function RecurringForm({
    initial,
    invoices,
    onClose,
    onSave,
}: {
    initial?: RecordData;
    invoices: RecordData[];
    onClose: () => void;
    onSave: (body: any) => Promise<void>;
}) {
    const [value, setValue] = useState({
        invoice_id: initial?.invoice_id || "",
        cadence: initial?.cadence || "monthly",
        next_run_at: localDateTime(initial?.next_run_at),
        end_at: localDateTime(initial?.end_at),
        timezone:
            initial?.timezone ||
            Intl.DateTimeFormat().resolvedOptions().timeZone,
    });
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    return (
        <DetailPage
            open
            title={
                initial ? "Edit recurring schedule" : "New recurring schedule"
            }
            description="Choose an invoice to use as the template. Review approval is required before scheduling starts."
            onClose={onClose}
        >
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError("");
                    try {
                        await onSave({
                            ...value,
                            ...(initial ? { version: initial.version } : {}),
                            next_run_at: new Date(
                                value.next_run_at,
                            ).toISOString(),
                            end_at: value.end_at
                                ? new Date(value.end_at).toISOString()
                                : null,
                        });
                    } catch (e) {
                        setError((e as Error).message);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <div className="dialog-body form-stack">
                    <Alert>{error}</Alert>
                    <Field label="Invoice template *">
                        <select
                            required
                            value={value.invoice_id}
                            onChange={(e) =>
                                setValue({
                                    ...value,
                                    invoice_id: e.target.value,
                                })
                            }
                        >
                            <option value="">Choose invoice</option>
                            {invoices.map((i) => (
                                <option key={i.id} value={i.id}>
                                    {i.number || "Draft"} · {i.recipient?.name}{" "}
                                    · {money(i.total_minor, i.currency)}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <div className="form-grid">
                        <Field label="Frequency">
                            <select
                                value={value.cadence}
                                onChange={(e) =>
                                    setValue({
                                        ...value,
                                        cadence: e.target.value,
                                    })
                                }
                            >
                                {[
                                    "weekly",
                                    "monthly",
                                    "quarterly",
                                    "yearly",
                                ].map((v) => (
                                    <option key={v}>{v}</option>
                                ))}
                            </select>
                        </Field>
                        <Field
                            label="Schedule timezone"
                            hint="IANA timezone used to preserve the schedule’s local time."
                        >
                            <input
                                required
                                value={value.timezone}
                                onChange={(e) =>
                                    setValue({
                                        ...value,
                                        timezone: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field
                            label="First draft *"
                            hint="Enter the time in your browser’s local timezone."
                        >
                            <input
                                required
                                type="datetime-local"
                                value={value.next_run_at}
                                onChange={(e) =>
                                    setValue({
                                        ...value,
                                        next_run_at: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field
                            label="End date and time"
                            hint="Optional, in your browser’s local timezone."
                        >
                            <input
                                type="datetime-local"
                                value={value.end_at}
                                onChange={(e) =>
                                    setValue({
                                        ...value,
                                        end_at: e.target.value,
                                    })
                                }
                            />
                        </Field>
                    </div>
                </div>
                <div className="dialog-footer">
                    <Button variant="secondary" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button disabled={busy}>
                        {busy ? "Saving…" : "Save for approval"}
                    </Button>
                </div>
            </form>
        </DetailPage>
    );
}
