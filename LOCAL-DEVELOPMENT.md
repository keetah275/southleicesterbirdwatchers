Local development
=================

Open the existing southleicesterbirdwatchers site in Local and start it.
The local URL is http://southleicesterbirdwatchers.local.

WordPress lives in `app/public`. Use Local's Open Site Shell to run WP-CLI.
The local database connection remains in the untracked `wp-config.php`.

The migration backup of the original local files and database is in
`.local-migration/backups/`. The live database export and downloaded files
are in `.local-migration/live/`. These contain private data: keep them local.

Local-only safeguards disable scheduled WP-Cron jobs and outgoing WordPress
email. SiteGround hosting plugins are disabled locally. The live site is
unaffected. The imported database retains the live site's WordPress users.

Uploads, database dumps, runtime logs, and credentials are excluded from Git.
Git tracks custom code, documentation, and Local configuration templates.
WordPress core, installed third-party plugins, and the bundled default themes
are excluded. New custom plugin and theme directories are tracked by default.
Review `.gitignore` when installing additional third-party dependencies.

The local database and uploads are required to reproduce the imported site's
content; cloning this repository alone does not recreate that content. Keep
private backups separately. Activate the same theme and plugins when rebuilding
the environment. The database includes site-editor customisations.

The `local-development.php` must-use plugin blocks email only when
`WP_ENVIRONMENT_TYPE` is `local`. Package only the feature plugin for production,
not the entire repository or Local configuration.
