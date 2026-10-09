<!-- Author: ramanpal singh | URL: https://kwebby.com -->

# Website editing, local SEO and publishing

LawyerCMS separates a firm's public website from private client and staff records. Website settings controls the homepage and shared branding; the Content workspace controls individual pages and their BlockNote bodies. Both use reviewed publication. A saved draft never replaces the public version until it is approved and published.

Open `/app/website`. The editor uses dedicated sections for Homepage, Colors & fonts, Public media, Offices, Local listings, Navigation & footer, Page pack and Review & publish. Access follows `pages.read`, `pages.write`, `pages.approve` and `pages.publish`: editing and submitting for review need `pages.write`, approving needs `pages.approve`, and publishing or rolling back needs `pages.publish`. Pages in the Content workspace follow the same model. Installing fonts and changing their catalog credential additionally requires an owner or administrator.

## First publication

1. Enter the firm's real name and contact details in Website settings.
2. Choose colors and installed heading/body fonts. Set a logo from ready Public media.
3. Add real offices, distinguish physical offices from remote/service-area delivery, verify their details and choose which to publish.
4. Edit the homepage sections. Enable only complete sections with factual text and approved images.
5. Generate a draft page pack if needed. Complete, review and publish those pages individually in Content.
6. Set navigation to the pages that are actually available. Edit footer links and approved notices.
7. Configure Search & Social defaults and page overrides. Check the SEO audit.
8. Save, open the private saved preview, submit for review, approve and publish.

A reviewer should check the rendered page, not just the fields: links, office details, image descriptions, text contrast, mobile layout, keyboard access and factual claims all matter. Publication checks require a site name, homepage title, at least one visible section, ready image references and appropriate office/proof data. The three core text/background contrast pairs must reach 4.5:1.

## Homepage section library

There are nine starter slots. Only the useful default hero/contact sections are visible on a fresh installation; other starters can be filled and enabled. Existing approved homepage content can appear through a migrated content section.

| Section | Purpose and editable content |
| --- | --- |
| `hero` | Main heading, short explanation, primary/secondary button and lead image. |
| `practices` | Practice cards with title, text, image and page link. |
| `about` | Firm introduction and image. |
| `commitments` | Factual communication/service commitments as cards. |
| `people` | Lawyer/team cards linked to published profiles. |
| `process` | Ordered steps describing intake and working together. |
| `resources` | Selected guides/articles with links and images. |
| `locations` | Selected canonical offices; office details come from the registry. |
| `contact` | Firm contact details and a dedicated contact-request page. |

Eight optional modules complete the library:

| Section | Use |
| --- | --- |
| `testimonials` | Authentic, permissioned feedback with source attribution and review note. |
| `results` | Jurisdiction-reviewed factual outcomes, with source attribution and review note. |
| `awards` | Verified recognition, with source attribution and review note. |
| `faq` | Visible question/answer items rendered as accessible disclosure elements. |
| `tools` | Links to configured public tools. |
| `fees` | Accurate fee information and explanatory cards. |
| `content` | The published homepage's validated BlockNote body. |
| `cta` | A focused heading, explanation and action. |

All sections have a stable ID, visibility, a layout choice (`standard`, `split`, `centered`, `cards`), heading/text, optional image and buttons. The rendered features depend on type: item lists drive cards/process/FAQ, while locations uses office selection. There are at most 30 sections and 20 items per section. Reorder using the outline controls; duplicate a useful section or remove and undo it inline. The first visible hero supplies the page H1; additional section headings are subordinate.

Section headings support 200 characters, section text 5,000, button labels 80, and image alternative text 300. Use concise visitor-focused copy. Avoid placing NAP copies in arbitrary text when the canonical office component can display them.

Proof modules cannot publish without both an authentic source URL and a reviewer note. The source link can be public; reviewer notes stay private. No reviews, offices, qualifications or results are invented by the builder.

## Public images

Public media is separate from matter attachments. Upload genuine PNG, JPEG or WebP images, at most 10 MiB, 8 megapixels and 8,000 pixels on either side. Every upload stays quarantined until the configured malware scanner returns a clean verdict for its digest. A scanner failure does not make an upload available. An editor can retry after the integration is healthy.

