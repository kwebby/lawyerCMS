<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# LawyerCMS native deployment and operations

Use [INSTALLATION.md](INSTALLATION.md) for the application install, first owner and database profiles. This guide covers the host around it. Example paths use `/srv/lawyercms/current`; adapt domains, users, PHP versions and socket paths to the real host before enabling a configuration.

The baseline is a **single application node** with local private storage, file sessions/cache and a shared filesystem lock. Multiple web nodes require a separately designed shared-storage/session/locking strategy. Adding a second node or replacing cache with Redis alone does not provide safe clustering.

## Ubuntu LTS example

Ubuntu 24.04 LTS provides a suitable PHP 8.3 base. Later supported Ubuntu LTS versions may use different package/socket versions. Check the distribution's [PHP installation guide](https://ubuntu.com/server/docs/how-to/web-services/install-php/) and package candidates before applying commands.

For a fresh Ubuntu 24.04 host using Nginx:

```sh
sudo apt update
sudo apt install nginx php8.3-cli php8.3-fpm php8.3-common php8.3-mbstring php8.3-intl php8.3-bcmath php8.3-curl php8.3-xml php8.3-zip php8.3-gd php8.3-opcache
```

Install the selected SQL extension:

```sh
sudo apt install php8.3-mysql
```

Use `php8.3-pgsql` instead for PostgreSQL/Supabase. Firestore does not need a SQL driver. Developer SQLite tests additionally need `php8.3-sqlite3`. OpenSSL, sodium and other standard modules may come through the base/common package; confirm them with `php -m` and the installer instead of assuming they are present.

For Apache, install `apache2` instead of Nginx and enable its FPM integration:

```sh
sudo a2enmod proxy_fcgi setenvif rewrite ssl headers
sudo a2enconf php8.3-fpm
```

Do not have two web servers compete for ports 80/443. Configure a dedicated PHP-FPM pool rather than running unrelated applications under the same identity. On an Ubuntu 24.04 installation, pool files typically live under `/etc/php/8.3/fpm/pool.d/`; the exact path is an operator choice. Example pool excerpt:

```ini
[lawyercms]
user = lawyercms
group = lawyercms
listen = /run/php/lawyercms.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 25M
php_admin_value[post_max_size] = 27M
php_admin_value[max_execution_time] = 60
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
```

This is a starting pool, not a capacity promise. Size `pm.max_children` from available RAM and measured PHP process size; 5 × 512 MB can exceed a small server's memory budget. Match the web-server config's `fastcgi_pass`/`SetHandler` to the actual socket. Configure equivalent CLI memory independently for document jobs and backups.

The bundled `deploy/php.ini` contains **php.ini syntax**. Merge it into the appropriate CLI/FPM `conf.d` files, or translate directives to `php_admin_value[...]` in a pool file as above. Do not paste raw `memory_limit=...` directives into a FPM pool section.

## CentOS Stream 10 example

