# Trip bird lists — feature specification

Status: proposal for review before implementation  
Date: 28 October 2026

## Purpose

Allow administrators to record bird sightings against a trip event and publish
a complete HTML trip list without creating a spreadsheet, exporting a PDF,
uploading it, and manually assembling a WordPress post.

The checklist must work well on a phone so an administrator can maintain it
during a trip when a mobile connection is available. Recording sightings and
publishing a public report are separate actions.

## Confirmed requirements

- Administrators manage the lists.
- A list is associated with a trip created as an event.
- Every trip report includes the full bird list, with an indicator for each
  species showing whether it was seen. It must not contain only sightings.
- Publishing creates a WordPress post in the existing Trip Lists category,
  containing an HTML checklist.
- Administrators can record sightings on mobile during a trip.
- The feature is delivered as a custom plugin that can be tested locally and
  installed on the production website.

The details below describe the recommended first version. Outstanding choices
are recorded at the end of this document.

## Administrator workflow

### Start a checklist

1. Create or open the trip in the existing events system.
2. Select **Create bird list** from the event's administration screen.
3. Open a dedicated checklist screen showing the trip name, date, and full list
   of birds, initially marked as not recorded as seen.

Creating a checklist does not create a public post. If a checklist already
exists, the event offers **Edit bird list** instead. On mobile, the checklist
should also be reachable through an administration list of trips, without
requiring the administrator to work through the full event editor each time.

### Record sightings

- Tick a bird to mark it as seen; untick it to correct a mistake.
- Search by bird name without losing selections elsewhere in the list.
- Show a running total, such as **42 species seen out of 180**.
- Save progress automatically while connected, with a visible **Save now** action.
- Allow the administrator to leave and return to the saved checklist.
- Provide an optional written trip summary for the report, subject to review.

The full list is the default view. An optional **Seen only** editing filter can
help review selections, but it must never change which birds appear in the
published report. Show when a search or filter is active and offer a clear way
to restore the full list.

### Review and publish

1. Select **Preview report** to review the title, trip details, summary, and
   complete checklist.
2. Select **Publish trip report** explicitly.
3. The plugin creates a post in the existing Trip Lists category and stores its
   association with the event and checklist.
4. Show links to view the report and return to the event.

The title should be generated from the trip name and date, with an opportunity
to edit it before publication. Publishing requires a saved checklist and must
wait for outstanding changes to be successfully saved.

### Make later corrections

Reopening a published checklist allows corrections. Saving checklist changes
stores a working draft; an explicit **Update published report** action applies
them to the public report. This avoids making every tap during editing a public
change. Display when the checklist contains unpublished changes.

Updating or retrying publication must reuse the associated post rather than
creating duplicates. If that post has been trashed or deleted, explain the
situation and offer an explicit recovery or replacement action.

## Mobile experience

The dedicated checklist screen is designed for use on a phone in portrait
orientation as well as on a tablet or desktop.

- Use a single column of bird names with large checkboxes and tappable rows.
- Make each tap target at least 44 × 44 CSS pixels.
- Avoid horizontal scrolling at a viewport width of 320 CSS pixels.
- Keep search, the seen count, and save status easy to reach. Sticky controls
  must not cover checklist rows or hide content behind the phone keyboard.
- Keep the user's scroll position while selecting birds and saving.
- Distinguish seen birds with a checkmark and text or an accessible label;
  colour alone is insufficient.
- Support keyboard navigation, labelled controls, visible focus, and screen
  reader announcements for save status without announcing every background retry.
- Prevent repeated taps on publication controls from producing duplicate posts.

## Saving and variable signal

The first version supports connected use with recovery from short connection
interruptions. Full offline operation, including opening a previously unloaded
checklist without a connection, is outside the initial scope.

Recommended save behaviour:

- Save checkbox changes after a short delay, batching rapid selections.
- Show distinct states: **Saving…**, **Saved at [time]**, **Unsaved changes**, and
  **Connection lost — changes not yet saved**.
- Only show a saved confirmation after the server acknowledges the changes.
- Retain pending changes in the open screen and retry when connectivity returns.
- Warn before navigating away when changes have not reached the server, where
  the browser supports such a warning.
- Retain a recovery copy of pending edits in browser storage, scoped to the
  current user and trip. Clear acknowledged edits once saved. If storage is
  unavailable, make that limitation visible.
- On reopening the same checklist in the same browser, offer to recover pending
  changes after comparing them with the latest server version.
- If login expires, preserve pending edits and ask the administrator to sign
  back in before retrying.
- Never publish automatically after reconnecting.

A browser recovery copy is a fallback, not a guarantee of offline operation.
Unsaved changes may be lost if browser data is cleared or a different device is
used. The interface must make server save status clear enough that the
administrator knows when it is safe to close the page.

