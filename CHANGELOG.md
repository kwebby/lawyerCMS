<!-- Author: ramanpal singh | URL: https://kwebby.com -->
# Changelog

## Unreleased

- Fixed listings, search, conflict checks, payslip release and staff/owner lookups that silently stopped at the newest 100–1,000 records. They now page through every record (`RecordStore::each()`), and a listing loads each shared parent matter and role once.
- Workspace pages compute dashboard totals only on the dashboard, and notifications are read per user.

## 0.1.0 — release candidate, 2026-10-09

- Rebranded the application as LawyerCMS, by ramanpal singh.
- Legal intake, matters, team/client workflows, writing, chat, billing, payroll and reviewed AI assistance.
- Reviewed website builder, public media, local fonts and optional catalog installation.
- Declarative theme imports, inner-page template support, starter package and publishing rollback.
- Canonical office records, listing observations, centralized metadata and structured data.
- Installation, hosting, dependency, architecture, website and theme-development documentation.
- Prebuilt release packaging and SQL contract CI.

This release remains subject to the [production gates](docs/RELEASE_GATES.md). Historical internal theme IDs, database test guards, commands and environment variable names are retained for compatibility.
