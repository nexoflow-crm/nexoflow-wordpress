=== NexoFlow ===
Contributors: fl0rentg
Tags: seo, structured data, llms.txt, elementor, publishing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your site to your NexoFlow SEO team. It publishes posts, works on pages, and keeps search listings, structured data and llms.txt current.

== Description ==

NexoFlow is an AI SEO team for your website. This plugin is how the team works on a WordPress site. After you paste the site connect key from NexoFlow, the team can:

* publish and update your posts, with their categories, tags, featured images, search titles, descriptions and focus keywords;
* work on your pages through the Website Agent, after your OK in NexoFlow;
* build pages with Elementor;
* fix what NexoFlow's site check finds: search titles and descriptions, link previews, main headings, archives kept out of search, redirects and image descriptions;
* keep your structured data and llms.txt current, and tell search engines when a page changes.

= How it works =

1. Install and activate the NexoFlow plugin.
2. Open **NexoFlow** in your WordPress admin sidebar.
3. Paste the site connect key from NexoFlow.
4. Click **Save** to test the connection.
5. Use **Test connection** or **Sync now** whenever you want to check by hand.

Once connected, the plugin checks for pending work from NexoFlow every minute. NexoFlow can also securely ask it to check at once, for example when you publish something immediately.

= Publishing =

For each article, the plugin can:

* Create or update WordPress posts
* Set the post title and content
* Assign categories and tags
* Upload and set featured images
* Set SEO titles and meta descriptions
* Set the focus keyword
* Acknowledge completed publish jobs back to NexoFlow

= Website Agent =

NexoFlow can work on the pages of a connected site. It asks the site what it is able to do, then carries out a small, fixed set of page actions on it: read the site and its pages, create a page, change a page or one of its sections, add a section, rebuild a page in one change, add an image to the media library, and choose the homepage. On a block theme it can also read and change the header, the footer and the template that draws the homepage. The theme files are not changed.

* New pages are saved as drafts. A page is published, or made the homepage, only when NexoFlow explicitly asks for it.
* A published page is only changed when NexoFlow explicitly asks for a live change. The previous version is always kept as a WordPress revision first, and the change can be undone. If the site does not keep page revisions, published pages are left alone.
* A repeated request never runs twice, and a page that changed in the meantime is not overwritten.
* Actions run as a WordPress account on your site and follow that account's permissions.
* The plugin detects the block editor and Elementor, and uses each builder's own supported interface.
* There is nothing to configure for AI. The plugin has no AI provider setting and never contacts an AI provider. All AI work happens in NexoFlow.
* The settings page lists what NexoFlow did on the site in its Activity column, and says in plain words why a request did not go through.
* An administrator can turn the Website Agent off on the NexoFlow settings page. Publishing keeps working.

= Search listings =

The plugin can carry what NexoFlow's site check asks for on each page:

* the search title and description, the canonical address, and whether search engines may show the page. With nothing written yet, the description comes from the page's own words;
* link previews (Open Graph and X tags) with the page's own picture, or one chosen for the site;
* exactly one main heading, looking the same as before;
* author, date, tag and category archives kept out of search and the sitemap;
* a redirect from an address that no longer exists to another page on the site. A page that exists is never redirected;
* descriptions for images that have none;
* a phone viewport, language or title where a page has none;
* a page or post moved to the Trash and brought back, with its old address sent on. The homepage, the blog page, the privacy page and a page with pages under it are refused. Nothing is deleted for good;
* a plain page cache, only when asked for. It stands aside for any other caching plugin.

No SEO plugin is needed. With one active, it keeps printing its tags, and what NexoFlow writes goes into its fields.

= Structured data and llms.txt =

A connected site also carries two things NexoFlow keeps current for it:

* Structured data. For each page NexoFlow has it for, the plugin prints one JSON-LD script tag in the page's head. It says who the business is, where it works and what it offers, from the business's own records in NexoFlow. Other pages say what they are and where they sit (web page, article, breadcrumbs).
* llms.txt. The plugin answers /llms.txt with a short plain-text file that tells AI assistants what the business is and where its key pages are. If a file named llms.txt already sits in the site's root folder, your web server serves that file and the plugin's is not used.

Both are fetched from NexoFlow about once an hour and stored in your site's options, so a page view never waits on NexoFlow. Disconnecting the site removes them. Developers can switch either off with the `nexoflow_print_structured_data` and `nexoflow_serve_llms_txt` filters.

When NexoFlow publishes or changes a page, it tells search engines, Bing among them, so they read it again soon. The site answers the small key file they read to confirm those notices come from it. When you connect Bing in NexoFlow, your homepage carries Bing's verification tag.

= Security and privacy =

The plugin only connects to NexoFlow after a WordPress administrator provides a site connect key.

Communication with NexoFlow takes place over HTTPS. The optional sync URL is used only to trigger the plugin to check for pending jobs. It does not receive or contain post content.

The Website Agent adds two routes, /wp-json/nexoflow/v1/site/capabilities and /wp-json/nexoflow/v1/actions/execute. Both require the site connect key. They only run the actions built into the plugin: there is no way to run code, database queries or file operations through them. Each change request is recorded in a table on your site, which is removed when the plugin is uninstalled.

The plugin does not track website visitors or add visitor analytics.

NexoFlow service: https://nexoflow.net

== Installation ==

1. In WordPress go to Plugins → Add New, search for NexoFlow, and install it.
2. Activate NexoFlow.
3. Open NexoFlow in the admin sidebar.
4. In NexoFlow, copy your site connect key (in Chat, ask to connect WordPress), and paste it here.
5. Click Save. The plugin tests the connection automatically.

= Updating from NexoFlow's own download =

