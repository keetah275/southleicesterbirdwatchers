=== SLB Trip Bird Lists ===
Contributors: southleicesterbirdwatchers
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.1.4
License: GPLv2 or later

Record a full bird checklist against a trip event and publish an HTML trip report.

== Installation ==

1. Install and activate The Events Calendar.
2. Upload the plugin ZIP through Plugins > Add New > Upload Plugin, then activate.
3. Open Events > Bird list settings. Check the report category and bundled master
   list. You can edit the list, using one bird per line, in report order.
4. Open Events > Bird lists and select a trip. Alternatively use the Trip bird
   list panel or Bird list row action on an event.
5. Tick sightings, save, preview the complete report, then publish explicitly.

== Recording sightings ==

Seen and Heard are independent columns: tick either or both for each bird.
Reports show both columns for every bird. The recorded-species total counts a
bird once even if both boxes are ticked. Older checklists keep their existing
Seen selections, with Heard initially unticked. The editing filter shows all
recorded birds, whether seen or heard.

Use Report details in the sticky toolbar to jump past the checklist, and Back
to checklist to return to its start.

Only administrators can manage lists. The editor works on phones and saves
automatically while connected. Search and the recorded-only filter affect the
editor, never the full published list. A trip summary is optional.

Wait for the Saved confirmation before closing the screen. During brief signal
loss, keep the screen open so it can retry. Browser storage provides a recovery
copy of pending edits where available. On reopening, review and apply pending
edits against the latest server version. This is not full offline access.

If sign-in expires, sign in in another tab, then reload the editor to recover
pending edits. Conflicting saves are reviewed rather than silently overwriting
another screen's changes. Applying your edits preserves unrelated server edits.

== Reports and data ==

Draft saves do not change published reports. Preview and publish/update the
report explicitly. Repeated publication reuses the associated post. Trashed
or deleted reports require explicit restoration/replacement confirmation.

Reports render through the [slb_trip_report event_id="123"] shortcode. Keep it
in the report content. Additional post text is preserved on report updates.
The shortcode uses the last published snapshot, not the working draft.

Every trip keeps its original bird names and order when the master list changes.
Changing the master list affects only trips which have not yet been saved.
Do not delete trip events to archive them: checklist data is associated with the
event ID. Deactivation and deletion of the plugin retain its database data, but
the plugin must be active to render shortcode reports. No uninstall data purge
is performed.

A 263-bird master list is bundled from Potteric-Carr-Trip-List-2.pdf, supplied
by the administrator. It is used only when no master list is configured.
The repeated Fulmar entry is included once. Names and abbreviations are retained
as supplied, with "Girl Bunting" corrected to "Cirl Bunting" by the administrator.
Trip-specific X marks are not imported. Database tables and settings are local
to each WordPress installation; deployment does not copy development sightings.

== Changelog ==

= 0.1.4 =
Give reports and previews a document-style panel, clear trip heading, summary
totals, shaded checklist rows, and responsive screen and print styling.

= 0.1.3 =
Add independent Heard recording alongside Seen, including autosave recovery,
conflict reconciliation, previews, reports, print layout, and legacy checklists.

= 0.1.2 =
Add shortcuts between the checklist and report details for mobile use.

= 0.1.1 =
Bundle the administrator-supplied master checklist for new installations.
Preserve configured master lists and historical trip snapshots on upgrade.

= 0.1.0 =
Initial version: event-linked checklists, mobile editor, save recovery, master
list settings, complete HTML reports, and explicit report updates.
