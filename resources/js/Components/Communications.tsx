// Author: ramanpal singh | URL: https://kwebby.com
import { usePageState } from "../lib/navigation";
import { useEffect, useRef, useState } from "react";
import {
    Bell,
    Check,
    Clock3,
    MessageSquare,
    Plus,
    Send,
    Sparkles,
    Paperclip,
    ArrowUpRight,
} from "lucide-react";
import type { RecordData, User } from "../lib/types";
import { api, dateLabel, initials, patch, post } from "../lib/api";
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
} from "./ui";
import { DeliverySettings } from "./DeliverySettings";
export function Chat({ user }: { user: User }) {
    const client = user.roles.some((r) => ["client", "prospect"].includes(r));
    const [conversations, setConversations] = useState<RecordData[]>([]);
    const [selected, setSelected] = usePageState<RecordData | null>(
        "conversation",
        null,
        { records: conversations, page: false },
    );
    const [messages, setMessages] = useState<RecordData[]>([]);
    const [body, setBody] = useState("");
    const [error, setError] = useState("");
    const [create, setCreate] = usePageState("new-conversation", false);
    const [busy, setBusy] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const end = useRef<HTMLDivElement>(null);
    const key = useRef(crypto.randomUUID());
    const activity = useRef(Date.now());
    const [editMessage, setEditMessage] = usePageState<RecordData | null>(
        "edit-message",
        null,
        { records: messages },
    );
    const [attachOpen, setAttachOpen] = usePageState("attach-document", false);
    const [attachments, setAttachments] = useState<RecordData[]>([]);
    const [attachable, setAttachable] = useState<RecordData[]>([]);
    useEffect(() => {
        if (!attachOpen) return;
        let active = true;
        api("/api/v1/documents")
            .then((r) => {
                if (active)
                    setAttachable(
                        r.data.filter((d: RecordData) => d.status === "clean"),
                    );
            })
            .catch((e) => {
                if (active) setError(e.message);
            });
        return () => {
            active = false;
        };
    }, [attachOpen]);
    async function list() {
        try {
            const result = await api("/api/v1/chat");
            setConversations(result.data || []);
            setLoaded(true);
        } catch (e) {
            setError((e as Error).message);
            setLoaded(true);
        }
    }
    useEffect(() => {
        void list();
    }, []);
    useEffect(() => {
        if (!selected) return;
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout>;
        let cursor = 0;
        setMessages([]);
        const pull = async () => {
            if (cancelled) return;
            if (document.hidden) {
                timer = setTimeout(() => void pull(), 5000);
                return;
            }
            try {
                let hasMore = true;
                while (hasMore && !cancelled && !document.hidden) {
                    const result = await api(
                        `/api/v1/chat/${selected.id}/messages?after=${cursor}`,
                    );
                    if (cancelled) return;
                    const incoming = result.data || [];
                    setMessages((previous) =>
                        [
                            ...new Map(
                                [...previous, ...incoming].map((m) => [
                                    m.id,
                                    m,
                                ]),
                            ).values(),
                        ].sort((a, b) => (a.sequence || 0) - (b.sequence || 0)),
                    );
                    const next =
                        result.next_after ??
                        incoming[incoming.length - 1]?.sequence ??
                        cursor;
                    hasMore = result.has_more === true && next > cursor;
                    cursor = next;
                    if (incoming.length)
                        await post(`/api/v1/chat/${selected.id}/read`, {
                            sequence: cursor,
                        });
                }
            } catch (e) {
                if (!cancelled) setError((e as Error).message);
            }
            if (!cancelled)
                timer = setTimeout(
                    () => void pull(),
                    Date.now() - activity.current > 60000 ? 30000 : 5000,
                );
        };
        void pull();
        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [selected?.id]);
    useEffect(() => {
        end.current?.scrollIntoView({ block: "nearest" });
    }, [messages.length]);
    async function send() {
        if (!selected || !body.trim()) return;
        setBusy(true);
        setError("");
        try {
            const result = await post(`/api/v1/chat/${selected.id}/messages`, {
                body,
                idempotency_key: key.current,
                attachment_ids: attachments.map((a) => a.id),
            });
            setMessages((prev) =>
                prev.some((m) => m.id === result.data.id)
                    ? prev
                    : [...prev, result.data],
            );
            setBody("");
            setAttachments([]);
            key.current = crypto.randomUUID();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <Alert>{error}</Alert>
            <div
                className="chat-shell"
                onPointerMove={() => {
                    activity.current = Date.now();
                }}
                onKeyDown={() => {
                    activity.current = Date.now();
                }}
            >
                <aside className="chat-sidebar">
                    <div className="chat-list-head">
                        <h2>Conversations</h2>
                        {!client && (
                            <button
                                className="icon-button"
                                aria-label="New conversation"
                                onClick={() => setCreate(true)}
                            >
                                <Plus size={18} />
                            </button>
                        )}
                    </div>
                    {conversations.map((c) => (
                        <button
                            key={c.id}
                            className={`conversation-row ${selected?.id === c.id ? "selected" : ""}`}
                            onClick={() => setSelected(c)}
                        >
                            <span className="avatar avatar-light">
                                <MessageSquare size={18} />
                            </span>
                            <span>
                                <strong>{c.title}</strong>
                                <small>
                                    {c.client_visible
                                        ? "Client conversation"
                                        : "Internal conversation"}
                                </small>
                            </span>
                            <span className="conversation-time">
                                {c.unread_count > 0 ? (
                                    <Badge>{c.unread_count}</Badge>
                                ) : null}
                            </span>
                        </button>
                    ))}
                    {loaded && !conversations.length && (
                        <Empty
                            title="Start a conversation"
                            action={
                                !client && (
                                    <Button
                                        variant="secondary"
                                        onClick={() => setCreate(true)}
                                    >
                                        New conversation
                                    </Button>
                                )
                            }
                        >
                            Connect with your matter team.
                        </Empty>
                    )}
                </aside>
                <section className="chat-content">
                    {selected ? (
                        <>
                            <header className="chat-header">
                                <div>
                                    <h3>{selected.title}</h3>
                                    <p>
                                        {selected.client_visible
                                            ? "Visible to invited clients and your team"
                                            : "Private conversation for your team"}
                                    </p>
                                </div>
                                <Badge>
                                    {selected.client_visible
                                        ? "Client visible"
                                        : "Internal"}
                                </Badge>
                            </header>
                            <div
                                className="message-list"
                                role="log"
                                aria-live="polite"
                            >
                                {messages.length ? (
                                    messages.map((message) => (
                                        <div
                                            key={message.id}
                                            className={`message ${message.sender_id === user.id || message.author_id === user.id ? "own" : ""}`}
                                        >
                                            <span className="avatar avatar-light">
                                                {initials(
                                                    message.sender_name ||
                                                        message.author_name ||
                                                        (message.sender_id ===
                                                        user.id
                                                            ? user.name
                                                            : "Team"),
                                                )}
                                            </span>
                                            <div>
                                                <div className="message-author">
                                                    {message.sender_name ||
                                                        message.author_name ||
                                                        (message.sender_id ===
                                                        user.id
                                                            ? "You"
                                                            : "Team member")}
                                                    <time>
                                                        {new Date(
                                                            message.created_at ||
                                                                Date.now(),
                                                        ).toLocaleTimeString(
                                                            [],
                                                            {
                                                                hour: "2-digit",
                                                                minute: "2-digit",
                                                            },
                                                        )}
                                                    </time>
                                                </div>
                                                <p>
                                                    {message.deleted_at
                                                        ? "Message removed"
                                                        : message.body}
                                                </p>
                                                {message.sender_id ===
                                                    user.id &&
                                                    !message.deleted_at && (
                                                        <button
                                                            className="message-edit"
                                                            onClick={() =>
                                                                setEditMessage(
                                                                    message,
                                                                )
                                                            }
                                                        >
                                                            Edit message
                                                        </button>
                                                    )}
                                                {(
                                                    message.attachment_ids || []
                                                ).map((id: string) => (
                                                    <a
                                                        className="text-link"
                                                        href={`/api/v1/files/${id}/download`}
                                                        key={id}
                                                    >
                                                        <Paperclip size={14} />
                                                        Attachment
                                                    </a>
                                                ))}
                                            </div>
                                        </div>
                                    ))
                                ) : (
                                    <Empty title="Begin the conversation">
                                        Messages stay with this conversation’s
                                        invited members.
                                    </Empty>
                                )}
                                <div ref={end} />
                            </div>
                            <form
                                className="message-composer"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    void send();
                                }}
                            >
                                {attachments.length > 0 && (
                                    <div className="attachment-chips">
                                        {attachments.map((a) => (
                                            <button
                                                key={a.id}
                                                type="button"
                                                onClick={() =>
                                                    setAttachments(
                                                        attachments.filter(
                                                            (f) =>
                                                                f.id !== a.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                {a.title || a.name} ×
                                            </button>
                                        ))}
                                    </div>
                                )}
                                <textarea
                                    rows={2}
                                    aria-label="Message"
                                    placeholder="Write a message…"
                                    value={body}
                                    onChange={(e) => setBody(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === "Enter" && !e.shiftKey) {
                                            e.preventDefault();
                                            void send();
                                        }
                                    }}
                                />
                                <div>
                                    <div className="button-row">
                                        <button
                                            type="button"
                                            className="icon-button"
                                            aria-label="Attach a document"
                                            onClick={() => {
                                                setAttachOpen(true);
                                            }}
                                        >
                                            <Paperclip size={17} />
                                        </button>
                                        <small>
                                            Enter to send. Shift + Enter for a
                                            new line.
                                        </small>
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={busy || !body.trim()}
                                    >
                                        <Send size={15} />
                                        {busy ? "Sending…" : "Send message"}
                                    </Button>
                                </div>
                            </form>
                        </>
                    ) : (
                        <div className="chat-welcome">
                            <MessageSquare size={36} strokeWidth={1.3} />
                            <h2>The conversation stays with the work.</h2>
                            <p>
                                Open a conversation to catch up, ask a question
                                or share an update.
                            </p>
                            {!client && (
                                <Button onClick={() => setCreate(true)}>
                                    <Plus size={15} />
                                    New conversation
                                </Button>
                            )}
                        </div>
                    )}
                </section>
            </div>
            {create && (
                <ConversationForm
                    onClose={() => setCreate(false)}
                    onSave={async (body) => {
                        const result = await post("/api/v1/chat", body);
                        await list();
                        setSelected(result.data);
                        setCreate(false);
                    }}
                />
            )}
            {editMessage && (
                <RecordForm
                    title="Edit message"
                    initial={editMessage}
                    fields={[
                        { name: "body", label: "Message", type: "textarea" },
                    ]}
                    onClose={() => setEditMessage(null)}
                    onSave={async (values) => {
                        const r = await patch(
                            `/api/v1/chat/${selected?.id}/messages/${editMessage.id}`,
                            {
                                body: values.body,
                                expected_version: editMessage.version,
                            },
                        );
                        setMessages(
                            messages.map((m) =>
                                m.id === editMessage.id ? r.data : m,
                            ),
                        );
                    }}
                />
            )}
            {attachOpen && (
                <DetailPage
                    open
                    title="Attach a document"
                    description="Choose a scanned file already shared with every conversation member."
                    onClose={() => setAttachOpen(false)}
                >
                    <div className="dialog-body">
                        {attachable.map((a) => (
                            <label className="checkbox-label" key={a.id}>
                                <input
                                    type="checkbox"
                                    checked={attachments.some(
                                        (f) => f.id === a.id,
                                    )}
                                    onChange={(e) =>
                                        setAttachments(
                                            e.target.checked
                                                ? [...attachments, a]
                                                : attachments.filter(
                                                      (f) => f.id !== a.id,
                                                  ),
                                        )
                                    }
                                />
                                {a.title || a.name}
                            </label>
                        ))}
                        {!attachable.length && (
                            <p className="subtle">
                                No scanned files are available. Upload and scan
                                a document first.
                            </p>
                        )}
                    </div>
                    <div className="dialog-footer">
                        <Button onClick={() => setAttachOpen(false)}>
                            Done
                        </Button>
                    </div>
                </DetailPage>
            )}
        </>
    );
}
function ConversationForm({
    onClose,
    onSave,
}: {
    onClose: () => void;
    onSave: (body: any) => Promise<void>;
}) {
    const [title, setTitle] = useState("");
    const [members, setMembers] = useState("");
    const [visible, setVisible] = useState(false);
    const [matter, setMatter] = useState("");
    const [people, setPeople] = useState<RecordData[]>([]);
    const [matters, setMatters] = useState<RecordData[]>([]);
    useEffect(() => {
        api(
            `/api/v1/people${matter ? "?matter_id=" + encodeURIComponent(matter) : ""}`,
        )
            .then((r) => setPeople(r.data))
            .catch((e) => setError(e.message));
    }, [matter]);
    useEffect(() => {
        api("/api/v1/records/matters")
            .then((r) => setMatters(r.data))
            .catch(() => {});
    }, []);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    return (
        <DetailPage
            open
            title="New conversation"
            description="Invite the people who should have access to this conversation."
            onClose={onClose}
        >
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    try {
                        await onSave({
                            title,
                            member_ids: members
                                .split(",")
                                .map((v) => v.trim())
                                .filter(Boolean),
                            client_visible: visible,
                            matter_id: matter || null,
                        });
                    } catch (e) {
                        setError((e as Error).message);
                        setBusy(false);
                    }
                }}
            >
                <div className="dialog-body form-stack">
                    <Alert>{error}</Alert>
                    <Field label="Conversation title *">
                        <input
                            required
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                        />
                    </Field>
                    <Field label="Matter (optional)">
                        <select
                            value={matter}
                            onChange={(e) => {
                                setMatter(e.target.value);
                                setMembers("");
                            }}
                        >
                            <option value="">General team conversation</option>
                            {matters.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.title}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field
                        label="Invite members"
                        hint="Only eligible members appear. You are included automatically."
                    >
                        <div className="source-documents">
                            {people.map((p) => (
                                <label className="checkbox-label" key={p.id}>
                                    <input
                                        type="checkbox"
                                        checked={members
                                            .split(",")
                                            .includes(p.id)}
                                        onChange={(e) =>
                                            setMembers(
                                                e.target.checked
                                                    ? [
                                                          ...members
                                                              .split(",")
                                                              .filter(Boolean),
                                                          p.id,
                                                      ].join(",")
                                                    : members
                                                          .split(",")
                                                          .filter(
                                                              (id) =>
                                                                  id !== p.id,
                                                          )
                                                          .join(","),
                                            )
                                        }
                                    />
                                    {p.name}
                                    <span className="subtle">{p.email}</span>
                                </label>
                            ))}
                        </div>
                    </Field>
                    <label className="checkbox-label">
                        <input
                            type="checkbox"
                            checked={visible}
                            onChange={(e) => setVisible(e.target.checked)}
                        />
                        Allow explicitly invited clients in this conversation
                    </label>
                </div>
                <div className="dialog-footer">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={busy}>
                        Create conversation
                    </Button>
                </div>
            </form>
        </DetailPage>
    );
}
export function Notifications({
    records,
    reload,
    canManage = false,
}: {
    records: RecordData[];
    canManage?: boolean;
    reload: () => Promise<void>;
}) {
    const [filter, setFilter] = usePageState("notification-filter", "all", {
        page: false,
    });
    const [sort, setSort] = usePageState("notification-sort", "newest", {
        page: false,
    });
    const [error, setError] = useState("");
    const visible = records
        .filter(
            (r) =>
                filter === "all" ||
                (filter === "unread" && !r.read_at) ||
                (filter === "action_required" && r.action_required) ||
                r.category === filter,
        )
        .sort((a, b) =>
            sort === "priority"
                ? ({
                      error: 0,
                      urgent: 0,
                      warning: 1,
                      high: 1,
                      info: 2,
                      normal: 2,
                  }[a.severity as "urgent"] ?? 3) -
                  ({
                      error: 0,
                      urgent: 0,
                      warning: 1,
                      high: 1,
                      info: 2,
                      normal: 2,
                  }[b.severity as "urgent"] ?? 3)
                : sort === "due"
                  ? (a.due_at || "9999").localeCompare(b.due_at || "9999")
                  : (b.created_at || "").localeCompare(a.created_at || ""),
        );
    return (
        <>
            <Alert>{error}</Alert>
            <div className="module-toolbar">
                <DeliverySettings canManage={canManage} />
                <div className="segmented">
                    {["all", "unread"].map((value) => (
                        <button
                            key={value}
                            className={filter === value ? "selected" : ""}
                            onClick={() => setFilter(value)}
                        >
                            {value === "all" ? "All updates" : "Unread"}
                        </button>
                    ))}
                </div>
                <label className="inline-label">
                    Show
                    <select
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                    >
                        <option value="all">All categories</option>
                        <option value="unread">Unread</option>
                        <option value="action_required">Action required</option>
                        {[
                            ...new Set(
                                records.map((r) => r.category).filter(Boolean),
                            ),
                        ].map((category) => (
                            <option key={category} value={category}>
                                {category.replaceAll("_", " ")}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="inline-label">
                    Sort by
                    <select
                        value={sort}
                        onChange={(e) => setSort(e.target.value)}
                    >
                        <option value="newest">Newest first</option>
                        <option value="priority">Priority</option>
                        <option value="due">Due date</option>
                    </select>
                </label>
            </div>
            <div className="panel notifications-list">
                {visible.length ? (
                    visible.map((notification) => (
                        <article
                            key={notification.id}
                            className={`notification-row ${!notification.read_at ? "unread" : ""}`}
                        >
                            <span className="notification-symbol">
                                <Bell size={19} />
                            </span>
                            <div>
                                <div className="notification-title">
                                    <h3>
                                        {notification.title ||
                                            notification.type?.replaceAll(
                                                ".",
                                                " ",
                                            ) ||
                                            "Workspace update"}
                                    </h3>
                                    <Badge>
                                        {notification.severity ||
                                            notification.category ||
                                            "update"}
                                    </Badge>
                                </div>
                                <p>
                                    {notification.body ||
                                        notification.message ||
                                        notification.summary}
                                </p>
                                {notification.action_url && (
                                    <a
                                        className="text-link"
                                        href={notification.action_url}
                                    >
                                        Open update <ArrowUpRight size={14} />
                                    </a>
                                )}
                                <small>
                                    {dateLabel(notification.created_at)}
                                    {notification.due_at &&
                                        ` · Due ${dateLabel(notification.due_at)}`}
                                </small>
                            </div>
                            {!notification.read_at && (
                                <Button
                                    variant="ghost"
                                    onClick={async () => {
                                        try {
                                            await post(
                                                `/api/v1/notifications/${notification.id}/read`,
                                            );
                                            await reload();
                                        } catch (e) {
                                            setError((e as Error).message);
                                        }
                                    }}
                                >
                                    <Check size={15} />
                                    Mark read
                                </Button>
                            )}
                        </article>
                    ))
                ) : (
                    <Empty title="You’re all caught up">
                        New assignments, reviews and account updates will appear
                        here.
                    </Empty>
                )}
            </div>
        </>
    );
}
export function AiWorkspace({
    records,
    reload,
}: {
    records: RecordData[];
    reload: () => Promise<void>;
}) {
    const [create, setCreate] = usePageState("new-ai-request", false);
    const [kind, setKind] = usePageState("ai-kind", "summary", { page: false });
    const [selected, setSelected] = usePageState<RecordData | null>(
        "ai-record",
        null,
        {
            records,
            load: async (id) => (await api(`/api/v1/ai/runs/${id}`)).data,
        },
    );
    const [reviewNotes, setReviewNotes] = useState("");
    const [error, setError] = useState("");
    const tools = [
        {
            kind: "summary",
            name: "Document summary",
            text: "Find the important points with links back to the source.",
        },
        {
            kind: "chronology",
            name: "Build a chronology",
            text: "Arrange events into a timeline for your review.",
        },
        {
            kind: "draft",
            name: "Draft with context",
            text: "Turn selected facts and instructions into a working draft.",
        },
    ];
    return (
        <>
            <div className="ai-intro">
                <span className="ai-symbol">
                    <Sparkles size={25} />
                </span>
                <div>
                    <h2>A considered starting point.</h2>
                    <p>
                        Choose authorized source documents. Review the output
                        before using it in your legal work.
                    </p>
                </div>
            </div>
            <div className="ai-tools">
                {tools.map((tool) => (
                    <button
                        key={tool.kind}
                        onClick={() => {
                            setKind(tool.kind);
                            setCreate(true);
                        }}
                    >
                        <span>
                            <Sparkles size={19} />
                            <ArrowUpRight size={16} />
                        </span>
                        <h3>{tool.name}</h3>
                        <p>{tool.text}</p>
                        <strong>
                            Start a {tool.kind === "draft" ? "draft" : "review"}
                        </strong>
                    </button>
                ))}
            </div>
            <div className="section-space">
                <h2 className="section-heading">Recent work</h2>
                <DataTable
                    records={records}
                    onOpen={(r) => {
                        setSelected(r);
                        api(`/api/v1/ai/runs/${r.id}`)
                            .then((data) => setSelected(data.data))
                            .catch((e) => setError(e.message));
                    }}
                    columns={[
                        { key: "kind", label: "Task" },
                        {
                            key: "status",
                            label: "Status",
                            render: (r) => <Badge>{r.status}</Badge>,
                        },
                        { key: "instructions", label: "Instructions" },
                        {
                            key: "created_at",
                            label: "Created",
                            render: (r) => dateLabel(r.created_at),
                        },
                    ]}
                    emptyTitle="Start with a document"
                />
            </div>
            {create && (
                <AiForm
                    kind={kind}
                    onClose={() => setCreate(false)}
                    onSave={async (body) => {
                        const result = await post("/api/v1/ai/runs", body);
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
                    title="AI work record"
                    description="Source context, output and review status for this request."
                    onClose={() => setSelected(null)}
                >
                    <div className="dialog-body">
                        <Alert>{error}</Alert>
                        <Badge>{selected.status}</Badge>
                        <dl className="detail-grid">
                            <div>
                                <dt>Task</dt>
                                <dd>{selected.kind}</dd>
                            </div>
                            <div>
                                <dt>Created</dt>
                                <dd>{dateLabel(selected.created_at)}</dd>
                            </div>
                            <div>
                                <dt>Instructions</dt>
                                <dd>{selected.instructions}</dd>
                            </div>
                            <div>
                                <dt>Provider</dt>
                                <dd>
                                    {selected.provider || "Configured provider"}
                                </dd>
                            </div>
                        </dl>
                        {selected.error && <Alert>{selected.error}</Alert>}
                        {selected.result || selected.output ? (
                            <div className="ai-output">
                                {typeof (selected.result || selected.output) ===
                                "string"
                                    ? selected.result || selected.output
                                    : JSON.stringify(
                                          selected.result || selected.output,
                                          null,
                                          2,
                                      )}
                            </div>
                        ) : (
                            <p className="subtle">
                                Output will appear when the queued request
                                completes. Refresh the workspace to check its
                                status.
                            </p>
                        )}
                        {selected.status === "review" && (
                            <div className="form-stack section-space">
                                <Field label="Review notes">
                                    <textarea
                                        rows={3}
                                        value={reviewNotes}
                                        onChange={(e) =>
                                            setReviewNotes(e.target.value)
                                        }
                                    />
                                </Field>
                                <div className="button-row">
                                    {["approved", "rejected"].map(
                                        (decision) => (
                                            <Button
                                                key={decision}
                                                variant={
                                                    decision === "approved"
                                                        ? "primary"
                                                        : "secondary"
                                                }
                                                disabled={!reviewNotes.trim()}
                                                onClick={async () => {
                                                    try {
                                                        const r = await post(
                                                            `/api/v1/ai/runs/${selected.id}/review`,
                                                            {
                                                                expected_version:
                                                                    selected.version,
                                                                decision,
                                                                review_notes:
                                                                    reviewNotes,
                                                            },
                                                        );
                                                        setSelected(r.data);
                                                        await reload();
                                                    } catch (e) {
                                                        setError(
                                                            (e as Error)
                                                                .message,
                                                        );
                                                    }
                                                }}
                                            >
                                                {decision === "approved"
                                                    ? "Approve draft"
                                                    : "Reject draft"}
                                            </Button>
                                        ),
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </DetailPage>
            )}
        </>
    );
}
function AiForm({
    kind,
    onClose,
    onSave,
}: {
    kind: string;
    onClose: () => void;
    onSave: (body: any) => Promise<void>;
}) {
    const [documents, setDocuments] = useState<RecordData[]>([]);
    const [selected, setSelected] = useState<string[]>([]);
    const [instructions, setInstructions] = useState("");
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        api("/api/v1/documents")
            .then((r) =>
                setDocuments(
                    (r.data || []).filter(
                        (d: RecordData) =>
                            d.kind !== "upload" || d.status === "clean",
                    ),
                ),
            )
            .catch((e) => setError(e.message));
    }, []);
    return (
        <DetailPage
            open
            title={`New ${kind}`}
            description="Use only documents you are authorized to share with your configured AI provider."
            onClose={onClose}
        >
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    try {
                        await onSave({
                            kind,
                            document_ids: selected,
                            instructions,
                        });
                    } catch (e) {
                        setError((e as Error).message);
                        setBusy(false);
                    }
                }}
            >
                <div className="dialog-body form-stack">
                    <Alert>{error}</Alert>
                    <Field label="Source documents">
                        <div className="source-documents">
                            {documents.map((document) => (
                                <label
                                    className="checkbox-label"
                                    key={document.id}
                                >
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(document.id)}
                                        onChange={(e) =>
                                            setSelected(
                                                e.target.checked
                                                    ? [...selected, document.id]
                                                    : selected.filter(
                                                          (id) =>
                                                              id !==
                                                              document.id,
                                                      ),
                                            )
                                        }
                                    />
                                    {document.title || document.name}
                                </label>
                            ))}
                            {!documents.length && (
                                <p className="subtle">
                                    Add or upload a source document first.
                                </p>
                            )}
                        </div>
                    </Field>
                    <Field label="Instructions *">
                        <textarea
                            rows={5}
                            required
                            placeholder="Describe what you need, the jurisdiction and any known gaps…"
                            value={instructions}
                            onChange={(e) => setInstructions(e.target.value)}
                        />
                    </Field>
                    <p className="subtle">
                        An AI provider must be configured in Practice settings.
                        Generated output requires a lawyer’s review.
                    </p>
                </div>
                <div className="dialog-footer">
                    <Button variant="secondary" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={busy || !selected.length}>
                        {busy ? "Starting…" : "Queue request"}
                    </Button>
                </div>
            </form>
        </DetailPage>
    );
}
