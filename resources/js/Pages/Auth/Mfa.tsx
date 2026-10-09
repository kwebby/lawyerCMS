// Author: ramanpal singh | URL: https://kwebby.com
import { useState } from "react";
import { router, usePage, Link } from "@inertiajs/react";
import { AuthLayout } from "./AuthForm";
import { Alert, Button, Field } from "../../Components/ui";
export default function Mfa({
    enrollment_required,
    secret,
    provisioning_uri,
}: {
    enrollment_required: boolean;
    secret?: string;
    provisioning_uri?: string;
}) {
    const [code, setCode] = useState("");
    const [busy, setBusy] = useState(false);
    const errors = usePage<any>().props.errors || {};
    return (
        <AuthLayout
            title={
                enrollment_required
                    ? "Secure your account."
                    : "Verify your sign-in."
            }
            description={
                enrollment_required
                    ? "Add LawyerCMS to your authenticator app, then enter the six-digit code."
                    : "Enter the six-digit code from your authenticator app."
            }
        >
            <form
                className="form-stack"
                onSubmit={(e) => {
                    e.preventDefault();
                    setBusy(true);
                    router.post(
                        "/mfa",
                        { code },
                        { onFinish: () => setBusy(false) },
                    );
                }}
            >
                <Alert>{errors.code || errors.message}</Alert>
                {enrollment_required && (
                    <div className="mfa-setup">
                        <p>Enter this setup key in your authenticator:</p>
                        <code>{secret}</code>
                        {provisioning_uri && (
                            <a className="text-link" href={provisioning_uri}>
                                Open authenticator app
                            </a>
                        )}
                    </div>
                )}
                <Field label="Verification code">
                    <input
                        autoFocus
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        pattern="[0-9]{6}"
                        maxLength={6}
                        required
                        value={code}
                        onChange={(e) =>
                            setCode(e.target.value.replace(/\D/g, ""))
                        }
                    />
                </Field>
                <Button disabled={busy} type="submit">
                    {busy
                        ? "Verifying…"
                        : enrollment_required
                          ? "Enable and continue"
                          : "Verify and continue"}
                </Button>
                {!enrollment_required && (
                    <Link className="text-link" href="/mfa/recover">
                        Use a recovery code
                    </Link>
                )}
            </form>
        </AuthLayout>
    );
}
