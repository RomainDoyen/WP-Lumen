=== Lumen ===
Contributors: romaindoyen
Tags: images, webp, avif, seo, media
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.9.8
License: Proprietary

Optimize media library images (WebP/AVIF/JPEG), enrich SEO metadata (alt, JSON-LD, Gutenberg), and process SVG/PDF/video — multi-AI Vision support.

== Description ==

Lumen optimizes and enriches your WordPress media library in one go:

* **Image Optimization** — Convert to WebP, AVIF (if supported), or JPEG with configurable quality. Generates standard WP sizes (thumbnail, medium, large, full).
* **Original Replacement** — Optionally replace originals with modern formats while keeping a full backup for one-click restore.
* **SEO Metadata** — Auto-generate title, alt text (SEO / WCAG / short), caption, and description for images, SVG, PDF, and video.
* **Schema.org** — JSON-LD ImageObject, FAQPage, and VideoObject injection in page head.
* **Gutenberg Snippet** — Ready-to-paste `<picture>` markup for block editor.
* **Content URL Rewriting** — Automatically update hardcoded media URLs in post content and Elementor after format replacement.
* **Multi-AI Vision** — Optional enrichment via Mistral, OpenAI, Anthropic, or Google Gemini with monthly budget control and human validation workflow.
* **Bulk Processing** — Background processing with adaptive batch sizes, time-budget aware for shared hosting (Hostinger, OVH, etc.).
* **Icon Kit** — Generate PNG icon set (16–512px) and apply favicons site-wide.
* **Reports** — Export audit and history data as CSV, Excel, or PDF.
* **Admin UI** — Dashboard, dark/light theme, responsive design.

= Key Features =

* WebP / AVIF / JPEG conversion with Imagick or GD
* Triple alt text: SEO-oriented, WCAG-accessible, and short variant
* Backup + restore original before any replacement
* Stale URL detection and rewriting (content, Elementor CSS, WP options)
* Background processing via Action Scheduler or WP-Cron
* AI Vision with 4 providers, rate-limit handling, and monthly budget
* Clean uninstall (options, transients, custom table)

= Why Lumen? =

Unlike plugins that only compress images, Lumen handles the full lifecycle: optimize, replace, rewrite URLs, generate SEO metadata, and emit structured data — all with AI enrichment and production-grade background processing.

== Installation ==

1. Upload the `lumen-wp` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu
3. Go to **Lumen → Settings** to configure formats, quality, and AI provider
4. (Optional) Run **Lumen → Processing** to optimize existing media

== Frequently Asked Questions ==

= Does Lumen replace my original images? =

Yes, if "Replace original" is enabled (default). A permanent backup is created before replacement, and you can restore the original from the media edit screen at any time.

= What happens if my server doesn't support AVIF? =

Lumen checks server capabilities at runtime. If AVIF is not supported, it falls back to WebP + JPEG. A notice is shown in the admin.

= Can I use Lumen without AI? =

Absolutely. Lumen generates SEO metadata from filenames by default. AI Vision is optional and can be enabled per-provider with a monthly budget.

= Does it work with Elementor? =

Yes. Lumen rewrites hardcoded URLs in Elementor data and CSS files after format replacement.

= Is it compatible with multisite? =

The plugin is designed for single-site installations. Multisite support is not officially tested.

== Screenshots ==

1. Dashboard overview
2. Settings page
3. Bulk processing
4. Media library meta box
5. Icon kit generator

== Changelog ==

= 1.9.8 =
* With AI on, a short tick waits instead of closing the media with local SEO
* AI bulk batch size stays at 1 (no spiral of skipped Vision calls)

= 1.9.7 =
* Bulk no longer retries a stuck « processing » item as if it had never been processed
* Queue cursor advances before Imagick so a PHP crash cannot loop the same ID
* Repair/upgrade marks processing as error (out of queue), not pending
* Optimizer: no 180s time limit in bulk; Imagick TIME 25s; fail-fast on unreadable files

= 1.9.6 =
* Uninstall deletes `_lumen_*` attachment meta (no ghost « processing » after reinstall)
* Upgrade auto-repairs stuck processing statuses
* Tools: repair stuck processing button

