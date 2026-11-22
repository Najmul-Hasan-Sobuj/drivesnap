# DriveSnap

Encrypted database backups to Google Drive, with a dashboard for **Laravel + Inertia** applications. MIT licensed.

Supports Laravel 12/13, Inertia Laravel 2/3, PHP 8.3+, and Vue 3, React, or Svelte 5 page adapters. Database engines: MySQL, MariaDB, SQLite. PostgreSQL and application-file backups are not included.

## What you get

- A responsive dashboard: history, progress, encrypted downloads, retries, deletion, daily scheduling and retention.
- Backup locations: copy a project-relative local archive path and open its Google Drive file/folder.
- Local encrypted copies, with optional Google Drive copies using resumable uploads.
- Streaming XChaCha20-Poly1305 encryption and archive checksums; bounded memory for large archives.
- Explicit server-side permissions, encrypted OAuth credentials, single-use OAuth state and private storage.
- Native Laravel jobs, scheduling and activity events. No Google SDK or frontend runtime dependency.

This is an administrator tool: authorized downloads contain the **entire configured database**, including sensitive tables. It is single-installation, not tenant-scoped.

## Install

```bash
composer require najmul-hasan-sobuj/drivesnap:^0.2
php artisan drivesnap:install --stack=vue
php artisan migrate
npm run build
```

Choose `--stack=react` or `--stack=svelte` for your app. The installer publishes an Inertia page, a shared dashboard module/style, configuration and migrations. It preserves existing files. Your Inertia resolver must load `resources/js/pages`; adjust its resolver if your app uses a different directory.

For nested admin pages:

```bash
php artisan drivesnap:install --stack=vue --page=admin/backups/Index
```

Set `page` in `config/drivesnap.php` to the same page name. Your application's persistent layout can wrap it normally. The shared module mounts only in the browser; all adapters support SSR.

