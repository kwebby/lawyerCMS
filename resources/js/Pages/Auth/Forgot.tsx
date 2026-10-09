// Author: ramanpal singh | URL: https://kwebby.com
import { useState } from "react";
import { Link } from "@inertiajs/react";
import { AuthLayout } from "./AuthForm";
import { Alert, Button, Field } from "../../Components/ui";
import { post } from "../../lib/api";
export default function Forgot() {
    const [email, setEmail] = useState("");
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState("");
    const [error, setError] = useState("");
    return (
        <AuthLayout
            title="Reset your password."
            description="Enter your account email to request a password reset link."
        >
            <form
                className="form-stack"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setBusy(true);
                    setError("");
                    try {
                        const r = await post("/forgot-password", { email });
                        setMessage(
                            r.message ||
                                "If an account exists, a reset link will be sent to its email address.",
                        );
                    } catch (e) {
                        setError((e as Error).message);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <Alert>{error}</Alert>
                <Alert kind="success">{message}</Alert>
                <Field label="Email address">
                    <input
                        required
                        type="email"
                        autoComplete="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                    />
                </Field>
                <Button type="submit" disabled={busy}>
                    {busy ? "Requesting…" : "Send reset link"}
                </Button>
                <Link href="/login" className="text-link">
                    Back to sign in
                </Link>
            </form>
        </AuthLayout>
    );
}
