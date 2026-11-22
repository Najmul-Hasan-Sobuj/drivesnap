# DriveSnap

DriveSnap provides encrypted database backups and an administrator dashboard for Laravel Inertia applications. For DriveSnap installation, configuration, customization or troubleshooting, activate the `drivesnap-development` skill.

- Read the installed package README and `config/drivesnap.php`; retain the host's guard, permission system, page resolver and UI stack.
- Backups contain the entire configured database. Keep storage private and require view plus the action's ability; download permission controls location metadata too.
- Run `php artisan drivesnap:doctor` for sanitized readiness checks. A recent heartbeat is an observation, not proof of a running daemon.
- Preserve archives, Google credentials and encryption keys during upgrades. Publish missing migrations with `drivesnap:install`, then migrate normally. Review UI customizations before `--force`; never reset a database as an upgrade step.
- `DRIVESNAP_DUMP_BINARY` points to a server-approved dump executable. Never accept executable paths from browser input or expose secrets in logs, prompts or commits.
