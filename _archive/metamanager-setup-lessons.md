# MetaManager Setup & Optimization — Lessons from Gutter Tymer

**Date:** 2026-11-15
**Site:** Gutter Tymer (guttertymer.com) — first full MetaManager conversion, used as the reference template.

---

## What we did

1. Converted 4 service pages to `mm_service` CPT posts (not hacked pages)
2. Populated business profile (HomeAndConstructionBusiness type)
3. Set schema fields (`mm_schema_fields` with service_type)
4. Removed 14 hand-written JSON-LD blocks from HTML widgets
5. Validated with schema.org + Google Rich Results tools
6. Fixed hentry suppression, Person schema, archive titles
7. Standardized all 10 CPT templates (single + archive)

## Key lessons

### 1. Use the CPT, don't hack pages

Converting regular WordPress pages to schema types by setting `_wp_page_template` is a hack. The `mm_service` CPT has its own template (`single-mm_service.php`) with `render_details_table()` that displays schema fields as visible content below the page. Use `wp_insert_post` with `post_type => 'mm_service'` to create proper CPT posts.

**The right way:** Export SiteOrigin `panels_data` from old pages → create `mm_service` CPT posts with same slug → import `panels_data` → set `mm_schema_fields` → trash old pages → update nav menus.

### 2. Schema fields must be visible on-page

Google flags structured data that isn't visible in the rendered page. The `render_details_table()` method renders `mm_schema_fields` as a "Details" section below the content. For page-template-based schema (mm-about, mm-contact), a `the_content` filter should append the same Details section.

### 3. ImageObject dimensions need QuantitativeValue

Schema.org validators reject plain numbers for `ImageObject.width`/`height`. Use `QuantitativeValue` format: `{@type: "QuantitativeValue", value: 2400, unitCode: "PIX"}`.

### 4. Service nodes need provider link

Google requires `provider: {@id: #organization}` on `Service` nodes for Service rich results. Without it, the rich result test flags "Missing provider."

### 5. hentry suppression is default-on

WordPress adds `hentry` to `post_class()` for hAtom compatibility. Schema.org validators flag it as an unrecognized type. `hygiene.remove_hentry` setting (default: true) strips it. Toggleable in MetaManager hygiene settings.

### 6. Person schema is controlled by `authors.person_schema`

Setting exists but `MM_Mod_Author` wasn't reading it — Person nodes appeared unconditionally. Fixed: `person_schema = false` suppresses Person nodes entirely. This is a per-site setting (Gutter Tymer has it off because the business identity is the company, not a person).

### 7. Archive titles need plural labels

`%%post_type_label%%` resolves to singular ("Service") via `get_post_type_label()`. For archives, use `$pt_obj->labels->name` (plural). The `filter_title` method now pre-replaces `%%post_type_label%%` with the plural label before calling `resolve()`.

### 8. CPT rewrite slugs should be plural

`mm_service` → rewrite slug `service` (singular) broke URL compatibility. Changed to `services` (plural from TYPES array label). Preserves URLs when converting pages to CPTs.

### 9. Excerpt only, never scraped content

Archive cards show `the_excerpt()`. Never generate summaries from `get_the_content()` — SiteOrigin widget shortcodes render as raw text. Populate real excerpts on each post.

### 10. Theme-agnostic templates

Templates use standard WordPress tags (`get_header`, `the_content`, `post_class`) with self-contained CSS. No dependency on theme-specific files (`loops/loop-blog-*`). Works with any theme.

### 11. Hand-written schema conflicts with MetaManager

Before MetaManager, hand-written JSON-LD in HTML widgets compensated for SEOPress gaps. Now MetaManager generates all fields — the hand-written blocks are duplicates. Always remove one. Check `panels_data` in `wp_postmeta` for embedded schema.

### 12. Business type matters for LocalBusiness subtype

`RoofingContractor` was wrong for a gutter company (different legal requirements). `HomeAndConstructionBusiness` is the correct subtype. Available types are in `MM_Mod_Local::get_business_types()`.

## Template standards (applied to all 10 CPTs)

**Single templates:**
- Title in `<h1 class="entry-title">`, no featured image display
- `the_content()` renders the SiteOrigin layout
- `render_details_table()` shows schema fields below content
- Theme-agnostic (standard WordPress tags only)

**Archive templates:**
- Heading = CPT plural label (not "Archive")
- Cards: image left 36% (24% tablet), text right, vertically centered
- Mobile: stacked (image, text, link)
- Excerpt only ("Learn more" link, no arrow)
- Follows Customizer `blog_archive_layout` when available
- Self-contained CSS, no theme dependency

## Validation tools

| Tool | Endpoint | Use |
|---|---|---|
| Schema.org Markup Validator | `POST validator.schema.org/validate?url=...&output=json` | Authoritative schema.org compliance |
| Schemagen.io | `POST schemagen.io/api/validate` with `jsonString` | Google Rich Result eligibility |
| Google Rich Results Test | Web UI only (no API) | Visual rich result preview |