Use a revision/version check on saves. If another device or administrator has
changed the checklist, do not silently overwrite their work. Explain the
conflict and allow the administrator to review and reconcile pending changes.
Retries must not allow an older request to overwrite newer selections.

## Public trip report

The generated report contains:

- Trip title and date, with a link to the associated event.
- An optional written summary if included in the first version.
- Total number of species seen.
- The complete checklist in its agreed order.
- A clear **Seen** indicator for recorded sightings and a dash for other birds.
- A legend explaining that a dash means **not recorded as seen**, rather than
  asserting that the species was absent from the location.

The checklist must be readable on mobile and available as rendered HTML without
requiring visitor interaction or JavaScript. Use appropriate table headings or
equivalent accessible structure. Provide print styles so visitors can print or
use their browser's Save as PDF function. Generating downloadable PDF files is
outside the initial scope.

Existing PDF trip posts remain available. Retrospective conversion is a separate
task. The event administration screen links to the report; adding a public
report link to the event page is recommended, subject to the site's layout.

## Master bird list and historical records

Maintain a reusable master bird list with a stable identifier, display name,
and ordering for each species. The existing spreadsheet should be the starting
point for agreeing the names and order.

Each new trip takes a snapshot of the master list. Later changes to bird names,
ordering, or membership of the master list must not silently change historical
trip reports. Store sightings against stable species identifiers, rather than
names or positions in a list.

First-version setup can import an agreed species list. A full spreadsheet import
interface and advanced taxonomy management are optional later work. The exact
way administrators maintain the master list remains to be agreed.

## Plugin design and deployment

- Implement a standalone custom WordPress plugin, integrated with the existing
  The Events Calendar installation and its event administration screens.
- Keep the checklist editor in a dedicated responsive administration screen;
  the event editor provides its entry point and status.
- Store checklist data separately from ordinary report text, with references
  to the event, the published post, the species snapshot, and revision state.
- Store the last published snapshot separately from working changes so draft
  edits cannot alter the public report before an explicit update.
- Render the report from the published snapshot. Keep any manual report content
  separate from the generated checklist so updates do not overwrite it.
- Restrict editing and publishing endpoints to authorised administrators, check
  permissions for the relevant event and post, validate inputs, verify request
  tokens, and escape rendered output.
- Avoid theme-specific dependencies and styling that affects other site content.
- Handle an unavailable events plugin with a clear administration notice.
- Keep historical checklist data on plugin deactivation. Define deletion and
  uninstall behaviour explicitly before implementing destructive cleanup.

The exact storage schema and rendering mechanism should be settled during
implementation. Package the plugin as an installable ZIP after local acceptance
testing. Deployment installs plugin code and initial master-list data; it must
not replace the production database with the local database. No SSH credentials,
local safeguards, or private database exports belong in the release package.

## Acceptance criteria

1. An administrator can create and reopen one checklist for a trip event.
2. A new checklist contains every bird from the agreed master list, in order.
3. Ticking, unticking, searching, and saving work on a 320-pixel-wide viewport
   without horizontal scrolling or lost selections.
4. Saved selections survive a reload and are available on another authenticated
   device.
5. A failed save is visibly different from a successful save; pending selections
   can be retried after a short connection interruption.
6. Browser recovery is offered for unsaved edits where storage is available;
   login expiry and conflicting edits do not silently discard or overwrite work.
7. Preview and publication show the entire checklist, with accurate seen markers
   and totals.
8. Publishing creates a post in the existing Trip Lists category and associates
   it with the event. Repeated publication requests do not create duplicates.
9. Draft corrections remain private until **Update published report** is used.
10. Master-list changes do not alter existing trip snapshots or published reports.
11. Visitors can read and print the full report on mobile and desktop.
12. Unauthorised users cannot create, edit, or publish checklists.
13. Existing events and PDF trip posts continue to work.
14. The installable plugin is tested locally before production deployment.

## Questions to resolve before implementation

- Does every trip use the same spreadsheet checklist? Obtain an example to
  confirm species names, grouping, and order.
- Should the first version include a written trip summary?
- Who will maintain the master list, and how often does it change?
- Should only administrators have access, or should an editor/trip-leader role
  also be supported later?
- Confirm the existing Trip Lists category and preferred report title format.
- Are there birds or locations whose sightings should be withheld from public
  reports? If so, agree how that interacts with the full-list requirement.

## Possible later additions

- Full offline checklist access and synchronisation.
- Generated downloadable PDFs.
- Counts, notes, times, or photos for individual sightings.
- Multiple observers and collaborative entry.
- Seasonal or location-specific master lists.
- Conversion of historical spreadsheet/PDF reports.
