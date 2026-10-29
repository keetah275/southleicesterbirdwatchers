# Trip bird lists development

The plugin source is in `app/public/wp-content/plugins/slb-trip-bird-lists`.
The bundled master list contains 263 unique birds from the administrator's
Potteric Carr PDF. Installation seeds it only if no master list is configured.

## Checks

Run PHP lint on both plugin PHP files and `tests/wordpress.php`. Check JavaScript
syntax with `node --check` on `assets/editor.js`.

### WordPress integration

On the **local site only**, activate the plugin and run this from Local's site
shell, using the absolute path to the test file:

```sh
wp plugin activate slb-trip-bird-lists
wp eval-file '/absolute/project/path/scripts/trip-bird-lists/tests/wordpress.php'
```

The integration suite creates temporary events, report posts, and a subscriber.
It temporarily replaces the master-list/category settings, then restores them
and removes its records in a `finally` block. Back up the local database first.
Never run the suite against production.

It checks permissions, complete snapshots, invalid input, revision conflicts,
draft/public separation, category assignment, idempotent publication, preservation
of manual report content, and explicit recovery of trashed/deleted reports.

Run `wp eval-file '/absolute/project/path/scripts/trip-bird-lists/tests/dashboard.php'`
to check dashboard category, calendar-date, and missing-checklist filters. This
local-only suite creates and removes its own calendar events.

### Browser behaviour

Install Playwright in a temporary tools directory or use an existing install.
The script defaults to the installed macOS Chrome executable; override
`SLB_CHROME` for another executable. For an external Playwright installation:

```sh
SLB_PLAYWRIGHT_MODULE=/path/to/node_modules/playwright node scripts/trip-bird-lists/tests/browser.mjs
```

This suite runs the real editor JavaScript and CSS against a simulated API.
It covers 320px mobile layout, search, queued edits, failed saves, browser recovery,
conflict reconciliation, complete preview, publication, and login expiry. It does
not replace the WordPress integration suite or a check inside the real admin UI.
Screenshots are written to `/private/tmp/slb-trip-bird-lists/`.

For a browser check inside the real WordPress admin, use Local's site shell to
run `wp eval-file '/absolute/project/path/scripts/trip-bird-lists/tests/live-browser-fixture.php' setup`.
Then run `tests/wordpress-browser.mjs` with the same Playwright environment
variable as above. Always finish by running the fixture PHP file with `cleanup`
instead of `setup`, including after a test failure. The fixture creates a
temporary administrator, event, and checklist; session cookies stay in an ignored
file under `.local-migration/`. Setup restores the original master list as soon
as the temporary checklist has its snapshot. This helper refuses non-local sites.

## Package

```sh
python3 scripts/trip-bird-lists/build-release.py
```

The ignored `dist/` ZIP contains only the plugin code and readme. It excludes
credentials, backups, tests, uploads, and local-only safeguards. Test the plugin
locally before installing this ZIP through the production Plugins screen.
No production deployment is performed by the build script.
