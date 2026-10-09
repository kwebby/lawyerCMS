<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# LawyerCMS encrypted backups and database migration

The compatibility command `crm:backup` (unchanged by the LawyerCMS rebrand) snapshots the application-owned record store and all referenced encrypted private files. It works through the same bounded `RecordStore::scan` / `restore` interfaces for MySQL, PostgreSQL, Supabase PostgreSQL and Firestore. IDs, optimistic versions, creation times, update times, issued invoice snapshots and document revision references are preserved exactly. No application server or external database is contacted during archive verification.

The archive uses Argon2id passphrase derivation and authenticated XChaCha20-Poly1305 secretstream frames. Each private file has its own SHA-256 check, and the final authenticated manifest verifies event order, record/file counts and the aggregate checksum. A wrong password, modified frame, missing file, duplicate identity, path traversal, truncated archive or appended payload fails verification. There is no unencrypted JSON export endpoint.

## Recovery materials

Keep these in separately controlled recovery storage:

- A verified `.lcrm` backup and a second off-server copy.
- Its randomly generated backup passphrase (at least 16 characters; use substantially more entropy in production).
- The original Laravel `APP_KEY`. Vault files and encrypted integration settings still use this key inside the backup. Restoration refuses a different application key. The archive stores only a one-way fingerprint of the key.
- The application release, Composer/npm lockfiles, server configuration and separately protected `.env`/service-account credentials. These are deliberately not embedded in an archive of application data.

Use the interactive hidden prompt. Scheduled runs can receive `CRM_BACKUP_PASSPHRASE` from a protected service environment or secret manager; never place a passphrase in a command-line argument, shell history, repository or publicly readable cron file. The file and working-directory permissions are 0600 and 0700 respectively. The web server's document root must remain the application's `public` directory.

## Create and verify

1. Stop any optional persistent workers. Take the application into maintenance mode with `php artisan down`. The standard `crm:tick` command checks maintenance and shares the backup lock, so scheduled jobs cannot race a snapshot.
2. Create an output directory outside the public webroot, with access limited to the service account. Ensure enough space for the database JSON plus referenced ciphertext and temporary work. Do not put the archive in a vault that will be used as a restore destination.
3. Run:

   ```sh
   php artisan crm:backup create /srv/lawyercms/backups/firm-2026-10-09.lcrm
   php artisan crm:backup verify /srv/lawyercms/backups/firm-2026-10-09.lcrm
   ```

4. Copy the verified encrypted archive to separately controlled storage. Verify the copied archive as well.
5. Resume the application with `php artisan up`, then restart optional workers. A failed backup leaves maintenance mode active so the operator can inspect the failure.

Schedule this procedure daily during a planned maintenance window. Alert on nonzero exit status and on missing daily archives. The application never silently discards an existing archive or rotates old backups; set retention and deletion in the server's backup policy. Verification reads the entire archive but does not change the database or private vault.

## Exclusions

Temporary analyzer uploads, analyzer verification grants, public AI results, public analyzer jobs and verification/result emails, public quota identifiers, identity reset/verification tokens, server sessions and unreferenced vault files are excluded. A lead explicitly created from an analyzer result remains a business record. An unexpected reference from a retained business record's file field into `temporary/` aborts the backup instead of copying temporary material. Move retained documents into the normal private-document lifecycle before backing up.

Application caches, compiled assets, logs, native database tables outside `crm_records`, and PHP session files are not part of this format. Restored users sign in again; expired email verification/reset links must be requested again. Pending ordinary business jobs are preserved, so review integration configuration and outstanding deliveries before restarting workers.

## Restore drill or migration

Restore only into a **new empty database/Firestore database and empty private vault**. Existing records or vault files cause an immediate refusal. This keeps a restore from overwriting the live firm.

1. Follow [INSTALLATION.md](INSTALLATION.md) to unpack the same application release on an isolated server. Configure the destination database profile, its credentials, the original `APP_KEY`, and a fresh `CRM_PRIVATE_PATH`. For SQL profiles run `php artisan migrate --force --no-interaction`; do not run the owner bootstrap or demo seed. For Firestore provision the empty destination database/IAM and required indexes without creating installation records.
2. Block public access, pause cron/optional workers, and run `php artisan down`. Keep email, payments, AI and other outbound integrations disabled for the drill.
3. Verify the source archive, then restore:

   ```sh
   php artisan crm:backup verify /srv/lawyercms/recovery/firm-2026-10-09.lcrm
   php artisan crm:backup restore /srv/lawyercms/recovery/firm-2026-10-09.lcrm
   ```

4. Restore authenticates and stages the entire archive before modifying the destination. Metadata staging is encrypted with the application key; private-file staging contains the original encrypted bytes. Record imports are bounded by both row count and byte size. A failed import attempts to remove only the exact records/files introduced into the empty destination and keeps maintenance active. If cleanup cannot be completed, use another empty destination before retrying.
5. Compare record counts and sample stable IDs/versions. Sign in as an owner and representative staff/client users; verify matter grants, a private upload, an older document revision, an issued invoice, a payslip and a theme asset. Test the available database profile's transactions and review pending jobs. Update canonical site URL/SMTP/gateway endpoints only after confirming the correct target environment.
6. Keep the old database and vault as rollback material. Switch the web application/database configuration only after the drill passes. Re-enable the intended integrations, run `php artisan up`, then resume cron/workers. The restore command never reopens the site automatically.

## Evidence and release gates

Automated tests cover a 504-record paginated round trip, exact version/timestamp preservation, encrypted private-file recovery, temporary-data exclusion, tampering, incorrect passwords, truncation, application-key mismatch, nonempty destination refusal, maintenance enforcement and job-lock contention. Store contract tests cover bounded snapshot pagination and collision-safe restoration independently.

A real managed Supabase/Firestore restore and production-size restore timing remain deployment gates requiring the actual service credentials and infrastructure. Measure the recovery time and document the oldest recoverable backup in each firm's operating procedure. An independent credentialed penetration test and an operator-observed restore drill are still required before production release.

## Backup coverage and operator record

The portable archive is an application backup, not a full host image. It includes retained record metadata plus the private encrypted files referenced from the application's own file fields (`Backup::FILE_FIELDS`): uploaded and written documents, page and document revisions, non-public AI results, issued invoice and payslip PDFs, theme uploads/assets, website media and its resized variants, and installed font files/licenses. Text typed into other fields (enquiries, chat messages, names, theme tokens) is never treated as a file reference, even when it looks like a vault path. A referenced file that is missing from the vault aborts the backup. When a new feature stores a vault path in a record, add that field to the list and to the backup tests. Bundled fonts and application source belong to the matching release archive. Preserve any custom server files, certificates, system jobs and external service configuration separately.

The `.lcrm` extension is retained for format compatibility; renaming the product did not change archive format 1. Do not rename record collections or encrypted vault paths inside an archive.

For each drill, record the release version, profile, archive hash, creation/verification dates, protected key identifier, restored counts, validation checks, elapsed restore time and the operator's decision. Store no passwords or client document text in the drill report. A successful `verify` authenticates the archive; only an actual restore and application-access check demonstrate operational recovery.

For unattended creation, provide `CRM_BACKUP_PASSPHRASE` through a protected process environment, use `--no-interaction`, capture the exit status, verify before copying, and alert on any failure. Do not add `php artisan up` to an unconditional shell cleanup trap: a failed backup or migration should leave the application closed for inspection. The application does not provide automatic backup scheduling, off-site transport, retention deletion or key escrow. Implement those in the host's controlled backup system.
