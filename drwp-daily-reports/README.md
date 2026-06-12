# 日報マン (Nippoman) — DRWP Daily Reports

A WordPress plugin for capturing field/site daily reports with a review
workflow, photo attachments, and one-click conversion into public posts.
Marketed under the brand name **日報マン**; the internal slug
`drwp-daily-reports` is kept for backwards compatibility.
Pairs with a standalone license server (`../license-server/`) that signs
its responses with Ed25519 so a tampered status can't unlock features.

## Feature overview

### Reports
- Per-user daily reports with project (現場), date, work description,
  issues, next plan, separate "public-side" intro/body/next plan, post
  template, category, tags, post status (draft / pending / future) and
  scheduled publish time.
- Photos via the WordPress Media Library with reorderable cards and
  per-image captions.
- Convert a report into a `post` post type, embedding a captioned
  gallery between body and next-plan.
- List with full-text search, filter by review status / post status /
  project / date range, and pagination.

### Mobile submission (shortcode)
- `[drwp_report_form]` renders a mobile-first front-end form on any
  page or post. Field workers log in once on their phone and bookmark
  the page; the shortcode talks to the REST endpoints with a
  `wp_rest` nonce.
- Fields are kept short: project, date, work description, issues,
  next plan, photos. Photo input declares `capture="environment"` so
  the phone goes straight to the rear camera; multiple files queue up
  with thumbnail previews and per-item delete.
- Submissions are stored with `review_status=pending`, which means
  they show up in the existing review queue without any extra wiring.

### Review workflow
- Review states: `pending` / `approved` / `needs_revision`.
- Per-report reviewer panel with optional comment.
- Bulk approve / revise / convert / republish settings.
- Comment thread per report.
- Cross-report audit log viewer with filter, pagination, and
  filter-preserving CSV export.

### Licensing
- Calls `/api/check` on the license server, caches plan / expiry, and
  honours a 7-day grace window so a transient outage can't immediately
  lock writes.
- Public key is fetched separately and cached. Every check response is
  verified with `sodium_crypto_sign_verify_detached` against the cached
  key — a missing/invalid signature forces `status=inactive`.
- Server-side key rotation pushes archived public keys via
  `previous_keys` so signatures issued before a rotation continue to
  validate.

### REST API
- Namespace `/wp-json/drwp/v1/*`. Authenticates via WordPress core
  (Application Passwords work out of the box).
- Endpoints: `reports` (list/create), `reports/{id}` (read/update),
  `reports/{id}/review` (status change), `reports/{id}/comments`
  (list/add), `reports/{id}/audit` (list), `projects` (list),
  `license` (cached state, no key/public_key).
- Uses the same capability gates as the admin pages; non-reviewers see
  only their own rows. Writes return HTTP 402 when the license is
  inactive.

### Dashboard
- Widget on the WP dashboard showing today's count, pending /
  needs_revision / approved totals, the five most recent reports, and
  quick actions. Status counts link into a filtered list view.

## Install

1. Drop the plugin folder into `wp-content/plugins/`.
2. Activate via `Plugins → 日報マン`. Activation creates the
   five `wp_drwp_*` tables (reports, projects, comments, audit_logs,
   report_photos).
3. Visit `日報マン → ライセンス`, set the API URL and license key,
   click "公開鍵を取得", then "いま照会する". `signature_valid` should
   read `valid`. Without a license, REST writes return HTTP 402 and
   the report editor blocks save.

For local development with the bundled license server, see
`../docker-compose.yml` and `../scripts/docker-setup.sh` in the repo
root — `bash scripts/docker-setup.sh` brings up WordPress + MySQL +
license server, installs WP, activates the plugin, seeds a demo
license, and runs the initial check.

## Capabilities

| Capability         | What it grants                                          |
| ------------------ | ------------------------------------------------------- |
| `edit_posts`       | View / create / edit own reports, REST list & writes    |
| `edit_others_posts`| Approve / request revisions, see all reports, bulk ops  |
| `publish_posts`    | Convert reports into posts, bulk convert                |
| `manage_options`   | Project / license / audit admin pages                   |

## Tables

- `wp_drwp_reports` — the report rows
- `wp_drwp_projects` — current sites / clients
- `wp_drwp_comments` — review thread per report
- `wp_drwp_audit_logs` — append-only event log (`event`, `message`,
  `meta_json`)
- `wp_drwp_report_photos` — media library attachments + caption per
  report

`DRWP_DB::maybe_upgrade()` runs on `plugins_loaded` and re-runs
`dbDelta` whenever the stored schema version is behind the plugin's,
so existing installs pick up new tables without a deactivate/activate
cycle.

## Notes

This is still a prototype-grade plugin. The capability model and DB
schema are stable enough to demo, but it has not been audited for
WordPress.org plugin-directory submission.
