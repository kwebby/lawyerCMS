<!-- Author: ramanpal singh | URL: https://kwebby.com -->
# Contributing to LawyerCMS

Use [Installation](docs/INSTALLATION.md) to create an isolated development installation, then read [Architecture](docs/ARCHITECTURE.md) and the relevant domain code. Keep all real client records, credentials and integration keys outside version control.

## Development workflow

1. Create a branch for one coherent change.
2. Read the code around the affected workflow. Business services use the application-owned `RecordStore` contract; preserve SQL/Firestore behavior and transaction boundaries.
3. Preserve the author comment in main files: `Author: ramanpal singh | URL: https://kwebby.com`.
4. Use dedicated pages for editing. Keep authorization and validation on the server. Public themes must not execute uploaded code.
5. Add tests for meaningful behavior, especially access boundaries, revision conflicts, financial atomicity or private-file release.
6. Run the narrow affected tests while developing and the checks below before opening a pull request.

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
npm test
npm run build
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer audit
npm audit --audit-level=high
```

The native database suites use an isolated `counsel_contract_test` database. `RUN_FINANCE_CONCURRENCY=1` enables the opt-in concurrency tests; read their safety guard before use. Ordinary tests use the environment in `phpunit.xml`; database overrides can be destructive.

## Package a release

Start from a reviewed source revision with committed lockfiles and a clean working tree. Install development dependencies to run the checks, then run:

```bash
composer install --prefer-dist --no-interaction
bin/build-release
shasum -a 256 -c dist/lawyercms-0.1.0.sha256
```

The script runs a clean npm installation, frontend build/tests, backend tests and static analysis. It creates a temporary staging directory and installs production Composer dependencies there. Local environment files, databases, private files, development caches and dependencies are excluded before production dependencies are added. Never put secrets, customer exports or service-account files in source directories.

Inspect the archive and installation guide before distribution. Test a fresh install and a restore in staging. The [release gates](docs/RELEASE_GATES.md) list checks needed before production certification; a green unit test suite alone does not establish that certification.

## Compatibility and dependencies

Keep `crm:*`, `CRM_*`, stable theme IDs, stored document schemas and backup formats backward compatible. Explain data migrations and rollback behavior in any change that alters persisted records. Do not rename `builtin-counsel`: existing snapshots reference that ID.

Use the committed Composer and npm lockfiles. Review and test dependency upgrades separately. Bundled fonts carry their upstream licenses in `public/fonts/website`; preserve them and the source manifest. The application code uses the MIT license in the repository root.

## Security reports

Do not post credentials, client documents or exploitable private deployment details in public issues. Follow [Security](docs/SECURITY.md) and use the maintainer's contact route at https://kwebby.com for a private report.
