// Author: ramanpal singh | URL: https://kwebby.com
import { useCallback, useEffect, useRef, useState } from "react";
import { Head, Link } from "@inertiajs/react";
import { RefreshCw, Plus, ShieldCheck } from "lucide-react";
import type { RecordData, WorkspaceProps } from "../lib/types";
import { api, post, setConfirmationHandler } from "../lib/api";
import { Shell } from "../Components/Shell";
import { Dashboard, Operations } from "../Components/Operations";
import { Documents, Pages, Themes } from "../Components/Publishing";
import { Invoices, Payroll } from "../Components/Finance";
import { Chat, Notifications, AiWorkspace } from "../Components/Communications";
import { Settings } from "../Components/Settings";
import { Website } from "../Components/Website";
import { Alert, Button, Field, DetailPage } from "../Components/ui";
import { WorkspacePages, usePageState } from "../lib/navigation";
export default function Workspace(props: WorkspaceProps) {
    return (
        <WorkspacePages>
            <WorkspaceContent {...props} />
        </WorkspacePages>
    );
}
function WorkspaceContent(props: WorkspaceProps) {
    const section = props.section || "dashboard";
    const [records, setRecords] = useState<RecordData[]>(
        Array.isArray(props.records) ? props.records : [],
    );
    const [stats, setStats] = useState(props.stats || {});
    const [settings, setSettings] = useState(props.settings || {});
    const [error, setError] = useState("");
    const [loading, setLoading] = useState(false);
    const [confirmation, setConfirmation] = usePageState(
        "confirm-identity",
        false,
    );
    const [password, setPassword] = useState("");
    const [confirmationError, setConfirmationError] = useState("");
    const [confirming, setConfirming] = useState(false);
    const confirmPromise = useRef<{
        resolve: () => void;
        reject: (error: Error) => void;
    } | null>(null);
    const user = props.user;
    const client =
        user.roles.includes("client") || user.roles.includes("prospect");
    useEffect(() => {
        setRecords(Array.isArray(props.records) ? props.records : []);
        setStats(props.stats || {});
        setSettings(props.settings || {});
        setError("");
    }, [props.records, section]);
    useEffect(() => {
        setConfirmationHandler(
            () =>
                new Promise<void>((resolve, reject) => {
                    if (confirmPromise.current) {
                        reject(
                            new Error(
                                "Please finish the current confirmation first.",
                            ),
                        );
                        return;
                    }
                    confirmPromise.current = { resolve, reject };
                    setPassword("");
                    setConfirmationError("");
                    setConfirmation(true);
                }),
        );
        return () => {
            setConfirmationHandler(null);
            confirmPromise.current?.reject(
                new Error("The confirmation was closed."),
            );
        };
    }, []);
    useEffect(() => {
        if (!confirmation && confirmPromise.current) {
            confirmPromise.current.reject(
                new Error("Password confirmation was cancelled."),
            );
            confirmPromise.current = null;
        }
    }, [confirmation]);
    const reload = useCallback(async () => {
        setLoading(true);
        try {
            const data = await api(`/api/v1/workspace/${section}`);
            setRecords(Array.isArray(data.data) ? data.data : []);
            if (data.stats) setStats(data.stats);
            if (data.settings) setSettings(data.settings);
            setError("");
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setLoading(false);
        }
    }, [section]);
    const closeConfirmation = () => {
        setConfirmation(false);
        confirmPromise.current?.reject(
            new Error("Password confirmation was cancelled."),
        );
        confirmPromise.current = null;
    };
    return (
        <>
            <Head title={section.charAt(0).toUpperCase() + section.slice(1)} />
            <Shell
                firmName={
                    settings.business?.trading_name ||
                    settings.business?.legal_name
                }
                user={user}
                section={section}
                unread={
                    (props.notifications || []).filter((n) => !n.read_at).length
                }
                actions={
                    <>
                        <Button
                            variant="secondary"
                            disabled={loading}
                            onClick={reload}
                            aria-label="Refresh workspace"
                        >
                            <RefreshCw
                                size={15}
                                className={loading ? "spin" : ""}
                            />
                            <span className="refresh-label">Refresh</span>
                        </Button>
                        {section === "dashboard" && !client && (
                            <Link
                                className="button button-primary"
                                href="/app/leads"
                            >
                                <Plus size={16} />
                                New inquiry
                            </Link>
                        )}
                    </>
                }
            >
                <Alert>{error}</Alert>
                {section === "dashboard" ? (
                    <Dashboard records={records} stats={stats} user={user} />
                ) : ["leads", "contacts", "matters", "tasks"].includes(
                      section,
                  ) ? (
                    <Operations
                        key={section}
                        section={section}
                        records={records}
                        reload={reload}
                        user={user}
                    />
                ) : section === "documents" ? (
                    <Documents
                        client={client}
                        records={records}
                        reload={reload}
                    />
                ) : section === "chat" ? (
                    <Chat user={user} />
                ) : section === "invoices" ? (
                    <Invoices
                        records={records}
                        reload={reload}
                        client={client}
                    />
                ) : section === "payroll" ? (
                    <Payroll
                        canManage={user.roles.some((r) =>
                            ["owner", "admin", "hr"].includes(r),
                        )}
                        records={records}
                        reload={reload}
                    />
                ) : section === "website" ? (
                    <Website
                        canManageFonts={user.roles.some((role) =>
                            ["owner", "admin"].includes(role),
                        )}
                    />
                ) : section === "pages" ? (
                    <Pages records={records} reload={reload} />
                ) : section === "themes" ? (
                    <Themes records={records} reload={reload} />
                ) : section === "ai" ? (
                    <AiWorkspace records={records} reload={reload} />
                ) : section === "notifications" ? (
                    <Notifications
                        records={records}
                        reload={reload}
                        canManage={user.roles.some((r) =>
                            ["owner", "admin"].includes(r),
                        )}
                    />
                ) : section === "settings" ? (
                    <Settings initial={settings} user={user} />
                ) : (
                    <Alert>This workspace section is unavailable.</Alert>
                )}
            </Shell>
            {confirmation && (
                <DetailPage
                    open
                    priority={100}
                    title="Confirm it’s you"
                    description="Enter your password to continue with this sensitive action."
                    onClose={closeConfirmation}
                >
                    <form
                        onSubmit={async (event) => {
                            event.preventDefault();
                            setConfirming(true);
                            setConfirmationError("");
                            try {
                                await post("/api/v1/auth/confirm", {
                                    password,
                                });
                                setConfirmation(false);
                                confirmPromise.current?.resolve();
                                confirmPromise.current = null;
                                setPassword("");
                            } catch (e) {
                                setConfirmationError((e as Error).message);
                            } finally {
                                setConfirming(false);
                            }
                        }}
                    >
                        <div className="dialog-body form-stack">
                            <Alert>{confirmationError}</Alert>
                            <Field label="Your password">
                                <input
                                    autoFocus
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                    value={password}
                                    onChange={(e) =>
                                        setPassword(e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                        <div className="dialog-footer">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={closeConfirmation}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={confirming}>
                                <ShieldCheck size={16} />
                                {confirming
                                    ? "Checking…"
                                    : "Confirm and continue"}
                            </Button>
                        </div>
                    </form>
                </DetailPage>
            )}
        </>
    );
}
