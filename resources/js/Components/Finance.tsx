// Author: ramanpal singh | URL: https://kwebby.com
import { EmployeeInputs } from "./EmployeeInputs";
import { usePageState } from "../lib/navigation";
import { useEffect, useState } from "react";
import { BillingWork, RecurringInvoices } from "./Billing";
import {
    Download,
    Plus,
    ReceiptText,
    Trash2,
    CheckCircle2,
    Settings2,
} from "lucide-react";
import type { RecordData } from "../lib/types";
import { api, dateLabel, money, post, patch, toMinor } from "../lib/api";
import {
    AddButton,
    Alert,
    Badge,
    Button,
    DataTable,
    Field,
    DetailPage,
    SectionTitle,
} from "./ui";
export function Invoices({
    records,
    reload,
    client = false,
}: {
    records: RecordData[];
    reload: () => Promise<void>;
    client?: boolean;
}) {
    const [tab, setTab] = usePageState("finance-tab", "invoices", {
        page: false,
    });
    if (client) return <InvoiceList records={records} reload={reload} client />;
    return (
        <>
            <div className="tabs" aria-label="Finance sections">
                {[
                    ["invoices", "Invoices"],
                    ["time-entries", "Time entries"],
                    ["expenses", "Expenses"],
                    ["recurring", "Recurring schedules"],
                ].map(([id, label]) => (
                    <button
                        key={id}
                        className={tab === id ? "active" : ""}
                        aria-pressed={tab === id}
                        onClick={() => setTab(id)}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {tab === "invoices" ? (
                <InvoiceList records={records} reload={reload} />
            ) : tab === "recurring" ? (
                <RecurringInvoices invoices={records} />
            ) : (
                <BillingWork
                    key={tab}
                    kind={tab as "time-entries" | "expenses"}
                    onInvoice={async () => {
                        await reload();
                        setTab("invoices");
                    }}
                />
            )}
        </>
    );
}
function InvoiceList({
    records,
    reload,
    client = false,
}: {
    records: RecordData[];
    reload: () => Promise<void>;
    client?: boolean;
}) {
    const [create, setCreate] = usePageState("new-invoice", false);
    const [selected, setSelected] = usePageState<RecordData | null>(
        "invoice",
        null,
        {
            records,
            load: async (id) => (await api(`/api/v1/invoices/${id}`)).data,
        },
    );
    const [error, setError] = useState("");
    const [designer, setDesigner] = usePageState("invoice-designer", false);
    return (
        <>
            <Alert>{error}</Alert>
            <div className="finance-summary">
                <div>
                    <ReceiptText size={22} />
                    <span>{records.length} invoices</span>
                </div>
                <div>
                    <span>Draft</span>
                    <strong>
                        {records.filter((r) => r.status === "draft").length}
                    </strong>
                </div>
                <div>
                    <span>Awaiting payment</span>
                    <strong>
                        {
                            records.filter((r) =>
                                [
                                    "issued",
                                    "partial",
                                    "part_paid",
                                    "partially_paid",
                                    "overdue",
                                ].includes(r.status),
                            ).length
                        }
                    </strong>
                </div>
                <div>
                    <span>Paid</span>
                    <strong>
                        {records.filter((r) => r.status === "paid").length}
                    </strong>
                </div>
            </div>
            <div className="module-toolbar">
                <p className="subtle">
                    Issued invoices preserve the details and template used.
                </p>
                {!client && (
                    <div className="button-row">
                        <Button
                            variant="secondary"
                            onClick={() => setDesigner(true)}
                        >
                            <Settings2 size={15} />
                            Invoice designer
                        </Button>
                        <AddButton onClick={() => setCreate(true)}>
                            New invoice
                        </AddButton>
                    </div>
                )}
            </div>
            <DataTable
                records={records}
                onOpen={setSelected}
                columns={[
                    {
                        key: "number",
                        label: "Invoice",
                        render: (r) => (
                            <span>
                                {r.number ||
                                    r.invoice_number ||
                                    "Draft invoice"}
                                <small className="cell-sub">
                                    {r.recipient?.name}
                                </small>
                            </span>
                        ),
                    },
                    {
                        key: "status",
                        label: "Status",
                        render: (r) => (
                            <div className="button-row">
                                <Badge>{r.status}</Badge>
                                {!client && r.status === "draft" && (
                                    <Button
                                        variant="ghost"
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            setSelected(r);
                                        }}
                                    >
                                        Review &amp; issue
                                    </Button>
                                )}
                            </div>
                        ),
                    },
                    {
                        key: "total_minor",
                        label: "Amount",
                        render: (r) =>
                            money(
                                r.total_minor || r.totals?.total_minor,
                                r.currency,
                            ),
                    },
                    {
                        key: "paid_minor",
                        label: "Paid",
                        render: (r) => money(r.paid_minor || "0", r.currency),
                    },
                    {
                        key: "created_at",
                        label: "Created",
                        render: (r) => dateLabel(r.created_at),
                    },
                ]}
                emptyTitle="Create your first invoice"
                emptyAction={
                    !client && (
                        <AddButton onClick={() => setCreate(true)}>
                            New invoice
                        </AddButton>
                    )
                }
            />
            {create && (
                <InvoiceForm
                    onClose={() => setCreate(false)}
                    onSave={async (body) => {
                        const result = await post("/api/v1/invoices", body);
                        await reload();
                        setCreate(false);
                        setSelected(result.data);
                    }}
                />
            )}
            {selected && (
                <InvoiceDetails
                    invoice={selected}
                    client={client}
                    onClose={() => setSelected(null)}
                    reload={async () => {
                        await reload();
                        const list = await api("/api/v1/invoices");
                        setSelected(
                            list.data.find(
                                (i: RecordData) => i.id === selected.id,
                            ) || null,
                        );
                    }}
                />
            )}
            {designer && <InvoiceDesigner onClose={() => setDesigner(false)} />}
        </>
    );
}
function InvoiceForm({
    onClose,
    onSave,
}: {
    onClose: () => void;
    onSave: (body: any) => Promise<void>;
}) {
    const [recipient, setRecipient] = useState({
        name: "",
        email: "",
        address: "",
    });
    const [currency, setCurrency] = useState("USD");
    const [due, setDue] = useState("");
    const [items, setItems] = useState([
        { description: "", quantity: "1", price: "", tax: "0" },
    ]);
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const [notes, setNotes] = useState("");
    const [matter, setMatter] = useState("");
    return (
        <DetailPage
            open
            wide
            title="New invoice"
            description="Save a draft, check the details, then issue the invoice."
            onClose={onClose}
        >
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError("");
                    try {
                        await onSave({
                            recipient,
                            currency,
                            due_at: due || null,
                            matter_id: matter || null,
                            items: items.map((i) => ({
                                description: i.description,
                                quantity: i.quantity,
                                unit_minor: toMinor(i.price, currency),
                                tax_bps: Math.round(Number(i.tax) * 100),
                            })),
                            discount_minor: "0",
                            notes,
                            client_ids: [],
                        });
                    } catch (e) {
                        setError((e as Error).message);
                        setBusy(false);
                    }
                }}
            >
                <div className="dialog-body">
                    <Alert>{error}</Alert>
                    <div className="form-grid">
                        <Field label="Client / business name *">
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
                        <Field label="Email">
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
                        <Field label="Billing address">
                            <textarea
                                rows={2}
                                value={recipient.address}
                                onChange={(e) =>
                                    setRecipient({
                                        ...recipient,
                                        address: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <div className="form-grid">
                            <Field label="Currency">
                                <select
                                    value={currency}
                                    onChange={(e) =>
                                        setCurrency(e.target.value)
                                    }
                                >
                                    {[
                                        "USD",
                                        "INR",
                                        "GBP",
                                        "EUR",
                                        "CAD",
                                        "AUD",
                                        "AED",
                                        "JPY",
                                    ].map((c) => (
                                        <option key={c}>{c}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Due date">
                                <input
                                    type="date"
                                    value={due}
                                    onChange={(e) => setDue(e.target.value)}
                                />
                            </Field>
                        </div>
                        <Field label="Matter ID (optional)">
                            <input
                                value={matter}
                                onChange={(e) => setMatter(e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="line-items">
                        <div className="line-item-head">
                            <span>Description</span>
                            <span>Quantity</span>
                            <span>Rate ({currency})</span>
                            <span>Tax %</span>
                            <span />
                        </div>
                        {items.map((item, index) => (
                            <div className="line-item" key={index}>
                                <input
                                    required
                                    aria-label={`Item ${index + 1} description`}
                                    value={item.description}
                                    onChange={(e) =>
                                        setItems(
                                            items.map((v, i) =>
                                                i === index
                                                    ? {
                                                          ...v,
                                                          description:
                                                              e.target.value,
                                                      }
                                                    : v,
                                            ),
                                        )
                                    }
                                />
                                <input
                                    required
                                    aria-label={`Item ${index + 1} quantity`}
                                    inputMode="decimal"
                                    value={item.quantity}
                                    onChange={(e) =>
                                        setItems(
                                            items.map((v, i) =>
                                                i === index
                                                    ? {
                                                          ...v,
                                                          quantity:
                                                              e.target.value,
                                                      }
                                                    : v,
                                            ),
                                        )
                                    }
                                />
                                <input
                                    required
                                    aria-label={`Item ${index + 1} rate`}
                                    inputMode="decimal"
                                    value={item.price}
                                    onChange={(e) =>
                                        setItems(
                                            items.map((v, i) =>
                                                i === index
                                                    ? {
                                                          ...v,
                                                          price: e.target.value,
                                                      }
                                                    : v,
                                            ),
                                        )
                                    }
                                />
                                <input
                                    aria-label={`Item ${index + 1} tax percent`}
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value={item.tax}
                                    onChange={(e) =>
                                        setItems(
                                            items.map((v, i) =>
                                                i === index
                                                    ? {
                                                          ...v,
                                                          tax: e.target.value,
                                                      }
                                                    : v,
                                            ),
                                        )
                                    }
                                />
                                <button
                                    type="button"
                                    className="icon-button"
                                    disabled={items.length === 1}
                                    aria-label="Remove line item"
                                    onClick={() =>
                                        setItems(
                                            items.filter((_, i) => i !== index),
                                        )
                                    }
                                >
                                    <Trash2 size={16} />
                                </button>
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                setItems([
                                    ...items,
                                    {
                                        description: "",
                                        quantity: "1",
                                        price: "",
                                        tax: "0",
                                    },
                                ])
                            }
                        >
                            <Plus size={15} />
                            Add line item
                        </Button>
                    </div>
                    <Field label="Notes and payment terms">
                        <textarea
                            rows={3}
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                        />
                    </Field>
                </div>
                <div className="dialog-footer">
                    <Button variant="secondary" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button disabled={busy} type="submit">
                        {busy ? "Creating…" : "Create draft invoice"}
                    </Button>
                </div>
            </form>
        </DetailPage>
    );
}
function InvoiceDetails({
    invoice,
    onClose,
    reload,
    client,
}: {
    invoice: RecordData;
    onClose: () => void;
    reload: () => Promise<void>;
    client: boolean;
}) {
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const [payment, setPayment] = usePageState("record-payment", false, {
        page: false,
    });
    const [amount, setAmount] = useState("");
    const [method, setMethod] = useState("bank_transfer");
    const [reference, setReference] = useState("");
    const [key] = useState(crypto.randomUUID());
    async function action(path: string, body?: any) {
        setBusy(true);
        setError("");
        try {
            const result = await post(path, body);
            if (result.data?.url || result.data?.checkout_url) {
                window.location.assign(
                    result.data.url || result.data.checkout_url,
                );
                return;
            }
            await reload();
            setPayment(false);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <DetailPage
            open
            wide
            title={invoice.number || invoice.invoice_number || "Draft invoice"}
            description={`Invoice for ${invoice.recipient?.name || "client"}`}
            onClose={onClose}
        >
            <div className="dialog-body">
                <Alert>{error}</Alert>
                <div className="invoice-detail-top">
                    <div>
                        <Badge>{invoice.status}</Badge>
                        <h3>{invoice.recipient?.name}</h3>
                        <p>{invoice.recipient?.email}</p>
                        <p>{invoice.recipient?.address}</p>
                    </div>
                    <div className="invoice-amount">
                        <small>Total amount</small>
                        <strong>
                            {money(
                                invoice.total_minor ||
                                    invoice.totals?.total_minor,
                                invoice.currency,
                            )}
                        </strong>
                        <span>
                            {money(invoice.paid_minor || "0", invoice.currency)}{" "}
                            paid
                        </span>
                    </div>
                </div>
                <div className="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Quantity</th>
                                <th>Rate</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(invoice.items || []).map(
                                (item: any, i: number) => (
                                    <tr key={i}>
                                        <td>{item.description}</td>
                                        <td>{item.quantity}</td>
                                        <td>
                                            {money(
                                                item.unit_minor,
                                                invoice.currency,
                                            )}
                                        </td>
                                        <td>
                                            {money(
                                                item.total_minor ||
                                                    item.line_total_minor ||
                                                    item.subtotal_minor,
                                                invoice.currency,
                                            )}
                                        </td>
                                    </tr>
                                ),
                            )}
                        </tbody>
                    </table>
                </div>
                {payment && (
                    <div className="payment-form form-stack">
                        <h3>Record a received payment</h3>
                        <div className="form-grid">
                            <Field label={`Amount (${invoice.currency})`}>
                                <input
                                    inputMode="decimal"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                />
                            </Field>
                            <Field label="Payment method">
                                <select
                                    value={method}
                                    onChange={(e) => setMethod(e.target.value)}
                                >
                                    <option value="bank_transfer">
                                        Bank transfer
                                    </option>
                                    <option value="cash">Cash</option>
                                </select>
                            </Field>
                        </div>
                        <Field label="Payment reference">
                            <input
                                value={reference}
                                onChange={(e) => setReference(e.target.value)}
                            />
                        </Field>
                        <Button
                            disabled={busy || !amount}
                            onClick={() => {
                                try {
                                    void action(
                                        `/api/v1/invoices/${invoice.id}/payments`,
                                        {
                                            amount_minor: toMinor(
                                                amount,
                                                invoice.currency,
                                            ),
                                            method,
                                            reference,
                                            idempotency_key: key,
                                        },
                                    );
                                } catch (e) {
                                    setError((e as Error).message);
                                }
                            }}
                        >
                            Confirm received payment
                        </Button>
                    </div>
                )}
            </div>
            <div className="dialog-footer">
                <a
                    className="button button-secondary"
                    href={`/api/v1/invoices/${invoice.id}/pdf`}
                    target="_blank"
                    rel="noreferrer"
                >
                    <Download size={15} />
                    PDF invoice
                </a>
                <div className="button-row">
                    {invoice.status === "draft" && !client && (
                        <Button
                            disabled={busy}
                            onClick={() =>
                                action(`/api/v1/invoices/${invoice.id}/issue`)
                            }
                        >
                            Issue invoice
                        </Button>
                    )}
                    {[
                        "issued",
                        "partial",
                        "part_paid",
                        "partially_paid",
                        "overdue",
                    ].includes(invoice.status) &&
                        (!client ? (
                            <Button
                                variant="secondary"
                                onClick={() => setPayment(!payment)}
                            >
                                Record payment
                            </Button>
                        ) : (
                            <>
                                <Button
                                    disabled={busy}
                                    onClick={() =>
                                        action(
                                            `/api/v1/invoices/${invoice.id}/checkout`,
                                            {
                                                provider: "stripe",
                                                idempotency_key: key,
                                            },
                                        )
                                    }
                                >
                                    Pay with Stripe
                                </Button>
                                <Button
                                    variant="secondary"
                                    disabled={busy}
                                    onClick={() =>
                                        action(
                                            `/api/v1/invoices/${invoice.id}/checkout`,
                                            {
                                                provider: "paypal",
                                                idempotency_key: key,
                                            },
                                        )
                                    }
                                >
                                    Pay with PayPal
                                </Button>
                            </>
                        ))}
                </div>
            </div>
        </DetailPage>
    );
}
function InvoiceDesigner({ onClose }: { onClose: () => void }) {
    const [value, setValue] = useState<any>({
        template: "classic",
        paper: "A4",
        accent: "#16736c",
        font: "dejavusans",
        margin_mm: 15,
        notes: "Thank you for trusting us with your legal work.",
    });
    const [business, setBusiness] = useState<any>({});
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState("");
    useEffect(() => {
        Promise.all([api("/api/v1/settings"), api("/api/v1/invoice-design")])
            .then(([r, design]) => {
                setBusiness(r.data?.business || r.business || {});
                setValue({ ...value, ...design.data });
            })
            .catch((e) => setError(e.message));
    }, []);
    return (
        <DetailPage
            open
            wide
            title="Invoice designer"
            description="Issued invoices keep their original layout and business details."
            onClose={onClose}
        >
            <div className="dialog-body">
                <Alert>{error}</Alert>
                <Alert kind="success">{saved}</Alert>
                <div className="designer-grid">
                    <div className="form-stack">
                        <Field label="Template">
                            <select
                                value={value.template}
                                onChange={(e) =>
                                    setValue({
                                        ...value,
                                        template: e.target.value,
                                    })
                                }
                            >
                                <option value="classic">
                                    Classic letterhead
                                </option>
                                <option value="modern">Modern statement</option>
                                <option value="compact">Compact</option>
                            </select>
                        </Field>
                        <Field label="Paper size">
                            <select
                                value={value.paper}
                                onChange={(e) =>
                                    setValue({
                                        ...value,
                                        paper: e.target.value,
                                    })
                                }
                            >
                                <option>A4</option>
                                <option>Letter</option>
                            </select>
                        </Field>
                        <Field label="Accent color">
                            <input
                                type="color"
                                value={value.accent}
                                onChange={(e) =>
                                    setValue({
                                        ...value,
                                        accent: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field label="Default invoice note">
                            <textarea
                                value={value.notes}
                                onChange={(e) =>
                                    setValue({
                                        ...value,
                                        notes: e.target.value,
                                    })
                                }
                                rows={3}
                            />
                        </Field>
                        <p className="subtle">
                            Set the legal name, address, tax IDs, bank details
                            and payment terms in Practice settings → Business.
                        </p>
                    </div>
                    <div
                        className={`invoice-preview invoice-preview-${value.template}`}
                        style={{ "--invoice-accent": value.accent } as any}
                    >
                        <div>
                            <strong>
                                {business.legal_name ||
                                    business.name ||
                                    "Your practice"}
                            </strong>
                            <span>Invoice</span>
                        </div>
                        <p>
                            {business.address || "Your business address"}
                            <br />
                            {business.tax_id || "Tax / registration details"}
                        </p>
                        <hr />
                        <small>Bill to</small>
                        <h4>Client name</h4>
                        <table>
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Professional services</td>
                                    <td>1,000.00</td>
                                </tr>
                                <tr>
                                    <td>Tax</td>
                                    <td>0.00</td>
                                </tr>
                            </tbody>
                        </table>
                        <h4 className="preview-total">Total 1,000.00</h4>
                        <p>{value.notes}</p>
                        <small>Design preview · sample values</small>
                    </div>
                </div>
            </div>
            <div className="dialog-footer">
                <a
                    className="button button-secondary"
                    href="/api/v1/invoice-design/preview"
                    target="_blank"
                    rel="noreferrer"
                >
                    Preview saved PDF
                </a>
                <Button
                    disabled={busy}
                    onClick={async () => {
                        setBusy(true);
                        setError("");
                        try {
                            await patch("/api/v1/invoice-design", value);
                            setSaved("Invoice template saved.");
                        } catch (e) {
                            setError((e as Error).message);
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    {busy ? "Saving…" : "Save invoice template"}
                </Button>
            </div>
        </DetailPage>
    );
}
export function Payroll({
    records,
    reload,
    canManage = true,
}: {
    records: RecordData[];
    reload: () => Promise<void>;
    canManage?: boolean;
}) {
    const [create, setCreate] = usePageState("new-salary-run", false);
    const [selected, setSelected] = usePageState<RecordData | null>(
        "salary-run",
        null,
        { records },
    );
    const [error, setError] = useState("");
    const [slips, setSlips] = useState<RecordData[]>([]);
    const [tab, setTab] = usePageState(
        "payroll-tab",
        canManage ? "runs" : "payslips",
        { page: false },
    );
    useEffect(() => {
        api("/api/v1/payslips")
            .then((r) => setSlips(r.data))
            .catch((e) => setError(e.message));
    }, [records]);
    async function transition(run: RecordData, action: string) {
        try {
            await post(`/api/v1/payroll-runs/${run.id}/${action}`);
            await reload();
            setSelected(null);
        } catch (e) {
            setError((e as Error).message);
        }
    }
    return (
        <>
            <Alert>{error}</Alert>
            <EmployeeInputs canManage={canManage} onGenerated={reload} />
            <div className="module-toolbar">
                <div className="segmented">
                    {canManage && (
                        <button
                            className={tab === "runs" ? "selected" : ""}
                            onClick={() => setTab("runs")}
                        >
                            Salary runs
                        </button>
                    )}
                    <button
                        className={tab === "payslips" ? "selected" : ""}
                        onClick={() => setTab("payslips")}
                    >
                        Payslips
                    </button>
                </div>
                {canManage && (
                    <AddButton onClick={() => setCreate(true)}>
                        New salary run
                    </AddButton>
                )}
            </div>
            {tab === "runs" ? (
                <DataTable
                    records={records}
                    onOpen={setSelected}
                    columns={[
                        { key: "period", label: "Pay period" },
                        {
                            key: "status",
                            label: "Status",
                            render: (r) => (
                                <div className="button-row">
                                    <Badge>{r.status}</Badge>
                                    {r.status !== "released" && (
                                        <Button
                                            variant="ghost"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                setSelected(r);
                                            }}
                                        >
                                            Review run
                                        </Button>
                                    )}
                                </div>
                            ),
                        },
                        {
                            key: "employees",
                            label: "Employees",
                            render: (r) => (r.employees || []).length,
                        },
                        {
                            key: "net_minor",
                            label: "Net salary",
                            render: (r) =>
                                money(
                                    r.total_minor ||
                                        r.net_minor ||
                                        r.total_net_minor,
                                    r.currency,
                                ),
                        },
                        {
                            key: "created_at",
                            label: "Created",
                            render: (r) => dateLabel(r.created_at),
                        },
                    ]}
                    emptyTitle="Prepare your first salary run"
                />
            ) : (
                <DataTable
                    records={slips}
                    columns={[
                        {
                            key: "employee_name",
                            label: "Employee",
                            render: (r) => r.employee_name || r.name,
                        },
                        { key: "period", label: "Period" },
                        {
                            key: "net_minor",
                            label: "Net salary",
                            render: (r) => money(r.net_minor, r.currency),
                        },
                        {
                            key: "download",
                            label: "Payslip",
                            render: (r) => (
                                <a
                                    className="text-link"
                                    href={`/api/v1/payslips/${r.id}/pdf`}
                                >
                                    <Download size={15} />
                                    Download PDF
                                </a>
                            ),
                        },
                    ]}
                    emptyTitle="Payslips appear after a salary run is released"
                />
            )}
            {create && (
                <PayrollForm
                    onClose={() => setCreate(false)}
                    onSave={async (body) => {
                        const result = await post("/api/v1/payroll-runs", body);
                        await reload();
                        setCreate(false);
                        setSelected(result.data);
                    }}
                />
            )}
            {selected && (
                <DetailPage
                    open
                    wide
                    title={`Salary run · ${selected.period}`}
                    description="Review earnings and deductions before approving release."
                    onClose={() => setSelected(null)}
                >
                    <div className="dialog-body">
                        <Alert>{error}</Alert>
                        <Badge>{selected.status}</Badge>
                        <DataTable
                            records={(selected.employees || []).map(
                                (r: any, index: number) => ({
                                    ...r,
                                    id: r.employee_id || String(index),
                                }),
                            )}
                            columns={[
                                { key: "name", label: "Employee" },
                                {
                                    key: "earnings",
                                    label: "Earnings",
                                    render: (r) =>
                                        money(
                                            (r.earnings || [])
                                                .reduce(
                                                    (sum: bigint, i: any) =>
                                                        sum +
                                                        BigInt(i.amount_minor),
                                                    0n,
                                                )
                                                .toString(),
                                            selected.currency,
                                        ),
                                },
                                {
                                    key: "deductions",
                                    label: "Deductions",
                                    render: (r) =>
                                        money(
                                            (r.deductions || [])
                                                .reduce(
                                                    (sum: bigint, i: any) =>
                                                        sum +
                                                        BigInt(i.amount_minor),
                                                    0n,
                                                )
                                                .toString(),
                                            selected.currency,
                                        ),
                                },
                            ]}
                        />
                    </div>
                    <div className="dialog-footer">
                        <Button
                            variant="secondary"
                            onClick={() => setSelected(null)}
                        >
                            Close
                        </Button>
                        {selected.status !== "released" && (
                            <Button
                                onClick={() =>
                                    transition(
                                        selected,
                                        selected.status === "draft"
                                            ? "review"
                                            : selected.status === "reviewed"
                                              ? "approve"
                                              : "release",
                                    )
                                }
                            >
                                {selected.status === "draft"
                                    ? "Mark reviewed"
                                    : selected.status === "reviewed"
                                      ? "Approve salary run"
                                      : "Release payslips"}
                            </Button>
                        )}
                    </div>
                </DetailPage>
            )}
        </>
    );
}
function PayrollForm({
    onClose,
    onSave,
}: {
    onClose: () => void;
    onSave: (body: any) => Promise<void>;
}) {
    const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
    const [currency, setCurrency] = useState("USD");
    const [employees, setEmployees] = useState([
        { employee_id: "", name: "", salary: "", deduction: "0" },
    ]);
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    return (
        <DetailPage
            open
            wide
            title="Prepare a salary run"
            description="Add approved earnings and deductions. Release follows review and approval."
            onClose={onClose}
        >
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    try {
                        await onSave({
                            period,
                            currency,
                            employees: employees.map((person) => ({
                                employee_id: person.employee_id,
                                name: person.name,
                                earnings: [
                                    {
                                        label: "Base salary",
                                        amount_minor: toMinor(
                                            person.salary,
                                            currency,
                                        ),
                                    },
                                ],
                                deductions: [
                                    {
                                        label: "Approved deductions",
                                        amount_minor: toMinor(
                                            person.deduction || "0",
                                            currency,
                                        ),
                                    },
                                ],
                            })),
                        });
                    } catch (e) {
                        setError((e as Error).message);
                        setBusy(false);
                    }
                }}
            >
                <div className="dialog-body">
                    <Alert>{error}</Alert>
                    <div className="form-grid">
                        <Field label="Pay period">
                            <input
                                type="month"
                                required
                                value={period}
                                onChange={(e) => setPeriod(e.target.value)}
                            />
                        </Field>
                        <Field label="Currency">
                            <select
                                value={currency}
                                onChange={(e) => setCurrency(e.target.value)}
                            >
                                {[
                                    "USD",
                                    "INR",
                                    "GBP",
                                    "EUR",
                                    "CAD",
                                    "AUD",
                                    "AED",
                                ].map((c) => (
                                    <option key={c}>{c}</option>
                                ))}
                            </select>
                        </Field>
                    </div>
                    {employees.map((person, index) => (
                        <div className="employee-row form-grid" key={index}>
                            {[
                                ["employee_id", "Employee ID"],
                                ["name", "Employee name"],
                                ["salary", `Gross earnings (${currency})`],
                                ["deduction", `Deductions (${currency})`],
                            ].map(([key, label]) => (
                                <Field key={key} label={label}>
                                    <input
                                        required
                                        value={(person as any)[key]}
                                        onChange={(e) =>
                                            setEmployees(
                                                employees.map((p, i) =>
                                                    i === index
                                                        ? {
                                                              ...p,
                                                              [key]: e.target
                                                                  .value,
                                                          }
                                                        : p,
                                                ),
                                            )
                                        }
                                    />
                                </Field>
                            ))}
                        </div>
                    ))}
                    <Button
                        variant="secondary"
                        type="button"
                        onClick={() =>
                            setEmployees([
                                ...employees,
                                {
                                    employee_id: "",
                                    name: "",
                                    salary: "",
                                    deduction: "0",
                                },
                            ])
                        }
                    >
                        <Plus size={15} />
                        Add employee
                    </Button>
                </div>
                <div className="dialog-footer">
                    <Button variant="secondary" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button disabled={busy} type="submit">
                        {busy ? "Saving…" : "Create salary draft"}
                    </Button>
                </div>
            </form>
        </DetailPage>
    );
}