Sites that installed the plugin from NexoFlow's own download (versions 1.0.0 to 1.2.5) stay connected when they update to 2.0.0: the site connect key they saved under Settings → NexoFlow is moved across on first use, with nothing to paste again. The settings now live under NexoFlow in the admin sidebar.

== Frequently Asked Questions ==

= Does this plugin open my site to the public internet? =

WordPress contacts NexoFlow over HTTPS to pull jobs. NexoFlow may also POST to /wp-json/nexoflow/v1/sync with the site connect key so a publish can land immediately. That route does not accept article content.

The Website Agent routes accept requests from NexoFlow that read the site, save draft pages, or change existing pages. They need the same site connect key and cannot be used by anyone without it.

= Can NexoFlow publish pages or change my live pages? =

New pages are drafts. A page is published, or set as your homepage, only when NexoFlow explicitly asks for it. NexoFlow can also change a page that is already published, for example to add a section, and on a block theme the header, the footer and the template that draws your homepage. Before any such change WordPress stores the page as it was as a revision, so the change can be undone from NexoFlow or from the WordPress revisions screen. A page that is open in the editor, or that changed since NexoFlow last read it, is not touched.

= Do I need an SEO plugin? =

No. With none active, the plugin prints each page's search title, description, canonical address and link previews itself. With one active, that plugin keeps printing them, and NexoFlow writes into its fields.

= Do I need an AI account or an API key? =

No. The plugin has no AI settings. AI work is done by NexoFlow.

= Does it work with Elementor? =

Yes. The plugin builds and reads Elementor pages through the abilities Elementor provides for its MCP feature. That needs Elementor 4.3.0 or newer, WordPress 6.9 or newer, and Elementor MCP switched on under Elementor > Elementor MCP; the NexoFlow settings page has a button that switches it on. A new page is a draft, and a change to a published page is held back by Elementor until it is published. The NexoFlow settings page shows what was detected.

= How do I turn the Website Agent off? =

Open NexoFlow in the admin sidebar and choose Turn off Website Agent. Article publishing is not affected.

= When do posts appear after I publish in NexoFlow? =

As soon as NexoFlow notifies this site, or within about a minute on the next WordPress cron run. Use Sync now if you want to pull by hand.

= What happens if NexoFlow cannot be reached? =

The last error is stored and shown on the settings page. Jobs are not retried forever. After five failed attempts a job is acknowledged as failed.

= Are posts deleted when I uninstall the plugin? =

No. Uninstall removes NexoFlow settings and the Website Agent request table. Posts and pages stay on the site.

== Changelog ==

= 2.0.0 =
One release for every NexoFlow site, whether it ran 1.0.2 from WordPress.org or a version from NexoFlow's own download (1.0.0 to 1.2.5).

* Website Agent: NexoFlow reads the site and its pages, creates draft pages, changes a page or one or more of its sections, adds a section, and rebuilds a page in one change. The previous version is kept so every change can be undone. A published page changes only when NexoFlow asks for a live change.
* Header and footer of a block theme, for example links in the menu, and the template that draws the homepage. The theme files are not changed.
* Media library: an image can be added. Only real JPEG, PNG, WebP and GIF images are accepted.
* Elementor: pages built through Elementor's own abilities (Elementor 4.3 or newer with its MCP switched on, with one button on the settings page to switch it on). A new page is a draft, and a change to a published page is held back until it is published.
* Homepage: choose which published page is the homepage, and put the earlier one back.
* Search listings: each page's search title and description, its canonical address and whether search engines may show it, with or without an SEO plugin.
* Link previews: Open Graph and X tags on every page, with the page's own picture, or one chosen for the site.
* Main heading, archives kept out of search and the sitemap, redirects for addresses that no longer exist, descriptions for images that have none, and a phone viewport, language or title where a page has none.
* Trash: a page or post can be moved to the Trash and brought back, and its old address sent on. Nothing is deleted for good.
* Page cache: a plain cache for visitors who are not signed in, only on request.
* Structured data on each page NexoFlow describes, and web page, article and breadcrumb markup on the rest.
* llms.txt at the site's root. A file of your own is never replaced.
* Change notices: when NexoFlow publishes or changes a page, search engines, Bing among them, are told to read it again. Bing's verification tag is added to the homepage once Bing is connected in NexoFlow.
* Settings page: an Activity column of what NexoFlow did on the site and why a request did not go through, with a report to copy for support that never contains the site connect key. Each button's result shows in its own card. The Website Agent can be switched off.
* A repeated request never runs twice, and a page that changed in the meantime is not overwritten.
* The NexoFlow menu icon is white and full size in every state and on every admin page, even where another plugin's or theme's styles reset every element, and stays sharp on high-resolution screens.
* Sites updating from NexoFlow's own download (1.0.0 to 1.2.5) stay connected: their site connect key is moved across on first use, and the old once-a-minute check is replaced by this version's.
* Posts from NexoFlow keep their featured image, search title and description, author and date, and a post published before this version is updated in place, never copied.
* The site connect key is only ever sent to NexoFlow's own address, and a key NexoFlow has replaced stops working on the site at the next check.
* Pages built with Elementor get the same cleaning as every other page, and the site's switch for live changes also covers the homepage, redirects, search settings and the page cache.
* The page cache keeps a copy only for a page's own address and never one a visitor could shape, carries the page's security headers, and keeps the database small.

= 1.0.2 =
* Use https://nexoflow.net as the only API host.
* Set the plugin author to fl0rentg.
* Write SEO title, description, and focus keyword for every article.

= 1.0.1 =
* Pull jobs every minute even after a zip update.
* Allow NexoFlow to trigger an immediate pull on publish.

= 1.0.0 =
* Initial release.
