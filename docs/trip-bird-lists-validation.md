# Trip bird lists validation

Validated locally on 28 October 2026 for version 0.1.0.

- PHP syntax checks passed for the plugin and test helpers.
- JavaScript syntax checks passed; Git whitespace checks passed.
- 29 WordPress integration checks passed against the local database, including
  authorisation, invalid inputs, complete snapshots, version conflicts,
  category assignment, publication retries, draft/public separation,
  preservation of manual post content, and report restoration/replacement.
- Automated Chrome checks passed using the real editor with a simulated API:
  320px mobile and desktop sizing, search without losing selections, saves with
  further edits in flight, connection failure, browser-storage recovery,
  reconciliation with another screen's changes, full-list preview, publication,
  private corrections, and sign-in expiry.
- Chrome checks against the real local WordPress admin passed at 320px:
  settings, older-trip pagination, saving sightings, preview, publication,
  full public reports, draft corrections remaining private, and updates reusing
  the same report. Anonymous access to the checklist API was denied.
- Public report mobile screenshots and an A4 print PDF were generated for
  inspection under `/private/tmp/slb-trip-bird-lists/`.
- Release packaging uses an explicit six-file allowlist and checks ZIP integrity.

The master list remains empty for the administrator's replacement list. Tests
use temporary species snapshots and restore the original settings. All temporary
test accounts, events, reports, and checklist records are removed after testing.

Coverage is automated Chrome on macOS, not a physical phone or Safari. Full
offline access is outside version 0.1.0; recovery supports short interruptions
and depends on the same browser retaining pending edits.

See [test and packaging instructions](../scripts/trip-bird-lists/README.md).
