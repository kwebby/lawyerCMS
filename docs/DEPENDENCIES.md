<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# LawyerCMS dependencies and build reference

The release is resolved by `composer.lock` and `package-lock.json`. Version constraints in `composer.json` are not the installed version list. Install from the lockfiles; upgrades are reviewed code changes, not part of routine deployment.

## Runtime requirements

| Component | Requirement / purpose |
| --- | --- |
| Operating system | Supported Ubuntu LTS or CentOS Stream 10 is the target; native hosting must pass the installer and deployment checks |
| PHP | 64-bit PHP 8.3+ for CLI and PHP-FPM/Apache; Composer's target is pinned to `8.3.0` |
| Memory | Minimum `memory_limit=256M`, recommended `512M`; CLI and FPM each need this |
| Web server | Nginx + PHP-FPM or Apache 2.4 + PHP-FPM, HTTPS, document root `public/` |
| Storage | Private local writable vault outside web root, writable `storage/` and `bootstrap/cache/`, backup headroom |
| Database | MySQL 8.4/InnoDB, PostgreSQL 16+, Supabase PostgreSQL or Firestore Standard REST |
| Scheduler | PHP CLI cron every minute; application durable jobs do not require a permanent worker |
| Network | Configured database transport; outbound HTTPS for integrations and SMTP 465/587; reachable HTTPS gateway callbacks |
| Scanner | Required before uploaded documents, images or themes can be released from quarantine |

A prebuilt release includes PHP dependencies and browser assets. **Production baseline does not require Node, npm, Composer, Redis, Docker, Chromium, Python, PHP shell execution, Reverb or a permanent worker.** Asset compilation and release packaging happen on the build machine. Browser JavaScript runs on the client for the staff/client workspace; public site content and metadata are server-rendered.

### PHP extensions

The capability check requires:

```text
openssl sodium mbstring intl bcmath curl fileinfo dom xml zip gd
```

SQL profiles additionally require the matching `pdo_mysql` or `pdo_pgsql`. SQLite tests/local fixtures use `pdo_sqlite`. Firestore uses `google/auth` and HTTPS REST, without `grpc`. Framework dependencies also rely on standard PHP modules such as `ctype`, `filter`, `hash`, `json`, `pcre`, `session`, `tokenizer` and PDO where applicable. GD must support the image types used by the site (PNG, JPEG and WebP); verify your distribution build.

Check the real host, not just the development machine:

```sh
php -v
php --ini
php -m
php artisan crm:install --profile=mysql --check --no-interaction
```

Replace the profile as appropriate. With Composer available, `composer check-platform-reqs --no-dev` checks the production dependency requirements against the actual PHP process, ignoring the Composer platform simulation. `php --ini` does not prove FPM uses the same configuration; verify the FPM service separately. Do not leave a public `phpinfo()` file online.

## Direct PHP production packages

Locked versions observed on 2026-10-09:

| Package | Locked version | Use |
| --- | --- | --- |
| `laravel/framework` | 13.35.0 | HTTP, validation, authentication integration, filesystem and framework services |
| `inertiajs/inertia-laravel` | 3.5.1 | Server adapter for the React workspace |
| `laravel/ai` | 1.2.0 | Reviewed AI provider gateway |
| `google/auth` | 1.55.1 | Server-side OAuth for Firestore REST |
| `mpdf/mpdf` | 8.3.1 | Controlled PDF exports without Chromium |
| `phpoffice/phpword` | 1.4.0 | DOCX exports from validated document blocks |
| `smalot/pdfparser` | 2.12.5 | Bounded PDF text extraction |
| `spomky-labs/otphp` | 11.5.0 | Staff TOTP MFA |
| `laravel/tinker` | 3.0.2 | Artisan debugging console; it is currently a production Composer requirement |

Transitive dependencies are enumerated in `composer.lock`. Do not remove or upgrade them independently of the lockfile and tests.

## Browser and build packages

| Package | Locked version | Use |
| --- | --- | --- |
| `react`, `react-dom` | 19.3.0 | Staff/client UI |
| `@inertiajs/react` | 3.8.0 | Inertia client navigation |
| `@blocknote/core`, `@blocknote/react`, `@blocknote/shadcn` | 0.55.0 | Canonical BlockNote writing UI |
| `@radix-ui/react-dialog` | 1.2.0 | Existing component dependency; dedicated-page workflows remain the product convention |
| `lucide-react` | 1.54.0 | UI icons |
| `vite` | 8.3.4 | Browser asset bundler |
| `typescript` | 7.0.2 | Frontend type checking |
| `tailwindcss`, `@tailwindcss/vite` | 4.3.3 | Styling |
| `@vitejs/plugin-react` | 6.1.2 | React transform |
| `laravel-vite-plugin` | 3.2.0 | Laravel asset integration |
| `vitest` | 5.0.3 | Frontend tests |
| `prettier` | 3.9.9 | Source formatting |