= 1.9.5 =
* Bulk: unsupported MIME no longer stuck in queue (« Déjà traité » loop)
* Admin poll no longer drains Action Scheduler (Hostinger HTTP 503)
* Faster skip of already-done media in a tick

= 1.9.4 =
* Bulk start/poll no longer blocks on heavy AS COUNT + AI tick (Hostinger timeouts)
* Clearer AJAX error messages

= 1.9.3 =
* Bulk: recover stuck « processing » after PHP timeout
* Bulk: always reschedule next tick while running
* Vision HTTP timeout 25s; skip AI when tick budget is low

= 1.9.2 =
* Bulk: unsupported image MIME (GIF, HEIC, TIFF…) skipped as unsupported — queue continues
* Bulk: Vision API rate limit pauses the job with a clear message

= 1.9.1 =
* Security: edit_post on Suggest / Reprocess / Restore and Validation; Bulk, Tools, URLs, exports require manage_options
* Sanitize AI metadata before writing to attachments
* Backup / sidecar paths confined to uploads directory
* Job log: auto-purge rows older than 90 days
* Uninstall: options, transients, and lumen_jobs table cleaned
* Setting: optional site prefix on WCAG / short alts
* Release zip excludes vendor/

= 1.9.0 =
* FAQPage schema: local extraction, optional AI enrichment, front injection
* VideoObject schema for processed video media
* Settings: emit FAQ / enrich FAQ via AI
* Validation: rebuild VideoObject on approval

= 1.8.0 =
* API keys encrypted at rest (AES-256-CBC with WP salts)
* Settings: leave empty to keep key; clear key option
* Dashboard: server capability details (Ghostscript, FFmpeg, exec, OpenSSL, Action Scheduler, memory)

= 1.7.0 =
* SQL job log table (lumen_jobs): token history per treatment
* History: token badge + last run details
* Dashboard: monthly token KPI
* Tools: purge job log (table + cache)

= 1.6.2 =
* Media library: compact SEO card in detail modal
* PDF: AI preview via Ghostscript if Imagick absent
* Audit: ring score %; PDF export
* Error modal: removed phantom Cancel button

= 1.6.1 =
* History page: timeline, status filters, detail modal
* Validation integrated in Processing tab
* Dashboard: next actions + shortcuts

= 1.6.0 =
* SEO/GEO audit page (missing alts, unprocessed media, meta SEO, FAQ, llms.txt)
* Assisted fixes via Action Scheduler
* llms.txt served dynamically via rewrite
* CSV export of last audit

= 1.5.0 =
* Optional AI validation: approve/reject workflow
* AI call estimator before bulk processing
* awaiting_validation status

= 1.4.0 =
* Bundled Action Scheduler 3.6.4
* Adaptive multi-media ticks (Hostinger-friendly)
* Lazy total estimation (no heavy COUNT at start)

== Upgrade Notice ==

= 1.9.8 =
AI bulk no longer marks items OK with local SEO when the tick is too short for Vision.

= 1.9.7 =
Poison-pill media leave the queue as errors; bulk continues instead of hanging forever.

= 1.9.6 =
Clears stuck « processing » meta; uninstall now removes Lumen attachment meta.

= 1.9.5 =
Fixes bulk stuck after « Déjà traité » / HTTP 503 on Hostinger.

= 1.9.4 =
Fixes generic « Une erreur est survenue » on bulk start (admin-ajax timeouts).

= 1.9.3 =
Fixes bulk stalls after Vision/PHP timeouts (stuck processing + dead queue).

= 1.9.2 =
Bulk no longer stalls on unsupported MIME types or silent Vision rate limits.

= 1.9.1 =
Security hardening (capabilities, path confinement, AI sanitize). Bulk and Tools require manage_options.

= 1.9.0 =
FAQPage and VideoObject schema support, AI FAQ enrichment.

= 1.8.0 =
API keys are now encrypted. Re-save your keys if migrating from a previous version.

== Copyright ==

Lumen is proprietary software. All rights reserved.
