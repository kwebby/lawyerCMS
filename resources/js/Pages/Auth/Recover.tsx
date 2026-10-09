// Author: ramanpal singh | URL: https://kwebby.com
import { useState } from "react";
import { router,usePage,Link } from "@inertiajs/react";
import { AuthLayout } from "./AuthForm";
import { Alert,Button,Field } from "../../Components/ui";
export default function Recover() {
 const [code,setCode]=useState("");const [busy,setBusy]=useState(false);const errors=usePage<any>().props.errors||{};
 return <AuthLayout title="Recover your authenticator." description="Enter one of the recovery codes saved when you enabled multi-factor authentication.">
  <form className="form-stack" onSubmit={e=>{e.preventDefault();setBusy(true);router.post("/mfa/recover",{code},{onFinish:()=>setBusy(false)});}}>
   <Alert>{errors.code||errors.message}</Alert><Field label="One-time recovery code"><input autoComplete="off" required value={code} onChange={e=>setCode(e.target.value.toUpperCase())} placeholder="XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX"/></Field>
   <p className="subtle">This revokes existing sessions and lets you enroll a new authenticator.</p><Button type="submit" disabled={busy}>{busy?"Verifying…":"Verify recovery code"}</Button><Link className="text-link" href="/mfa">Back to authenticator sign-in</Link>
  </form>
 </AuthLayout>;
}
