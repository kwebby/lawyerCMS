<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Install LawyerCMS

LawyerCMS is one application per firm, with multiple offices, staff and clients. Choose the database during installation. All profiles keep the application, authentication, sessions and encrypted file vault on your server.

This guide describes the implemented 0.1.0 release. Start with an isolated staging installation and complete the deployment-specific checks in [RELEASE_GATES.md](RELEASE_GATES.md) before entering real client material.

## 1. Choose an installation method

| Method | What you receive | Tools needed on the application host |
| --- | --- | --- |
| **Prebuilt release — recommended for hosting** | `lawyercms-0.1.0.tar.gz`, production `vendor/`, compiled `public/build/`, bundled fonts | PHP CLI and FPM, required extensions, web server, database access, cron; no Composer or Node runtime required |
| **GitHub source checkout / source ZIP** | Application source and lockfiles; no installed PHP or JavaScript dependencies | Composer 2 and a supported Node build environment in addition to the runtime requirements; building elsewhere and uploading a release is supported |

GitHub's automatically generated **Source code (zip/tar.gz)** files are source archives. Use the separately attached `lawyercms-0.1.0.tar.gz` release asset for a ready-built installation. If no attached asset is available, build it using the source procedure below.

See [DEPENDENCIES.md](DEPENDENCIES.md) for the complete runtime/build split, locked versions and extension checks. See [DEPLOYMENT.md](DEPLOYMENT.md) for Ubuntu, CentOS Stream 10, Nginx, Apache and hosting configuration.

## 2. Prepare the host

Required baseline:

- A supported Ubuntu LTS or CentOS Stream 10 system, or equivalent hosting that passes the capability check.
- 64-bit PHP **8.3 or later**, with PHP CLI and a web SAPI; 256 MB memory minimum, 512 MB recommended.
- HTTPS, a canonical domain, one-minute cron and outbound HTTPS/SMTP.
- Private storage outside the document root, writable by the application account.
- MySQL 8.4/InnoDB, PostgreSQL 16+, Supabase PostgreSQL, or Cloud Firestore Standard.
- At least enough disk for the application, vault, temporary processing, logs and two verified backups. Size the vault and backup headroom against actual document volume.

Create a dedicated service account and application root according to your host's policy. In the examples, that account is `lawyercms`, and the layout is:

```text
/srv/lawyercms/
├── current/                  application release; web root is current/public
├── shared/
│   ├── storage/              Laravel logs, sessions, cache and execution lock
│   ├── vault/                encrypted documents, media, themes and installed fonts
│   └── secrets/              private service-account files / database CA certificate
└── backups/                  restricted encrypted archives; also copy off-server
```

Keep `shared/` and `backups/` outside `current/public/`. Do not run `php artisan storage:link` to expose the vault. LawyerCMS serves files through authorized routes.

A simple first installation can leave Laravel's `storage/` inside `current/` and set only the vault to `shared/vault/`. A release-switching deployment should make `current/storage` point to `shared/storage` so sessions, maintenance state and the scheduler/backup lock survive upgrades. Configure this before starting cron; never copy an old live lock directory over a new one while jobs run.

## 3. Extract a prebuilt release

Download the archive and its checksum from the same release. Verify before extraction; the project does not currently publish a signed provenance attestation. On Linux:

```sh
sha256sum lawyercms-0.1.0.tar.gz
cat lawyercms-0.1.0.sha256
```

Compare the two hexadecimal digests. The checksum file may include the build-time `dist/` path; that path need not match your download directory. On macOS use `shasum -a 256`.

As the application account, extract into a **new empty** application directory:

```sh
mkdir -p /srv/lawyercms/current
mkdir -p /srv/lawyercms/shared/vault
mkdir -p /srv/lawyercms/shared/secrets
mkdir -p /srv/lawyercms/backups
tar -xzf lawyercms-0.1.0.tar.gz -C /srv/lawyercms/current --strip-components=1
cd /srv/lawyercms/current
cp .env.example .env
chmod 600 .env
chmod 700 /srv/lawyercms/shared/vault /srv/lawyercms/shared/secrets /srv/lawyercms/backups
```

The archive contains an outer `lawyercms/` folder. Do not extract over an existing installation; use the upgrade procedure instead. Ensure the application account can write `storage/`, `bootstrap/cache/` and the vault, and the web server can traverse/read `public/`. See deployment permissions below.

## 4. Alternatively, build from source

Clone the URL shown by this repository's GitHub **Code** button, or unpack the source archive, then enter its root. Use an unprivileged build account and an isolated development/staging environment:

