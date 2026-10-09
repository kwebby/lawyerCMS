// Author: ramanpal singh | URL: https://kwebby.com
import { useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { AuthLayout } from "./AuthForm";
import { Alert, Button, Field } from "../../Components/ui";
export default function Reset({ token }: { token: string }) {
    const [password, setPassword] = useState("");
    const [confirmation, setConfirmation] = useState("");
    const [busy, setBusy] = useState(false);
    const errors = usePage<any>().props.errors || {};
    return (
        <AuthLayout
            title="Choose a new password."
            description="Use a strong password that you don’t use for another account."
        >
            <form
                className="form-stack"
                onSubmit={(e) => {
                    e.preventDefault();
                    setBusy(true);
                    router.post(
                        `/password/reset/${token}`,
                        { password, password_confirmation: confirmation },
                        { onFinish: () => setBusy(false) },
                    );
                }}
            >
                <Alert>{errors.message || errors.token}</Alert>
                <Field label="New password" error={errors.password}>
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
                    label="Confirm new password"
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
                    {busy ? "Saving…" : "Reset password"}
                </Button>
            </form>
        </AuthLayout>
    );
}
