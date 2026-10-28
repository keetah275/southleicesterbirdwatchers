=== SLB Trip Bird Lists ===
Contributors: southleicesterbirdwatchers
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

Record a full bird checklist against a trip event and publish an HTML trip report.

== Installation ==

1. Install and activate The Events Calendar.
2. Upload the plugin ZIP through Plugins > Add New > Upload Plugin, then activate.
3. Open Events > Bird list settings. Choose the existing Trip list category and
   paste your master list, one bird per line, in report order.
4. Open Events > Bird lists and select a trip. Alternatively use the Trip bird
   list panel or Bird list row action on an event.
5. Tick sightings, save, preview the complete report, then publish explicitly.

== Recording sightings ==

Only administrators can manage lists. The editor works on phones and saves
automatically while connected. Search and the seen-only filter affect the
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

No master species list is bundled. Database tables and settings are local to
each WordPress installation; deployment does not copy development data.

== Changelog ==

= 0.1.0 =
Initial version: event-linked checklists, mobile editor, save recovery, master
list settings, complete HTML reports, and explicit report updates.