Type packages and transitive packages are pinned in `package-lock.json`. BlockNote XL PDF/DOCX exporter packages are **not** installed; exports use the PHP libraries above. Bundled Google Fonts include their own redistribution licenses under `public/fonts/website/`.

## Development and test dependencies

Direct PHP development packages: PHPUnit 12.5.38, Larastan 3.13.0, Pint 1.32.1, Laravel Boost 2.10.3, Pail 1.2.7, Pao 1.1.5, Faker 1.24.1, Mockery 1.6.15 and Collision 8.9.5. They are excluded from the prebuilt production `vendor/` directory.

Use Composer 2 and **Node 24 LTS** for a stable build environment. The currently locked toolchain requires at least Node 22.12 on the Node 22 line; Vite and Vitest's engine requirements differ, so use a version accepted by both. Node 26.7.0/npm 11.19.0 was the local build environment used during implementation; that does not make Node 26 an LTS recommendation. Consult the [official Node release schedule](https://nodejs.org/en/about/previous-releases) when updating build images.

Release packaging additionally uses Bash, rsync, tar, `mktemp`, `shasum` and enough temporary disk for a staged production copy. On Linux, `shasum` is commonly provided by the distribution's Perl Digest::SHA package. None of these packaging tools is called by public HTTP requests.

```sh
composer install --prefer-dist --no-interaction
npm ci
npm run typecheck
npm test
php artisan test --compact
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
npm run build
```

`composer run check` combines PHP tests, PHPStan, the asset build and frontend tests. Run checks in a development checkout with isolated test configuration, never in a production release. `bash bin/build-release` also runs frontend tests alongside the build, PHP tests and PHPStan before staging production dependencies. These commands are not evidence of a successful production penetration test.

## Testing without touching live data

**Tests can recreate/drop database tables.** Laravel feature tests use `RefreshDatabase`; some use explicit fixture writes. The default `phpunit.xml` selects SQLite `:memory:`. Environment variables and cached configuration can override that selection. Never run tests in a checkout with production credentials, or against a database containing firm information. Use separate DB users denied access to every live database.

Before the fast suite, use an isolated development checkout and clear configuration cache. Set the SQLite test variables explicitly if your shell has database variables exported:

```sh
php artisan config:clear
DB_URL='' CRM_STORE=sql DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --compact
```

Native adapter tests use a separately created, disposable database. For example, with credentials supplied securely in the test environment:

```sh
DB_URL='' CRM_STORE=sql DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=counsel_contract_test php artisan test --compact tests/Feature/Publishing
DB_URL='' CRM_STORE=sql DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=counsel_contract_test php artisan test --compact tests/Feature/Publishing
```

`counsel_contract_test` is a deliberate legacy safety identifier retained after the LawyerCMS rebrand. The optional multiprocess finance test refuses any other database name and requires MySQL/PostgreSQL plus explicit `RUN_FINANCE_CONCURRENCY=1`. Ordinary feature suites do **not** all enforce that name; isolating credentials remains mandatory.

For the finance concurrency check, migrate the disposable test database first, then run the single test with the same explicit connection variables and `RUN_FINANCE_CONCURRENCY=1`:

```sh
php artisan test --compact tests/Feature/Business/FinanceConcurrencyTest.php
```

That command alone skips the concurrency case unless the opt-in flag is set. The test creates concurrent PHP processes, so process execution is a **test-host** requirement only. Managed Supabase and Firestore runs require real isolated projects and indexes; mocked REST tests do not certify either service. See [RELEASE_GATES.md](RELEASE_GATES.md) for evidence and remaining work.

## External services are optional until their feature is enabled

| Feature | Additional configuration |
| --- | --- |
| Transactional mail | Approved SMTP destination, saved credentials, verified sending domain, working cron |
| Uploaded files/media/themes | Contract-compatible HTTPS scanner; failures keep material quarantined |
| Stripe | API secret, signing secret, optional connected account ID; provider sandbox verification |
| PayPal | Client ID/secret, webhook ID, merchant ID and sandbox/live mode |
| Cloud AI | Administrator-supplied provider/model/key, budgets, approval workflow |
| Local AI | Operator-controlled loopback `LOCAL_AI_URL`; local model serving is an optional VPS service |
| Public analyzers | AI, scanner, Turnstile and jurisdiction review |
| Extra Google Fonts | Google Fonts Developer API key, official upstream access; installed fonts then served locally |
| Firestore | Google service-account credentials, IAM, composite indexes and network access |

Reverb, realtime BlockNote coediting, court integrations, mailbox synchronization, country-specific payroll engines and turnkey local OCR/scanning are not required dependencies of the current baseline. They are not implied to be shipped by the presence of generic framework configuration.

## Updating dependencies

Review upstream release notes and license changes, update locks in a development branch, run all relevant tests and native-store checks, scan dependency advisories, rebuild the distributable and perform a restore/upgrade drill. Keep original font and third-party license files in redistributed artifacts. The project license does not replace the licenses of its dependencies.
