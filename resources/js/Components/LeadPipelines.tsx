// Author: ramanpal singh | URL: https://kwebby.com
import { usePageState } from "../lib/navigation";
import { useEffect, useState } from "react";
import { api, post, patch } from "../lib/api";
import type { RecordData } from "../lib/types";
import { Alert, Button, Field, DetailPage, Badge } from "./ui";
export function LeadPipelines({
    records,
    reload,
    canManage,
}: {
    records: RecordData[];
    reload: () => Promise<void>;
    canManage: boolean;
}) {
    const [pipelines, setPipelines] = useState<RecordData[]>([]);
    const [selected, setSelected] = usePageState("pipeline", "", {
        page: false,
    });
    const [error, setError] = useState("");
    const [editing, setEditing] = usePageState<any>("pipeline-designer", null, {
        load: async (id) =>
            id === "current"
                ? {
                      name: "",
                      active: true,
                      stages: [{ key: "inquiry", label: "New inquiry" }],
                  }
                : (await api("/api/v1/pipelines")).data.find(
                      (item: RecordData) => item.id === id,
                  ),
    });
    const [busy, setBusy] = useState(false);
    const [lead, setLead] = useState("");
    const [stage, setStage] = useState("");
    const load = async () => {
        const rows = (await api("/api/v1/pipelines")).data;
        setPipelines(rows);
        setSelected((old) => old || rows.find((r: any) => r.active)?.id || "");
    };
    useEffect(() => {
        load().catch((e) => setError(e.message));
    }, []);
    const pipeline = pipelines.find((p) => p.id === selected);
    return (
        <>
            <Alert>{error}</Alert>
            <div className="module-toolbar">
                <Field label="Intake pipeline">
                    <select
                        value={selected}
                        onChange={(e) => {
                            setSelected(e.target.value);
                            setStage("");
                        }}
                    >
                        <option value="">Choose pipeline</option>
                        {pipelines.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                                {!p.active ? " (archived)" : ""}
                            </option>
                        ))}
                    </select>
                </Field>
                {canManage && (
                    <div className="button-row">
                        {pipeline && (
                            <Button
                                variant="secondary"
                                onClick={() => setEditing({ ...pipeline })}
                            >
                                Edit pipeline
                            </Button>
                        )}
                        <Button
                            onClick={() =>
                                setEditing({
                                    name: "",
                                    active: true,
                                    stages: [
                                        {
                                            key: "inquiry",
                                            label: "New inquiry",
                                        },
                                        {
                                            key: "consultation",
                                            label: "Consultation",
                                        },
                                    ],
                                })
                            }
                        >
                            Create pipeline
                        </Button>
                    </div>
                )}
            </div>
            <p className="subtle">
                Pipeline stages organize intake. Conflict clearance and accepted
                engagement still control matter activation.
            </p>
            {pipeline && (
                <>
                    <div className="form-grid">
                        <Field label="Lead">
                            <select
                                value={lead}
                                onChange={(e) => setLead(e.target.value)}
                            >
                                <option value="">Choose lead</option>
                                {records.map((r) => (
                                    <option key={r.id} value={r.id}>
                                        {r.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Move to stage">
                            <select
                                value={stage}
                                onChange={(e) => setStage(e.target.value)}
                            >
                                <option value="">Choose stage</option>
                                {pipeline.stages.map((s: any) => (
                                    <option key={s.key} value={s.key}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>
                    <Button
                        variant="secondary"
                        disabled={!lead || !stage || busy || !pipeline.active}
                        onClick={async () => {
                            setBusy(true);
                            setError("");
                            try {
                                await post(`/api/v1/leads/${lead}/pipeline`, {
                                    version: records.find((r) => r.id === lead)
                                        ?.version,
                                    pipeline_id: pipeline.id,
                                    stage,
                                });
                                await reload();
                            } catch (e) {
                                setError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        Move lead
                    </Button>
                    <div className="pipeline section-space">
                        {pipeline.stages.map((s: any) => (
                            <section key={s.key}>
                                <div className="pipeline-heading">
                                    <h3>{s.label}</h3>
                                    <span>
                                        {
                                            records.filter(
                                                (r) =>
                                                    r.pipeline_id ===
                                                        pipeline.id &&
                                                    r.pipeline_stage === s.key,
                                            ).length
                                        }
                                    </span>
                                </div>
                                {records
                                    .filter(
                                        (r) =>
                                            r.pipeline_id === pipeline.id &&
                                            r.pipeline_stage === s.key,
                                    )
                                    .map((r) => (
                                        <div className="lead-card" key={r.id}>
                                            <strong>{r.name}</strong>
                                            <p>
                                                {r.next_action ||
                                                    "Set a next action in the lead record"}
                                            </p>
                                            <Badge>{r.status}</Badge>
                                        </div>
                                    ))}
                            </section>
                        ))}
                    </div>
                </>
            )}
            {editing && (
                <DetailPage
                    open
                    title={editing.id ? "Edit pipeline" : "Create pipeline"}
                    onClose={() => setEditing(null)}
                >
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setBusy(true);
                            setError("");
                            try {
                                const data = {
                                    name: editing.name,
                                    active: editing.active,
                                    stages: editing.stages,
                                    ...(editing.id
                                        ? { version: editing.version }
                                        : {}),
                                };
                                const saved = editing.id
                                    ? await patch(
                                          `/api/v1/pipelines/${editing.id}`,
                                          data,
                                      )
                                    : await post("/api/v1/pipelines", data);
                                await load();
                                setSelected(saved.data.id);
                                setEditing(null);
                            } catch (e) {
                                setError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <div className="dialog-body form-stack">
                            <Alert>{error}</Alert>
                            <Field label="Pipeline name">
                                <input
                                    required
                                    value={editing.name}
                                    onChange={(e) =>
                                        setEditing({
                                            ...editing,
                                            name: e.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <label>
                                <input
                                    type="checkbox"
                                    checked={editing.active}
                                    onChange={(e) =>
                                        setEditing({
                                            ...editing,
                                            active: e.target.checked,
                                        })
                                    }
                                />{" "}
                                Active for lead movement
                            </label>
                            {editing.stages.map((s: any, i: number) => (
                                <Field key={s.key} label={`Stage ${i + 1}`}>
                                    <input
                                        required
                                        value={s.label}
                                        onChange={(e) =>
                                            setEditing({
                                                ...editing,
                                                stages: editing.stages.map(
                                                    (v: any, j: number) =>
                                                        j === i
                                                            ? {
                                                                  ...v,
                                                                  label: e
                                                                      .target
                                                                      .value,
                                                              }
                                                            : v,
                                                ),
                                            })
                                        }
                                    />
                                </Field>
                            ))}
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={editing.stages.length >= 20}
                                onClick={() =>
                                    setEditing({
                                        ...editing,
                                        stages: [
                                            ...editing.stages,
                                            {
                                                key: `stage_${crypto.randomUUID().slice(0, 8)}`,
                                                label: "New stage",
                                            },
                                        ],
                                    })
                                }
                            >
                                Add stage
                            </Button>
                            <p className="subtle">
                                Existing stage identities are retained for lead
                                history. Archive a pipeline when it is no longer
                                used.
                            </p>
                        </div>
                        <div className="dialog-footer">
                            <Button disabled={busy} type="submit">
                                Save pipeline
                            </Button>
                        </div>
                    </form>
                </DetailPage>
            )}
        </>
    );
}
