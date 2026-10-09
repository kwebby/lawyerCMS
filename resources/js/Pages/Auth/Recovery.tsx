// Author: ramanpal singh | URL: https://kwebby.com
import { Link } from "@inertiajs/react";
import { AuthLayout } from "./AuthForm";
export default function Recovery({ codes }: { codes: string[] }) {
 return <AuthLayout title="Keep a way back in." description="Save these one-time recovery codes somewhere private, separate from this device.">
  <div className="form-stack">
   {codes.length ? <><p>Each code can replace a lost authenticator once. Using a code revokes other sessions and requires a new authenticator.</p><pre style={{fontSize:12,whiteSpace:"pre-wrap",padding:16,background:"#f2f6f7",borderRadius:8}}>{codes.join("\n")}</pre><p className="subtle">These codes will not be shown again after leaving this page.</p></> : <p>No new recovery codes are available in this session.</p>}
   <Link className="button" href="/app">I have saved my codes →</Link>
  </div>
 </AuthLayout>;
}
