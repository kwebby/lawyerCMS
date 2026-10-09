<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# LawyerCMS Theme API v1 and publishing content

Start with [the complete theme-building tutorial](THEME_TUTORIAL.md) and [ready-to-package starter](../resources/themes/starter). For homepage editing and canonical office data, see [the Website guide](WEBSITE_GUIDE.md).

The theme designer and ZIP importer produce the same declarative theme representation. Themes cannot execute PHP, Blade, JavaScript or arbitrary HTML, install packages, change authentication/payment components, or replace central metadata. The application renders approved theme sections as initial server-side HTML. Imported versions are immutable; changing a design creates a new version. Activation requires recent authentication and can be rolled back to a previous activation.

The supplied designs are Chambers (`builtin-chambers`) and LawyerCMS Modern (`builtin-counsel`). The latter ID and its `resources/themes/counsel` path are retained for compatibility with existing snapshots. Importable examples are `resources/themes/chambers.zip` and `resources/themes/counsel.zip`. Built-in source manifests combine tokens/templates internally and are not the ZIP manifest format: use those prepackaged examples or the split-layout starter.

Website settings at `/app/website` adds a reviewed site-wide snapshot: editable homepage sections, local fonts, public media, canonical offices, navigation and footer. In Colors & fonts, applying a theme copies its palette/navigation and records the immutable theme ID for inner-page templates. It preserves the homepage sections. Save, preview, review, approve and publish this draft to apply the change. Draft theme changes cannot change the published website. After the first Website publication, legacy direct theme activation is disabled; use this reviewed workflow and Website rollback instead.

## ZIP layout

Files must be at the archive root, without a wrapping folder:

```text
theme.json
tokens.json
templates/page.json
templates/article.json        optional
assets/firm-logo.png          optional
assets/office.webp            optional
assets/brand-font.woff2       optional
preview.webp                 optional
```

`theme.json`:

```json
{
  "schema_version": 1,
  "name": "My law firm theme",
  "version": "1.0.0",
  "renderer": 1,
  "author": "ramanpal singh | https://kwebby.com",
  "license": "Private",
  "templates": ["page", "article"],
  "supported_blocks": ["paragraph", "heading", "bulletListItem", "numberedListItem", "table", "citation"],
  "navigation": [
    {"label": "Home", "url": "/"},
    {"label": "Client portal", "url": "/login"}
  ]
}
```

The manifest accepts only the keys shown above. `schema_version` and `renderer` must equal integer `1`; `name`, `version`, `author` and `license` are required strings of at most 120 characters. Version must be `major.minor.patch` with an optional prerelease suffix. `supported_blocks` must contain only supported BlockNote type names. If `templates` is present, each listed name must exist. JSON has no comments: store author attribution in the manifest's supported `author` string. Extra fields, including `author_url`, are rejected by the ZIP importer even when an internal built-in manifest contains extra metadata.

`tokens.json`:

```json
{
  "accent": "#285448",
  "ink": "#202622",
  "paper": "#fbfaf6",
  "muted": "#6c726c",
  "font_family": "serif",
  "radius": 4,
  "content_width": 1120
}
```

Colors use six-digit hexadecimal values. Font values are `serif`, `sans`, or `system`; arbitrary CSS is rejected. Radius is an integer from 0 to 24. Content width is an integer from 720 to 1440 pixels. Fonts included as assets can be downloaded from the trusted asset route but cannot inject CSS or change the fixed font-family allowlist.

ZIP files are parsed into immutable records; `version` becomes `theme_version` because the store reserves integer `version` for concurrency. The designer endpoint already calls its release field `theme_version`.

A `templates/page.json` file is mandatory. Add a template named after any supported public content type (`article`, `service`, `profile`, `office`, `tool`, `about`, `contact`) to override that type; otherwise the page template is used.

```json
{
  "sections": [
    {"type": "hero"},
    {"type": "content"},
    {
      "type": "columns",
      "columns": 2,
      "items": [
        {"heading": "Preparing for a consultation", "text": "Bring the documents related to your question.", "url": "/p/consultation"},
        {"heading": "Working with our team", "text": "Learn how we communicate and agree next steps.", "url": "/p/working-together"}
      ]
    },
    {"type": "contact", "heading": "Speak with our team", "text": "We will explain the next steps."}
  ]
}
```

Section types and fields:

| Type | Fields |
|---|---|
| `hero` | Optional `heading`, `text`; otherwise uses the page title/summary |
| `content` | No executable content; renders the page's validated BlockNote document |
| `contact` | Optional `heading`, `text`; firm contact details come from publishing settings |
| `cta` | `heading`, `text`, `button_label`, `button_url` |
| `columns` | `columns` integer 1–3; `items` up to 12 objects with `heading`, `text`, optional `url` |

