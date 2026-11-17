# Metamanager Roadmap

Last updated 2026-11-16.

---

## What This Is

Metamanager manages metadata for WordPress media and pages. It extracts, normalizes, and synchronizes metadata across file system, database, and frontend output. The daemon layer handles the heavy lifting (ExifTool embed, lossless compression, ffmpeg remux). The plugin layer handles WordPress integration (admin UI, job queue, structured data, frontend output).

Closed history (completed fixes, audits, compliance snapshots) lives in
[`_archive/ROADMAP-HISTORY.md`](_archive/ROADMAP-HISTORY.md) — record, not
maintained. The schema-expansion programme is a design record in
[`ROADMAP-SCHEMA-EXPANSION.md`](ROADMAP-SCHEMA-EXPANSION.md) (Phases 1–5
implemented). The plugin↔daemon contract is [`JOB_QUEUE_SPEC.md`](JOB_QUEUE_SPEC.md).

---

## Repository Overview

| Repo | Branches | Purpose |
|------|----------|---------|
| `metamanager-plugin` | dev/test/main | WordPress plugin: metadata sync, frontend output, admin UI, job queue |
| `metamanager` | dev/test/main | Daemon scripts (meta embed, compression), apt deployment, systemd units |

## Architecture

```
WordPress Plugin (PHP)                    OS Daemons (Bash)
┌─────────────────────────────┐          ┌─────────────────────────────┐
│ MM_Admin                    │          │ metamanager-meta-daemon.sh  │
│ MM_Metadata (field defs)    │   jobs   │  - inotifywait watcher      │
│ MM_Job_Queue (write/claim)  │ ──────> │  - ExifTool embed           │
│ MM_DB (log/query)           │ <────── │  - writes result JSON       │
│ MM_Cron (import results)    │ results │                             │
│                             │          │ metamanager-compress-daemon │
│ MM_Media_Detector           │          │  - jpegtran/optipng/cwebp   │
│  (content media scanning)   │          │  - avifenc/ffmpeg remux     │
│ MM_Head_Emitter + Modules   │          │                             │
│  (page-level output)        │          │                             │
│ MM_Abilities (AI API)       │          │                             │
│ MM_MCP_Server (AI tools)    │          │                             │
│ MM_Mod_Discovery (llms.txt) │          │                             │
└─────────────────────────────┘          └─────────────────────────────┘
```

---

## Current Status

| Item | Value |
|------|-------|
| Fleet (production + test hosts) | See the workspace `AGENTS.md` fleet table |
| Apt server | apt.richardkentgates.com |
| Versions | Auto-bumped by CI — check the repos themselves, never hardcode |

Plugin updates: `MM_Updater` polls the apt server `metadata.json` feed (no
caching) through the native WordPress update pipeline. Daemon updates: OS apt
inside the GCM maintenance window; the apt channel decides the version;
`MM_Daemon_Updater` is display-only.

---

## Pipeline

```
dev ──push──> ci.yml (lint + PHPStan + tests + build + auto version bump)
    │
    │  workflow_dispatch: promote-to-test.yml
    ▼
test ──merge + build zip + deploy to apt server test channel (/metamanager-test/)
    │
    │  workflow_dispatch: promote-to-main.yml
    ▼
main ──merge + tag + GitHub release + deploy to apt server production channel (/metamanager/)
```

Real workflows: `ci.yml`, `promote-to-test.yml`, `promote-to-main.yml` only.

---

## Open Items

### Author Settings Simplification

**Current state:** User profile has SEO Title Override, Meta Description textarea, and noindex radio buttons (Default/Force noindex/Force index). These overrides are not saving correctly and are unnecessary — WordPress already has titles and the author bio field for description.

**Changes:**
- Remove SEO Title Override field from user profile (title override removal also covers the per-term check in `templates/metabox/term-metadata.php`)
- Remove Meta Description textarea from user profile; author archive description uses `$author->description` (bio) directly
- Replace noindex radio buttons with a single "Allow search indexing" checkbox
- Add a per-author "Show Person schema" checkbox (currently only a global setting)
- Fix: author archive title uses global template only, no per-author override
- Fix: settings save issue — investigate why existing settings say they saved but didn't persist

**Files:**
- `templates/metabox/user-metadata.php` — simplify fields
- `includes/metadata/admin/class-mm-user-meta-panel.php` — update save logic
- `includes/metadata/modules/class-mm-mod-head-meta.php` — remove per-author title/description override resolution
- `includes/metadata/modules/class-mm-mod-author.php` — check per-author person_schema toggle

### HIGH — Event as WooCommerce Product (S-4)

Simple WooCommerce integration for Event posts — make events purchasable as products with ticket generation and QR codes.

**Scope:** Utilize WooCommerce, don't replace it. Let WC handle inventory, analytics, coupons, etc.

