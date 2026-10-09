// Author: ramanpal singh | URL: https://kwebby.com
import { useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import { Scale, ArrowUpRight, ShieldCheck } from "lucide-react";
import { Alert, Button, Field } from "../../Components/ui";
export function AuthLayout({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <div className="auth-shell">
            <Head title={title} />
            <aside className="auth-brand">
                <a className="brand" href="/">
                    <span className="brand-mark">
                        <Scale size={26} strokeWidth={1.4} />
                    </span>
                    <span>LawyerCMS</span>
                </a>
                <div>
                    <h1>
                        More clarity.
                        <br />
                        Better counsel.
                    </h1>
                    <p>
                        Bring your people, matters and knowledge into one
                        considered workspace.
                    </p>
                </div>
                <span>
                    <ShieldCheck size={17} />A private place for your practice.
                </span>
            </aside>
            <main className="auth-main">
                <div className="auth-form">
                    <a className="auth-mobile-brand" href="/">
                        LawyerCMS
                    </a>
                    <h2>{title}</h2>
                    <p>{description}</p>
                    {children}
                </div>
                <footer>LawyerCMS · Your practice, connected.</footer>
            </main>
        </div>
    );
}
export default function AuthForm({
    mode,
}: {
    mode: "login" | "register" | "setup";
}) {
    const page = usePage<any>();
    const [values, setValues] = useState<any>({
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
        firm_name: "",
        bootstrap_token: "",
        remember: true,
    });
    const [busy, setBusy] = useState(false);
    const [localError, setLocalError] = useState("");
    const errors = page.props.errors || {};
    const title =
        mode === "setup"
            ? "Welcome to your practice."
            : mode === "register"
              ? "Create your client account."
              : "Welcome back.";
    const description =
        mode === "setup"
            ? "Set up the first owner account for this installation."
            : mode === "register"
              ? "Create an account to start an inquiry and connect with the firm."
              : "Sign in to pick up where your team left off.";
    const fields =
        mode === "setup"
            ? [
                  ["firm_name", "Firm name", "text"],
                  ["name", "Your name", "text"],
                  ["email", "Email address", "email"],
                  ["password", "Password", "password"],
                  ["password_confirmation", "Confirm password", "password"],
                  [
                      "bootstrap_token",
                      "Installation bootstrap token",
                      "password",
                  ],
              ]
            : mode === "register"
              ? [
                    ["name", "Full name", "text"],
                    ["email", "Email address", "email"],
                    ["password", "Password", "password"],
                    ["password_confirmation", "Confirm password", "password"],
                ]
              : [
                    ["email", "Email address", "email"],
                    ["password", "Password", "password"],
                ];
    return (
        <AuthLayout title={title} description={description}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    setBusy(true);
                    setLocalError("");
                    router.post(`/${mode}`, values, {
                        onFinish: () => setBusy(false),
                        onError: () =>
                            setLocalError(
                                "Please check the highlighted details.",
                            ),
                    });
                }}
                className="form-stack"
            >
                <Alert kind="success">{page.props.status}</Alert>
                <Alert>
                    {localError || errors.message || errors.authentication}
                </Alert>
                {fields.map(([key, label, type]) => (
                    <Field
                        key={key}
                        label={label}
                        error={errors[key]}
                        hint={
                            key === "bootstrap_token"
                                ? "Use the token supplied by the installation administrator."
                                : key === "password" && mode !== "login"
                                  ? "Use at least 12 characters."
                                  : undefined
                        }
                    >
                        <input
                            name={key}
                            type={type}
                            required
                            autoComplete={
                                key === "password"
                                    ? mode === "login"
                                        ? "current-password"
                                        : "new-password"
                                    : key === "email"
                                      ? "email"
                                      : key === "name"
                                        ? "name"
                                        : "off"
                            }
                            value={values[key]}
                            onChange={(e) =>
                                setValues({ ...values, [key]: e.target.value })
                            }
                        />
                    </Field>
                ))}
                {mode === "login" && (
                    <div className="auth-options">
                        <label className="checkbox-label">
                            <input
                                type="checkbox"
                                checked={values.remember}
                                onChange={(e) =>
                                    setValues({
                                        ...values,
                                        remember: e.target.checked,
                                    })
                                }
                            />
                            Keep me signed in
                        </label>
                        <Link href="/forgot-password" className="text-link">
                            Forgot password?
                        </Link>
                    </div>
                )}
                <Button disabled={busy} type="submit" className="auth-submit">
                    {busy
                        ? "Please wait…"
                        : mode === "login"
                          ? "Sign in"
                          : mode === "setup"
                            ? "Create your practice"
                            : "Create account"}
                    <ArrowUpRight size={16} />
                </Button>
                {mode === "login" ? (
                    <p className="auth-alternate">
                        New client?{" "}
                        <Link href="/register">Create an account</Link>
                    </p>
                ) : mode === "register" ? (
                    <p className="auth-alternate">
                        Already registered? <Link href="/login">Sign in</Link>
                    </p>
                ) : null}
            </form>
        </AuthLayout>
    );
}
