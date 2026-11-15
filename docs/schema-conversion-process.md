# Schema Conversion Process — Service Pages to Schema.org Service Type

**Date:** 2026-11-15
**Site:** Gutter Tymer (guttertymer.com) — first conversion, used as the learning template.

---

## What we did

Converted 4 regular WordPress pages from generic `WebPage` schema to `Service` schema.org type:
- Rain Gutter Installation (page ID 62)
- Rain Gutter Replacement (page ID 66)
- Rain Gutter Repair (page ID 70)
- Rain Gutter Cleaning & Maintenance (page ID 74)

Set the business identity to `RoofingContractor` schema type (the appropriate LocalBusiness subtype for a gutter company).

## How MetaManager handles schema

MetaManager maps WordPress content to schema.org types two ways:

1. **Custom Post Types (CPTs):** Each schema type gets its own CPT (`mm_service`, `mm_event`, `mm_trip`, etc.). Posts in these CPTs automatically get the right schema type.

2. **Page Templates:** Regular WordPress pages can be assigned a template slug that maps to a schema type. The `TEMPLATES` constant in `class-mm-schema-post-types.php` holds the mapping.

For existing pages with content, the **page template approach** is the right choice — it adds schema without changing URLs or post structure.

## The conversion steps

### 1. Add the template mapping (MetaManager code change)

In `metamanager-plugin/includes/metadata/class-mm-schema-post-types.php`, add to the `TEMPLATES` constant:

```php
'mm-service' => 'Service',
```

This tells MetaManager that pages with template slug `mm-service` should get `Service` schema type.

### 2. Set the business profile

In WordPress (via `wp eval` or MetaManager admin):

```php
$s = MM_Site_Settings::get_instance();
$biz = $s->get_business();
$biz['name'] = 'Gutter Tymer';
$biz['type'] = 'RoofingContractor';  // LocalBusiness subtype
$biz['description'] = '...';
$biz['service_areas'] = array('Northwest Florida');
$s->save_business($biz);
```

The business type determines the `@type` of the `Organization`/`LocalBusiness` node in the schema graph. Available types are grouped by industry in `MM_Mod_Local::business_types()`.

### 3. Assign the template to pages

```php
update_post_meta( $post_id, '_wp_page_template', 'mm-service' );
```

This is the standard WordPress page template meta key. MetaManager reads it to determine the schema type.

### 4. Verify the output

Fetch the page and check the JSON-LD `<script>` block:

```bash
curl -s "https://guttertymer.com/services/rain-gutter-installation/" | grep 'application/ld+json'
```

Expected output in the `@graph`:
```json
{
  "@type": "Service",
  "name": "Rain Gutter Installation",
  "areaServed": [{ "@type": "City", "name": "Northwest Florida" }],
  "mainEntityOfPage": { "@id": ".../#webpage" }
}
```

## What the schema looks like

Each service page now emits:

| Node | Type | Purpose |
|---|---|---|
| `WebSite` | `WebSite` | Site identity + search action |
| `WebPage` | `WebPage` | Page identity + breadcrumb |
| `Service` | `Service` | The service being offered |
| `RoofingContractor` | `LocalBusiness` subtype | Business identity |
| `BreadcrumbList` | `BreadcrumbList` | Navigation trail |
| `Person` | `Person` | Author/owner |

The `Service` node links to the `WebPage` via `mainEntityOfPage`, and the `WebPage` links back via `mainEntity`. The business profile (`RoofingContractor`) provides `areaServed` which flows into the `Service` node.

## Key learnings

1. **Page templates are the non-destructive path.** Converting existing pages to CPTs would change URLs and break existing links. Page templates add schema without touching the content or structure.

2. **The `TEMPLATES` constant is the control point.** Adding one line (`'mm-service' => 'Service'`) enables the mapping. The template file (`page-mm-service.php`) is optional — WordPress falls back to the normal template if it doesn't exist.

3. **Business type drives the LocalBusiness node.** Setting `$biz['type']` to the right subtype (`RoofingContractor`, `LandscapingBusiness`, `GeneralContractor`, etc.) makes the business identity more specific for search engines and AI assistants.

4. **`areaServed` comes from the business profile.** The `Service` node automatically pulls `areaServed` from `$biz['service_areas']`. Set it once in the business profile and it flows to every service.

5. **The `NON_CREATIVEWORK_TYPES` array controls the node structure.** `Service`, `Event`, `TouristDestination`, etc. get `name` + `mainEntityOfPage` (not `headline` + `datePublished`), because they're Intangibles/Places, not CreativeWorks.

6. **Verify with curl, not the browser.** Browser caching can mask schema changes. `curl` shows the raw JSON-LD output.

## Template for other sites

For any service business with service pages:

1. Identify the right `LocalBusiness` subtype (see `MM_Mod_Local::business_types()`)
2. Add `'mm-service' => 'Service'` to `TEMPLATES` (already done in MetaManager)
3. Set the business profile with the right type
4. Assign `mm-service` template to each service page
5. Verify with curl

## Validation results (2026-11-15)

All 4 service pages validated clean:

| Tool | Result |
|---|---|
| Schema.org Markup Validator | 5 objects, 0 errors, 0 warnings |
| Schemagen.io (Schema.org + Google Rich Results) | isValid: true, 0 issues, 0 Google warnings |

### Issues found and fixed during validation

1. **ImageObject width/height format** - plain numbers rejected as "Invalid input". Fixed: wrap in QuantitativeValue (type: QuantitativeValue, value: N, unitCode: PIX).
2. **Service missing provider** - Google requires provider linking to the business for Service rich results. Fixed: provider references #organization on all Service nodes.
3. **Hand-written schema in HTML widgets** - 14 blocks of hand-written JSON-LD in SiteOrigin widgets duplicated MetaManager output. Removed after confirming MetaManager generates all fields (description was the gap - added to MM_Mod_Local).
4. **Sitemap honeypot exclusion** - wp-statistics-honey-pot-page-for-tracking was in the sitemap. Set mm_schema_fields.noindex = true to exclude it.
5. **Business type correction** - RoofingContractor was wrong (Gutter Tymer is not a roofer). Changed to HomeAndConstructionBusiness (correct LocalBusiness subtype for a gutter company).

### Key validation learnings

- **Schema.org Markup Validator** - POST validator.schema.org/validate?url=...&output=json. Strips )]}' prefix from response (tail -c +5). Authoritative for schema.org spec compliance.
- **Schemagen.io** - POST schemagen.io/api/validate with jsonString. Adds Google Rich Result eligibility checks. Stricter than schema.org on some types (ImageObject dimensions must be QuantitativeValue, not plain numbers).
- **Google Rich Results Test** has no public API - web UI only.
- **Service nodes** need provider linking to the business organization for Google rich results.
- **Hand-written schema in widgets** conflicts with MetaManager-generated schema - always remove one.

## What's next for Gutter Tymer

- [x] Review business profile details (phone, address, hours, price range, geo)
- [x] Schema types assigned (mm-service template on 4 service pages)
- [x] Hand-written schema removed (14 blocks)
- [x] Schema validated (schema.org + Google Rich Results)
- [x] Sitemap honeypot exclusion
- [x] Business type corrected (HomeAndConstructionBusiness)
- [ ] Configure MetaManager link checking schedule
- [ ] Run one-time image compression pass
- [ ] Verify remaining pages have appropriate schema types (AboutPage, ContactPage)