Accepted media is re-encoded, metadata is stripped, the primary is resized to at most 1,920 pixels wide without upscaling, and smaller 768/1,280-pixel responsive variants are generated when appropriate. Set:

- A descriptive name, alternative text, caption and rights note.
- The focal point from 0 to 100 on each axis, to keep an important subject visible when a component crops the image.
- Section-specific alternative text where the surrounding context changes the image's meaning.

Select only `ready` images in published sections. Private previews require page-read permission. Public media reads require a reference in the current published, visible website; an unreferenced image is not made public just because it was uploaded. Deletion is blocked while draft, published or retained revision references exist, preserving rollback. Image paths and encryption keys never go into public HTML.

## Brand and font library

Seven colors control background, surface, text, muted text, primary, primary text and borders. Radius is 0–24 pixels and content width is 720–1,440 pixels. Preview contrast before publication. Heading/body fonts are selected independently.

Sixteen bundled Google Fonts are locally hosted with license/source records. They cover several serif/sans designs and selected Devanagari, Gurmukhi and Tamil families. These fonts work without an API key or requests to Google from visitors' browsers.

An owner/administrator can configure a **Google Fonts Developer API** key in Colors & fonts, browse the full catalog and install a family. The key is stored encrypted and returned only as a configured/not-configured status. Installation uses fixed official destinations, verifies catalog membership and license metadata, downloads bounded WOFF2 assets and serves the installed files locally. A successful installation adds that family to the existing pickers. Installation can fail when a family does not meet the supported normal-weight, license or download constraints; the bundled library remains usable.

The API key is for server-side catalog access. Do not paste remote CSS, font URLs or secret credentials into a theme. ZIP font assets do not install a font family automatically.

Theme presets are optional starting points. Applying a preset updates the draft palette, navigation, geometry and inner-page template selection. It preserves homepage sections and canonical business records. Use [the theme tutorial](THEME_TUTORIAL.md) to distribute your own preset.

## Canonical offices and NAP

NAP means name, address and phone. Create one canonical office record per real location, with:

- Stable office ID and unique lowercase page slug.
- Name, street address, city, region, postal code and country.
- Phone, email, opening-hours text and directions URL.
- Kind: physical office, remote delivery or service area.
- Verification date and whether the office should be public.

A published physical office must have a real address, city, country and verification date. Remote delivery and a service area must not be described as a fabricated physical branch. Choose which offices appear in each Locations section; leaving its selection empty means all published offices.

Published office records feed the homepage, contact components, office detail pages and central structured data. Office pages generated by the page pack are bound by `website_office_id`; their addresses are not frozen into editable body text. An unavailable/unpublished bound office makes that office page unavailable publicly and excludes it from the sitemap.

After the first Website publication, the legacy Search & Social identity fields are read-only for identity changes. Edit canonical identity here, review and publish it, so HTML and schema agree. Other SEO defaults remain configurable in Search & Social.

## Local listing consistency tracker

For each external listing, select the canonical office and record the provider, listing URL, observed name/address/phone, observation date, status and notes. Examples of providers can include a business profile, legal directory or map listing; the software does not claim that a listing exists until you record it.

The comparison strips punctuation/whitespace and ignores letter case to highlight differing NAP fields. It does not verify a directory automatically, prove that two addresses identify the same office or update a third-party listing. Review the page yourself and choose the appropriate status:

`not_audited`, `match`, `format_only`, `different_office`, `outdated`, `missing`, `duplicate_suspected`, `correction_pending`, `resolved`, `unable_to_verify`.

Keep separate offices separate. A difference can be a valid second office, not an error. Record the correction outside LawyerCMS, then revisit the listing and update its observed values/status. Citation records and private notes do not render on the public site.

## Navigation, footer and page packs

Website navigation and footer each support up to 16 labeled links. Website URLs accept approved public local routes, anchors, telephone/email links and safe HTTP(S) destinations. The ZIP format is stricter and accepts only same-site root-relative paths. Use the correct format for the surface you are editing.

