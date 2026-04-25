=== DRWP Daily Reports ===
Contributors: drwp-prototype
Tags: daily-reports, workflow, review, japanese
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later

Field/site daily reports with a review workflow, photo attachments, and
license-gated conversion to public WordPress posts. Pairs with a
standalone Ed25519-signing license server.

== Description ==

Capture daily site reports, route them through an approve / revise
review queue, attach photos via the Media Library, and convert
approved reports into `post` posts with captioned galleries. Includes
CSV bulk import, a cross-report audit log viewer, email notifications,
a dashboard widget, and a `wp-json/drwp/v1/*` REST API that works with
WordPress Application Passwords.

License calls go to a standalone server and are verified with
Ed25519. A 7-day grace window prevents transient outages from
immediately locking write access; rotated public keys are accepted
during the rotation window.

== Changelog ==

= 1.8.0 =
* REST API at /wp-json/drwp/v1/* with the same capability gates as the
  admin pages.
* Cross-report audit log viewer with filters, pagination, and filtered
  CSV export.
* Email notifications on submit / review state change / new comment,
  each with independent toggles.
* Dashboard widget surfacing today / pending / needs_revision /
  approved counts plus recent reports.
* CSV bulk import (UTF-8, BOM optional, max 5 MB) with auto-creation
  of unknown projects.
* License server signing-key rotation: previous keys are kept so old
  signatures keep validating until clients refresh.
* Plugin verifies signatures with libsodium against current+previous
  keys.

= 1.6.0 =
* Bulk publish-settings updates, category IDs for post conversion,
  and bulk sync to linked posts.

= 1.3.0 =
* Audit log table + screen, with entries for save, review, comments,
  post conversion, project changes, and license settings.

= 1.1.0 =
* Capability model split between site staff, reviewers, and
  publishers. Menus filter by capability.
