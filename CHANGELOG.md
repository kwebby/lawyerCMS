<!-- Author: ramanpal singh | URL: https://kwebby.com -->
# Changelog

## Unreleased

### Security and access
- Ethical walls and restricted matters apply to every listing (leads, AI runs, invoices), not only to single-record reads.
- Changing a record's owner, team, walls or confidentiality needs firm-wide permission, and at least one owner always keeps access.
- Unverified sign-ups cannot be given staff roles; an invitation takes over an unverified sign-up for that address; only owners grant or remove owner/admin; email verification must come from the account's own session.
- Second-factor and recovery codes lock after five wrong attempts (15 minutes, doubling up to 24 hours).
- Sign-in is throttled per account and address. Set `TRUSTED_PROXIES` when running behind a reverse proxy.
- Registration, sign-in and password reset no longer reveal whether an account exists. New registrants now sign in after registering instead of automatically.
- Client portal responses omit internal fields such as team, walls, confidentiality and internal notes.
- Site-wide and page-type SEO defaults cannot set a canonical URL; changing their robots rules needs an owner or admin.
- Public pages, sitemaps and public assets are served without session cookies; responses with a session are `private, no-store`.
- The website enquiry form verifies Turnstile when it is configured.
- Public image paths with `.`/`..` segments and identifiers with trailing newlines are rejected.
- Backups read only known file-path fields, so text in a record can no longer make backups fail.

### Approvals
- New owner-only Settings → Security option, audited when used. By default nobody approves their own pay run, employee input, credit note, manual refund, page or website change.
- Pages and the website share one model: submitting needs `pages.write`, approving `pages.approve`, publishing `pages.publish`. The public reviewer name comes from the approving account.

### Finance
- One ISO 4217 minor-unit table is used for entry, documents, PayPal and Stripe. Amounts a provider cannot represent are refused rather than rescaled. Review existing records in AFN, ALL, IQD, IRR, KPW, LAK, LBP, MGA, MMK, RSD, SLL, SOS, SYP and YER (entry scale changed) and CLF, UYI and UYW (document scale changed).
- Reusing a manual payment key with different details returns 409 instead of silently dropping the second payment.
- Credit notes and receipts use gapless sequential numbers.
- PayPal checkouts expire after 72 hours and are not captured when the invoice no longer has enough outstanding.

### Reliability
- Fixed listings, search, conflict checks, payslip release and staff/owner lookups that silently stopped at the newest 100–1,000 records. They now page through every record (`RecordStore::each()`), and a listing loads each shared parent matter and role once.
- Workspace pages compute dashboard totals only on the dashboard, and notifications are read per user.
- A job whose last attempt died is marked failed instead of retrying forever, each handler is time-limited, finished jobs are purged (completed after 30 days, failed after 90), and one failing cron step no longer stops job processing.
- The website homepage body comes only from a published home route.
- Inertia page discovery works on case-sensitive file systems.

## 0.1.0 — release candidate, 2026-10-09

- Rebranded the application as LawyerCMS, by ramanpal singh.
- Legal intake, matters, team/client workflows, writing, chat, billing, payroll and reviewed AI assistance.
- Reviewed website builder, public media, local fonts and optional catalog installation.
- Declarative theme imports, inner-page template support, starter package and publishing rollback.
- Canonical office records, listing observations, centralized metadata and structured data.
- Installation, hosting, dependency, architecture, website and theme-development documentation.
- Prebuilt release packaging and SQL contract CI.

This release remains subject to the [production gates](docs/RELEASE_GATES.md). Historical internal theme IDs, database test guards, commands and environment variable names are retained for compatibility.
