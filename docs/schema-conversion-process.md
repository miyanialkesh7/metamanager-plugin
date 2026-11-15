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

## What's next for Gutter Tymer

- [ ] Review business profile details (phone, address, hours, payment methods)
- [ ] Configure MetaManager settings (sitemap, robots, link checking)
- [ ] Run one-time image compression pass
- [ ] Set up link checking schedule
- [ ] Verify all pages have appropriate schema types