**What we add:**
| Feature | Implementation |
|---------|----------------|
| Event ↔ Product link | Custom field `_mm_wc_product_id` on Event (Product side handled by WooCommerce) |
| Ticket purchase | WC Product type "Event Ticket" with event linking |
| Ticket hash | Unique hash per order item: `md5($order_id . $item_id . $salt)` |
| QR code | `chillerlan/php-qrcode` library, payload: `site/event/{slug}?ticket={hash}` |
| Ticket download | Rewrite endpoint: `/gcm-event/{event_id}/ticket/{hash}/` |
| Order email | WC email attachment with QR code image |
| Check-in | Simple REST endpoint: `POST /wp-json/metamanager/v1/checkin` with ticket hash |

**Files to change:**
- New: `includes/class-mm-event-product.php` — WC product type registration, event linking UI
- New: `includes/class-mm-ticket-qr.php` — QR code generation and download endpoint
- Modified: `includes/metadata/class-mm-schema-types.php` — add `event_ticket_url` and `event_capacity` fields
- Modified: `includes/metadata/modules/class-mm-mod-business-contact.php` — update .ical to include ticket URL
- New: `includes/rest-api/class-mm-rest-checkin.php` — ticket validation endpoint

**WooCommerce integration points:**
- Register custom product type "Event Ticket" in WC admin
- Product data tab shows event selector dropdown
- On order complete → generate ticket hash, save to order meta
- WC email template filter → attach QR code image
- REST API endpoint for door check-in (validates hash, returns event/attendee data)

**Dependencies:** `chillerlan/php-qrcode` (composer require chillerlan/php-qrcode)

**NOT in scope:** (WooCommerce handles these)
- Inventory management
- Analytics and reporting
- Coupon/discount codes
- Refund processing
- Attendee management beyond basic check-in
- PDF ticket generation (QR code image is sufficient)

---

### HIGH — Service as WooCommerce Product (S-9)

Simple WooCommerce integration for Service posts — make services purchasable as products with booking/scheduling.

**Scope:** Utilize WooCommerce, don't replace it. Let WC handle payment processing, analytics, coupons, etc.

**What we add:**
| Feature | Implementation |
|---------|----------------|
| Service ↔ Product link | Custom field `_mm_wc_product_id` on Service (Product side handled by WooCommerce) |
| Service purchase | WC Product type "Service Booking" with service linking |
| Booking confirmation | Unique booking hash: `md5($order_id . $item_id . $salt)` |
| Booking details | Custom order meta: service name, date/time, location, notes |
| Service page | Auto-generated service details from business profile + service meta |

**Files to change:**
- New: `includes/class-mm-service-product.php` — WC product type registration, service linking UI
- Modified: `includes/metadata/class-mm-schema-types.php` — add `service_booking_url` field
- Modified: `includes/metadata/modules/class-mm-mod-schema.php` — Service schema includes offers from WC when available

**Service-specific fields:**
| Field | Purpose |
|-------|---------|
| `service_type` | Type of service (e.g., "Pressure Washing") |
| `service_area` | Area served (e.g., "Destin, FL") |
| `service_price` | Base price (fallback when no WC product) |
| `service_currency` | Currency code |
| `service_booking_url` | Link to book/purchase the service |
| `service_duration` | Estimated duration (for scheduling) |
| `service_includes` | What's included in the service |

**NOT in scope:** (WooCommerce handles these)
- Payment processing, analytics, coupons, refunds
- Appointment scheduling beyond basic booking hash
- Calendar integration (use Bookly, Amelia, etc. if needed)

---

### HIGH — Digital Media as WooCommerce Product (S-10)

Sell digital media files (photos, videos, documents) as WooCommerce products with file protection, watermarked previews, and per-customer licensing.

**Scope:** Extend existing WordPress attachments (media library), NOT a new CPT. Utilize WooCommerce for payment, order management, and customer accounts.

**Architecture:**
- Work with WordPress `attachment` post type (existing media library)
- Add product linking via custom field `_mm_wc_product_id` on attachment
- Move protected files to secure directory outside web root
- Watermarked previews replace public images for protected files
- License generation per customer (not per purchase)

**File Protection Strategy:**
- Protected files stored in `/srv/media/` (outside web root)
- On product link: move file from `wp-content/uploads/` to `/srv/media/`
- Update attachment `_wp_attached_file` to point to new location
- Serve via PHP streaming endpoint with purchase validation
- Watermarked preview replaces public image in media library

**Watermarking (Imagick):**
| Element | Behavior |
|---------|----------|
| Site title | Translucent text overlay on all preview images |
| Site logo | Desaturated + translucent overlay (if logo is set) |
| Applied to | Preview/thumbnail images only, not original downloads |
| Auto-generate | When protection is enabled, before file is moved |

