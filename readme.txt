=== ForgeHub IndexNow ===
Contributors: wpforgehub
Tags: indexnow, instant indexing, bing, yandex, seo
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Instant search engine indexing via the IndexNow protocol with automatic API key hosting, publish pings, and submission logs.

== Description ==

**ForgeHub IndexNow** notifies Bing, Yandex, Seznam, Naver, and all participating IndexNow search engines the exact moment you publish, update, or remove content—without waiting days for crawlers or relying on third-party SaaS proxies.

Simply activate the plugin: it automatically generates a cryptographic IndexNow API key, serves the required `<key>.txt` verification file virtually at your site root, and submits URLs in real time with a clean 50-entry submission log.

### Key Features (Free Version)
* **Automatic IndexNow API Key Generation**: Creates a standards-compliant 32-character hex key on activation with one-click regeneration and custom key support.
* **Virtual Root `.txt` Key Verification Endpoint**: Serves `https://yoursite.com/<api-key>.txt` dynamically without needing writable root permissions or manual FTP uploads.
* **Automatic Publish, Update & Trash Pings**: Instantly notifies IndexNow when posts, pages, or WooCommerce products are published, updated, or moved to trash.
* **Configurable Search Engine Endpoint**: Choose between the global `api.indexnow.org` hub, `www.bing.com/indexnow`, or `yandex.com/indexnow`.
* **Manual Single-URL Instant Ping**: Submit any site URL directly from the Settings screen with immediate HTTP status feedback.
* **Last 50 Submissions Log**: Inspect timestamp, URL, trigger type, endpoint, HTTP status code, and response summary right in your dashboard.
* **Zero External SaaS & Lightweight**: 100% local execution using native HTTP APIs, zero frontend scripts, and WCAG 2.1 AA compliant admin UI.

### Pro Features (Separate Commercial Plugin)
Need bulk indexing, automatic retry queues, custom post types, or CSV analytics? The separate commercial Pro edition is available at [WPForgeHub](https://wpforgehub.com/product/indexnow/) and is not bundled in this free plugin:
* **Bulk URL Submission**: Submit hundreds or thousands of URLs in a single IndexNow batch request—paste URLs or send recent published content in one click.
* **Automatic WP-Cron Retry Queue**: Failed submissions are automatically queued and retried on schedule with configurable max attempts and manual queue execution.
* **Custom Post Types & Taxonomy Archives**: Enable instant indexing for any public Custom Post Type and automatically ping updated category/tag/taxonomy archive URLs.
* **Status Statistics & CSV Export**: Track success rates, 2xx/4xx/5xx breakdowns, and export full submission logs to CSV.

== Installation ==

1. Upload the `forgehub-indexnow` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu.
3. Navigate to **Settings > IndexNow** to verify your auto-generated key endpoint and monitor live submissions.

== Frequently Asked Questions ==

= Do I need to manually upload a .txt file to my server root? =
No. ForgeHub IndexNow serves the required `<api-key>.txt` verification endpoint virtually at your site root with `text/plain` headers. You can test it directly from the Settings page.

= Which search engines receive my URL notifications? =
By default, URLs are sent to `https://api.indexnow.org/indexnow`, which shares notifications across all participating IndexNow engines including Microsoft Bing, Yandex, Seznam.cz, and Naver.

= Does this plugin add any frontend scripts or slow down my site? =
No. It loads zero frontend CSS or JavaScript and executes pings asynchronously/lightweight only when content changes or when you submit a URL manually.

= Can I regenerate my IndexNow API key at any time? =
Yes. Click **Regenerate Key** in **Settings > IndexNow** and the virtual `.txt` verification endpoint updates immediately.

== Screenshots ==

1. ForgeHub IndexNow settings dashboard showing the auto-generated API key, virtual .txt endpoint verification, manual URL ping tool, and live 50-entry submission log.

== Changelog ==

= 1.0.0 =
* Initial stable release with automatic IndexNow API key generation, virtual root `.txt` verification endpoint, automatic publish/update/trash pings for posts, pages, and products, manual URL submission, 50-entry submission log, and Pro Bulk Submit, WP-Cron Retry Queue, CPT/Taxonomy support, and CSV statistics.
