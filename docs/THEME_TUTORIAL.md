<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Build and package a LawyerCMS theme

This tutorial builds a reusable public-site design without running uploaded code. Start with [the complete starter](../resources/themes/starter), then use the [Theme API reference](THEME_API.md) for every supported field and limit. Installation of LawyerCMS itself is covered in [deployment](DEPLOYMENT.md).

## 1. Choose the right customization surface

| Goal | Use |
| --- | --- |
| Change a firm's homepage text, images, buttons, order or visibility | Website settings → Homepage |
| Change logo, palette, heading/body fonts, width or corner radius | Website settings → Colors & fonts |
| Maintain offices, phone numbers, addresses and local listing comparisons | Website settings → Offices / Local listings |
| Write a service page, guide or lawyer biography | Content → BlockNote editor |
| Distribute reusable palette, navigation and inner-page structures | Theme API v1 ZIP |
| Introduce a completely new section, editor control or renderer | A reviewed application source change, with validation, rendering and tests |

A theme ZIP does not install PHP, Blade, React, JavaScript, CSS, plugins or Composer/npm packages. It cannot change logins, permissions, chat, payment flows, invoice layouts or metadata. The 17 homepage modules in Website settings are a separate document schema from the five inner-page template section types in a theme ZIP.

## 2. Copy the starter

Run from the repository root:

```sh
cp -R resources/themes/starter /tmp/my-law-firm-theme
```

The starter contains only files accepted by the importer:

```text
my-law-firm-theme/
├── theme.json
├── tokens.json
└── templates/
    ├── page.json
    ├── article.json
    └── service.json
```

`theme.json` supplies identity, compatibility, navigation and the list of templates. `tokens.json` supplies design values. Each template contains an ordered `sections` array. JSON files cannot contain comments; the starter records the requested author and URL in the supported manifest `author` string.

The built-in source directories use a combined internal `theme.json` containing token and template objects. **Do not ZIP a built-in source directory directly.** The importer expects the split layout above. The prepackaged supplied ZIPs and the starter use the import format.

## 3. Set your manifest

The starter uses this manifest:

```json
{
  "schema_version": 1,
  "name": "LawyerCMS Starter",
  "version": "1.0.0",
  "renderer": 1,
  "author": "ramanpal singh | https://kwebby.com",
  "license": "MIT",
  "templates": ["page", "article", "service"],
  "supported_blocks": ["paragraph", "heading", "bulletListItem", "numberedListItem", "checkListItem", "quote", "table", "image", "file", "citation", "mergeField", "question", "pageBreak"],
  "navigation": [
    {"label": "Home", "url": "/"},
    {"label": "Contact", "url": "/contact-request"},
    {"label": "Client portal", "url": "/login"}
  ]
}
```

Change the name and semantic version when distributing your own design. Keep schema/renderer at `1`. Use a license you have the right to grant, and keep third-party asset license records with your source distribution. The importer does not allow arbitrary extra manifest keys such as `author_url`, `scripts`, `stylesheets` or `dependencies`.

Navigation links must be same-site root-relative paths. For example, `/p/business-advice` is allowed; `https://example.com`, `//example.com`, `javascript:...` and `../contact` are rejected. Pages referenced in a menu must be created and published separately. Importing a theme does not create their content.

## 4. Define the palette and geometry

```json
{
  "accent": "#285448",
  "ink": "#202622",
  "paper": "#fbfaf6",
  "muted": "#596159",
  "font_family": "serif",
  "radius": 4,
  "content_width": 1120
}
```

Use six-digit hexadecimal colors. Radius is an integer from `0` to `24`; content width is an integer from `720` to `1440` pixels. `font_family` accepts `serif`, `sans` or `system`.

Applying a theme in Website settings copies `accent → primary`, `ink → text`, `paper → background` and `muted → muted`, plus radius, width and navigation. It records the theme ID for inner-page templates. A serif preset selects Lora headings; the other choices select DM Sans headings. Body text defaults to DM Sans. You can then select different installed fonts and tune all seven Website color tokens. Existing homepage sections, offices, media and footer remain in the draft.

Theme ZIP WOFF2 files are downloadable assets; they do not inject `@font-face` declarations. Use the Website font library for active typography: bundled fonts work offline, and an administrator can optionally install additional families through the Google Fonts catalog.

## 5. Build a custom page structure

The starter's page template shows all five supported section types:

```json
{
  "sections": [
    {"type": "hero"},
    {"type": "content"},
    {
      "type": "columns",
      "columns": 2,
      "items": [
        {"heading": "Prepare for a consultation", "text": "Bring the documents relevant to your question."},
        {"heading": "Agree the next step", "text": "Discuss scope, communication and fees with the team."}
      ]
    },
    {
      "type": "cta",
      "heading": "Start with a conversation",
      "text": "Share your contact details so the team can respond.",
      "button_label": "Request contact",
      "button_url": "/contact-request"
    },
    {"type": "contact", "heading": "Contact our team"}
  ]
}
```

The `content` section renders the page's validated BlockNote body. Every template requires exactly one. Place it wherever the page body belongs. Omitting a hero causes the renderer to add a page-title header; normally use one hero. A hero without explicit text uses the page title/summary, allowing one design to serve many pages.

`columns` accepts one, two or three columns and up to 12 items; the layout collapses on small screens. Item fields are `heading`, `text` and optional `url`. A CTA uses `heading`, `text`, `button_label` and `button_url`. `contact` uses the firm's canonical published contact data rather than duplicating a phone/address in the template.

Add `templates/article.json` to customize articles or `templates/service.json` for services. Recognized content-type templates are `page`, `article`, `service`, `profile`, `office`, `tool`, `about` and `contact`. The `page` template is the fallback. The importer also accepts syntactically valid custom names, but a new name does not register a new content type; that requires application work.

