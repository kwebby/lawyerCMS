// Author: ramanpal singh | URL: https://kwebby.com
import { usePageState } from "../lib/navigation";
import { useEffect, useState } from "react";
import { api, post, toMinor } from "../lib/api";
import type { RecordData } from "../lib/types";
import { Alert, Badge, Button, DataTable, Field, DetailPage } from "./ui";
export function EmployeeInputs({
    canManage,
    onGenerated,
}: {
    canManage: boolean;
    onGenerated: () => Promise<void>;
}) {
    const [kind, setKind] = usePageState("employee-input-kind", "leave", {
        page: false,
    });
    const [records, setRecords] = useState<RecordData[]>([]);
    const [people, setPeople] = useState<RecordData[]>([]);
    const [error, setError] = useState("");
    const [create, setCreate] = usePageState("new-employee-input", false);
    const [generate, setGenerate] = usePageState("payroll-from-inputs", false);
    const [busy, setBusy] = useState(false);
    const [selected, setSelected] = usePageState<RecordData | null>(
        "employee-input",
        null,
        { records },
    );
    const [notes, setNotes] = useState("");
    const [values, setValues] = useState<any>(() => ({
        kind: "annual",
        currency: "USD",
        deduction: "0",
        bonus: "0",
        allowance: "0",
        idempotency_key: crypto.randomUUID(),
    }));
    const load = async () =>
        setRecords((await api(`/api/v1/employee-inputs/${kind}`)).data);
    useEffect(() => {
        load().catch((e) => setError(e.message));
    }, [kind]);
    useEffect(() => {
        if (canManage)
            api("/api/v1/people")
                .then((r) => setPeople(r.data))
                .catch((e) => setError(e.message));
    }, [canManage]);
    const field = (
        key: string,
        label: string,
        type = "text",
        required = true,
    ) => (
        <Field label={label}>
            <input
                type={type}
                required={required}
                value={values[key] || ""}
                onChange={(e) =>
                    setValues({ ...values, [key]: e.target.value })
                }
            />
        </Field>
    );
    return (
        <section className="section-space">
            <Alert>{error}</Alert>
            <div className="module-toolbar">
                <div className="segmented">
                    {[
                        "leave",
                        "attendance",
                        ...(canManage ? ["compensation"] : []),
                    ].map((k) => (
                        <button
                            key={k}
                            className={kind === k ? "selected" : ""}
                            onClick={() => {
                                setKind(k);
                                setError("");
                            }}
                        >
                            {k.charAt(0).toUpperCase() + k.slice(1)}
                        </button>
                    ))}
                </div>
                <div className="button-row">
                    {canManage && (
                        <Button
                            variant="secondary"
                            onClick={() => {
                                setGenerate(true);
                                setValues({
                                    currency: "USD",
                                    idempotency_key: crypto.randomUUID(),
                                });
                            }}
                        >
                            Prepare payroll from inputs
                        </Button>
                    )}
                    <Button
                        onClick={() => {
                            setCreate(true);
                            setValues({
                                kind: "annual",
                                currency: "USD",
                                deduction: "0",
                                bonus: "0",
                                allowance: "0",
                            });
                        }}
                    >
                        Add {kind}
                    </Button>
                </div>
            </div>
            <p className="subtle">
                Approved inputs are preserved with the payroll draft. A reviewer
                checks adjustments before salary release.
            </p>
            <DataTable
                records={records}
                onOpen={(r) => {
                    setSelected(r);
                    setNotes("");
                }}
                columns={[
                    { key: "employee_name", label: "Employee" },
                    {
                        key: "period",
                        label: "Period",
                        render: (r) =>
                            r.period ||
                            `${r.from || r.effective_from} — ${r.to || r.effective_to || "ongoing"}`,
                    },
                    {
                        key: "status",
                        label: "Status",
                        render: (r) => (
                            <div className="button-row">
                                <Badge>{r.status}</Badge>
                                {canManage && r.status === "submitted" && (
                                    <Button
                                        variant="ghost"
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            setSelected(r);
                                            setNotes("");
                                        }}
                                    >
                                        Review input
                                    </Button>
                                )}
                            </div>
                        ),
                    },
                ]}
                emptyTitle={`No ${kind} records yet`}
            />
            {(create || generate) && (
                <DetailPage
                    open
                    title={
                        generate ? "Prepare salary draft" : `New ${kind} input`
                    }
                    onClose={() => {
                        setCreate(false);
                        setGenerate(false);
                    }}
                >
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError("");
                            try {
                                let payload: any = { ...values };
                                if (generate) {
                                    payload.employee_ids = (
                                        values.employee_ids || ""
                                    )
                                        .split(",")
                                        .map((x: string) => x.trim())
                                        .filter(Boolean);
                                    await post(
                                        "/api/v1/payroll-from-inputs",
                                        payload,
                                    );
                                    await onGenerated();
                                } else {
                                    if (kind === "compensation") {
                                        payload.earnings = [
                                            {
                                                label: "Base salary",
                                                amount_minor: toMinor(
                                                    values.salary,
                                                    values.currency,
                                                ),
                                            },
                                            ...["allowance", "bonus"]
                                                .filter(
                                                    (k) =>
                                                        Number(values[k]) > 0,
                                                )
                                                .map((k) => ({
                                                    label: k,
                                                    amount_minor: toMinor(
                                                        values[k],
                                                        values.currency,
                                                    ),
                                                })),
                                        ];
                                        payload.deductions =
                                            Number(values.deduction) > 0
                                                ? [
                                                      {
                                                          label: "Approved deduction",
                                                          amount_minor: toMinor(
                                                              values.deduction,
                                                              values.currency,
                                                          ),
                                                      },
                                                  ]
                                                : [];
                                    }
                                    if (kind === "attendance")
                                        payload.approved_leave_ids = (
                                            values.approved_leave_ids || ""
                                        )
                                            .split(",")
                                            .map((x: string) => x.trim())
                                            .filter(Boolean);
                                    if (!payload.employee_id)
                                        delete payload.employee_id;
                                    await post(
                                        `/api/v1/employee-inputs/${kind}`,
                                        payload,
                                    );
                                    await load();
                                }
                                setCreate(false);
                                setGenerate(false);
                            } catch (e) {
                                setError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <div className="dialog-body form-stack">
                            <Alert>{error}</Alert>
                            {!generate && canManage && (
                                <Field label="Employee">
                                    <select
                                        required
                                        value={values.employee_id || ""}
                                        onChange={(e) =>
                                            setValues({
                                                ...values,
                                                employee_id: e.target.value,
                                            })
                                        }
                                    >
                                        <option value="">
                                            Select employee
                                        </option>
                                        {people.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}
                            {generate ? (
                                <>
                                    {field("period", "Pay period", "month")}
                                    {field("currency", "Currency (ISO code)")}
                                    <Field label="Employees">
                                        <div className="form-stack">
                                            {people.map((p) => (
                                                <label key={p.id}>
                                                    <input
                                                        type="checkbox"
                                                        checked={(
                                                            values.employee_ids ||
                                                            ""
                                                        )
                                                            .split(",")
                                                            .includes(p.id)}
                                                        onChange={(e) => {
                                                            const ids = (
                                                                values.employee_ids ||
                                                                ""
                                                            )
                                                                .split(",")
                                                                .filter(
                                                                    Boolean,
                                                                );
                                                            setValues({
                                                                ...values,
                                                                employee_ids: (e
                                                                    .target
                                                                    .checked
                                                                    ? [
                                                                          ...ids,
                                                                          p.id,
                                                                      ]
                                                                    : ids.filter(
                                                                          (
                                                                              id: string,
                                                                          ) =>
                                                                              id !==
                                                                              p.id,
                                                                      )
                                                                ).join(","),
                                                            });
                                                        }}
                                                    />{" "}
                                                    {p.name}
                                                </label>
                                            ))}
                                        </div>
                                    </Field>
                                    <p className="subtle">
                                        Each employee needs approved
                                        compensation and attendance for this
                                        month. Leave deductions and statutory
                                        amounts require explicit review.
                                    </p>
                                </>
                            ) : kind === "leave" ? (
                                <>
                                    <div className="form-grid">
                                        {field("from", "First day", "date")}
                                        {field("to", "Last day", "date")}
                                    </div>
                                    <Field label="Leave type">
                                        <select
                                            value={values.kind}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    kind: e.target.value,
                                                })
                                            }
                                        >
                                            {[
                                                "annual",
                                                "sick",
                                                "unpaid",
                                                "other",
                                            ].map((v) => (
                                                <option key={v}>{v}</option>
                                            ))}
                                        </select>
                                    </Field>
                                    {field(
                                        "units",
                                        "Requested days (half days allowed)",
                                    )}
                                    {field(
                                        "coverage_notes",
                                        "Coverage notes",
                                        "text",
                                        false,
                                    )}
                                </>
                            ) : kind === "attendance" ? (
                                <>
                                    {field("period", "Month", "month")}
                                    {field(
                                        "scheduled_minutes",
                                        "Scheduled minutes",
                                        "number",
                                    )}
                                    {field(
                                        "worked_minutes",
                                        "Worked minutes",
                                        "number",
                                    )}
                                    {field(
                                        "approved_leave_ids",
                                        "Approved leave record IDs, comma separated",
                                        "text",
                                        false,
                                    )}
                                    {field("notes", "Notes", "text", false)}
                                </>
                            ) : (
                                <>
                                    <div className="form-grid">
                                        {field(
                                            "effective_from",
                                            "First pay month",
                                            "month",
                                        )}
                                        {field(
                                            "effective_to",
                                            "Last pay month (optional)",
                                            "month",
                                            false,
                                        )}
                                    </div>
                                    {field("currency", "Currency (ISO code)")}
                                    {field("salary", "Base salary")}
                                    {field("allowance", "Allowance")}
                                    {field("bonus", "Bonus")}
                                    {field("deduction", "Approved deduction")}
                                </>
                            )}
                        </div>
                        <div className="dialog-footer">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => {
                                    setCreate(false);
                                    setGenerate(false);
                                }}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={busy}>
                                {busy
                                    ? "Saving…"
                                    : generate
                                      ? "Prepare draft"
                                      : "Submit for review"}
                            </Button>
                        </div>
                    </form>
                </DetailPage>
            )}
            {selected && (
                <DetailPage
                    open
                    title={`${kind} review`}
                    description={`Record ${selected.id}`}
                    onClose={() => setSelected(null)}
                >
                    <div className="dialog-body form-stack">
                        <Badge>{selected.status}</Badge>
                        {Object.entries(selected)
                            .filter(
                                ([k, v]) =>
                                    ![
                                        "id",
                                        "version",
                                        "owner_id",
                                        "created_by",
                                        "reviewed_by",
                                        "employee_id",
                                        "status",
                                    ].includes(k) && typeof v !== "object",
                            )
                            .map(([k, v]) => (
                                <div key={k}>
                                    <small className="subtle">
                                        {k.replaceAll("_", " ")}
                                    </small>
                                    <p>{String(v ?? "")}</p>
                                </div>
                            ))}
                        {selected.earnings && (
                            <DataTable
                                records={selected.earnings.map(
                                    (r: any, i: number) => ({
                                        ...r,
                                        id: String(i),
                                    }),
                                )}
                                columns={[
                                    { key: "label", label: "Earnings" },
                                    {
                                        key: "amount_minor",
                                        label: "Minor units",
                                    },
                                ]}
                            />
                        )}{" "}
                        {canManage && selected.status === "submitted" && (
                            <>
                                <Field label="Review notes">
                                    <textarea
                                        value={notes}
                                        onChange={(e) =>
                                            setNotes(e.target.value)
                                        }
                                    />
                                </Field>
                                <div className="button-row">
                                    {["approved", "rejected"].map(
                                        (decision) => (
                                            <Button
                                                key={decision}
                                                disabled={busy || !notes.trim()}
                                                variant={
                                                    decision === "rejected"
                                                        ? "secondary"
                                                        : "primary"
                                                }
                                                onClick={async () => {
                                                    setBusy(true);
                                                    try {
                                                        setSelected(
                                                            (
                                                                await post(
                                                                    `/api/v1/employee-inputs/${kind}/${selected.id}/decision`,
                                                                    {
                                                                        decision,
                                                                        notes,
                                                                    },
                                                                )
                                                            ).data,
                                                        );
                                                        await load();
                                                    } catch (e) {
                                                        setError(
                                                            (e as Error)
                                                                .message,
                                                        );
                                                    } finally {
                                                        setBusy(false);
                                                    }
                                                }}
                                            >
                                                {decision === "approved"
                                                    ? "Approve"
                                                    : "Reject"}
                                            </Button>
                                        ),
                                    )}
                                </div>
                            </>
                        )}
                    </div>
                </DetailPage>
            )}
        </section>
    );
}