CentOS Stream 10 is the CentOS target, not CentOS Linux 7/8. Its release notes list PHP 8.3, PostgreSQL 16 and MySQL 8.4; use supported packages and security updates. See the [CentOS Stream 10 release notes](https://www.centos.org/centos10/) and [RHEL 10 PHP/FPM instructions](https://docs.redhat.com/en/documentation/red_hat_enterprise_linux/10/pdf/installing_and_using_dynamic_programming_languages/Red_Hat_Enterprise_Linux-10-Installing_and_using_dynamic_programming_languages-en-US.pdf).

For a fresh host using Nginx, begin with:

```sh
sudo dnf install nginx php php-cli php-fpm php-mbstring php-intl php-bcmath php-xml php-gd php-opcache php-pecl-zip
```

Install `php-mysqlnd` for MySQL or `php-pgsql` for PostgreSQL/Supabase. Use `httpd mod_ssl` instead of Nginx for Apache. Resolve any missing extension against the host's enabled, trusted repositories:

```sh
sudo dnf provides '*/sodium.so'
sudo dnf provides '*/curl.so'
php -m
```

Sodium availability can depend on enabled repositories; install the matching extension package from an approved source before continuing. Do not silently skip a failed extension check or mix PHP extension ABIs. Stream 10 uses traditional packages instead of the older `dnf module` workflow.

FPM configuration is normally under `/etc/php-fpm.d/`, and the service is `php-fpm`. Create a dedicated `lawyercms` pool, adjust socket ownership for `nginx` or `apache`, and point the virtual host to that socket. Nginx config is typically under `/etc/nginx/conf.d/`; Apache config under `/etc/httpd/conf.d/`.

Keep SELinux enforcing. Set appropriate persistent read labels for the release and write labels for the vault, `storage/` and `bootstrap/cache/`; use `semanage fcontext` and `restorecon` under the server's policy. Outbound database/HTTPS/SMTP access may need narrowly reviewed policy changes. Diagnose audit denials rather than disabling SELinux or making the vault world-writable. AppArmor hosts need an equivalent path/network review.

## Filesystem ownership and permissions

Run PHP-FPM, Artisan jobs and cron as the same dedicated application account. Build/deploy tooling may own immutable source; grant the application write access only where required.

| Path | Runtime requirement |
| --- | --- |
| `current/public/` | Readable/traversable by the web server; static assets only |
| Application source and `vendor/` | Readable by PHP; keep read-only to runtime where possible |
| `.env` | Readable by the application, mode `0600`; installer needs a temporary write grant to update profile/token |
| `bootstrap/cache/` | Writable by the deployment/application account for cache builds |
| `storage/` | Writable by the application for logs, sessions, cache, views, temporary work and execution lock |
| `CRM_PRIVATE_PATH` | Absolute path outside public root; application-owned, private directories `0700` |
| `shared/secrets/` | Private directory `0700`; credential files `0600` |
| `backups/` | Private archives `0600`, directory `0700`; second copy off-server |

With a same-user deployment, set directory/file permissions deliberately, respecting the web server's traversal rights. Never recursively `chmod 777`. If you use release symlinks, grant the front-end web server access to the real `public/` path and ancestors without exposing the vault. The installer checks CLI permissions; a different FPM user can still fail until configured correctly.

The vault holds application-encrypted files. Encryption does not eliminate the need for access controls, encrypted backup storage and a separately secured `APP_KEY`.

## Nginx

Adapt [deploy/nginx.conf](../deploy/nginx.conf). It redirects HTTP to the explicit canonical HTTPS host, sets `public/` as root, routes missing files to `index.php`, executes only that front controller, denies hidden/sensitive paths and gives hashed build assets long cache lifetimes.

1. Set `server_name`, certificate paths, application path and PHP socket.
2. Install a valid certificate using your host's certificate management process; the sample points to already issued certificate files.
3. Ensure any default/catch-all virtual host does not serve this repository root or accept unintended hosts.
4. Run `sudo nginx -t`, then reload Nginx only after validation succeeds.
5. Verify request-size limits at every reverse proxy as well as Nginx/PHP. The application theme ZIP ceiling is 25 MB compressed; `post_max_size` must allow multipart overhead.

If TLS terminates at a proxy, configure Laravel trusted proxy handling for **only the real proxy addresses** and ensure correct forwarded protocol/host behavior. The sample is for TLS termination at Nginx itself. Do not trust arbitrary forwarded headers from the public internet.

## Apache

Adapt [deploy/apache.conf](../deploy/apache.conf), with modules `rewrite`, `ssl`, `headers` and `proxy_fcgi` enabled. The included `public/.htaccess` provides front-controller rewrites. Keep `AllowOverride All` for that file or move the equivalent rules into the virtual host and disable overrides after testing.

Set the canonical domain, existing certificate paths, `DocumentRoot` and FPM socket. Deny indexes, protect hidden files, and execute only the application front controller. Validate before reloading: `apache2ctl configtest` on Ubuntu or `httpd -t` on CentOS. Use the matching service name (`apache2` or `httpd`).

## Shared hosting / control panels

Use the **prebuilt release** if the host cannot run Composer or Node builds. The host must still provide:

1. PHP 8.3+ with all required extensions, CLI and sufficient memory/time limits.
2. A real document-root setting pointing at the release's `public/`, or a supported equivalent that never exposes application source.
3. A private writable directory outside the public website.
4. CLI access for installation/cache/backup commands and a cron running every minute.
5. Access to the selected database and the required outbound HTTPS/SMTP ports.

Upload/extract the archive in a private application directory, copy/configure `.env`, run the installer, configure the domain's document root and set a versioned PHP path in the cron entry. Use the control panel's PHP selection and extension manager for **both web and CLI**. If the provider cannot meet these requirements, it is not a supported deployment merely because it advertises PHP hosting. Never move `.env`, `vendor/` and the vault into `public_html` to work around a document-root restriction.

## Cron and cache

Install [deploy/cron.example](../deploy/cron.example) as the application user's crontab. Use `crm:tick --budget=45` directly. No Laravel generic worker or scheduler daemon is required; application jobs have primary-store claims, leases, retries and step state. The budget limits when the tick stops starting new jobs; each job handler is separately limited to 120 seconds, enforced on wall-clock time when the CLI has the pcntl extension (recommended) and on CPU time otherwise. A job that exhausts its five attempts, including attempts that crashed PHP, is marked failed and reported to owners/admins. Each tick also deletes completed job records after 30 days and failed ones after 90; keep any longer audit trail you need outside the job table. A scheduling step that keeps failing is logged and shown under cron in the health screen without stopping other background work.

The scheduler and backup commands share `storage/framework/crm-backup.lock`, and jobs respect maintenance mode. Run one application-node cron. Keep its output private and rotate logs using the host's log policy. Do not store secrets in cron command lines. Use a separate external monitor to alert on a stale heartbeat or missing daily backup.

After environment/source changes, run `config:clear`, `config:cache`, `route:cache` and `view:cache` as the application account. Preserve the original `APP_KEY`. `APP_DEBUG` must remain false in production.

## Upload scanning

Set:

```dotenv
SCANNER_URL=https://scanner.example.com/scan
SCANNER_API_KEY="replace-with-private-token-if-required"
CRM_APPROVED_HOSTS=scanner.example.com,smtp.example.com
```

`CRM_APPROVED_HOSTS` is a comma-separated list of **exact lowercase hosts without spaces**, schemes, paths or wildcards. The scanner URL must use HTTPS on port 443 or 8443. The application's destination policy rejects private/reserved IP destinations and redirects; a loopback ClamAV socket or arbitrary private HTTP endpoint is not accepted by this adapter.

The scanner adapter sends multipart field `file`, original filename and optional bearer token. Its successful JSON response must match the digest of the exact submitted bytes:

```json
{
  "verdict": "clean",
  "sha256": "64-lowercase-hex-characters-for-the-submitted-file"
}
```

`verdict` must be `clean` or `infected`. The application uses a 5-second connection timeout and 20-second request timeout. Missing service, TLS/DNS failure, timeout, unexpected verdict or digest mismatch retains quarantine. Scanning is followed by format validation. This contract is not a bundled malware-scanning engine; the operator must provide a contracted service/adaptor meeting it. Do not send privileged documents to a public sample-sharing scanner.

## SMTP and notifications

Approve the exact SMTP host in `CRM_APPROVED_HOSTS`, then configure it in Practice settings. Port 465 uses implicit TLS; 587 requires STARTTLS. Destination IPs are validated/pinned, and TLS verifies the configured host. Private SMTP IPs are blocked by the current adapter. Configure SPF, DKIM and DMARC with the provider.

The test email is queued to the signed-in administrator. Use the health/delivery logs and the cron heartbeat to diagnose it. Outbound messages may be delivered again if a process stops after the SMTP server accepts a message but before the job is marked complete; a stable delivery header supports investigation. Mailbox synchronization is not implemented.

Users select notification categories and immediate-email/daily-digest preferences in the inbox. Digests use UTC grouping and the same cron. Localized plain-text templates support approved variables and generic secure action URLs; sensitive matter details stay inside authenticated views. Identity/invitation emails remain transactional messages. Saved application SMTP settings are separate from unused Laravel scaffold defaults.

## Payments

Configure server-side keys without exposing them to the browser:

- Stripe: `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, optional `STRIPE_ACCOUNT_ID`.
- PayPal: `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_MERCHANT_ID`, `PAYPAL_MODE=sandbox` or `live`.

Register reachable HTTPS callbacks at:

```text
https://legal.example.com/api/v1/payments/webhooks/stripe
https://legal.example.com/api/v1/payments/webhooks/paypal
```

Provider signatures, account, amount, currency and duplicate events are verified server-side. Browser return URLs cannot mark invoices paid. Cron reconciles pending checkouts. Test the configured account in provider sandboxes, including duplicate/unordered callbacks, before using live keys. Do not put a login wall or cache in front of webhook routes.

## AI, public lead tools and local endpoints

Configure the provider, explicit model and key in administrator settings. Source text is capped at 100 KB and output at 4,000 tokens. Daily budgets count runs, not actual currency expenditure; also configure provider-side spending limits. AI outputs still need the application's human review/acceptance workflow.

Custom cloud endpoints need deployment-reviewed transports. Ollama uses an operator-set loopback `LOCAL_AI_URL`; never expose that local model service publicly. Model hosting is an optional VPS service, not part of the baseline package. Slow models can exceed cron budgets; long-running batch integration needs further work.

Public tools additionally require scanner, Turnstile site/secret keys and jurisdiction approval. Public uploads/results expire after 24 hours, reads enforce expiry immediately, and cron performs deletion. Temporary analyzer data is excluded from application backups. Configure processing agreements and consent wording before real uploads.

Google Fonts catalog browsing/installing is separately configured in Website settings with a Google Fonts Developer API key. Only official upstream endpoints are used; installed files and licenses are stored locally. The 16 bundled families need no external font API.

## Reverse proxy, caching and observability

Cache static hashed `/build/` assets and reviewed public pages only under a configuration tested for this application. Never cache authenticated workspace/portal, private files, previews, invoice views, chat, analyzer results, `/setup`, authentication pages or APIs in a public CDN. Keep origin HTTPS and canonical redirects consistent.

Monitor disk/vault growth, PHP errors, database latency, cron age, queue age, failed jobs, scanner and SMTP readiness, payment reconciliation and AI failures. Restrict logs to administrators and avoid recording request bodies, credentials, client documents or bearer links. The application health screen is useful evidence but does not replace an external uptime/cron monitor.

The `/up` framework route reports application boot health; it is not proof that every integration or database profile is operational. Check the authenticated health dashboard for application-specific status.

## Upgrade and rollback

Treat an upgrade as a data migration until proven otherwise:

1. Read the release notes and compare runtime requirements. Build/test the new archive off-server, verify its checksum and retain the old release.
2. Pause cron and any operator-added persistent workers. Enter maintenance with `php artisan down` from the old release.
3. Create and verify an encrypted application backup and copy it off-server. Preserve `.env`, `APP_KEY`, credential files and server configuration separately. Follow [BACKUP_RESTORE.md](BACKUP_RESTORE.md).
4. Extract the new release into a new directory. Attach the same protected `.env`, shared `storage/` and vault. Keep public traffic blocked during the switch. Never overwrite or regenerate `APP_KEY`.
5. For SQL profiles only, apply compatible schema changes with `php artisan migrate --force --no-interaction`. Firestore uses its REST store and requires any release-specific index/data migration instructions rather than SQL migration commands.
6. Rebuild configuration, route and view caches. Switch the release path atomically using the host's deployment tooling; reload FPM if its opcode cache could retain old code.
7. Verify owner/MFA login, representative role permissions, old documents and invoices, current website, a background job and the health screen. Check integrations before allowing queued outbound work.
8. Run `php artisan up`, resume cron/workers, and monitor. Keep the previous release and matching backup through the agreed rollback window.

If checks fail, remain in maintenance. Reverting code alone is safe only when the database/file format is backward compatible. Otherwise restore the matching verified backup into an empty destination and switch as documented. Do not blindly run `migrate:rollback` against issued financial or client records.

## Remaining production gates

Native automated checks and local builds do not establish the security or operating behavior of your server. Real managed Supabase/Firestore certification, approved scanner/SMTP/payment/AI service tests, operating-system deployment checks, a restoration drill, representative document/font rendering and independent credentialed penetration testing remain explicit gates in [RELEASE_GATES.md](RELEASE_GATES.md).

The baseline currently uses persisted polling for chat. Reverb/realtime coediting, country-specific statutory payroll, turnkey court feeds and turnkey local OCR/scanning are extension work rather than preinstalled services.
