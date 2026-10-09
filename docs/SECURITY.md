# Security model and verification

This is a description of implemented controls, not an independent security certification.

## Boundaries

Public content, prospects, analyzer sessions, clients, staff, finance/HR, administrators, workers, databases and external processors have distinct access boundaries. Payer status does not grant legal-file access.

Role/action permissions and object scopes apply to HTTP reads/writes, downloads, chat, financial documents and AI sources. Restricted matters and explicit denials override ordinary firm scope. Staff invitations cannot assign owner/admin privileges. Bootstrap is single-use; privilege changes revoke sessions. TOTP and recent authentication protect sensitive operations.

Private files use authenticated encryption and opaque references. Uploads remain quarantined until a scanner confirms their digest. ZIP imports also reject traversal, links, nested archives, executable content, unsupported formats and expansion limits.

Themes contain declarative JSON and trusted blocks. AI receives bounded source versions and no application tools. Permissions are rechecked during processing and result/review access. Consequential decisions remain human-controlled.

## Verification target

The review target is OWASP ASVS 5.0.0 Level 2:
https://github.com/OWASP/ASVS/tree/v5.0.0

A full independently evidenced control-by-control assessment has not been completed. Local tests cover authentication, permission isolation, source revocation, quarantine, stale revisions, rollback, leases, financial idempotency/immutability, hostile themes, metadata and backup tampering.

An independent authorized penetration test must cover every role and database profile, recovery/MFA, exports, chat, SSRF/DNS rebinding, SMTP, files/archives, document rendering, financial concurrency, prompt injection, retention, server configuration and restoration.

Release requires remediation and independent retest of critical/high findings and all known unauthorized disclosure paths. Keep scope, versions, evidence, dates and residual risks. Automated local tests must not be represented as that independent test.

## Operations

- Disable production debug output and avoid logging request bodies/provider responses.
- Maintain packages, TLS, restrictive ownership and least-privilege IAM/database identities.
- Behind a load balancer or reverse proxy, set `TRUSTED_PROXIES` to exactly those proxy addresses so sign-in throttling sees real client addresses; leave it empty otherwise (see [DEPLOYMENT.md](DEPLOYMENT.md)).
- Keep backup passphrase and APP_KEY in controlled recovery storage.
- Review external-processing terms, jurisdiction-specific promotion and retention policies.
- Validate scale, search/index design, bounded listings and document fidelity with representative firm data.
- Store one-time MFA recovery codes offline. Complete application-key rotation runbooks before broad commercial rollout.
