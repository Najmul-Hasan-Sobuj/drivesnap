# DriveSnap

Encrypted database backups to Google Drive, with a dashboard for **Laravel + Inertia** applications. MIT licensed.

Supports Laravel 12/13, Inertia Laravel 2/3, PHP 8.3+, and Vue 3, React, or Svelte 5 page adapters. Database engines: MySQL, MariaDB, SQLite. PostgreSQL and application-file backups are not included.

## What you get

- A responsive dashboard: history, progress, encrypted downloads, retries, deletion, daily scheduling and retention.
- Local encrypted copies, with optional Google Drive copies using resumable uploads.
- Streaming XChaCha20-Poly1305 encryption and archive checksums; bounded memory for large archives.
- Explicit server-side permissions, encrypted OAuth credentials, single-use OAuth state and private storage.
- Native Laravel jobs, scheduling and activity events. No Google SDK or frontend runtime dependency.

This is an administrator tool: authorized downloads contain the **entire configured database**, including sensitive tables. It is single-installation, not tenant-scoped.

## Install

```bash
composer require najmul-hasan-sobuj/drivesnap:^0.1
php artisan drivesnap:install --stack=vue
php artisan migrate
npm run build
```

Choose `--stack=react` or `--stack=svelte` for your app. The installer publishes an Inertia page, a shared dashboard module/style, configuration and one migration. It preserves existing files. Your Inertia resolver must load `resources/js/pages`; adjust its resolver if your app uses a different directory.

For nested admin pages:

```bash
php artisan drivesnap:install --stack=vue --page=admin/backups/Index
```

Set `page` in `config/drivesnap.php` to the same page name. Your application's persistent layout can wrap it normally. The shared module mounts only in the browser; all adapters support SSR.

Available on [Packagist](https://packagist.org/packages/najmul-hasan-sobuj/drivesnap); no custom Composer repository is required.

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

Run migrations normally after upgrading. To refresh published UI assets:

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
