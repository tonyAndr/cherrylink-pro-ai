=== CherryLink – AI Internal Linking ===
Contributors: blueberryacc
Tags: internal links, internal linking, seo, links, link building
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find related posts and anchor phrases while you write, and audit your site's internal links. AI-powered, in the Block and Classic editors.

== Description ==

CherryLink helps you build internal links without leaving the editor. It uses semantic (AI) search to find the posts on your site that are most relevant to what you are writing, suggests anchor phrases inside your text, and inserts the link in one click. A link health dashboard shows where your internal linking is weak.

**In the editor (Block editor and Classic editor)**

* A sidebar panel lists related posts and pages for the content you are writing, ranked by meaning rather than by keyword overlap.
* For each result, CherryLink suggests anchor phrases that already exist in your text. Click to insert the link.
* An "All posts" search lets you find any post by keyword and link to it.
* Works with posts, pages and custom post types.

**Link health dashboard**

* Orphan posts (no incoming links) and posts with no outgoing links, with suggestions for fixing them.
* Broken internal links, repeated links and self links.
* Automatically detected key pages: the pages your site's content points to most, plus "underlinked" pages that deserve more links. You can pin or demote pages.
* CSV export of link data.

**Setup**

A short Connect wizard creates a free, domain-bound key with one click. No email and no payment details are required.

**Free plan**

The free plan indexes your full site up to 1,000 posts. If your site has more, the 1,000 most recently modified posts are indexed and the link health dashboard still covers every post. Nothing expires and editor actions are never metered. Larger sites can move to a paid plan at https://cherrylink.app/pricing/ without reinstalling the plugin.

**Languages**

Search is multilingual and works with any language. The plugin interface is available in English and Russian. Polylang and WPML post languages are respected.

== External services ==

CherryLink relies on a hosted service operated by the plugin authors. Related-post suggestions, the incoming-link list and the link health analysis need it, because the semantic search index lives there. Inserting links from the category list and the other tools that run on your own site work without a connection. **Nothing is sent until an administrator clicks "Connect" in the CherryLink setup screen** (or enters an API key).

**1. CherryLink connection service**
URL: https://api.cherrylink.app/cherrylink-pro-licensing/

* When: when an administrator clicks Connect, and afterwards periodically (and when a key is saved) to check that the key is valid and which plan limits apply.
* What is sent: your site's domain name and the API key. During Connect, the service also fetches a one-time proof token from your site (a public URL on your own site that exists for about ten minutes) to confirm that you own the domain.

**2. CherryLink indexing API**
URL: https://api.cherrylink.app

* When: after you connect. When posts are published or updated, when a scan is started, when you open the editor panel and search for related posts, and when the link health dashboard is refreshed.
* What is sent: for each indexed published post, its ID, title, plain-text content, URL, modified date, language and category/term names, and the links it contains. Your API key authenticates each request. Search requests send the text of the post being edited. Unpublished drafts are not indexed.
* Error events: when a background scan step fails, a short error message and the failing step are sent to the same API for diagnostics. No post content is included.

**3. Alternative route (relay)**
If api.cherrylink.app cannot be reached directly (for example it is blocked by your network), the plugin automatically retries the same HTTPS requests through a relay server at 31.129.48.202, port 9443. The relay passes the encrypted traffic through to the same API; the TLS connection and certificate are still validated against api.cherrylink.app. The relay is used only after a failed direct connection and only for the requests listed above.

Terms of service: https://cherrylink.app/terms/
Privacy policy: https://cherrylink.app/privacy/

You can remove all data stored on the service for your site with the "remove site data" action on the CherryLink Indexing screen, and uninstalling the plugin removes its settings from your database.

== Installation ==

1. Install and activate CherryLink from the Plugins screen, or upload the plugin to `/wp-content/plugins/cherrylink-ai/`.
2. Open CherryLink in the admin menu and click Connect. Reading the External services section above first is recommended.
3. Start the first scan from the Indexing tab. The scan runs in the background; keep WP-Cron (or a server cron) enabled so it can progress.
4. Open any post in the Block or Classic editor and use the CherryLink panel.

== Frequently Asked Questions ==

= Is CherryLink free? =

Yes. The free plan covers a full-site index of up to 1,000 posts, with no expiry and no limit on how often you use the editor suggestions. Paid plans are for larger sites and multiple sites.

= What happens if my site has more than 1,000 posts? =

The 1,000 most recently modified posts are indexed and available for suggestions. The link health dashboard still analyzes every post. When you edit an older post it moves into the indexed set.

= What data leaves my site? =

Only after you connect: the content of your published posts (for the search index), the text of the post you are editing when you ask for suggestions, and your domain. See the External services section for details and links to the privacy policy.

= Which languages are supported? =

Search works in many languages. The interface is available in English and Russian.

= Does it work with page builders? =

CherryLink works in the Block editor (Gutenberg) and the Classic editor (TinyMCE and text mode). Interactive page builders are not supported in the editor panel.

= Will my links disappear if I deactivate or uninstall the plugin? =

No. Links are ordinary links inside your content and stay in place.

= Indexing seems stuck. =

The background scan relies on WP-Cron. If your site has WP-Cron disabled, set up a server cron that calls wp-cron.php, or run `wp action-scheduler run`.

= Where can I get support? =

Use the plugin support forum on WordPress.org, or see https://cherrylink.app/docs/.

== Screenshots ==

1. The CherryLink sidebar in the Block editor: phrases from your text with the post they should link to.
2. The same panel in the Classic editor.
3. "All posts" search: find any post by keyword and insert a link to it.
4. The dashboard: orphan pages, link health and how many posts are learned.
5. Key pages: the main page of each topic and the posts that don't link to it yet.
6. The Connect wizard: one click creates a free key for the site.

== Source code ==

The editor and admin interface JavaScript in `js/` is compiled from the readable source in the public repository: https://github.com/tonyAndr/cherrylink-pro-ai/tree/feature/free-mode/gutenberg-src (directory `gutenberg-src/`, build with `npm install && npm run build`).

== Changelog ==

= 2.0.0 =
* New WordPress.org release under the name CherryLink.
* Free plan: full-site index up to 1,000 posts, activated with a one-click Connect wizard (no email).
* New link health dashboard: orphans, posts without outgoing links, broken, repeated and self links, key pages, CSV export.
* "All posts" search in the editor panel; one unified panel for the Block and Classic editors.
* Interface strings are now English with a Russian translation.
* The built-in GitHub updater is removed; updates come from WordPress.org.
* Requires deactivating the older "CherryLink Pro AI" plugin first; the two cannot run together.

== Upgrade Notice ==

= 2.0.0 =
First WordPress.org release. Deactivate the older "CherryLink Pro AI" plugin before activating this one.
