// Author: ramanpal singh | URL: https://kwebby.com
import { useEffect, useState } from "react";
import { usePageState } from "../lib/navigation";
import { api, patch, post } from "../lib/api";
import { Alert, Button, Field, DetailPage } from "./ui";

export function DeliverySettings({
    canManage = false,
}: {
    canManage?: boolean;
}) {
    const [open, setOpen] = usePageState("delivery", false);
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState("");
    const [preferences, setPreferences] = useState<any>({
        locale: "en",
        digest_hour: 8,
        channels: {},
    });
    const [categories, setCategories] = useState<string[]>([]);
    const [templates, setTemplates] = useState<any>(null);
    const [key, setKey] = usePageState("template", "notification", {
        page: false,
    });
    const [locale, setLocale] = usePageState("language", "en", { page: false });
    const [subject, setSubject] = useState("");
    const [body, setBody] = useState("");
    useEffect(() => {
        if (!open) return;
        api("/api/v1/notification-preferences")
            .then((r) => {
                setPreferences(r.data);
                setCategories(r.categories);
            })
            .catch((e) => setError(e.message));
        if (canManage)
            api("/api/v1/email-templates")
                .then((r) => setTemplates(r.data))
                .catch((e) => setError(e.message));
    }, [open]);
    useEffect(() => {
        if (!templates) return;
        const t =
            templates.overrides.find(
                (t: any) => t.key === key && t.locale === locale,
            ) || templates.defaults[key];
        setSubject(t?.subject || "");
        setBody(t?.body || "");
    }, [key, locale, templates]);
    return (
        <>
            <Button variant="secondary" onClick={() => setOpen(true)}>
                Delivery preferences
            </Button>
            {open && (
                <DetailPage
                    open
                    wide
                    title="Notification delivery"
                    description="Email contains a secure workspace link. Matter details stay inside your account."
                    onClose={() => setOpen(false)}
                >
                    <div className="dialog-body form-stack">
                        <Alert>{error}</Alert>
                        {saved && <p role="status">{saved}</p>}
                        <div className="form-grid">
                            <Field label="Email language">
                                <input
                                    value={preferences.locale}
                                    onChange={(e) =>
                                        setPreferences({
                                            ...preferences,
                                            locale: e.target.value,
                                        })
                                    }
                                    placeholder="en or en-GB"
                                />
                            </Field>
                            <Field label="Daily digest hour (UTC)">
                                <input
                                    type="number"
                                    min="0"
                                    max="23"
                                    value={preferences.digest_hour}
                                    onChange={(e) =>
                                        setPreferences({
                                            ...preferences,
                                            digest_hour: Number(e.target.value),
                                        })
                                    }
                                />
                            </Field>
                        </div>
                        <div className="form-grid">
                            {categories.map((category) => (
                                <Field
                                    key={category}
                                    label={category.replaceAll("_", " ")}
                                >
                                    <select
                                        value={
                                            preferences.channels[category] ||
                                            "off"
                                        }
                                        onChange={(e) =>
                                            setPreferences({
                                                ...preferences,
                                                channels: {
                                                    ...preferences.channels,
                                                    [category]: e.target.value,
                                                },
                                            })
                                        }
                                    >
                                        <option value="off">In-app only</option>
                                        <option value="immediate">
                                            In-app and email
                                        </option>
                                        <option value="daily">
                                            In-app and daily digest
                                        </option>
                                    </select>
                                </Field>
                            ))}
                        </div>
                        <Button
                            disabled={busy}
                            onClick={async () => {
                                setBusy(true);
                                setError("");
                                try {
                                    await patch(
                                        "/api/v1/notification-preferences",
                                        preferences,
                                    );
                                    setSaved(
                                        "Your delivery preferences were saved.",
                                    );
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            Save preferences
                        </Button>
                        {canManage && templates && (
                            <section className="form-stack">
                                <h3>Practice email templates</h3>
                                <p className="subtle">
                                    Plain text templates. Available variables:{" "}
                                    {"{{firm_name}}, {{action_url}}, {{count}}"}
                                    . Add a translation using its language code.
                                </p>
                                <div className="form-grid">
                                    <Field label="Template">
                                        <select
                                            value={key}
                                            onChange={(e) =>
                                                setKey(e.target.value)
                                            }
                                        >
                                            <option value="notification">
                                                Workspace notification
                                            </option>
                                            <option value="digest">
                                                Daily digest
                                            </option>
                                        </select>
                                    </Field>
                                    <Field label="Template language">
                                        <input
                                            value={locale}
                                            onChange={(e) =>
                                                setLocale(e.target.value)
                                            }
                                        />
                                    </Field>
                                </div>
                                <Field label="Subject">
                                    <input
                                        value={subject}
                                        onChange={(e) =>
                                            setSubject(e.target.value)
                                        }
                                    />
                                </Field>
                                <Field label="Message">
                                    <textarea
                                        rows={6}
                                        value={body}
                                        onChange={(e) =>
                                            setBody(e.target.value)
                                        }
                                    />
                                </Field>
                                <Button
                                    disabled={busy}
                                    onClick={async () => {
                                        setBusy(true);
                                        setError("");
                                        try {
                                            await post(
                                                "/api/v1/email-templates",
                                                { key, locale, subject, body },
                                            );
                                            setTemplates(
                                                (
                                                    await api(
                                                        "/api/v1/email-templates",
                                                    )
                                                ).data,
                                            );
                                            setSaved(
                                                "Template saved. New delivery jobs use this version.",
                                            );
                                        } catch (e) {
                                            setError((e as Error).message);
                                        } finally {
                                            setBusy(false);
                                        }
                                    }}
                                >
                                    Save template
                                </Button>
                            </section>
                        )}
                    </div>
                </DetailPage>
            )}
        </>
    );
}
