# SiteOrigin Page Builder Content Migration Guide

## Critical: What Must Be Copied Together

SiteOrigin Page Builder stores content in TWO places that must BOTH be migrated:

1. **`post_content`** — the rendered HTML output (what the browser sees)
2. **`panels_data` post meta** — the serialized widget configuration (what Page Builder uses to render)

Copying only `post_content` without `panels_data` strips all SiteOrigin widgets.
Copying only `panels_data` without `post_content` leaves stale HTML.

## How SiteOrigin Stores Data

- **`panels_data`** (post meta): Serialized PHP array containing `widgets`, `grids`, `grid_cells`
  - Each widget has: `image` (attachment ID), `size` (thumbnail/medium/large/full), `text`, `title`, `url`, etc.
  - Grid definitions control column layout and responsive behavior
- **`post_content`**: Rendered HTML with `<input type="hidden">` fields containing escaped JSON widget config
- **`siteorigin_page_settings`** (post meta): Page layout settings (sidebar, title visibility, etc.)
- **`_thumbnail_id`** (post meta): Featured image attachment ID

## Migration Steps

1. Export `post_content` from source DB (use `mysql -N -r` for raw output, NO string manipulation)
2. Export `panels_data` from source DB
3. Export `siteorigin_page_settings` from source DB
4. Import `post_content` to target CPT (via `$wpdb->update`, not `wp_update_post` to avoid sanitization)
5. Import `panels_data` to target CPT (via `$wpdb->replace`)
6. Import `siteorigin_page_settings` to target CPT
7. Map attachment IDs if source and target media libraries differ
8. Verify: render page in browser, check images load, check widget output

## Image Size Gotcha

SiteOrigin image widgets use `"size":"thumbnail"` (150x150), `"size":"full"`, etc.
If the original page showed large images but the backup has `thumbnail`, the images will
render small. Check and fix the `size` field in `panels_data` if needed.

## Attachment ID Gotcha

`panels_data` references attachment IDs (e.g. `image: 9016`). These must match the
target site's media library. If IDs differ, update the `panels_data` to use the
correct IDs.

## Content Must NOT Be Modified

When copying `post_content` from SQL to WordPress:
- Use `mysql -N -r -e "SELECT post_content FROM ..."` for raw output
- Push via `$wpdb->update()` directly — never `wp_update_post()` (it sanitizes)
- Never do string replacements on the content (breaks serialized data)
- The `\\&quot;` and `\\n` sequences in SQL output are PART of the data format

## SQL Table Structure (from WordPress Codex)

`wp_posts` columns: ID, post_author, post_date, post_date_gmt, post_content,
post_title, post_excerpt, post_status, comment_status, ping_status,
post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt,
post_content_filtered, post_parent, guid, menu_order, post_type,
post_mime_type, comment_count

Key fields for migration:
- `post_content` (index 4): The rendered HTML
- `post_title` (index 5): Page title
- `post_name` (index 11): URL slug
- `post_type` (index 20): 'page', 'post', 'attachment', custom types

`wp_postmeta` stores `panels_data`, `siteorigin_page_settings`, `_thumbnail_id`, etc.
