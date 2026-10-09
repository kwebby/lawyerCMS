// Author: ramanpal singh | URL: https://kwebby.com
import { useEffect, useState } from "react";
import { Link } from "@inertiajs/react";
import { usePageState } from "../lib/navigation";
import {
    Settings2,
    Mail,
    Building2,
    Sparkles,
    ShieldCheck,
    CreditCard,
    Users,
    Activity,
    CheckCircle2,
    Globe2,
    Send,
} from "lucide-react";
import { api, patch, post } from "../lib/api";
import type { RecordData, User } from "../lib/types";
import { Alert, Badge, Button, DataTable, Field, DetailPage } from "./ui";
const sections = [
    ["business", "Business details", Building2],
    ["mail", "Email delivery", Mail],
    ["ai", "AI providers", Sparkles],
    ["payments", "Payment gateways", CreditCard],
    ["security", "Security & storage", ShieldCheck],
    ["team", "Team & invitations", Users],
    ["publishing", "Search & publishing", Globe2],
    ["health", "System health", Activity],
] as const;
const definitions: Record<
    string,
    {
        name: string;
        label: string;
        type?: string;
        hint?: string;
        options?: string[];
    }[]
> = {
    business: [
        { name: "legal_name", label: "Legal business name" },
        { name: "trading_name", label: "Trading name" },
        { name: "email", label: "Business email", type: "email" },
        { name: "phone", label: "Phone" },
        { name: "address", label: "Registered address", type: "textarea" },
        { name: "website", label: "Website URL" },
        { name: "tax_id", label: "Tax identification number" },
        { name: "registration_id", label: "Registration number" },
        { name: "signature", label: "Authorized signatory" },
        {
            name: "currency",
            label: "Default currency",
            options: ["USD", "INR", "GBP", "EUR", "CAD", "AUD", "AED", "JPY"],
        },
        { name: "invoice_prefix", label: "Invoice number prefix" },
        { name: "terms", label: "Payment terms", type: "textarea" },
        {
            name: "payment_instructions",
            label: "Bank and payment instructions",
            type: "textarea",
        },
    ],
    mail: [
        { name: "host", label: "SMTP host" },
        { name: "port", label: "SMTP port", type: "number" },
        { name: "encryption", label: "Encryption", options: ["tls", "ssl"] },
        { name: "username", label: "Username" },
        {
            name: "password",
            label: "Password",
            type: "password",
            hint: "Leave blank to keep the saved credential.",
        },
        { name: "from_address", label: "Sender email", type: "email" },
        { name: "from_name", label: "Sender name" },
        { name: "reply_to", label: "Reply-to email", type: "email" },
    ],
    ai: [
        {
            name: "provider",
            label: "Provider",
            options: [
                "openai",
                "anthropic",
                "gemini",
                "ollama",
                "openai-compatible",
            ],
        },
        { name: "model", label: "Model identifier" },
        {
            name: "api_key",
            label: "API key",
            type: "password",
            hint: "Leave blank to keep the saved key.",
        },
        {
            name: "endpoint",
            label: "Approved endpoint (optional)",
            hint: "Use a provider endpoint authorized by your administrator.",
        },
        {
            name: "daily_limit",
            label: "Maximum AI runs per day",
            type: "number",
        },
        { name: "jurisdiction", label: "Jurisdiction for public tools" },
    ],
    payments: [
        {
            name: "stripe_secret_key",
            label: "Stripe secret key",
            type: "password",
        },
        {
            name: "stripe_webhook_secret",
            label: "Stripe webhook signing secret",
            type: "password",
        },
        { name: "paypal_client_id", label: "PayPal client ID" },
        {
            name: "paypal_client_secret",
            label: "PayPal client secret",
            type: "password",
        },
        { name: "paypal_webhook_id", label: "PayPal webhook ID" },
        {
            name: "paypal_mode",
            label: "PayPal environment",
            options: ["sandbox", "live"],
        },
    ],
    security: [
        {
            name: "retention_days",
            label: "Document retention (days)",
            type: "number",
        },
        {
            name: "notification_digest",
            label: "Notification digest",
            options: ["off", "daily", "weekly"],
        },
    ],
    publishing: [
        { name: "name", label: "Site name" },
        { name: "url", label: "Canonical website URL" },
        {
            name: "description",
            label: "Default search description",
            type: "textarea",
        },
        { name: "locale", label: "Default language code" },
        {
            name: "google_verification",
            label: "Google Search Console verification",
        },
        { name: "bing_verification", label: "Bing verification" },
        { name: "jurisdiction", label: "Publishing jurisdiction" },
        { name: "default_author", label: "Default author" },
    ],
};
export function Settings({ initial, user }: { initial: any; user: User }) {
    const [section, setSection] = usePageState("tab", "business", {
        page: false,
    });
    const [settings, setSettings] = useState<any>(initial || {});
    const [values, setValues] = useState<any>(initial?.business || {});
    const [error, setError] = useState("");
    const [saved, setSaved] = useState("");
    const [busy, setBusy] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const [testEmail, setTestEmail] = useState(user.email);
    const [logos, setLogos] = useState<any[]>([]);
    useEffect(() => {
        api("/api/v1/workspace/documents")
            .then((r) =>
                setLogos(
                    (r.data || []).filter(
                        (d: any) =>
                            d.status === "clean" &&
                            !d.matter_id &&
                            ["image/png", "image/jpeg", "image/webp"].includes(
                                d.mime,
                            ),
                    ),
                ),
            )
            .catch(() => {});
    }, []);
    const [invite, setInvite] = usePageState("invite", false);
    const [members, setMembers] = useState<RecordData[]>([]);
    const [member, setMember] = usePageState<RecordData | null>(
        "member",
        null,
        {
            records: members,
            load: async (id) => {
                const result = await api("/api/v1/users");
                const found = result.data.find(
                    (entry: RecordData) => entry.id === id,
                );
                if (!found) throw new Error("This team member is unavailable.");
                return found;
            },
        },
    );
    const [roleEditor, setRoleEditor] = usePageState("roles", false);
    const [gateways, setGateways] = useState<any>(null);
    const managedIdentity = (name: string) =>
        section === "publishing" &&
        settings.publishing?.website_managed === true &&
        ["name", "email", "phone", "address"].includes(name);
    useEffect(() => {
        api("/api/v1/settings")
            .then((r) => {
                const data = r.data || r;
                setSettings(data);
                setLoaded(true);
            })
            .catch((e) => {
                setError(e.message);
                setLoaded(true);
            });
    }, []);
    function switchSection(key: string) {
        setSection(key);
        setError("");
        setSaved("");
    }
    useEffect(() => {
        if (!loaded) return;
        setValues(settings[section] || {});
        if (section === "payments")
            api("/api/v1/payment-gateways")
                .then((r) => setGateways(r.data))
                .catch((e) => setError(e.message));
        if (section === "team")
            api("/api/v1/users")
                .then((r) => setMembers(r.data || []))
                .catch((e) => setError(e.message));
        if (section === "publishing")
            api("/api/v1/publishing/settings")
                .then((r) => {
                    setValues(r.data?.site || {});
                    setSettings((s: any) => ({ ...s, publishing: r.data }));
                })
                .catch((e) => setError(e.message));
    }, [section, loaded]);
    async function save() {
        setBusy(true);
        setError("");
        setSaved("");
        try {
            const clean = Object.fromEntries(
                Object.entries(values).filter(
                    ([key, value]) =>
                        !(
                            [
                                "password",
                                "api_key",
                                "stripe_secret_key",
                                "stripe_webhook_secret",
                                "paypal_client_secret",
                                "scanner_token",
                            ].includes(key) && !value
                        ),
                ),
            );
            if (section === "publishing")
                await patch("/api/v1/publishing/settings", {
                    site: clean,
                    types: settings.publishing?.types || {},
                });
            else
                await patch("/api/v1/settings", {
                    section,
                    data:
                        section === "ai" ? { enabled: false, ...clean } : clean,
                });
            setSettings({
                ...settings,
                [section]:
                    section === "publishing"
                        ? { ...settings.publishing, site: clean }
                        : clean,
            });
            setSaved("Settings saved.");
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <div className="settings-layout">
            <nav className="settings-nav" aria-label="Settings sections">
                {sections.map(([key, label, Icon]) => (
                    <button
                        key={key}
                        className={section === key ? "active" : ""}
                        onClick={() => switchSection(key)}
                    >
                        <Icon size={17} />
                        {label}
                    </button>
                ))}
            </nav>
            <section className="settings-content">
                <div className="settings-heading">
                    <div>
                        <h2>{sections.find((s) => s[0] === section)?.[1]}</h2>
                        <p>
                            {section === "business"
                                ? "These details appear on invoices and business documents."
                                : section === "mail"
                                  ? "Configure an approved SMTP server for transactional email."
                                  : section === "ai"
                                    ? "Choose where authorized documents are processed."
                                    : section === "payments"
                                      ? "Configure your account and signed webhook delivery."
                                      : section === "security"
                                        ? "Set the services that protect your private files."
                                        : section === "publishing"
                                          ? "Set your website identity and default discovery settings."
                                          : section === "team"
                                            ? "Invite colleagues with the permissions they need."
                                            : "Installation capabilities and integration status."}
                        </p>
                    </div>
                </div>
                <Alert>{error}</Alert>
                <Alert kind="success">{saved}</Alert>
                {section === "publishing" && (
                    <div className="info-band">
                        <Globe2 size={20} />
                        <p>
                            {settings.publishing?.website_managed
                                ? "Public firm identity is managed through the reviewed website draft. "
                                : "Manage shared public identity and office records in Website settings. "}
                            <Link
                                className="text-link"
                                href="/app/website?v_website-tab=offices"
                            >
                                Edit firm and office details
                            </Link>
                        </p>
                    </div>
                )}
                {!loaded ? (
                    <p className="subtle">Loading settings…</p>
                ) : section === "health" ? (
                    <Health settings={settings} />
                ) : section === "payments" ? (
                    <PaymentConfiguration gateways={gateways} />
                ) : section === "team" ? (
                    <>
                        <div className="module-toolbar">
                            <span className="subtle">Team directory</span>
                            <div className="button-row">
                                <Button
                                    variant="secondary"
                                    onClick={() => setRoleEditor(true)}
                                >
                                    Role permissions
                                </Button>
                                <Button onClick={() => setInvite(true)}>
                                    Invite a colleague
                                </Button>
                            </div>
                        </div>
                        <DataTable
                            records={members}
                            onOpen={setMember}
                            columns={[
                                { key: "name", label: "Name" },
                                { key: "email", label: "Email" },
                                {
                                    key: "roles",
                                    label: "Role",
                                    render: (r) => (r.roles || []).join(", "),
                                },
                                {
                                    key: "status",
                                    label: "Status",
                                    render: (r) => (
                                        <Badge>{r.status || "active"}</Badge>
                                    ),
                                },
                            ]}
                            emptyTitle="Your team starts with an invitation"
                        />
                    </>
                ) : (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            void save();
                        }}
                    >
                        <div className="form-grid">
                            {definitions[section]?.map((field) => (
                                <Field
                                    key={field.name}
                                    label={field.label}
                                    hint={field.hint}
                                >
                                    {field.options ? (
                                        <select
                                            disabled={managedIdentity(
                                                field.name,
                                            )}
                                            value={values[field.name] || ""}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    [field.name]:
                                                        e.target.value,
                                                })
                                            }
                                        >
                                            <option value="">Select…</option>
                                            {field.options.map((o) => (
                                                <option key={o}>{o}</option>
                                            ))}
                                        </select>
                                    ) : field.type === "textarea" ? (
                                        <textarea
                                            disabled={managedIdentity(
                                                field.name,
                                            )}
                                            rows={3}
                                            value={values[field.name] || ""}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    [field.name]:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                    ) : (
                                        <input
                                            disabled={managedIdentity(
                                                field.name,
                                            )}
                                            type={field.type || "text"}
                                            autoComplete={
                                                field.type === "password"
                                                    ? "new-password"
                                                    : undefined
                                            }
                                            placeholder={
                                                field.type === "password" &&
                                                values[
                                                    `${field.name}_configured`
                                                ]
                                                    ? "Credential saved"
                                                    : ""
                                            }
                                            value={values[field.name] || ""}
                                            onChange={(e) =>
                                                setValues({
                                                    ...values,
                                                    [field.name]:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                    )}
                                </Field>
                            ))}
                        </div>
                        {section === "business" && (
                            <Field
                                label="Firm logo"
                                hint="Upload a standalone image in Documents and wait for security scanning before choosing it."
                            >
                                <select
                                    value={values.logo_file_id || ""}
                                    onChange={(e) =>
                                        setValues({
                                            ...values,
                                            logo_file_id:
                                                e.target.value || null,
                                        })
                                    }
                                >
                                    <option value="">No logo</option>
                                    {logos.map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.title || d.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        )}
                        {section === "ai" && (
                            <label className="checkbox-label setting-checkbox">
                                <input
                                    type="checkbox"
                                    checked={values.enabled === true}
                                    onChange={(e) =>
                                        setValues({
                                            ...values,
                                            enabled: e.target.checked,
                                        })
                                    }
                                />
                                Enable AI requests using this provider
                            </label>
                        )}
                        {section === "ai" && (
                            <label className="checkbox-label setting-checkbox">
                                <input
                                    type="checkbox"
                                    checked={
                                        values.public_tools_approved === true
                                    }
                                    onChange={(e) =>
                                        setValues({
                                            ...values,
                                            public_tools_approved:
                                                e.target.checked,
                                        })
                                    }
                                />
                                Jurisdiction review completed: enable public
                                lead tools
                            </label>
                        )}
                        {section === "security" && (
                            <p className="subtle setting-checkbox">
                                Staff MFA, private file encryption and malware
                                scanning are enforced by your installation
                                configuration. Health checks report scanner and
                                storage readiness.
                            </p>
                        )}
                        <div className="settings-save">
                            <span>Changes apply to this practice.</span>
                            <Button type="submit" disabled={busy}>
                                {busy ? "Saving…" : "Save changes"}
                            </Button>
                        </div>
                    </form>
                )}
                {section === "mail" && (
                    <div className="test-email">
                        <h3>Test email delivery</h3>
                        <p>
                            Send a test message to confirm your saved mail
                            settings.
                        </p>
                        <div className="button-row">
                            <input
                                aria-label="Test recipient email"
                                type="email"
                                value={user.email}
                                readOnly
                            />
                            <Button
                                variant="secondary"
                                disabled={busy || !testEmail}
                                onClick={async () => {
                                    setError("");
                                    try {
                                        await post(
                                            "/api/v1/settings/mail/test",
                                            { to: testEmail },
                                        );
                                        setSaved(
                                            "Test delivery queued. Check the recipient inbox and delivery logs.",
                                        );
                                    } catch (e) {
                                        setError((e as Error).message);
                                    }
                                }}
                            >
                                <Send size={15} />
                                Send test
                            </Button>
                        </div>
                    </div>
                )}
                {section === "payments" && (
                    <div className="info-band">
                        <ShieldCheck size={20} />
                        <p>
                            Payments are confirmed from signed provider events.
                            Start with sandbox credentials and verify your
                            webhook endpoint before accepting live payments.
                        </p>
                    </div>
                )}
            </section>
            {invite && (
                <InvitationForm
                    onClose={() => setInvite(false)}
                    onSave={async (body) => {
                        await post("/api/v1/invitations", body);
                        setInvite(false);
                        setSaved("Invitation created and delivery queued.");
                    }}
                />
            )}
            {member && (
                <MemberEditor
                    user={member}
                    onClose={() => setMember(null)}
                    onSave={async (data) => {
                        await patch(`/api/v1/users/${member.id}`, data);
                        setMembers((await api("/api/v1/users")).data);
                        setMember(null);
                        setSaved("Team access updated.");
                    }}
                />
            )}
            {roleEditor && <RoleEditor onClose={() => setRoleEditor(false)} />}
        </div>
    );
}
function Health({ settings }: { settings: any }) {
    const health = settings.health || {};
    return (
        <div className="health-list">
            {Object.keys(health).length ? (
                Object.entries(health).map(([key, value]) => (
                    <div key={key}>
                        <span>{key.replaceAll("_", " ")}</span>
                        <Badge
                            tone={
                                value === true ||
                                [
                                    "healthy",
                                    "ready",
                                    "ok",
                                    "configured",
                                ].includes(String(value))
                                    ? "green"
                                    : "neutral"
                            }
                        >
                            {typeof value === "object"
                                ? JSON.stringify(value)
                                : String(value)}
                        </Badge>
                    </div>
                ))
            ) : (
                <div className="status-summary">
                    <Activity size={25} />
                    <div>
                        <strong>No health report available</strong>
                        <p>
                            Check the installation health command and scheduled
                            cron worker.
                        </p>
                    </div>
                </div>
            )}
            <p className="subtle">
                Managed database certification and an independent penetration
                test are release checks performed against your deployed
                installation.
            </p>
        </div>
    );
}
function InvitationForm({
    onClose,
    onSave,
}: {
    onClose: () => void;
    onSave: (body: any) => Promise<void>;
}) {
    const [email, setEmail] = useState("");
    const [role, setRole] = useState("lawyer");
    const [name, setName] = useState("");
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    return (
        <DetailPage
            open
            title="Invite a colleague"
            description="Staff access begins with an expiring invitation."
            onClose={onClose}
        >
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    try {
                        await onSave({ email, name, roles: [role] });
                    } catch (e) {
                        setError((e as Error).message);
                        setBusy(false);
                    }
                }}
            >
                <div className="dialog-body form-stack">
                    <Alert>{error}</Alert>
                    <Field label="Name">
                        <input
                            required
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                        />
                    </Field>
                    <Field label="Email">
                        <input
                            required
                            type="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                        />
                    </Field>
                    <Field label="Role">
                        <select
                            value={role}
                            onChange={(e) => setRole(e.target.value)}
                        >
                            {[
                                "partner",
                                "lawyer",
                                "paralegal",
                                "intake",
                                "accounts",
                                "hr",
                                "content",
                                "collaborator",
                            ].map((r) => (
                                <option key={r}>{r}</option>
                            ))}
                        </select>
                    </Field>
                </div>
                <div className="dialog-footer">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={busy}>
                        Create invitation
                    </Button>
                </div>
            </form>
        </DetailPage>
    );
}
function PaymentConfiguration({ gateways }: { gateways: any }) {
    const values = Array.isArray(gateways)
        ? gateways
        : gateways
          ? Object.entries(gateways).map(([provider, data]) => ({
                provider,
                ...(typeof data === "object"
                    ? (data as object)
                    : { configured: !!data }),
            }))
          : [];
    return (
        <div className="form-stack">
            {values.map((gateway: any, index: number) => (
                <div className="gateway-status" key={gateway.provider || index}>
                    <div>
                        <CreditCard size={22} />
                        <strong>
                            {gateway.provider ||
                                gateway.name ||
                                "Payment gateway"}
                        </strong>
                    </div>
                    <Badge>
                        {gateway.configured ? "Configured" : "Not configured"}
                    </Badge>
                </div>
            ))}
            <p className="subtle">
                Payment keys are configured on your server by the installation
                administrator. This keeps provider credentials outside the
                public website and theme system.
            </p>
            <div className="configuration-guide">
                <h3>Stripe</h3>
                <p>
                    Set <code>STRIPE_SECRET</code> and{" "}
                    <code>STRIPE_WEBHOOK_SECRET</code>.
                </p>
                <h3>PayPal</h3>
                <p>
                    Set <code>PAYPAL_CLIENT_ID</code>,{" "}
                    <code>PAYPAL_SECRET</code> and{" "}
                    <code>PAYPAL_WEBHOOK_ID</code> and{" "}
                    <code>PAYPAL_MERCHANT_ID</code>. Use sandbox mode for
                    verification.
                </p>
                <h3>Signed callback URLs</h3>
                <p>
                    <code>
                        {window.location.origin}/api/v1/payments/webhooks/stripe
                    </code>
                    <br />
                    <code>
                        {window.location.origin}/api/v1/payments/webhooks/paypal
                    </code>
                </p>
            </div>
        </div>
    );
}
function MemberEditor({
    user,
    onClose,
    onSave,
}: {
    user: RecordData;
    onClose: () => void;
    onSave: (data: any) => Promise<void>;
}) {
    const [role, setRole] = useState(user.roles?.[0] || "lawyer");
    const [status, setStatus] = useState(user.status || "active");
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    return (
        <DetailPage
            open
            title={`Access for ${user.name}`}
            description="Changing access revokes existing sessions for this user."
            onClose={onClose}
        >
            <form
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    try {
                        await onSave({ roles: [role], status });
                    } catch (e) {
                        setError((e as Error).message);
                        setBusy(false);
                    }
                }}
            >
                <div className="dialog-body form-stack">
                    <Alert>{error}</Alert>
                    <Field label="Role">
                        <select
                            value={role}
                            onChange={(e) => setRole(e.target.value)}
                        >
                            {[
                                "owner",
                                "admin",
                                "partner",
                                "lawyer",
                                "paralegal",
                                "intake",
                                "accounts",
                                "hr",
                                "content",
                                "client",
                                "collaborator",
                            ].map((r) => (
                                <option key={r}>{r}</option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Account access">
                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                        >
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </Field>
                </div>
                <div className="dialog-footer">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={busy}>
                        Save access
                    </Button>
                </div>
            </form>
        </DetailPage>
    );
}
function RoleEditor({ onClose }: { onClose: () => void }) {
    const [roles, setRoles] = useState<any>({});
    const [overrides, setOverrides] = useState<any[]>([]);
    const [role, setRole] = usePageState("role", "lawyer", { page: false });
    const [permissions, setPermissions] = useState<Record<string, string>>({});
    const [ability, setAbility] = useState("matters.read");
    const [error, setError] = useState("");
    const [saved, setSaved] = useState("");
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        api("/api/v1/roles")
            .then((r) => {
                setRoles(r.data);
                setOverrides(r.overrides || []);
                setPermissions(
                    r.overrides?.find((o: any) => o.id === role)?.permissions ||
                        r.data[role] ||
                        {},
                );
            })
            .catch((e) => setError(e.message));
    }, []);
    useEffect(() => {
        setPermissions(
            overrides.find((entry) => entry.id === role)?.permissions ||
                roles[role] ||
                {},
        );
    }, [role, overrides, roles]);
    return (
        <DetailPage
            open
            wide
            title="Role permissions"
            description="Set the actions and record scope available to each staff role."
            onClose={onClose}
        >
            <div className="dialog-body form-stack">
                <Alert>{error}</Alert>
                <Alert kind="success">{saved}</Alert>
                <Field label="Staff role">
                    <select
                        value={role}
                        onChange={(e) => {
                            setRole(e.target.value);
                            setPermissions(
                                overrides.find((o) => o.id === e.target.value)
                                    ?.permissions ||
                                    roles[e.target.value] ||
                                    {},
                            );
                            setSaved("");
                        }}
                    >
                        {[
                            "partner",
                            "lawyer",
                            "paralegal",
                            "intake",
                            "accounts",
                            "hr",
                            "content",
                            "collaborator",
                        ].map((r) => (
                            <option key={r}>{r}</option>
                        ))}
                    </select>
                </Field>
                <div className="permission-grid">
                    {Object.entries(permissions).map(([action, scope]) => (
                        <div className="permission-row" key={action}>
                            <label htmlFor={`scope-${action}`}>{action}</label>
                            <select
                                id={`scope-${action}`}
                                value={scope}
                                onChange={(e) => {
                                    if (e.target.value === "none") {
                                        const next = { ...permissions };
                                        delete next[action];
                                        setPermissions(next);
                                    } else
                                        setPermissions({
                                            ...permissions,
                                            [action]: e.target.value,
                                        });
                                }}
                            >
                                <option value="assigned">
                                    Assigned records
                                </option>
                                <option value="team">Team records</option>
                                <option value="firm">All firm records</option>
                                <option value="none">Remove permission</option>
                            </select>
                        </div>
                    ))}
                </div>
                <div className="button-row">
                    <input
                        aria-label="Permission to add"
                        placeholder="documents.read"
                        value={ability}
                        onChange={(e) => setAbility(e.target.value)}
                    />
                    <Button
                        variant="secondary"
                        onClick={() =>
                            setPermissions({
                                ...permissions,
                                [ability]: "assigned",
                            })
                        }
                    >
                        Add permission
                    </Button>
                </div>
            </div>
            <div className="dialog-footer">
                <Button variant="secondary" onClick={onClose}>
                    Close
                </Button>
                <Button
                    disabled={busy}
                    onClick={async () => {
                        setBusy(true);
                        try {
                            const r = await post("/api/v1/roles", {
                                name: role,
                                permissions,
                            });
                            setOverrides([
                                ...overrides.filter((o) => o.id !== role),
                                r.data,
                            ]);
                            setSaved("Role permissions saved.");
                        } catch (e) {
                            setError((e as Error).message);
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    Save permissions
                </Button>
            </div>
        </DetailPage>
    );
}