```sh
composer install --prefer-dist --no-interaction
cp .env.example .env
chmod 600 .env
npm ci
npm run build
```

`npm run build` performs a TypeScript check and compiles assets. `npm test` runs the frontend tests. The release packager runs both commands. Composer installs the exact locked dependency versions; do not run `composer update` or `npm update` during installation.

Continue at environment configuration below. For a production package built off-server, first configure an isolated test environment and run:

```sh
npm test
bash bin/build-release
```

The release script installs npm dependencies from the lockfile, builds assets, runs frontend and PHP tests plus PHPStan, stages the application, installs production-only Composer dependencies and creates the archive/checksum under `dist/`. It requires the **development** Composer dependencies, Bash, rsync, tar, `mktemp` and `shasum` on the build machine. It never needs to run on a restricted production host. Read the test-database warning in [DEPENDENCIES.md](DEPENDENCIES.md#testing-without-touching-live-data) first.

Do not use `composer run setup` on an existing installation: it includes key generation and migrations. Do not use a development server or `npm run dev` as a production process.

## 5. Configure `.env`

Edit `.env` directly in a private editor; do not paste credentials into shared terminals, URLs or issue reports. Quote values that contain spaces or special dotenv characters. Never commit this file.

```dotenv
APP_NAME=LawyerCMS
APP_ENV=production
APP_DEBUG=false
APP_URL=https://legal.example.com
APP_KEY=
LOG_LEVEL=warning

APP_MAINTENANCE_DRIVER=file
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=file
QUEUE_CONNECTION=sync

CRM_REQUIRE_MFA=true
CRM_PRIVATE_PATH=/srv/lawyercms/shared/vault
CRM_BOOTSTRAP_TOKEN=
```

Use a dedicated hostname with `/public` as its document root, not a subdirectory URL. The baseline uses application-owned durable job records processed by `crm:tick`; `QUEUE_CONNECTION=sync` does not disable that outbox. Redis configuration in the Laravel scaffold is unused with these settings.

Generate an encryption key **once, only for a new installation**:

```sh
php artisan key:generate --no-interaction
```

Back up `APP_KEY` to a separate recovery vault immediately. Changing it breaks access to existing encrypted files, credentials and MFA secrets. For restore/migration, use the original key instead of generating one.

### MySQL

Have the database administrator create a private database and application user with privileges limited to that database. Migrations need schema privileges during installation/upgrades. Use MySQL 8.4/InnoDB, `utf8mb4`, and the same user/host pairing the database administrator granted.

```dotenv
CRM_STORE=sql
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lawyercms
DB_USERNAME=lawyercms
DB_PASSWORD="replace-with-generated-database-password"
```

For a self-managed database, the administrator can create a fresh database/account in the MySQL administrative console (replace the placeholder password and match the permitted host to your topology):

```sql
CREATE DATABASE lawyercms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lawyercms'@'127.0.0.1' IDENTIFIED BY 'replace-with-generated-database-password';
GRANT ALL PRIVILEGES ON lawyercms.* TO 'lawyercms'@'127.0.0.1';
```

Use your database administration tool's protected credential workflow; do not copy real passwords into a public support transcript. Managed services/control panels may provide equivalent database/user forms.

For a remote MySQL service, configure the service's CA file with `MYSQL_ATTR_SSL_CA=/private/path/ca.pem`, and verify TLS/hostname validation against that service before launch. Do not expose the database port publicly for a same-host deployment.

### PostgreSQL

Create a dedicated database owned by the application's non-superuser role. Use PostgreSQL 16 or later:

```dotenv
CRM_STORE=sql
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lawyercms
DB_USERNAME=lawyercms
DB_PASSWORD="replace-with-generated-database-password"
```

For a self-managed server, run these commands in `psql` as the database administrator; `\password` prompts without including the password in the SQL text:

```sql
CREATE ROLE lawyercms LOGIN;
\password lawyercms
CREATE DATABASE lawyercms OWNER lawyercms ENCODING 'UTF8';
```

Configure `pg_hba.conf` to allow that role only from the application host using the server's password/TLS policy. Managed-service dashboards may provision the database and credentials instead.

For a remote database, use `DB_SSLMODE=verify-full` and `DB_SSLROOTCERT=/srv/lawyercms/shared/secrets/postgres-ca.pem`. The CA file must be readable by both CLI and PHP-FPM. A local loopback or Unix-socket deployment may have a different transport policy; do not copy `sslmode=prefer` to a remote production connection without reviewing it.

### Supabase

Use the same PostgreSQL variables and `CRM_STORE=sql`; the installer profile is `supabase`. Copy the host, username, database and port from the project's **Connect** dialog. Prefer a direct connection where the host supports its network address, or the shared **session pooler** on an IPv4-only host. Do not guess the pooler hostname or username. Configure `DB_SSLMODE=verify-full` and the downloaded database CA in `DB_SSLROOTCERT`. Follow [Supabase's connection guidance](https://supabase.com/docs/guides/database/connecting-to-postgres).

LawyerCMS uses its own identity/RBAC, PostgreSQL repository and private local files. It does not require Supabase Auth, Storage or the browser Data API. Restrict any independently enabled Data API exposure to the application tables. Test transactions, migrations and restoration on the actual managed project before certification.

### Cloud Firestore Standard

Create a dedicated Firestore Standard database in an approved region. Enable Firestore's API and provide a least-privilege service account permitted to read/write its application records and transactions. Place its downloaded credentials outside the web root:

```dotenv
CRM_STORE=firestore
FIRESTORE_PROJECT_ID=your-project-id
FIRESTORE_DATABASE=(default)
GOOGLE_APPLICATION_CREDENTIALS=/srv/lawyercms/shared/secrets/firestore-service-account.json
```

Set the credential file to mode `0600`, owned by the application account. Do not place a Firebase browser API key or service-account JSON in frontend settings. The adapter uses Google's official REST API through backend OAuth, with no PHP gRPC extension. Service-account access is controlled by IAM and bypasses Firestore Security Rules; LawyerCMS permissions are enforced server-side. See [Firestore REST authentication](https://firebase.google.com/docs/firestore/use-rest-api).

Firestore does not use hidden SQL tables for identity, jobs or finance. SQL `DB_*` fields are ignored by the primary store, but retain file-backed sessions/cache as above. The repository currently has **no complete deployable composite-index manifest**: exercise the application's query paths in staging and provision indexes requested by Firestore before launch. A missing index is an error, not an empty result. `FIRESTORE_EMULATOR_HOST` is for local/testing only and is rejected in production.

## 6. Check capabilities and initialize

Clear stale configuration after editing `.env`, and choose **one** profile:

```sh
php artisan config:clear
php artisan crm:install --profile=mysql --check --no-interaction
php artisan crm:install --profile=mysql --no-interaction
```

Replace `mysql` with `pgsql`, `supabase` or `firestore` as appropriate. `--check` checks the actual CLI PHP/extensions, memory, private path and required production security settings. It does **not** test SMTP, scanning, HTTPS reachability, cron execution or managed-service certification.

The installation command writes the selected store profile to `.env`, runs SQL migrations where applicable, performs a primary-store write/transaction check, and prints a private 64-character bootstrap token. It does not create the database/user or provision Firestore indexes. Do not redirect the token into a publicly readable log.

Point the HTTPS virtual host at `/srv/lawyercms/current/public`, then visit `https://legal.example.com/setup`. Enter the one-time token, firm name, owner name, email and password. The password must contain letters and numbers and be at least 12 characters. Complete authenticator/TOTP enrollment and save the recovery codes offline. The setup endpoint becomes unavailable once the first owner exists. Remove `CRM_BOOTSTRAP_TOKEN` from `.env` after successful setup and rebuild configuration cache.

## 7. Configure the firm and integrations

Sign in and configure the actual business name, offices, currency, invoice details and numbering before issuing financial records. Configure staff through expiring invitations, and grant clients explicit portal access. A public registration creates a prospect, not an accepted legal engagement.

- **Mail:** approve the SMTP hostname in `CRM_APPROVED_HOSTS`, then configure Practice settings with SMTP host, TLS port 465/587, credentials, sender and reply-to. Use the administrator test delivery and inspect delivery logs. Scaffold `MAIL_*` values do not replace the application's saved SMTP settings.
- **Scanning:** set `SCANNER_URL`, optional `SCANNER_API_KEY` and the scanner hostname in `CRM_APPROVED_HOSTS`. Configure a service matching the [scanner contract](DEPLOYMENT.md#upload-scanning). Without it, uploads remain quarantined; do not bypass scanning to finish setup.
- **Website:** configure real offices/NAP, homepage sections, branding, public media, navigation and search/social metadata. Saving is a draft operation; review, approve and publish when ready. Bundled fonts work offline. Full Google Fonts browsing needs an administrator-supplied Google Fonts Developer API key in Website settings; approved fonts are downloaded and self-hosted.
- **Payments:** configure provider sandbox credentials and signed HTTPS callbacks before enabling live charges. See [DEPLOYMENT.md](DEPLOYMENT.md#payments).
- **AI:** configure approved provider/model/key, limits and review rules. Public analyzers additionally need scanner, Turnstile and jurisdiction approval. Disabled/unconfigured integrations do not produce simulated live results.

After any environment changes:

```sh
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run these as the application account. Cache files contain sensitive configuration and must not be public.

## 8. Start cron and verify operation

Use the application account's crontab. Resolve the real PHP CLI binary first; shared hosts often use a versioned path. Adapt [deploy/cron.example](../deploy/cron.example):

```cron
* * * * * cd /srv/lawyercms/current && /usr/bin/php artisan crm:tick --budget=45 >> /srv/lawyercms/current/storage/logs/cron.log 2>&1
```

Run `php artisan crm:tick --budget=45 --no-interaction` once manually to check permissions and configuration. The command takes a non-blocking filesystem execution lock, clamps its budget to 25–55 seconds, updates the health heartbeat and processes leased durable jobs. Laravel's generic `schedule:run` is not a replacement for this entry. Use a separate external monitor for a stale heartbeat: a stopped scheduler cannot send its own notification.

Before reopening staging to staff, verify owner/staff/client login, MFA, file quarantine and a clean scan, one email, a reviewed website preview/publication, invoice PDF, cron heartbeat and an encrypted backup/restore drill. Confirm `/login` and authenticated pages have no shared public caching, and `.env`, `/vendor`, `/storage` and vault files cannot be fetched through the web server. Gateways and AI must be tested with actual configured services separately.

## 9. Local development only

Use a local-only database or SQLite fixture store and synthetic information. For a quick demo from source:

```sh
cp .env.example .env
composer install --prefer-dist --no-interaction
npm ci
```

Edit `.env`: `APP_ENV=local`, `APP_URL=http://127.0.0.1:8000`, `SESSION_SECURE_COOKIE=false`, `CRM_REQUIRE_MFA=false`, `CRM_STORE=sql`, `DB_CONNECTION=sqlite`; set `DB_DATABASE` to an absolute writable local `.sqlite` file and create that file. These settings are for loopback development only.

```sh
php artisan key:generate --no-interaction
php artisan migrate --no-interaction
php artisan crm:demo --no-interaction
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

The demo command refuses non-local environments and skips an already initialized installation. Existing fictional demo credentials remain `alex@counsel.test` (owner), `sam@counsel.test` (client), password `CounselDemo2026!` for compatibility. Never enable or reuse them in production. For frontend iteration run `npm run dev` in a separate terminal alongside the local PHP server; for outbox development run `crm:tick` explicitly. SQL/Firestore parity still requires separate native/managed tests.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Missing PHP extension / failed platform requirement | Compare `php --ini`, `php -m` and FPM's configuration; install matching extensions for both SAPIs and restart FPM |
| `419`, login loop or missing session | Correct HTTPS `APP_URL`, secure-cookie setting, host/domain, proxy TLS configuration, and writable session directory; use `false` only for local HTTP |
| `502 Bad Gateway` | Actual FPM socket path, process status, socket owner/group and SELinux/AppArmor policy |
| Vite manifest missing / unstyled workspace | Source install needs `npm ci` and `npm run build`; prebuilt archive must contain `public/build/manifest.json` |
| Permission denied / cannot create vault | Absolute `CRM_PRIVATE_PATH` outside `public/`, traversable parent, correct application owner; do not use mode `777` |
| Bootstrap token rejected | Correct token, uncached `.env` changes, uninitialized store, correct database connection |
| Setup returns `404` | An owner already initialized this store; sign in rather than re-bootstrap |
| Upload never becomes usable | Scanner HTTPS/allowlist/DNS/TLS, response digest and verdict, cron and retry state; missing scanner intentionally fails closed |
| Mail queued but not delivered | Saved SMTP settings, approved host, firewall/TLS, one-minute cron, failed-job/delivery logs |
| Firestore `FAILED_PRECONDITION` | Required index not deployed; follow the official response/index workflow and retest the exact query |
| Changes to `.env` have no effect | Run `config:clear` then `config:cache` from the right release; restart optional persistent processes |
| Decryption failures after upgrade | Correct original `APP_KEY`, vault mount and file permissions; restore a matching verified backup if keys/data were replaced |
| Job command says another scheduler/backup owns the lease | Usually safe overlap; check the active process and stale heartbeat. Do not delete the lock file while processes may hold it |

`crm:*`, `CRM_*`, the `.lcrm` backup extension and `counsel_contract_test` test guard are retained compatibility identifiers. The product and repository branding is LawyerCMS. Do not rename these identifiers in server configuration without a code migration.