Available on [Packagist](https://packagist.org/packages/najmul-hasan-sobuj/drivesnap); no custom Composer repository is required.

## IDE agent guidance

DriveSnap ships a concise Laravel Boost guideline and an on-demand `drivesnap-development` skill using the [Agent Skills format](https://agentskills.io/specification). They cover safe installation/upgrades, permissions, approved dump binaries, Google setup, encryption/recovery, workers, readiness, UI customization and activity integration.

**With Laravel Boost:** after Composer installation/update, run `php artisan boost:install` for initial setup, or `php artisan boost:update` in an already configured project. Select `najmul-hasan-sobuj/drivesnap` when prompted for new package guidance and enable guidelines/skills for your agents. Boost reads `resources/boost/guidelines/core.blade.php` and `resources/boost/skills/drivesnap-development/SKILL.md` from the installed package and writes the selected agents' files. A non-interactive Composer update alone does not opt a new package into Boost guidance. See [Boost's package guidance documentation](https://laravel.com/framework/docs/boost#third-party-package-skills).

**Without Boost:** publish just the portable skill, without adding a dependency:

```bash
php artisan vendor:publish --tag=drivesnap-skill
```

This writes `.agents/skills/drivesnap-development/SKILL.md`. Existing files are preserved; after an upgrade, review local customizations before explicitly republishing with `--force`. For an IDE using a different skill directory, copy that skill folder into the IDE's documented project skill location. Restart/reload the agent session if necessary, then invoke `$drivesnap-development` or ask a DriveSnap-specific question. Discovery and implicit activation depend on the IDE; installing a Composer library alone cannot guarantee every agent loads it.

Package installation does not silently rewrite your project's `AGENTS.md`, editor settings, global skills or agent permissions. Boost remains optional and development-only. Keep project-specific conventions in your own instructions; use Boost's rule tooling for host-specific `.ai/rules` rather than distributing application rules as universal package requirements. Never put environment secrets or live database artifacts in an agent skill.

## Authorize before opening the dashboard

Access is denied until you define the gates. For example, in your application's service provider, using **your existing administrator check**:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewDriveSnap', fn ($user): bool => $user->is_admin === true);
Gate::define('manageDriveSnap', fn ($user): bool => $user->is_admin === true);
```

Replace `is_admin` with your application's actual authorization rule. Never authorize every authenticated user. The default URL is `/drivesnap`; routes use the `web` and `auth` middleware.

Custom admin guards and separate permissions are supported:

```php
// config/drivesnap.php
'prefix' => 'admin/backups',
'middleware' => ['web', 'auth:admin'],
'guard' => 'admin',
'page' => 'admin/backups/Index',
'abilities' => [
    'view' => 'backups.view',
    'create' => 'backups.create',
    'download' => 'backups.download',
    'delete' => 'backups.delete',
    'manage' => 'backups.manage',
],
```

Register these abilities with Laravel Gates or your existing permission system. Every action requires view access plus its own ability. Queued requests recheck the requester's current permission before running. Inactive users with an `is_active` attribute are refused. CLI/scheduled backups run as trusted server operations.

## Readiness and approved dump tools

Run the server-side checks after installation and whenever runtime configuration changes:

```bash
php artisan drivesnap:doctor
php artisan drivesnap:doctor --json
```

Doctor checks the approved executable/version (MySQL/MariaDB), source and metadata database connectivity, private storage read/write access, cache/atomic locks, timeout budgets and Google Drive API connectivity when connected. It returns a non-zero exit code for failed checks and caches a sanitized report for one hour. Database connectivity is not proof of dump privileges: complete a real backup and isolated restore as well.

The dashboard shows that report and the latest worker/scheduler heartbeats. It never runs an executable or calls Google just because someone opens or polls the page. **Not checked** and missing/stale heartbeat warnings are intentionally different from a verified successful check. Heartbeats are recent observations, not proof that a daemon will stay running. All servers must use the same cache store and private archive storage where applicable.

`queue:work` emits idle-worker signals for the configured backup queue. `queue:listen` / `--once` only produce a signal when processing a job; an idle listener can therefore have no recent signal. A lightweight scheduled cache heartbeat runs every minute; configure Laravel's scheduler normally. This heartbeat does not enable automatic database backups.

By default the dump tool comes from `DRIVESNAP_DUMP_BINARY` / `backup_binary`. Server operators can add approved choices in `config/drivesnap.php`:

```php
'binaries' => [
    'MySQL 8.4' => '/usr/local/mysql/bin/mysqldump',
    'MariaDB' => '/usr/local/bin/mariadb-dump',
],
```

These are examples: use existing executable paths on your own server. The dashboard stores only the selected **key**, never a browser-supplied executable path. Only users with the manage ability can select a tool. Full paths and provider diagnostics are not returned to the dashboard. The default choice continues to use `backup_binary`, and SQLite needs no external tool. After a selection change, rerun Doctor to verify the new choice. Restart long-lived workers after changing server configuration.

### Larger database timeouts

```dotenv
DRIVESNAP_DUMP_TIMEOUT=120
DRIVESNAP_JOB_TIMEOUT=300
```

The dump timeout must be 1–3600 seconds; the job timeout must be 30–7200 seconds and leave **more than 30 seconds** beyond the dump budget for encryption/upload. For queue connections exposing `retry_after`, it must exceed the job timeout. Unsafe configurations are reported by Doctor and refused before a new backup is queued. The snapshot engine also checks the budget before taking a snapshot.

For example, a 600-second dump budget can use a 900-second job budget and `retry_after=960`. Configure your worker/listener process timeout, process monitor termination grace period and SQS visibility timeout to match; Doctor cannot inspect external process-manager/SQS settings. Uploads must still fit within the total job budget; larger archives may resume on retry. SQLite ignores the external binary choice.

## Connect Google Drive

1. Create a **separate Google Cloud project and OAuth web client for DriveSnap**, enable the Google Drive API and configure the consent screen.
2. Add the exact absolute callback URL from your app's named `drivesnap.callback` route to authorized redirect URIs. Default: `https://your-app.example/drivesnap/google/callback`. Custom prefixes change this URL.
3. Store the OAuth client ID and secret in your server's protected environment:
    ```dotenv
    DRIVESNAP_GOOGLE_CLIENT_ID=
    DRIVESNAP_GOOGLE_CLIENT_SECRET=
    ```
4. Use HTTPS, secure HTTP-only session cookies and a shared cache in production. Rebuild config cache after configuration changes.
5. Open the dashboard as an authorized administrator, select **Connect Google Drive** and approve consent for your own account.

DriveSnap requests `drive.file`, `openid` and `email`. It can manage files it creates, rather than your whole Drive. Offline access requires a refresh token. Google testing-mode token expiry and OAuth publishing requirements still apply; see [Google's server-side OAuth guide](https://developers.google.com/identity/protocols/oauth2/web-server) and [Drive scope guide](https://developers.google.com/workspace/drive/api/guides/api-specific-auth).

Tokens are encrypted in the database and excluded from dashboard responses. A DriveSnap folder is created on the first upload. Reconnect the same account to retain access to older backups. Disconnect revokes the OAuth grant, preserves existing archives and disables the schedule. Google revocation invalidates grants for **all OAuth clients in the same Google Cloud project**, not only DriveSnap. A separate client inside a shared project does not isolate revocation.

When deliberately reusing a Google project (for example an existing Gmail integration), set `revoke_on_disconnect` to `false` in `config/drivesnap.php`. Disconnect then clears DriveSnap's encrypted local tokens and disables its schedule **without calling Google revocation**. The UI explicitly explains that the Google grant remains active. Other integrations retain their authorization. Remove the Google grant manually only when you intend to disconnect every affected integration. The default remains `true` for dedicated projects.

## Workers and automatic backups

Run a supervised worker:

```bash
php artisan queue:work --queue=backups --timeout=300 --tries=3
```

Set the queue connection's `retry_after` (or SQS visibility timeout) **above 300 seconds**, for example 360. Keep the server scheduler running:

```cron
* * * * * cd /path/to/application && php artisan schedule:run >> /dev/null 2>&1
```

Enable daily backups in the dashboard. Scheduling is **off by default**. Set `daily_at` and `timezone` in configuration. A connected account receives a Drive copy; otherwise the scheduled backup is local only. Shared atomic cache locks are required for multiple workers/servers. Run backup workers on the server holding the database/private archive storage, or provide shared private storage mounted at the same path.

Manual server commands:

```bash
php artisan drivesnap:run          # Drive when connected, otherwise local
php artisan drivesnap:run --local
php artisan drivesnap:prune --dry-run
php artisan drivesnap:prune
```

Retention removes completed backups older than the selected number of days, always preserving the newest completed backup. It pauses if that newest backup is inaccessible. Remote deletion must succeed before its local copy and record are removed.

MySQL/MariaDB require a compatible `mysqldump`/`mariadb-dump` binary and sufficient database privileges. Configure `backup_binary` or `DRIVESNAP_DUMP_BINARY` if necessary. Passwords are passed through a private temporary options file, not process arguments. Transactional-table consistency uses `--single-transaction`; avoid schema changes while a dump runs. SQLite uses `VACUUM INTO`. Set `connection`/`DRIVESNAP_DB_CONNECTION` to select another configured database.

## Find your backup files

Users with the download ability can select **View locations** in a completed backup's Storage column. The dialog shows the existing local archive's path relative to the Laravel project, offers **Copy path** and **Download encrypted**, and links to the completed Google Drive copy. The Drive card also has **Open Drive folder** after the first upload creates a folder.

Local paths refer to the **application server**, not the browser's computer. Browsers cannot open that server's file manager; use your deployment's file manager or SSH for server access. Absolute server paths, OAuth tokens and upload sessions are never exposed. View-only users receive no location paths or Drive links. Missing local copies can be retrieved through the authorized download action. Drive links require access in the owning Google account and do not change sharing permissions; the page records uploaded copies, not a live check for files moved/deleted directly in Drive. Archives remain encrypted and cannot be previewed as documents.

## Encryption and recovery

Archives and temporary snapshots are private under `storage/app/private/drivesnap`. Do not expose that directory through the web server. Downloads remain encrypted.

By default, archive encryption derives its key from `APP_KEY`. For a dedicated archive key, set `DRIVESNAP_ENCRYPTION_KEY` to a securely generated, base64-encoded 32-byte value. Store that key separately from the application/database backups. **Losing the key loses access to the archives.** Laravel's `APP_PREVIOUS_KEYS` are also considered during archive decryption. When rotating a dedicated key, retain the previous value in the protected previous-key configuration while old archives remain in use.

Decrypt explicitly on a trusted machine with the matching key configuration:

```bash
php artisan drivesnap:decrypt /private/archive.enc --output=/private/new-restore.sql
```

The output must not already exist. Authentication failures delete incomplete plaintext. SQLite output is a SQLite database file; MySQL/MariaDB output is SQL. Verify recovery in an **isolated database** before considering a backup usable. There is intentionally no browser action that overwrites your running database.

Database backups include the app's stored encrypted OAuth tokens. Keep the application encryption keys protected and separately recoverable. Download SHA-256 checks detect corruption; authenticated decryption verifies the actual archive content.

## Activity integration

Listen to `DriveSnap\BackupActivity` in your app to integrate existing audit logs or notifications:

```php
use DriveSnap\BackupActivity;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(BackupActivity::class, function (BackupActivity $event): void {
    Log::info('drivesnap.'.$event->action, $event->backup->auditLogValues());
});
```

Actions include requested, retried, completed, failed, downloaded and deleted. Backup IDs are UUID strings: do not place them in numeric audit subject columns. Use metadata when your audit system expects integer subjects. Activity payloads exclude tokens, upload sessions and private paths.

## Upgrades and customization

After upgrading, run `php artisan drivesnap:install` with your existing stack/page options to publish any missing upgrade migrations, then run `php artisan migrate`. Existing configuration, original migration files, backups and Google credentials are preserved. To refresh published UI assets:

```bash
php artisan drivesnap:install --stack=vue --page=admin/backups/Index --force
npm run build
```

`--force` replaces dashboard UI files, so preserve your customizations first. It does **not** overwrite existing configuration or create duplicate migration files. Review new configuration defaults when upgrading.

## Development and verification

```bash
composer install
composer test
npm ci
npm test
```

Tests cover encrypted snapshots, corruption, large-file streaming, permission boundaries, revoked requester access, OAuth state, resumable upload/idempotence, safe remote download/deletion, retention and repeat installation. Frontend tests compile all three adapters and check endpoint restrictions.

Google HTTP responses are faked in automated tests. Run a real consent, upload, download and isolated restore with your own dedicated OAuth client before production rollout. Test data and credentials are never required in source control. CI tests Laravel 12 and 13 and builds a tagged release from the `VERSION` file after checks pass on main.

## Security reports

For sensitive vulnerabilities, use this repository's **Security → Report a vulnerability** feature when private reporting is available, or contact the maintainer privately through their GitHub profile. Do not post tokens, database archives or encryption keys in public issues. For ordinary bugs, include framework versions and a sanitized reproduction.

## License

[MIT](LICENSE), © 2026 Najmul Hasan.