A page pack creates placeholders in **draft**:

- Eleven core pages: home, about, practices, people, locations, contact, resources, privacy, terms, accessibility and professional disclaimer.
- One page per supplied practice, lawyer/person and guide.
- One bound office page per physical office in the draft registry.
- Optionally three tool pages: notice explainer, consultation preparation and document completeness.

The count is `11 + practices + people + physical offices + guides + optional tools`, before duplicate skips. A pack accepts at most 60 candidate pages, preserves existing/reserved URLs and reports skips. It does not generate approved legal content or publish policies automatically. Complete the placeholders in BlockNote and obtain the applicable review. Directory pages list the corresponding available published pages.

The website homepage is `/`; a published CMS `home` page's URL resolves there. Normal content pages use `/p/{slug}`. Publishing a tool landing page binds it to `/tools/{tool-slug}`, and analyzer policy/integration gates still apply independently.

## BlockNote and page SEO

Write page copy in the Content workspace. The supported BlockNote subset includes headings, lists, tables, quotes, images/files and trusted citation/merge-field/question/page-break blocks. The canonical content is JSON; server renderers validate it and produce HTML/PDF/DOCX. See [Theme API](THEME_API.md#blocknote-subset-and-immutable-revisions) for exact limits.

Page review is `draft → in_review → approved → published`. The approver must be someone other than the page's author and anyone who edited it since its last approval; the public “Reviewed by” byline is the approving account's name, recorded at approval, and cannot be typed in. Autosaves use expected revisions; a stale save returns 409 rather than overwriting another editor. Revisions and comments are application records. Realtime coediting is not part of this baseline.

Search & Social supports defaults, content-type defaults and page overrides for metadata/social tags/schema. The central application generates title, description, canonical, robots, Open Graph, X cards, language alternates and JSON-LD. Themes cannot add competing tags. Site and content-type defaults change every page at once without review, so they cannot set a canonical URL, and only an owner or administrator can change their indexing (robots) setting. A canonical URL belongs on the individual page; pointing one at another site requires an owner or administrator. Review factual author/reviewer/jurisdiction/source information and visible page content together. Structured data is not a ranking or rich-result guarantee.

Only published, indexable, canonical pages enter the public sitemap. Private previews, portal records, chat, invoices and analyzer results stay outside it. The SEO audit flags missing metadata, internal-link issues and stale legal reviews. Manual AI-visibility observations record research rather than promising recommendations.

## Review, conflicts and rollback

Website state follows `draft → in_review → approved → published`. Saving a change returns it to draft and invalidates approval. Approval records a hash; publishing verifies that the approved document is unchanged. Nobody who saved the draft since its last approval can approve it. A small practice with a single approver can let an owner allow self-approval in Settings → Security; each self-approval is audited. Every write carries the current `expected_version`. If another editor has saved, preserve/download unsaved work, reload and reconcile explicitly.

The saved preview shows the saved draft. It does not include unsaved local fields. Published snapshots remain stable until the next successful publication. The previous 40 published snapshots are retained for rollback. Rollback requires publish permission and recent authentication, restores the chosen website document and records a new publication. Individual page bodies have independent revisions.

## Enquiries and public AI tools

The dedicated `/contact-request` page collects basic contact details, jurisdiction, issue category and an optional office. Contact consent is required; marketing consent is a separate unchecked choice. A successful request creates an assigned new CRM lead with email still marked unverified. Repeated submission of the same form is idempotent. When Cloudflare Turnstile is configured (`TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET`), the form also requires a bot check verified for this site's host; only the contact page's content security policy then allows the Turnstile script and frame. Without it, the form relies on its hidden field and per-address rate limit. It does not activate a matter, accept representation or mark a person as a client.

AI tool links do not enable processing by themselves. Configure the scanner, provider, bot protection, email and jurisdiction review gates first. Public analyzer results and uploads are temporary/private and remain separate from matter retrieval. Review operational configuration and release evidence in [deployment](DEPLOYMENT.md), [security](SECURITY.md) and [release gates](RELEASE_GATES.md).
