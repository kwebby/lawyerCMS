// Author: ramanpal singh | URL: https://kwebby.com
import { useState, type ReactNode } from "react";
import { Link, router } from "@inertiajs/react";
import {
    Scale,
    LayoutDashboard,
    Users,
    BriefcaseBusiness,
    CalendarDays,
    Files,
    MessagesSquare,
    ReceiptText,
    WalletCards,
    Globe2,
    Palette,
    Settings2,
    Sparkles,
    Bell,
    Search,
    LogOut,
    Menu,
    X,
    ChevronDown,
    ArrowUpRight,
    CircleHelp,
    ContactRound,
} from "lucide-react";
import type { User } from "../lib/types";
import { WorkspacePageOutlet } from "../lib/navigation";
import { initials } from "../lib/api";
const groups = [
    {
        label: "Workspace",
        items: [
            ["dashboard", "Overview", LayoutDashboard],
            ["leads", "Intake & leads", Users],
            ["contacts", "Contacts", ContactRound],
            ["matters", "Matters", BriefcaseBusiness],
            ["tasks", "Tasks & calendar", CalendarDays],
            ["documents", "Documents", Files],
            ["chat", "Messages", MessagesSquare],
        ],
    },
    {
        label: "Business",
        items: [
            ["invoices", "Billing & invoices", ReceiptText],
            ["payroll", "People & payroll", WalletCards],
        ],
    },
    {
        label: "Publishing",
        items: [
            ["website", "Website settings", Palette],
            ["pages", "Website & content", Globe2],
            ["themes", "Theme studio", Palette],
            ["ai", "AI workspace", Sparkles],
        ],
    },
] as const;
const titles: Record<string, [string, string]> = {
    dashboard: [
        "Your practice, at a glance.",
        "A clear view of the work that needs you today.",
    ],
    leads: ["Intake & leads", "Move every inquiry toward a clear next step."],
    contacts: [
        "Contacts",
        "The people and organizations connected to your practice.",
    ],
    matters: ["Matters", "All the details. The right people. One place."],
    tasks: ["Tasks & calendar", "Stay ahead of the dates that matter."],
    documents: ["Documents", "Write, review and share work with confidence."],
    chat: ["Messages", "Keep conversations connected to the work."],
    invoices: ["Billing & invoices", "From recorded work to a clear account."],
    payroll: [
        "People & payroll",
        "Manage your team’s pay with a traceable approval flow.",
    ],
    pages: [
        "Website & content",
        "Publish considered advice, built for discovery.",
    ],
    themes: ["Theme studio", "Make your public website feel like your firm."],
    website: [
        "Website settings",
        "Shape your homepage, brand and office information.",
    ],
    ai: [
        "AI workspace",
        "Source-led assistance, with your judgment in control.",
    ],
    settings: [
        "Practice settings",
        "Configure the systems that support your work.",
    ],
    notifications: [
        "Your inbox",
        "Updates, decisions and reminders in one place.",
    ],
};
export function Shell({
    user,
    section,
    unread = 0,
    children,
    actions,
    firmName,
}: {
    firmName?: string;
    user: User;
    section: string;
    unread?: number;
    children: ReactNode;
    actions?: ReactNode;
}) {
    const [mobile, setMobile] = useState(false);
    const client =
        user.roles?.includes("client") || user.roles?.includes("prospect");
    const prefix = client ? "/portal" : "/app";
    const [title, subtitle] = titles[section] || ["Workspace", ""];
    const allowed = (key: string) =>
        !client ||
        [
            "dashboard",
            "matters",
            "tasks",
            "documents",
            "chat",
            "invoices",
        ].includes(key);
    return (
        <div className="app-shell">
            <a className="skip-link" href="#main">
                Skip to content
            </a>
            {mobile && (
                <button
                    className="nav-scrim"
                    onClick={() => setMobile(false)}
                    aria-label="Close navigation"
                />
            )}
            <aside className={`sidebar ${mobile ? "is-open" : ""}`}>
                <Link href={`${prefix}/dashboard`} className="brand">
                    <span className="brand-mark">
                        <Scale size={24} strokeWidth={1.4} />
                    </span>
                    <span>LawyerCMS</span>
                </Link>
                <div className="firm-switch">
                    <span className="firm-monogram">
                        {initials(firmName || "Your practice")}
                    </span>
                    <div>
                        <strong>
                            {client
                                ? "Client workspace"
                                : firmName || "Your practice"}
                        </strong>
                        <small>
                            {client
                                ? "Private client portal"
                                : "Practice management"}
                        </small>
                    </div>
                    <ChevronDown size={15} />
                </div>
                <nav aria-label="Main navigation">
                    {groups.map((group) => {
                        const items = group.items.filter(([key]) =>
                            allowed(key),
                        );
                        return (
                            items.length > 0 && (
                                <div className="nav-group" key={group.label}>
                                    <p>{group.label}</p>
                                    {items.map(([key, label, Icon]) => (
                                        <Link
                                            key={key}
                                            href={`${prefix}/${key}`}
                                            onClick={() => setMobile(false)}
                                            className={`nav-link ${section === key ? "active" : ""}`}
                                            aria-current={
                                                section === key
                                                    ? "page"
                                                    : undefined
                                            }
                                        >
                                            <Icon size={18} strokeWidth={1.6} />
                                            <span>{label}</span>
                                        </Link>
                                    ))}
                                </div>
                            )
                        );
                    })}
                </nav>
                <div className="sidebar-bottom">
                    <Link
                        href={`${prefix}/notifications`}
                        className={`nav-link ${section === "notifications" ? "active" : ""}`}
                    >
                        <Bell size={18} strokeWidth={1.6} />
                        <span>Notifications</span>
                        {unread > 0 && (
                            <span className="nav-count">{unread}</span>
                        )}
                    </Link>
                    {!client && (
                        <Link
                            href="/app/settings"
                            className={`nav-link ${section === "settings" ? "active" : ""}`}
                        >
                            <Settings2 size={18} strokeWidth={1.6} />
                            <span>Settings</span>
                        </Link>
                    )}
                    <div className="sidebar-user">
                        <span className="avatar">{initials(user.name)}</span>
                        <div>
                            <strong>{user.name}</strong>
                            <small>
                                {(user.roles?.[0] || "Member").replaceAll(
                                    "_",
                                    " ",
                                )}
                            </small>
                        </div>
                        <button
                            className="icon-button"
                            aria-label="Sign out"
                            onClick={() => router.post("/logout")}
                        >
                            <LogOut size={17} />
                        </button>
                    </div>
                </div>
            </aside>
            <div className="main-shell">
                <header className="topbar">
                    <div className="breadcrumb">
                        <button
                            className="icon-button mobile-only"
                            onClick={() => setMobile(true)}
                            aria-label="Open navigation"
                        >
                            <Menu size={22} />
                        </button>
                        <span>{client ? "Client portal" : "Practice"}</span>
                        <span className="breadcrumb-slash">/</span>
                        <strong>
                            {section === "dashboard" ? "Overview" : title}
                        </strong>
                    </div>
                    <div className="topbar-actions">
                        <a
                            href="/"
                            target="_blank"
                            rel="noreferrer"
                            className="text-link visit-website"
                        >
                            View website <ArrowUpRight size={14} />
                        </a>
                        <Link
                            href={`${prefix}/notifications`}
                            className="notification-button"
                            aria-label={`Notifications${unread ? `, ${unread} unread` : ""}`}
                        >
                            <Bell size={19} />
                            {unread > 0 && <i />}
                        </Link>
                        <span className="avatar avatar-light">
                            {initials(user.name)}
                        </span>
                    </div>
                </header>
                <main id="main">
                    <WorkspacePageOutlet>
                        <div className="page-heading">
                            <div>
                                {section === "dashboard" && (
                                    <p className="greeting">
                                        {new Date().toLocaleDateString(
                                            undefined,
                                            {
                                                weekday: "long",
                                                month: "long",
                                                day: "numeric",
                                            },
                                        )}
                                    </p>
                                )}
                                <h1>
                                    {client && section === "dashboard"
                                        ? "Welcome to your client workspace."
                                        : title}
                                </h1>
                                <p>
                                    {client && section === "dashboard"
                                        ? "Follow your matters, share documents and keep in touch with your team."
                                        : subtitle}
                                </p>
                            </div>
                            {actions && (
                                <div className="heading-actions">{actions}</div>
                            )}
                        </div>
                        {children}
                    </WorkspacePageOutlet>
                    <footer className="workspace-footer">
                        <span>LawyerCMS · Your practice, connected.</span>
                        <span>
                            <span className="status-dot" />
                            Private workspace
                        </span>
                    </footer>
                </main>
            </div>
        </div>
    );
}