Every template requires exactly one content section. A missing hero receives a generated page-title header; normally include only one hero. There are at most ten templates, thirty sections per template and twelve navigation links. Template names match `^[a-z][a-z0-9_-]{0,40}$`; arbitrary valid names are accepted as records but only supported content types are selected automatically. The template object accepts only `sections`.

Theme links must be root-relative paths on the application, under 1,000 bytes, using the allowed URL characters (letters, digits, `/`, `_`, `?`, `=`, `&`, `#`, `.`, `%`, `-`), without `..` or a leading `//`. Examples: `/`, `/login`, `/contact-request`, `/p/business-advice`. External URLs, `mailto:`, `tel:` and standalone anchors do not meet the ZIP link rule. Website settings has a separate, broader safe-URL validator.

Section heading/text/button-label strings are limited to 3,000 characters each; column item heading/text to 1,000; navigation labels to 80. Only declared fields are accepted. Text is escaped by Blade; strings such as `<script>` render as text. Arbitrary styles, classes, HTML, script, data bindings and nested layout trees are not supported.

A theme asset does not automatically populate a logo/image/font slot. Use returned asset URLs in supported public content, or the Public media and font-library controls for Website settings. The homepage has 17 application-owned module types; those names cannot be substituted for the five ZIP section types.

## Import lifecycle and limits

`POST /api/v1/themes/upload` accepts multipart field `theme`. The response is `{data: theme}` when validation and scanning succeed, or `{data: {id,status:"quarantined",message}}` when the configured scanner is unavailable. A quarantined upload has no preview or activation route. `GET /api/v1/themes/uploads` lists imports; `POST /api/v1/themes/uploads/{id}/retry` retries one. Scanner responses must confirm the SHA-256 digest before the theme is accepted.

Limits: 25 MiB compressed, 100 MiB expanded, 1,000 archive entries, 20 MiB per asset, 2 MB per JSON file, image dimensions at most 4096 × 4096. Archives with traversal, duplicate paths including case aliases, symlinks, encryption, nested archives, JavaScript, HTML, PHP, SVG or unknown file types are rejected. Accepted raster images are decoded and re-encoded. Asset processing is bounded to one asset at a time. No archive content is extracted into the public webroot.

Accepted theme assets are public design resources even when the theme is not active; never include private matter content. They are encrypted in private storage and served through `/theme-assets/{themeId}/assets/{filename}` with a fixed MIME type, `nosniff`, restrictive CSP and immutable caching. The response to theme APIs contains asset URLs and never private storage paths.

Theme APIs use the authenticated browser session and CSRF protection. `themes.read` governs listing/preview, `themes.write` governs upload/designer/retry, and `themes.activate` governs legacy activation/rollback. Import/preview do not publish content. The fresh-authentication middleware may require normal reauthentication for state-changing sensitive actions.

Other APIs:

- `GET /api/v1/themes`: supplied and custom themes plus active status.
- `POST /api/v1/themes/designer`: `{name,theme_version?,tokens,templates,navigation?,author?,license?}`. Here `templates` is an object mapping template names to section documents. The designer creates JSON-only themes without imported assets; it is not the ZIP payload shape.
- `GET /api/v1/themes/{id}/preview`: authenticated, noindex, private preview.
- `POST /api/v1/themes/{id}/activate` and `POST /api/v1/themes/rollback`: fresh-authenticated state changes.

Those direct activation endpoints apply only before Website settings has a published snapshot. For a managed website, `POST /api/v1/website/theme` accepts `{expected_version,theme_id}` and updates the draft. Inner pages use the referenced theme's validated templates with the published website's branding and canonical contact details; the homepage uses its editable section list. The local font library and optional Google Fonts catalog are independent of ZIP assets.

Website state APIs use `pages` permissions and optimistic versions:

| API | Request / effect |
| --- | --- |
| `GET /api/v1/website` | Draft/published state, history, capabilities, catalog and citation comparisons. |
| `PATCH /api/v1/website/draft` | `{expected_version,document}`; validates the complete Website document and resets approval. |
| `POST /api/v1/website/theme` | `{expected_version,theme_id}`; copies an accepted preset into the draft. |
| `GET /api/v1/website/preview` | Saved draft rendered privately with noindex/no-store. |
| `POST /api/v1/website/review` / `approve` / `publish` | `{expected_version}`; publishing requires fresh authentication. |
| `POST /api/v1/website/rollback` | `{expected_version,revision_id}`; restores a retained published Website revision and requires fresh authentication. |
| `POST /api/v1/website/page-pack` | `{expected_version,practices,people,guides,include_tools}`; array entries use `{title,slug}` and create drafts only. |

Use the returned `version` for the next mutation. A 409 means another revision exists, so preserve your edits and reconcile rather than retrying blindly. Website snapshots retain up to 40 published revisions. Theme rollback does not revert independently published page bodies.

