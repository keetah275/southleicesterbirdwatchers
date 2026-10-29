# Events page routing and header

Investigated 29 October 2026 using the local database, installed plugin source,
and HTML from the live homepage and events archive.

## Local 404

The Events Calendar was active, but the saved WordPress rewrite rules contained
no events routes. Generating rules with the active plugins produced 103 rules
whose patterns contained `events`. Refreshing the local rewrite rules restored
HTTP 200 responses for `/events/`, `/events/month/`, and `/events/today/`.

If this recurs after migration, visit **Settings → Permalinks → Save Changes**
with The Events Calendar active. The precise cause of the missing saved rules
was not established.

## Different header on the live events archive

The site has two distinct Twenty Twenty-Three header template parts:

- `header` (Header): older cover with a 50px minimum height, Site Logo block,
  and responsive navigation.
- `header-copy` (Header new style): cover with a 301px minimum height, 279px
  logo image, and different navigation settings.

Ordinary page templates reference `header-copy`. The Events Calendar archive
template references `header`. Live HTML confirmed those different blocks are
rendered; this is a template selection issue, not merely a CSS height mismatch.
The calendar URL is a plugin archive, rather than an ordinary WordPress page.

Locally, the saved `tec//archive-events` template (post 3449) now references
`header-copy`. Its calendar and footer blocks are unchanged. The original
template content was backed up in the ignored `.local-migration/backups/`
directory before the change. No live configuration was changed.

To apply on production, open **Appearance → Editor → Templates → Calendar
Views (Event Archive)** and replace its Header template part with the existing
**Header new style** part, then save and check the archive. Avoid editing the
third-party plugin files or copying the entire local database to production.
The separate Single Event template also references the older header; it was
not changed during this archive investigation.

## Verification

Chrome checks at 1280px and 390px widths confirmed that the homepage and all
three calendar views have matching header heights and 279px logo widths.
All tested URLs returned HTTP 200 and the calendar views rendered their calendar
container. Screenshots were saved locally in `/tmp/slb-events-fixed-1280.png`
and `/tmp/slb-events-fixed-390.png`.

The mobile list view has horizontal overflow elsewhere in its content; month
and day views did not show overflow in the same checks. This remains a separate
layout issue.

The imported database also contains many old event-template records without a
theme association. They were left intact; no template cleanup was needed for
these repairs.
