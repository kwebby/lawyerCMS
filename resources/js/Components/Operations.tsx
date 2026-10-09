// Author: ramanpal singh | URL: https://kwebby.com
import { usePageState } from "../lib/navigation";
import { ProgressBoard, StatusShortcut, WorkCalendar } from "./ProgressViews";
import { LeadPipelines } from "./LeadPipelines";
import { useEffect, useState } from "react";
import { Link } from "@inertiajs/react";
import {
    ArrowUpRight,
    ChevronRight,
    CalendarDays,
    Check,
    Clock3,
    FileText,
    Plus,
    BriefcaseBusiness,
    Users,
    CheckCircle2,
    List,
    Columns3,
} from "lucide-react";
import type { RecordData, WorkspaceProps } from "../lib/types";
import {
    api,
    dateLabel,
    display,
    initials,
    money,
    patch,
    post,
} from "../lib/api";
import {
    AddButton,
    Alert,
    Badge,
    Button,
    DataTable,
    Empty,
    Field,
    DetailPage,
    RecordForm,
    SectionTitle,
} from "./ui";
import { moduleFields } from "../lib/modules";
export function Dashboard({
    records,
    stats,
    user,
}: {
    records: RecordData[];
    stats: Record<string, any>;
    user: WorkspaceProps["user"];
}) {
    const [tasks, setTasks] = useState<RecordData[]>(
        Array.isArray(stats.tasks) ? stats.tasks : [],
    );
    const [matters, setMatters] = useState<RecordData[]>(
        Array.isArray(stats.matters) ? stats.matters : records,
    );
    const [error, setError] = useState("");
    useEffect(() => {
        Promise.all([
            api("/api/v1/records/tasks"),
            api("/api/v1/records/matters"),
        ])
            .then(([a, b]) => {
                setTasks(a.data || []);
                setMatters(b.data || []);
            })
            .catch((e) => setError(e.message));
    }, []);
    const client =
        user.roles.includes("client") || user.roles.includes("prospect");
    const prefix = client ? "/portal" : "/app";
    const pending = tasks.filter(
        (t) => !["done", "completed", "cancelled"].includes(t.status),
    );
    const due = pending.filter(
        (t) => t.due_at && new Date(t.due_at) < new Date(Date.now() + 86400000),
    );
    const count = (key: string, fallback: any) =>
        typeof stats[key] === "number" || typeof stats[key] === "string"
            ? stats[key]
            : fallback;
    async function complete(task: RecordData) {
        try {
            await patch(`/api/v1/records/tasks/${task.id}`, {
                status: "done",
                version: task.version,
            });
            setTasks((prev) =>
                prev.map((t) =>
                    t.id === task.id ? { ...t, status: "done" } : t,
                ),
            );
        } catch (e) {
            setError((e as Error).message);
        }
    }
    return (
        <>
            <Alert>{error}</Alert>
            <div className="metric-strip">
                <div>
                    <div className="metric-label">
                        Active matters
                        <BriefcaseBusiness size={17} />
                    </div>
                    <strong>
                        {count(
                            "active_matters",
                            matters.filter((m) => m.status !== "closed").length,
                        )}
                    </strong>
                    <span>Open work across your practice</span>
                </div>
                <div>
                    <div className="metric-label">
                        Needs attention
                        <Clock3 size={17} />
                    </div>
                    <strong>{count("overdue_tasks", due.length)}</strong>
                    <span>Tasks due or coming up today</span>
                </div>
                <div>
                    <div className="metric-label">
                        Open tasks
                        <CheckCircle2 size={17} />
                    </div>
                    <strong>{count("pending_tasks", pending.length)}</strong>
                    <span>Assigned work in progress</span>
                </div>
                <div>
                    <div className="metric-label">
                        {client ? "Shared documents" : "New inquiries"}
                        <Users size={17} />
                    </div>
                    <strong>
                        {count(
                            client ? "shared_documents" : "open_leads",
                            stats.counts?.[client ? "documents" : "leads"] ?? 0,
                        )}
                    </strong>
                    <span>
                        {client
                            ? "Documents available in your portal"
                            : "Ready for an intake conversation"}
                    </span>
                </div>
            </div>
            <div className="dashboard-grid">
                <section className="panel agenda-panel">
                    <SectionTitle
                        title="Your next priorities"
                        description="Focus on the work that moves matters forward."
                        action={
                            <Link
                                className="text-link"
                                href={`${prefix}/tasks`}
                            >
                                View tasks <ArrowUpRight size={15} />
                            </Link>
                        }
                    />
                    {pending.length ? (
                        <div className="agenda-list">
                            {pending.slice(0, 6).map((task) => (
                                <div className="agenda-item" key={task.id}>
                                    {!client ? (
                                        <button
                                            className="task-check"
                                            aria-label={`Complete ${task.title}`}
                                            onClick={() => complete(task)}
                                        >
                                            <Check size={13} />
                                        </button>
                                    ) : (
                                        <span className="status-dot" />
                                    )}
                                    <div className="agenda-copy">
                                        <strong>{task.title}</strong>
                                        <span>
                                            {task.matter_title ||
                                                task.date_source ||
                                                "Practice task"}
                                        </span>
                                    </div>
                                    <div className="agenda-meta">
                                        <Badge>
                                            {task.priority || "normal"}
                                        </Badge>
                                        <span>
                                            <CalendarDays size={12} />
                                            {dateLabel(task.due_at)}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <Empty title="You’re up to date">
                            Create a task to set the next step for your team.
                        </Empty>
                    )}
                    <Link
                        href={`${prefix}/tasks`}
                        className="panel-bottom-link"
                    >
                        <Plus size={16} />
                        Plan the next step
                    </Link>
                </section>
                <aside className="practice-note">
                    <div className="note-illustration">
                        <ScaleMark />
                    </div>
                    <h2>
                        Good work starts
                        <br />
                        with a clear next step.
                    </h2>
                    <p>
                        Keep the people, deadlines and documents behind every
                        matter connected.
                    </p>
                    <Link
                        href={`${prefix}/matters`}
                        className="button button-secondary"
                    >
                        Open your matters <ArrowUpRight size={15} />
                    </Link>
                    <span className="note-foot">
                        Built around your practice.
                    </span>
                </aside>
            </div>
            <section className="section-space">
                <SectionTitle
                    title="Recent matters"
                    description="Pick up where your team left off."
                    action={
                        <Link className="text-link" href={`${prefix}/matters`}>
                            All matters <ArrowUpRight size={15} />
                        </Link>
                    }
                />
                <DataTable
                    records={matters.slice(0, 5)}
                    columns={[
                        {
                            key: "title",
                            label: "Matter",
                            render: (r) => (
                                <Link
                                    className="table-link"
                                    href={`${prefix}/matters?matter=${r.id}`}
                                >
                                    {r.title}
                                    <small className="cell-sub">
                                        {r.reference || r.id.slice(0, 8)}
                                    </small>
                                </Link>
                            ),
                        },
                        {
                            key: "practice",
                            label: "Practice area",
                            render: (r: RecordData) =>
                                r.practice ||
                                r.practice_area ||
                                r.issue_category ||
                                "General",
                        },
                        {
                            key: "status",
                            label: "Status",
                            render: (r) => <Badge>{r.status}</Badge>,
                        },
                        { key: "jurisdiction", label: "Jurisdiction" },
                        {
                            key: "updated_at",
                            label: "Last updated",
                            render: (r) => dateLabel(r.updated_at),
                        },
                    ]}
                    emptyTitle="Your first matter starts here"
                    emptyAction={
                        <Link
                            href={`${prefix}/matters`}
                            className="button button-primary"
                        >
                            Open matters
                        </Link>
                    }
                />
            </section>
        </>
    );
}
function ScaleMark() {
    return (
        <svg viewBox="0 0 190 100" aria-hidden="true">
            <path
                d="M95 14v67M57 86h76M70 81h50M36 37l118-12M50 34L30 65h40L50 34M141 27l-20 31h40l-20-31"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
            />
            <path
                d="M30 65q20 24 40 0M121 58q20 24 40 0"
                fill="currentColor"
                opacity=".22"
            />
            <circle
                cx="95"
                cy="14"
                r="5"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
            />
        </svg>
    );
}
export function Operations({
    section,
    records,
    reload,
    user,
}: {
    section: string;
    records: RecordData[];
    reload: () => Promise<void>;
    user: WorkspaceProps["user"];
}) {
    const [edit, setEdit] = usePageState<RecordData | null | undefined>(
        "edit",
        undefined,
        {
            records,
            load: async (id) =>
                (await api(`/api/v1/records/${section}/${id}`)).data,
        },
    );
    const [selected, setSelected] = usePageState<RecordData | null>(
        "record",
        null,
        {
            records,
            load: async (id) =>
                (await api(`/api/v1/records/${section}/${id}`)).data,
        },
    );
    const [error, setError] = useState("");
    const [view, setView] = usePageState("view", "list", { page: false });
    useEffect(() => {
        if (section === "matters") {
            const id = new URLSearchParams(window.location.search).get(
                "matter",
            );
            const found = records.find((r) => r.id === id);
            if (found) setSelected(found);
        }
    }, [section]);
    const singular: Record<string, string> = {
        leads: "lead",
        contacts: "contact",
        matters: "matter",
        tasks: "task",
    };
    const client =
        user.roles.includes("client") || user.roles.includes("prospect");
    const columns: any[] =
        section === "leads"
            ? [
                  {
                      key: "name",
                      label: "Contact",
                      render: (r: RecordData) => (
                          <span className="person-cell">
                              <span className="avatar avatar-light">
                                  {initials(r.name)}
                              </span>
                              <span>
                                  {r.name}
                                  <small className="cell-sub">{r.email}</small>
                              </span>
                          </span>
                      ),
                  },
                  {
                      key: "practice",
                      label: "Practice area",
                      render: (r: RecordData) =>
                          r.practice ||
                          r.practice_area ||
                          r.issue_category ||
                          "General",
                  },
                  {
                      key: "status",
                      label: "Stage",
                      render: (r: RecordData) => (
                          <Badge>{r.status || "new"}</Badge>
                      ),
                  },
                  { key: "source", label: "Source" },
                  {
                      key: "next_action_at",
                      label: "Follow-up",
                      render: (r: RecordData) => dateLabel(r.next_action_at),
                  },
              ]
            : section === "contacts"
              ? [
                    { key: "name", label: "Name" },
                    { key: "kind", label: "Type" },
                    { key: "email", label: "Email" },
                    { key: "phone", label: "Phone" },
                    { key: "safe_contact", label: "Contact preference" },
                ]
              : section === "matters"
                ? [
                      {
                          key: "title",
                          label: "Matter",
                          render: (r: RecordData) => (
                              <span>
                                  {r.title}
                                  <small className="cell-sub">
                                      {r.reference || r.id.slice(0, 8)}
                                  </small>
                              </span>
                          ),
                      },
                      {
                          key: "practice",
                          label: "Practice area",
                          render: (r: RecordData) =>
                              r.practice ||
                              r.practice_area ||
                              r.issue_category ||
                              "General",
                      },
                      {
                          key: "status",
                          label: "Status",
                          render: (r: RecordData) => <Badge>{r.status}</Badge>,
                      },
                      { key: "jurisdiction", label: "Jurisdiction" },
                      ...(client
                          ? []
                          : [
                                {
                                    key: "confidentiality",
                                    label: "Access",
                                    render: (r: RecordData) => (
                                        <Badge>
                                            {r.confidentiality || "standard"}
                                        </Badge>
                                    ),
                                },
                            ]),
                  ]
                : [
                      { key: "title", label: "Task" },
                      {
                          key: "due_at",
                          label: "Due date",
                          render: (r: RecordData) => dateLabel(r.due_at),
                      },
                      {
                          key: "priority",
                          label: "Priority",
                          render: (r: RecordData) => (
                              <Badge>{r.priority || "normal"}</Badge>
                          ),
                      },
                      {
                          key: "status",
                          label: "Status",
                          render: (r: RecordData) => <Badge>{r.status}</Badge>,
                      },
                      { key: "date_source", label: "Date source" },
                  ];
    async function updateStatus(record: RecordData, status: string) {
        try {
            await patch(`/api/v1/records/${section}/${record.id}`, {
                version: record.version,
                status,
            });
            await reload();
        } catch (error) {
            await reload();
            throw error;
        }
    }
    const quickColumns = columns.map((column) =>
        column.key === "status"
            ? {
                  ...column,
                  render: (record: RecordData) => (
                      <StatusShortcut
                          record={record}
                          section={section}
                          readOnly={client}
                          onOpen={setSelected}
                          onUpdate={updateStatus}
                      />
                  ),
              }
            : column,
    );
    const newAction =
        !client &&
        (section === "matters" ? (
            <Link className="button button-primary" href="/app/leads">
                <Plus size={16} />
                Open matter from intake
            </Link>
        ) : (
            <AddButton onClick={() => setEdit(null)}>
                New {singular[section]}
            </AddButton>
        ));
    return (
        <>
            <Alert>{error}</Alert>
            <div className="module-toolbar">
                <div className="segmented">
                    {(section === "leads"
                        ? ["list", "kanban", "calendar", "custom pipelines"]
                        : ["tasks", "matters"].includes(section)
                          ? ["list", "kanban", "calendar"]
                          : ["all"]
                    ).map((tab) => (
                        <button
                            className={
                                view === tab ||
                                (tab === "all" && view === "list")
                                    ? "selected"
                                    : ""
                            }
                            key={tab}
                            onClick={() => setView(tab)}
                        >
                            {tab === "list" ? (
                                <List size={15} />
                            ) : tab === "kanban" ? (
                                <Columns3 size={15} />
                            ) : null}
                            {tab === "all"
                                ? "All " + section
                                : tab[0].toUpperCase() + tab.slice(1)}
                        </button>
                    ))}
                </div>
                {newAction}
            </div>
            {view === "custom pipelines" ? (
                <LeadPipelines
                    records={records}
                    reload={reload}
                    canManage={user.roles.some((r) =>
                        ["owner", "admin"].includes(r),
                    )}
                />
            ) : ["kanban", "pipeline"].includes(view) ? (
                <ProgressBoard
                    records={records}
                    section={section}
                    onOpen={setSelected}
                    onUpdate={updateStatus}
                    readOnly={client}
                />
            ) : view === "calendar" ? (
                <WorkCalendar
                    records={records}
                    section={section}
                    onOpen={setSelected}
                />
            ) : (
                <DataTable
                    records={records}
                    columns={quickColumns}
                    onOpen={setSelected}
                    emptyAction={newAction}
                />
            )}
            {edit !== undefined && (
                <RecordForm
                    key={edit?.id || "new"}
                    fields={moduleFields[section]}
                    initial={edit || undefined}
                    title={`${edit ? "Edit" : "New"} ${singular[section]}`}
                    onClose={() => setEdit(undefined)}
                    onSave={async (values) => {
                        await (edit
                            ? patch(
                                  `/api/v1/records/${section}/${edit.id}`,
                                  values,
                              )
                            : post(`/api/v1/records/${section}`, values));
                        await reload();
                    }}
                />
            )}
            {selected && (
                <RecordDetail
                    section={section}
                    record={selected}
                    onClose={() => setSelected(null)}
                    onEdit={() => {
                        setEdit(selected);
                        setSelected(null);
                    }}
                    reload={async () => {
                        await reload();
                        const result = await api(
                            `/api/v1/records/${section}/${selected.id}`,
                        );
                        setSelected(result.data);
                    }}
                    client={client}
                />
            )}
        </>
    );
}
function RecordDetail({
    section,
    record,
    onClose,
    onEdit,
    reload,
    client,
}: {
    section: string;
    record: RecordData;
    onClose: () => void;
    onEdit: () => void;
    reload: () => Promise<void>;
    client: boolean;
}) {
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const [notes, setNotes] = useState("");
    const [scope, setScope] = useState(record.scope || "");
    const [feeTerms, setFeeTerms] = useState("");
    const [acceptanceReference, setAcceptanceReference] = useState("");
    const [related, setRelated] = useState<RecordData[]>([]);
    const [documents, setDocuments] = useState<RecordData[]>([]);
    const [proceedings, setProceedings] = useState<RecordData[]>([]);
    const [newProceeding, setNewProceeding] = usePageState(
        "new-proceeding",
        false,
    );
    const [people, setPeople] = useState<RecordData[]>([]);
    const [teamIds, setTeamIds] = useState<string[]>(record.team_ids || []);
    const [clientIds, setClientIds] = useState<string[]>(
        record.client_ids || [],
    );
    const [conflicts, setConflicts] = useState<any>(null);
    const [tab, setTab] = usePageState("detail-tab", "details", {
        page: false,
    });
    useEffect(() => {
        if (section === "matters")
            Promise.all([
                api("/api/v1/records/tasks"),
                api("/api/v1/documents"),
                api("/api/v1/records/proceedings"),
            ])
                .then(([tasks, docs, events]) => {
                    setRelated(
                        tasks.data.filter(
                            (r: RecordData) => r.matter_id === record.id,
                        ),
                    );
                    setDocuments(
                        docs.data.filter(
                            (r: RecordData) => r.matter_id === record.id,
                        ),
                    );
                    setProceedings(
                        events.data.filter(
                            (r: RecordData) => r.matter_id === record.id,
                        ),
                    );
                })
                .catch((e) => setError(e.message));
        if (section === "matters" && !client)
            api("/api/v1/users")
                .catch(() => api("/api/v1/people?matter_id=" + record.id))
                .then((r) => setPeople(r.data))
                .catch((e) => setError(e.message));
        if (section === "leads" && !client)
            api(`/api/v1/leads/${record.id}/conflict-matches`)
                .then((r) => setConflicts(r.data))
                .catch(() => {});
    }, [record.id, section]);
    async function action(path: string, body: any) {
        setBusy(true);
        setError("");
        try {
            await post(path, body);
            await reload();
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
            onClose={onClose}
            title={record.title || record.name}
            description={`${section.slice(0, -1)} reference ${record.reference || record.id}`}
        >
            <div className="dialog-body">
                <Alert>{error}</Alert>
                <div className="detail-top">
                    <Badge>{record.status || "active"}</Badge>
                    {!client && (
                        <Button variant="secondary" onClick={onEdit}>
                            Edit details
                        </Button>
                    )}
                </div>
                <div className="tabs">
                    {[
                        "details",
                        ...(section === "matters"
                            ? [
                                  "tasks",
                                  "documents",
                                  "proceedings",
                                  ...(!client ? ["team & access"] : []),
                              ]
                            : []),
                        ...(section === "leads" && !client
                            ? ["conflict review", "engagement"]
                            : []),
                    ].map((t) => (
                        <button
                            key={t}
                            onClick={() => setTab(t)}
                            className={tab === t ? "active" : ""}
                        >
                            {t[0].toUpperCase() + t.slice(1)}
                        </button>
                    ))}
                </div>
                {tab === "details" && (
                    <dl className="detail-grid">
                        {moduleFields[section]
                            .filter((f) => record[f.name] !== undefined)
                            .map((f) => (
                                <div key={f.name}>
                                    <dt>{f.label}</dt>
                                    <dd>{display(record[f.name])}</dd>
                                </div>
                            ))}
                        {section === "matters" && !client && (
                            <div>
                                <dt>Team members</dt>
                                <dd>
                                    {(record.team_ids || []).length} assigned
                                </dd>
                            </div>
                        )}
                    </dl>
                )}
                {tab === "tasks" && (
                    <DataTable
                        records={related}
                        columns={[
                            { key: "title", label: "Task" },
                            {
                                key: "due_at",
                                label: "Due",
                                render: (r) => dateLabel(r.due_at),
                            },
                            {
                                key: "status",
                                label: "Status",
                                render: (r) => <Badge>{r.status}</Badge>,
                            },
                        ]}
                        emptyTitle="No tasks linked to this matter"
                    />
                )}
                {tab === "documents" && (
                    <DataTable
                        records={documents}
                        columns={[
                            {
                                key: "title",
                                label: "Document",
                                render: (r) => (
                                    <Link
                                        className="table-link"
                                        href={
                                            client
                                                ? "/portal/documents"
                                                : "/app/documents"
                                        }
                                    >
                                        {r.title || r.name}
                                    </Link>
                                ),
                            },
                            {
                                key: "status",
                                label: "Status",
                                render: (r) => <Badge>{r.status}</Badge>,
                            },
                        ]}
                        emptyTitle="No documents linked to this matter"
                    />
                )}
                {tab === "proceedings" && (
                    <>
                        <div className="module-toolbar">
                            <p className="subtle">
                                Verified hearings, filings and service records.
                            </p>
                            {!client && (
                                <AddButton
                                    onClick={() => setNewProceeding(true)}
                                >
                                    Add proceeding
                                </AddButton>
                            )}
                        </div>
                        <DataTable
                            records={proceedings}
                            columns={[
                                { key: "title", label: "Proceeding" },
                                { key: "court", label: "Court / agency" },
                                {
                                    key: "case_reference",
                                    label: "Case reference",
                                },
                                {
                                    key: "hearing_at",
                                    label: "Hearing",
                                    render: (r) => dateLabel(r.hearing_at),
                                },
                                {
                                    key: "status",
                                    label: "Status",
                                    render: (r) => <Badge>{r.status}</Badge>,
                                },
                            ]}
                        />
                    </>
                )}
                {tab === "team & access" && (
                    <div className="form-stack">
                        <p className="subtle">
                            Grant this matter only to the team and verified
                            clients who need access.
                        </p>
                        <div className="form-grid">
                            <Field label="Team members">
                                <div className="source-documents">
                                    {people
                                        .filter(
                                            (p) =>
                                                !p.roles?.includes("client") &&
                                                !p.roles?.includes("prospect"),
                                        )
                                        .map((p) => (
                                            <label
                                                className="checkbox-label"
                                                key={p.id}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={teamIds.includes(
                                                        p.id,
                                                    )}
                                                    onChange={(e) =>
                                                        setTeamIds(
                                                            e.target.checked
                                                                ? [
                                                                      ...teamIds,
                                                                      p.id,
                                                                  ]
                                                                : teamIds.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          p.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {p.name}
                                            </label>
                                        ))}
                                </div>
                            </Field>
                            <Field label="Client portal access">
                                <div className="source-documents">
                                    {people
                                        .filter((p) =>
                                            p.roles?.includes("client"),
                                        )
                                        .map((p) => (
                                            <label
                                                className="checkbox-label"
                                                key={p.id}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={clientIds.includes(
                                                        p.id,
                                                    )}
                                                    onChange={(e) =>
                                                        setClientIds(
                                                            e.target.checked
                                                                ? [
                                                                      ...clientIds,
                                                                      p.id,
                                                                  ]
                                                                : clientIds.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          p.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {p.name}
                                            </label>
                                        ))}
                                </div>
                            </Field>
                        </div>
                        <Button
                            disabled={busy}
                            onClick={async () => {
                                setBusy(true);
                                try {
                                    await patch(
                                        `/api/v1/records/matters/${record.id}`,
                                        {
                                            version: record.version,
                                            team_ids: teamIds,
                                            client_ids: clientIds,
                                        },
                                    );
                                    await reload();
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            Save matter access
                        </Button>
                    </div>
                )}
                {tab === "conflict review" && (
                    <div className="form-stack">
                        {conflicts && (
                            <div className="conflict-results">
                                <strong>
                                    {conflicts.matches?.length || 0} potential
                                    matches
                                </strong>
                                {conflicts.matches?.map((match: any) => (
                                    <p key={match.id}>
                                        {match.name} — {match.collection} —
                                        matched {match.matched_term}
                                    </p>
                                ))}
                                <small>
                                    This search supports your review; it does
                                    not clear conflicts automatically.
                                </small>
                            </div>
                        )}
                        <p>
                            Record the result of your review of connected
                            parties before accepting an engagement.
                        </p>
                        <Badge>
                            {record.conflict_status ||
                                record.conflict_review?.decision ||
                                "Not reviewed"}
                        </Badge>
                        <Field label="Review notes">
                            <textarea
                                rows={4}
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                            />
                        </Field>
                        <div className="button-row">
                            {["clear", "flagged", "rejected"].map(
                                (decision) => (
                                    <Button
                                        disabled={busy || !notes.trim()}
                                        variant={
                                            decision === "clear"
                                                ? "primary"
                                                : "secondary"
                                        }
                                        key={decision}
                                        onClick={() =>
                                            action(
                                                `/api/v1/leads/${record.id}/conflict-review`,
                                                { decision, notes },
                                            )
                                        }
                                    >
                                        {decision === "clear"
                                            ? "Clear conflict review"
                                            : decision === "flagged"
                                              ? "Flag for review"
                                              : "Reject"}
                                    </Button>
                                ),
                            )}
                        </div>
                    </div>
                )}
                {tab === "engagement" && (
                    <div className="form-stack">
                        <Field label="Scope of engagement">
                            <textarea
                                value={scope}
                                onChange={(e) => setScope(e.target.value)}
                                rows={3}
                            />
                        </Field>
                        <Field label="Fee terms">
                            <textarea
                                value={feeTerms}
                                onChange={(e) => setFeeTerms(e.target.value)}
                                rows={3}
                            />
                        </Field>
                        <Field
                            label="Acceptance reference"
                            hint="Reference the signed engagement or documented acceptance."
                        >
                            <input
                                value={acceptanceReference}
                                onChange={(e) =>
                                    setAcceptanceReference(e.target.value)
                                }
                            />
                        </Field>
                        <Button
                            disabled={
                                busy ||
                                !scope ||
                                !feeTerms ||
                                !acceptanceReference
                            }
                            onClick={() =>
                                action(
                                    `/api/v1/leads/${record.id}/engagement`,
                                    {
                                        scope,
                                        fee_terms: feeTerms,
                                        accepted_by: record.name,
                                        accepted_at: new Date().toISOString(),
                                        acceptance_reference:
                                            acceptanceReference,
                                    },
                                )
                            }
                        >
                            Record accepted engagement
                        </Button>
                        <div className="divider" />
                        <p>
                            A cleared conflict review and accepted engagement
                            are required before conversion.
                        </p>
                        <Button
                            disabled={busy}
                            variant="secondary"
                            onClick={() =>
                                action(`/api/v1/leads/${record.id}/convert`, {
                                    title: `${record.name} — ${record.issue_category || record.practice || "New matter"}`,
                                    practice:
                                        record.issue_category ||
                                        record.practice ||
                                        "General",
                                    jurisdiction: record.jurisdiction,
                                })
                            }
                        >
                            Convert to matter
                        </Button>
                    </div>
                )}
            </div>
            <div className="dialog-footer">
                <Button variant="secondary" onClick={onClose}>
                    Close
                </Button>
            </div>
            {newProceeding && (
                <RecordForm
                    title="Add proceeding"
                    fields={moduleFields.proceedings.filter(
                        (f) => f.name !== "matter_id",
                    )}
                    onClose={() => setNewProceeding(false)}
                    onSave={async (data) => {
                        await post("/api/v1/records/proceedings", {
                            ...data,
                            matter_id: record.id,
                        });
                        setProceedings(
                            (
                                await api("/api/v1/records/proceedings")
                            ).data.filter(
                                (r: RecordData) => r.matter_id === record.id,
                            ),
                        );
                    }}
                />
            )}
        </DetailPage>
    );
}