For the ZIP format, the authoritative implementation is [`Themes.php`](../app/Domain/Publishing/Themes.php); for Website documents, it is [`Website.php`](../app/Domain/Publishing/Website.php).

## BlockNote subset and immutable revisions

Pages and written documents store canonical BlockNote JSON in encrypted versioned files. Autosaves use `expected_version`; stale edits receive HTTP 409. Updating approved/published content resets the working revision to draft. Public visitors keep seeing the last approved published snapshot until another review/approval/publication succeeds.

Supported blocks: `paragraph`, `heading`, `bulletListItem`, `numberedListItem`, `checkListItem`, `quote`, `table`, `image`, `file`, `citation`, `mergeField`, `question`, `pageBreak`. Text, bold, italic, underline, strikethrough, code style and safe links are supported. Unsupported blocks, unsafe URL schemes and unrecognized block properties receive 422. The document limit is 2 MB, 1,000 blocks and eight nesting levels. Tables allow 200 rows, twenty columns, header rows/columns and validated cell spans.

Custom block examples:

```json
[
  {"type":"citation","props":{"source":"Reviewed reference","url":"https://example.com/reference","pinpoint":"Paragraph 4"},"content":"Relevant passage or explanation"},
  {"type":"mergeField","props":{"field":"client.name","fallback":"[Client name]"},"content":[]},
  {"type":"question","props":{"resolved":false},"content":"Confirm the date from the source document."},
  {"type":"pageBreak","props":{}}
]
```

A document image may reference `props.fileId` for a clean, authorized local upload. PDF/DOCX export rechecks access and scan status before embedding its bytes. Exporters never fetch remote URLs. Public pages reject private `fileId` references; use validated `/theme-assets/...` images. Image paths with empty, `.` or `..` segments (including percent-encoded ones) are rejected and never rendered, because a browser would resolve them to another application route. Private image rendering and external image export fall back to a caption until an approved local attachment is available.

Application-owned review comments reference a block and document version. Review workflow is `draft → in_review → approved → published` for pages and `draft → in_review → approved` for written documents. Legal articles/services/tools require an author, reviewer and jurisdiction before publishing; articles additionally require sources. The reviewer is the account that approved the page (recorded at approval, not typed in), and it must differ from the page's author and anyone who edited it since its last approval unless an owner allows self-approval. Source documents and unapproved revisions are not made public by a page or theme activation.

## Search, social and schema ownership

Metadata follows site defaults, then content-type defaults, then page overrides. The publishing service—not the theme—owns title/description, canonical, robots, Open Graph, X cards, reciprocal language alternates and JSON-LD. Empty/null page overrides inherit defaults. Public indexing is restricted to published canonical pages with `index,follow`; drafts, previews, private documents and analyzer results never enter a sitemap. Archive returns 410 and published slug changes redirect to the current URL.

The schema selector supports WebPage, AboutPage, ContactPage, ProfilePage, Article, BlogPosting, Service, LegalService, SoftwareApplication, FAQPage and HowTo. The default graph adds factual firm/site entities, author identity and breadcrumbs where available. Firm contact details come from saved settings. An administrator-only advanced JSON editor permits a controlled set of additional types/URLs and the Schema.org context; ratings, reviews, prices, unsupported types, remote contexts and executable fields are rejected. JSON serialization escapes closing-script sequences. Structured data must describe the visible content; reviewers remain responsible for the factual accuracy of author credentials, addresses and legal sources.

A public content page of type `tool` can set `tool_slug` to `notice-explainer`, `consultation-preparation`, or `document-completeness`. Its approved content and centralized metadata then appear on `/tools/{tool_slug}`; the ordinary `/p/{slug}` redirects there. The landing page remains noindex until both publication and the configured AI/scanner/bot-protection/jurisdiction gates pass. Analyzer results and verification pages always stay private and noindex.

`GET/PATCH /api/v1/publishing/settings` accepts `site` details and `types` defaults. `GET /api/v1/seo/audit` returns metadata, indexing, stale-review and internal-link issues. `GET/POST /api/v1/seo/visibility` stores manual AI-search observations. Optional IndexNow uses `site.indexnow_enabled` and an 8–128 character `site.indexnow_key`, requires an HTTPS origin and `api.indexnow.org` in `CRM_APPROVED_HOSTS`, and queues submission only after publication commits. `/indexnow-key.txt` serves its intentionally public verification key. This does not guarantee crawling, rich results or placement in AI answers.

References: [BlockNote JSON storage](https://www.blocknotejs.org/docs/foundations/supported-formats), [Google structured-data policies](https://developers.google.com/search/docs/appearance/structured-data/sd-policies), [IndexNow protocol](https://www.indexnow.org/documentation).
