// Author: ramanpal singh | URL: https://kwebby.com
import { useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { AuthLayout } from "./AuthForm";
import { Alert, Button, Field } from "../../Components/ui";
export default function Invite({
    token,
    email,
}: {
    token: string;
    email?: string;
}) {
    const [name, setName] = useState("");
    const [password, setPassword] = useState("");
    const [confirmation, setConfirmation] = useState("");
    const [busy, setBusy] = useState(false);
    const errors = usePage<any>().props.errors || {};
    return (
        <AuthLayout
            title="Join your practice."
            description={`Complete your invited account${email ? " for " + email : ""}.`}
        >
            <form
                className="form-stack"
                onSubmit={(e) => {
                    e.preventDefault();
                    setBusy(true);
                    router.post(
                        `/invite/${token}`,
                        { name, password, password_confirmation: confirmation },
                        { onFinish: () => setBusy(false) },
                    );
                }}
            >
                <Alert>{errors.message || errors.token}</Alert>
                <Field label="Full name" error={errors.name}>
                    <input
                        required
                        autoComplete="name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                    />
                </Field>
                <Field
                    label="Password"
                    error={errors.password}
                    hint="Use at least 12 characters."
                >
                    <input
                        required
                        type="password"
                        autoComplete="new-password"
                        minLength={12}
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                    />
                </Field>
                <Field
                    label="Confirm password"
                    error={errors.password_confirmation}
                >
                    <input
                        required
                        type="password"
                        autoComplete="new-password"
                        value={confirmation}
                        onChange={(e) => setConfirmation(e.target.value)}
                    />
                </Field>
                <Button type="submit" disabled={busy}>
                    {busy ? "Joining…" : "Join practice"}
                </Button>
            </form>
        </AuthLayout>
    );
}
