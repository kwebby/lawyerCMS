<!-- Author: ramanpal singh | URL: https://kwebby.com -->
# LawyerCMS

**A self-hosted CRM, CMS and reviewed AI workspace for law firms.** Manage enquiries, matters, client communication, documents, billing, payroll and your public website in one Laravel and React application.

Created by **[ramanpal singh](https://kwebby.com)**. Application code is [MIT licensed](LICENSE); dependencies and bundled fonts retain their own licenses.

> Version 0.1.0 is a working release candidate. Managed database certification, live integration validation and independent penetration testing remain [release gates](docs/RELEASE_GATES.md). Use fictional data until your deployment has passed them.

## What you can do

| Area | Included workflows |
|---|---|
| Intake and practice | Configurable lead pipelines, contacts, conflict review, engagement acceptance, matters, proceedings, assignments and client grants |
| Daily work | Dedicated record pages, inline status changes, calendar and kanban views, tasks, team workload and approvals |
| Documents | BlockNote writing, validated JSON, autosave conflict handling, immutable revisions, review comments, PDF and DOCX exports |
| Client communication | Permission-checked client portal, persistent polling chat, attachments, unread markers, notifications, SMTP templates and digests |
| Website | Editable homepage sections, images, buttons, navigation, palettes, self-hosted fonts, responsive media, private preview and reviewed publishing |
| Themes | Declarative JSON theme designer, ZIP import, supplied themes, custom inner-page templates and retained-version rollback |
| SEO and local visibility | Server-rendered content, metadata inheritance, social tags, schemas, redirects, sitemaps, canonical office/NAP records and manual listing comparisons |
| Finance and employees | Invoices, credit notes, receipts, partial payments, Stripe/PayPal adapters, expenses, time records, salary runs, leave inputs and private payslips |
| AI assistance | Source-bound drafts, summaries and chronologies with human review; three public lead-tool flows with verified email, consent, quarantine and expiry |
| Administration | Roles and scopes, MFA, audit, encrypted private files, durable jobs, health checks and portable encrypted backups |

AI, uploads, email and online payments require their corresponding integrations. External citation observations do not automatically update Google Business Profile. Payroll is configurable globally; statutory country-specific calculations are separate work.

## Start here

| Goal | Guide |
|---|---|
| Install locally, on a server, or from a prebuilt package | [Installation](docs/INSTALLATION.md) |
| Check PHP, OS, Node, database and library requirements | [Dependencies](docs/DEPENDENCIES.md) |
| Configure Nginx/Apache, HTTPS, cron, upgrades and hosting | [Deployment](docs/DEPLOYMENT.md) |
| Build and package your first custom theme | [Theme tutorial](docs/THEME_TUTORIAL.md) |
| Look up every theme field, structure and import rule | [Theme API reference](docs/THEME_API.md) |
| Configure the website, homepage, fonts and local offices | [Website guide](docs/WEBSITE_GUIDE.md) |
| Understand the modules and extend the application | [Architecture](docs/ARCHITECTURE.md) |
| Create, verify and restore encrypted backups | [Backup and recovery](docs/BACKUP_RESTORE.md) |
| Review security controls and outstanding production checks | [Security](docs/SECURITY.md), [release gates](docs/RELEASE_GATES.md) |
| Contribute or build a distributable package | [Contributing](CONTRIBUTING.md) |

## Quick local installation from source

Install PHP 8.3+ with the extensions in the [dependency guide](docs/DEPENDENCIES.md), Composer 2, a supported Node build runtime and MySQL 8.4 or PostgreSQL 16+. Node and Composer are build tools; production can use prebuilt assets and dependencies.

```bash
git clone https://github.com/kwebby/LawyerCMS.git
cd LawyerCMS
composer install
cp .env.example .env
php artisan key:generate --no-interaction
npm ci
npm run build
```

Edit `.env` for your **empty** database, `APP_URL` and private file location. For local HTTP only, set `SESSION_SECURE_COOKIE=false`; production requires HTTPS and secure cookies. Then:

```bash
php artisan crm:install --check
php artisan crm:install --profile=mysql
php artisan serve --host=127.0.0.1 --port=8000
```

Use `--profile=pgsql`, `supabase` or `firestore` for the corresponding configured backend. The installer produces a one-time bootstrap token. Visit `/setup`, create the first owner and enroll MFA. Follow [Installation](docs/INSTALLATION.md) for prerequisites, exact profile settings, cron and first-run configuration.

The `crm:*` commands, `CRM_*` environment names and existing built-in theme IDs are stable compatibility identifiers. The product is now LawyerCMS.

## Demo and everyday use

A prepared local demo is available at `http://127.0.0.1:8765/app` in the development workspace. Fresh clones do not contain its accounts or data. The optional `php artisan crm:demo` command seeds fictional data only in a local environment; use an isolated disposable database.

The legacy demo credentials remain `alex@counsel.test`, `jordan@counsel.test` and `sam@counsel.test`, with password `CounselDemo2026!`. They are intentional test fixtures, not production credentials. Never deploy the demo database or local `.env`.

- Staff workspace: `/app`
- Website settings: `/app/website`
- BlockNote content: `/app/pages`
- Theme designer/import: `/app/themes`
- Client portal: `/portal`

Website changes follow **save draft → private preview → review → approve → publish**. Images stay quarantined until scanning succeeds. Public enquiries enter the CRM as unverified leads and do not create representation automatically.

## Your first custom theme

Start with [`resources/themes/starter`](resources/themes/starter). The import ZIP contains its files directly at the root:

```text
theme.json
tokens.json
templates/
  page.json
  article.json
  service.json
```

Follow the [complete tutorial](docs/THEME_TUTORIAL.md) to edit tokens, create custom sections and page-type layouts, add allowed assets, package the ZIP and preview it. Themes use validated declarative JSON; application code controls authentication, forms, payments, metadata and permissions.

A ready-to-import example is included as [`resources/themes/starter.zip`](resources/themes/starter.zip).

## Build and validate

```bash
php artisan test --compact
npm test
npm run build
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer audit
npm audit --audit-level=high
bin/build-release
```

Tests refresh database schema. Never run them against a real firm database. The guarded native concurrency tests use the compatibility database name `counsel_contract_test`.

The release builder writes `dist/lawyercms-0.1.0.tar.gz` and its SHA-256 checksum. It includes production PHP dependencies and compiled frontend assets and excludes local environment files, private storage and demo databases. The archive root is `lawyercms/`. Source-code downloads from GitHub need the normal Composer/npm build steps; they are not prebuilt deployment archives.
