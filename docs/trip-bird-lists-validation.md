# Trip bird lists validation

Validated locally on 28–29 October 2026 through version 0.1.9.

- PHP syntax checks passed for the plugin and test helpers.
- JavaScript syntax checks passed; Git whitespace checks passed.
- 41 WordPress integration checks passed against the local database, including
  authorisation, invalid inputs, complete snapshots, version conflicts,
  category assignment, publication retries, draft/public separation,
  preservation of manual post content, and report restoration/replacement.
  Version 0.1.1 adds checks for the 263-bird bundled list, unique bird identifiers,
  initial seeding, and preservation of an administrator-configured master list.
  Version 0.1.3 adds independent Seen/Heard records, heard-only entries, unique
  recorded totals, invalid Heard input, omission by older clients, private Heard
  draft changes, and legacy checklists without Heard data.
- Automated Chrome checks passed using the real editor with a simulated API:
  320px mobile and desktop sizing, search without losing selections, saves with
  further edits in flight, connection failure, browser-storage recovery,
  reconciliation with another screen's changes, full-list preview, publication,
  private corrections, and sign-in expiry. Heard edits are included in recovery
  and conflict reconciliation. Navigation shortcuts clear the sticky toolbar
  and move keyboard focus to the destination.
- Chrome checks against the real local WordPress admin passed at 320px:
  settings, older-trip pagination, saving sightings, preview, publication,
  full public reports, draft corrections remaining private, and updates reusing
  the same report. Anonymous access to the checklist API was denied.
- Public report mobile screenshots and an A4 print PDF were generated for
  inspection under `/private/tmp/slb-trip-bird-lists/`.
  Version 0.1.4 adds a document-style panel with a trip heading, summary totals,
  shaded rows, and matching preview styling; desktop screenshots are also captured.
- Release packaging uses an explicit file allowlist and checks ZIP integrity.
- 10 additional dashboard checks passed for trip categories, inclusive date
  boundaries, exclusion of future/old/unpublished events and saved checklists,
  and administrator permissions. Test events are created through the calendar's
  current API so its occurrence records participate in the query.
- The real WordPress browser suite also verifies the dashboard action at 320px,
  a 44px tap target, exclusion of an existing checklist, and removal immediately
  after saving a newly opened checklist.

Version 0.1.1 includes the administrator-supplied Potteric Carr master list.
Tests use temporary species snapshots and restore the original settings. All temporary
test accounts, events, reports, and checklist records are removed after testing.

Coverage is automated Chrome on macOS, not a physical phone or Safari. Full
offline access is outside version 0.1.0; recovery supports short interruptions
and depends on the same browser retaining pending edits.

See [test and packaging instructions](../scripts/trip-bird-lists/README.md).

## Intermittent search reset investigation — 29 October 2026

The reported reset has not yet been reproduced. Added browser regression checks
that search text and results survive selecting a bird and autosaving, and that a
delayed response from an earlier selection preserves a newer search, keyboard
focus, and cursor selection. The simulated-API suite passes with these checks.
A 390px touch-enabled Chrome check inside the local WordPress admin also kept
the search and control positions unchanged through selecting Wren and saving.
Its temporary test records and account were removed afterwards.

No plugin behaviour or release version was changed. These checks do not emulate
a physical phone's on-screen keyboard. Device/browser details and whether the
search text clears or only the view moves are still needed to narrow the issue.

Follow-up: the user confirmed a computer and results disappearing on selection.
Additional cases cover selecting while an earlier save is pending and the
recorded-only filter. That filter intentionally hides a bird when its final
Seen/Heard record is unticked; disabling it restores the matching result. This
has not been confirmed as the user's cause. The suite can also run at desktop
width using `SLB_TEST_WIDTH=1280`.

## Report publication shortcut — version 0.1.7

The sticky toolbar now opens the full report preview directly, with labels for
initial publication and updating an existing report. Publication and recovery
controls appear before the preview checklist. Closing returns keyboard focus
to the control that opened the preview. Existing save/publication guards remain.

The Chrome simulated-API suite passed at 320px and 1280px, including preview
position below the sticky toolbar, publication button within the viewport,
published-state labels, focus return, and the existing saving/publication cases.
JavaScript syntax, PHP lint, whitespace checks and release ZIP integrity passed.
The installable package is `dist/slb-trip-bird-lists-0.1.7.zip`; no production
deployment was performed.

## Trip Lists report cards — version 0.1.8

The existing `/trip-lists/` query loop's Post Excerpt and Post Date blocks now
show linked reports' published summary (up to 45 words), Seen/Heard/unique
recorded totals, a full-report link, and the associated event date. This is a
plugin rendering change and needs no production page/database migration. Other
pages and older unlinked PDF posts retain their existing output. Ordering is
still the page's publication-date order.

46 local WordPress integration checks passed, including event dates, published
summaries, draft privacy, older-post fallback and unique recorded totals.
The real WordPress Chrome suite passed with assertions on the anonymous list
page's card content and date, mobile overflow, and existing report lifecycle
checks. Screenshots: `/private/tmp/slb-trip-lists-cards-mobile.png` and
`/private/tmp/slb-trip-lists-cards-desktop.png`. Temporary fixtures were removed.
PHP/JavaScript syntax, whitespace and release ZIP checks passed. The installable
package is `dist/slb-trip-bird-lists-0.1.8.zip`; live configuration was not changed.

## Trip Lists card styling — version 0.1.9

Added a stylesheet scoped to the public Trip Lists page, packaged with the
plugin. Cards use cream backgrounds, orange top borders, green summary panels
and full-report buttons. Dates appear above titles. The layout uses two columns
on desktop and one below 760px; older PDF entries share the card styling.
Chrome checks at 1280px, 390px and 320px found no horizontal overflow and
confirmed 44px full-report buttons. Desktop and mobile screenshots were visually
reviewed during development. PHP lint, whitespace and release ZIP checks passed.
The package is `dist/slb-trip-bird-lists-0.1.9.zip`. No live deployment was made.