Response prefix `)]}'` must be stripped (`tail -c +5`).

## Settings changed on Gutter Tymer

| Setting | Value | Why |
|---|---|---|
| `business.type` | `HomeAndConstructionBusiness` | Gutter company, not roofer |
| `authors.person_schema` | `false` | Business identity is the company, not a person |
| `hygiene.remove_hentry` | `true` (default) | Suppress hAtom microformat class |

## What's next

- Apply same conversion to Hyer Cleaning, Ouachita Online, PEO, Gap Creek
- Configure link checking schedule
- Run one-time image compression pass
- Consider AboutPage/ContactPage template types for remaining pages

---

# Content Restoration & Backup Discovery — Lessons from Hair Studio

**Date:** 2026-11-16
**Site:** Destin Hair Studio (hairstudio.salon) — 25 service CPTs, content restored from backups.

---

## Backup locations — ALWAYS check both

Two backup sources exist for each site:

| Location | Format | Contents |
|---|---|---|
| `/media/richard/320GBWD/wpbkup/<Site>/` | `wordpress.sql` + `wordpress.tar.gz` | Published pages only |
| `/media/richard/320GBWD/gcmbkup/<Site>/` | `wordpress.sql` + `wordpress.tar` + `.meta.json` + `.sha256` | Published + draft pages |

**gcmbkup is more recent** (rolling daily backups) and contains **draft pages** that wpbkup misses. On Hair Studio, 8 services appeared "empty" on the live site — the content was in gcmbkup as draft pages (diamond-glow, dermaplaning, body-contouring, hydrafacial, led-light-therapy) and as a combined page (eyelashes-eyebrows containing lash-brow-lifts-tints, eyelash-extensions, eyebrow-lamination).

**Rule:** When a CPT has empty content, check gcmbkup FIRST, then wpbkup. The content is usually there — you're looking at the wrong backup.

## Draft pages are the source

Services that appear "empty" on the live site often have draft pages in the backup. These drafts were never published but contain full content. Extract them with:

```sql
SELECT post_content FROM wp_posts WHERE post_name='<slug>' AND post_type='page' AND post_status='draft' LIMIT 1;
```

## Combined pages contain multiple services

Some backup pages bundle multiple services (e.g., `eyelashes-eyebrows` = Lash Lift + Eyelash Extensions + Tinting + Eyebrow Lamination). Push the full combined content to each individual CPT — do not split or extract sections. The CPT titles already identify the specific service.

## wp_insert_post sanitizes content — use $wpdb->update()

`wp_insert_post()` strips/sanitizes content (small byte differences: 20866→20854, 27483→27459). For byte-exact restoration, use `$wpdb->update()` directly:

```php
$wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $post_id]);
```

Never use `wp_update_post()` for content restoration — it sanitizes and corrupts SiteOrigin data.

## SiteOrigin panels_data is PHP serialized, not JSON

`panels_data` in `wp_postmeta` is PHP `serialize()` output, not JSON. Use `unserialize()` not `json_decode()`:

```php
$panels = $wpdb->get_var("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id={$id} AND meta_key='panels_data'");
$data = unserialize($panels);  // NOT json_decode()
```

## Handmade schema can hide in post_content

Schema may be embedded in `post_content` as escaped `siteorigin_widget` shortcodes (not just in panels_data). The pattern is:

```
[siteorigin_widget class="WP_Widget_Custom_HTML"]<input type="hidden" value="{&quot;instance&quot;:{&quot;content&quot;:&quot;&lt;script type=&quot;application/ld+json&quot;&gt;...&quot;}" />[/siteorigin_widget]
```

Remove with regex: `/\[siteorigin_widget[^\]]*WP_Widget_Custom_HTML[^\]]*\].*?\[\/siteorigin_widget\]/is`

## mysql -N -r adds trailing newline

Extracted content is always +1 byte from the backup SQL `LENGTH(post_content)`. This is expected — `mysql -N -r` appends a newline. The content is exact.

## wp eval-file does not pass argv

`wp eval-file script.php arg1 arg2` — `$argv` is empty inside the script. Hardcode values in the PHP file instead.

## PHP version on fleet servers

Some servers run PHP without `??` (null coalescing) support. Use ternary: `$x !== null ? $x : $default` instead of `$x ?? $default`.

## Content restoration workflow

1. Find the service in gcmbkup (draft or published) → extract `post_content` with `mysql -N -r`
2. Extract `panels_data` from `wp_postmeta` (PHP serialized)
3. Push content via `$wpdb->update()` (not `wp_update_post`)
4. Push panels_data via `$wpdb->update()` or `$wpdb->insert()`
5. Verify byte count matches backup SQL `LENGTH(post_content)` (+1 for newline)

## Verification

Compare live `LENGTH(post_content)` to backup `LENGTH(post_content)`. The live value should be exactly +1 (newline from `mysql -N -r`). Any larger difference indicates corruption.
