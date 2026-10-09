# Release candidate status

Version 0.1.0 implements a broad single-firm CRM/CMS, finance, writing and reviewed AI workspace. It is not certified for production.

## Local evidence

Website settings extension, 9 October 2026: all 52 publishing tests (802 assertions) passed against native MySQL 9.7.1 and PostgreSQL 16.15. This covers draft/public isolation, reviewed publication/rollback, stale writes, media quarantine and responsive delivery, office visibility/schema consistency, preserved inner-page theme templates, enquiries and the optional Google Fonts catalog installer. Sixteen frontend tests passed. Browser verification covered the dedicated builder, saving, bundled font controls and private server-rendered preview. The library bundles 16 font families; additional installation requires an administrator-configured Google Fonts Developer API key. Catalog/provider responses were mocked in tests, so a live key-backed installation remains an integration check. External listing checks remain manual observations, not a Google Business Profile synchronization or ranking audit.

Validation on 9 October 2026: 110 backend tests / 668 assertions passed on SQLite, MySQL 9.7.1 and PostgreSQL 16.15. The opt-in concurrency test passed separately on both native databases (18 assertions each). Six frontend tests, TypeScript, production assets, Larastan level 5 and dependency audits passed. The release archive includes production dependencies and assets; it excludes local secrets, database and private storage.

- PHP dependencies target 8.3 through Composer platform and lockfile; local runtime is 8.5.9.
- React/Inertia/BlockNote build, TypeScript and frontend tests.
- Native MySQL 9.7.1 and PostgreSQL 16.15 contract tests; SQLite for rapid tests.
- Mocked Firestore REST typed values/transactions and snapshots. These do not establish managed Firestore compatibility.
- Real browser checks of login/MFA, desktop/mobile layouts, BlockNote persistence/revisions/review, and theme creation.
- Authorization, finance, uploads/themes, SEO, PDF/DOCX and encrypted backup tests.
- Independent PHP processes for concurrent financial operations.
- Larastan level 5, dependency audits and PHP formatting.

CI targets PHP 8.3, MySQL 8.4 and PostgreSQL 16. The configuration has been authored; no remote CI run is claimed.

## Production gates

1. Actual MySQL 8.4, Supabase and Firestore Standard certification, including indexes, retries, quotas, concurrency and restoration.
2. Independent credentialed penetration testing, remediation and retest.
3. Real scanner, SMTP, Turnstile, AI and Stripe/PayPal sandbox end-to-end tests. No live customer messages, charges or document processing occurred during implementation.
4. Practitioner-reviewed AI evaluations, legal publishing/advertising review and engagement wording.
5. Ubuntu/CentOS Stream 10 and Apache/Nginx deployment tests, minimum PHP version, cron interruption, storage failure, memory limits and restoration.
6. Long tables, pagination, fonts, RTL/Indic scripts and actual firm document-template fidelity.
7. Realistic data volumes, indexed search/pagination, load, accessibility, and public Core Web Vitals field measurements.
8. Dependency license review, artifact provenance and release signing policy.

## Further product work

- Advanced reporting and regional attendance calculations. Custom intake pipelines, approved leave, monthly attendance and effective-month compensation inputs are implemented.
- Broader transactional/auth email template coverage and escalation editing. Per-user category preferences, localized notification templates and daily digests are implemented.
- Regional statutory finance/payroll engines (intentionally outside the global core).
- Calendar/mailbox synchronization, court feeds and e-signature providers.
- Additional public composition presets and full print layout/font/pagination design controls. The homepage builder, responsive images, palettes, bundled and installable font library, office/citation editing and reviewed publishing are implemented.
- Long-running AI batches, practitioner evaluation corpus, cost reconciliation and optional OCR.
- Key rotation and broader retention administration.
- Reverb, realtime coediting and advanced indexed search.

The repository contains extension boundaries for these capabilities. Do not infer that every workflow in the original product roadmap is already complete.