Templates support plain escaped strings, not interpolation expressions. `{{ client.name }}` in a section is text. For validated document merge fields, use the BlockNote `mergeField` block and the application workflow. A theme cannot read client or matter records.

## 6. Add optional public assets

Accepted paths include:

```text
assets/logo.png
assets/office-photo.webp
assets/team/portrait.jpg
assets/fonts/brand.woff2
preview.webp
```

Images must match their declared extension and be no larger than 4096 × 4096. The importer re-encodes accepted raster images. WOFF2 files must have a valid WOFF2 signature. Never package confidential client material: imported theme assets have public download URLs, even if the theme is not the active design.

There is no automatic `assets/logo.png` slot binding. The accepted theme response includes each asset's `/theme-assets/{theme-id}/assets/...` URL. A public BlockNote image can use an approved theme asset URL. For the homepage, upload images into Website settings → Public media and select them there; these use scanned media IDs, alternative text and focal-point controls. `preview.webp` is accepted as a preview asset, but adding it does not change the page layout or create a content block.

Keep full license files in your separate source distribution when required. Only the strict accepted files go inside the install ZIP; `.txt`, `.md`, `.DS_Store`, `__MACOSX`, SVG and arbitrary CSS are rejected.

## 7. Package the ZIP correctly

From the copied theme directory, create the archive **outside** that directory:

```sh
cd /tmp/my-law-firm-theme
test ! -e /tmp/my-law-firm-theme-1.0.0.zip
zip -X /tmp/my-law-firm-theme-1.0.0.zip \
  theme.json tokens.json \
  templates/page.json templates/article.json templates/service.json
unzip -l /tmp/my-law-firm-theme-1.0.0.zip
```

When using the starter directly from the repository root:

```sh
mkdir -p dist
test ! -e dist/lawyercms-starter-1.0.0.zip
(cd resources/themes/starter && zip -X ../../../dist/lawyercms-starter-1.0.0.zip \
  theme.json tokens.json \
  templates/page.json templates/article.json templates/service.json)
```

Create a fresh output archive each time: stop if the `test` check fails, then choose a new version/file name or remove only the previous build artifact before rebuilding. Updating an existing ZIP can retain obsolete entries. Append explicit accepted asset paths to the `zip` command when needed. `-X` avoids platform-specific ZIP extra fields. Explicit file names avoid accidentally including editor backups, macOS metadata or a previous archive. Do not use `zip -r theme.zip my-law-firm-theme`: the wrapping directory prevents `theme.json` from being at the archive root.

The ZIP command is a packaging step, not the security validator. Import into a nonproduction LawyerCMS instance using the same scanner configuration you plan to use for production. See the limit/error reference below.

## 8. Import and preview

1. Sign in with `themes.write` permission and open **Themes** (`/app/themes`).
2. Upload the ZIP using the dedicated upload form.
3. If the scanner is unavailable, the archive remains quarantined. Configure the approved scanner and retry from the import list. A quarantined archive cannot be previewed or activated.
4. For an accepted theme, open its authenticated preview. Review content order, long headings, tables, narrow screens, keyboard focus and contrast.
5. Open **Website settings → Colors & fonts**, choose the accepted theme and apply it to the draft. Save any pending edits first.
6. Select fonts, supply real content/media/office details and open the saved Website preview.
7. Submit for review, approve, then publish with the required permission and recent authentication.

Before the first Website publication, the legacy **Activate** theme action switches the theme immediately and supports legacy rollback. Once Website settings has a published snapshot, that direct action is disabled. Use the draft/review/publish workflow above so the public website remains consistent.

Previewing a saved draft is private and noindex. It does not publish a page, change the live homepage or expose quarantined uploads. Theme import and page publication are separate actions.

## 9. Update and roll back

Edit your source and increment `version`, for example `1.0.0 → 1.0.1`. Package and import again. Each import produces a new immutable theme record; it does not overwrite a previously published theme. Apply the new record to a draft and publish after review.

Website settings retains up to 40 published snapshots. Its rollback chooses a retained prior snapshot and publishes a new snapshot of that restored document; it requires publish permission and recent authentication. This restores website settings/theme selection, not unrelated page body revisions or financial documents. Page content uses its own revisions and publication flow.

## Common import errors

| Message / symptom | Cause and remedy |
| --- | --- |
| Archive must contain `theme.json` / `tokens.json` | A wrapping directory or missing split file; rebuild using the explicit file command. |
| Unsupported manifest properties | A combined built-in source file or extra fields; follow the import manifest. |
| Every template must contain exactly one trusted content section | Add one `content`; remove duplicates. |
| Theme links must stay on this site | Replace absolute/external/unsafe links with permitted root-relative paths. |
| Unsupported file / directory | Remove metadata, documentation, code, SVG, nested ZIPs or unsupported folders from the ZIP. |
| Unsafe / duplicate archive path | Remove traversal, absolute paths, backslashes or case-alias duplicates. |
| Invalid font / image | Use a genuine supported file and stay within image/asset limits. |
| Quarantined | Scanner unavailable, timed out or failed to confirm the digest. Fix the integration and retry. |
| Apply a theme preset in Website settings, then publish | The website is already snapshot-managed; use Colors & fonts. |
| HTTP 409 when applying/saving | Another editor changed the draft. Preserve your edits, load the current revision and reconcile. |
| HTTP 403 / fresh authentication request | Your account lacks permission or needs recent reauthentication. Use the normal account workflow. |

Never disable validation or malware scanning to make an archive install. See [Theme API v1](THEME_API.md) for the precise constraints and [architecture](ARCHITECTURE.md) for adding a new supported structure to the application.