**License System:**
- One license document **per customer** (not per purchase)
- Generated from business license text (configurable in settings)
- Attached to WooCommerce order confirmation email
- Accessible in WooCommerce "My Account" → Downloads
- Applies to **all** purchases by that customer
- License includes: usage rights, restrictions, attribution requirements

**Files to change:**
- New: `includes/class-mm-media-protection.php` — File protection, watermarking, license generation
- New: `includes/rest-api/class-mm-rest-media-download.php` — PHP streaming endpoint with purchase validation
- Modified: `includes/class-mm-admin.php` — Add protection toggle and product linking to attachment edit screen
- Modified: `includes/metadata/class-mm-site-settings.php` — Add media.featured_image_citation setting
- Modified: `includes/metadata/class-mm-schema-types.php` — MediaObject schema from existing attachment metadata

**Protection Flow (existing media → product):**
1. System scans for all public references to the media (posts, pages, widgets, etc.)
2. If references found: Prompt user with list of locations using this media
3. User selects replacement media (must be different file)
4. System replaces ALL references with selected replacement
5. Move original file from `wp-content/uploads/` to `/srv/media/` (protected)
6. Update `_wp_attached_file` to point to new protected location
7. Link attachment to WooCommerce product
8. If no replacement selected: Product linking is ABORTED, media remains public

**NOT in scope:** (WooCommerce handles these)
- Payment processing, order management, customer accounts
- Download tracking/limits (we enforce, WC stores)
- Coupon/discount codes, refunds

---

### MEDIUM — Schema Type Cleanup (S-5)

Review the selectable type list (`MM_Schema_Types::get_schema_types()`) for
redundancy with WordPress built-ins:

| Type | Maps to | Proposed action |
|------|---------|-----------------|
| `BlogPosting` | Default `post` type | Drop from the selector if auto-detection suffices |
| `WebPage` | Default `page` type | Drop from the selector if auto-detection suffices |
| `ProfilePage` | Author archive pages | Drop from the selector if auto-detection suffices |

These are selectable override types, not CPTs. The registered CPTs
(`MM_Schema_Post_Types::TYPES`) are: `mm_event`, `mm_service`, `mm_how_to`,
`mm_faq_page`, `mm_trip`, `mm_destination`, `mm_vessel`, `mm_course`,
`mm_business`, `mm_organization`. Page templates (not CPTs): `mm-about`
(AboutPage), `mm-contact` (ContactPage), `mm-calendar` (CollectionPage).
`Article` is already removed (redundant with BlogPosting).

---

## Integration Tests on PEO

Integration tests require WordPress test suite at `/tmp/wordpress-tests-lib`. PEO has MySQL test database (`metamanager_test`) ready. Install test suite and run PHPUnit.

---

## Media Type Capabilities Reference

The bulk metadata form must respect these write capabilities per `MM_Metadata::WRITE_CAPABILITY` (code is the source of truth):

| Format | Write capability | Writable fields |
|--------|-----------------|-----------------|
| JPEG/PNG/WebP/AVIF/GIF/TIFF | `full` | All fields (EXIF+IPTC+XMP) |
| MP4/MOV/3GP | `full` | All fields (QuickTime+XMP) |
| AVI/WMV | `xmp_only` | XMP-only fields (Headline, Credit, Keywords, Date, Rating, City, State, Country, GPS) |
| MKV/WebM/OGG video | `read_only` | None — display only |
| MP3/M4A/FLAC/AIFF | `full` | All fields (ID3/QuickTime/Vorbis+XMP) |
| OGG audio | `vorbis_only` | Vorbis comment fields only |
| WAV/WMA audio | `xmp_only` | XMP-only fields |
| PDF | `xmp_only` | XMP-only fields |

Compression support (see `JOB_QUEUE_SPEC.md` for tool flags):
| Format | Compressible |
|--------|-------------|
| JPEG | Yes (jpegtran) |
| PNG | Yes (optipng) |
| WebP | Yes (cwebp) |
| AVIF | Yes (avifenc) |
| GIF/TIFF | No |
| Video (MP4/MOV/AVI/WMV/MKV/WebM/OGV/…) | Yes (ffmpeg remux) |
| Audio | No |
| PDF | No |

---

## Conventions

- All work on `dev` only. Never checkout/edit/push `test` or `main`.
- Promote via `workflow_dispatch` triggers only.
- CI auto-bumps `MM_VERSION` on every dev push — never edit manually.
- Compression is lossless ONLY.
- All software moves to production through native update systems (apt, WordPress auto-update).
- PHP 8.4 for WP-CLI (`php8.4 /usr/local/bin/wp --path=/srv/www/wordpress`).
- SSH user: `richardkentgates` (not root); default SSH key.
- Daemon updates happen via OS apt (GCM maintenance window) — the plugin never triggers them.
