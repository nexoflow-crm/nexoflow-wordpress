=== NexoFlow ===
Contributors: fl0rentg
Tags: nexoflow, publishing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish NexoFlow articles to this WordPress site.

== Description ==

NexoFlow connects your WordPress site to your NexoFlow project and automatically publishes AI-generated content directly to WordPress.

After connecting your site with a secure site connect key, NexoFlow can create and update posts, assign categories and tags, add featured images, and apply SEO metadata including optimized titles, meta descriptions, and focus keywords.

= How it works =

1. Install and activate the NexoFlow plugin.
2. Open **NexoFlow** in your WordPress admin sidebar.
3. Paste the site connect key from your NexoFlow project.
4. Click **Save** to test the connection.
5. Use **Test connection** or **Sync now** whenever you need to manually trigger a sync.

Once connected, the plugin checks for pending publish jobs from NexoFlow every minute. NexoFlow can also securely trigger a sync when you publish content immediately, run an autopilot workflow, or reach a scheduled publishing time.

= What NexoFlow can publish =

For each article, the plugin can:

* Create or update WordPress posts
* Set the post title and content
* Assign categories and tags
* Upload and set featured images
* Set SEO titles and meta descriptions
* Set the focus keyword
* Acknowledge completed publish jobs back to NexoFlow

= Security and privacy =

The plugin only connects to NexoFlow after a WordPress administrator provides a site connect key.

Communication with NexoFlow takes place over HTTPS. The optional sync URL is used only to trigger the plugin to check for pending jobs. It does not receive or contain post content.

The plugin does not track website visitors or add visitor analytics.

NexoFlow service: https://nexoflow.net

NexoFlow Terms: https://nexoflow.net/terms

NexoFlow Privacy Policy: https://nexoflow.net/privacy

== Installation ==

1. In WordPress go to Plugins → Add New, search for NexoFlow, and install it.
2. Activate NexoFlow.
3. Open NexoFlow in the admin sidebar.
4. Create a WordPress project in NexoFlow, copy the site connect key, paste it here.
5. Click Save. The plugin tests the connection automatically.

== Frequently Asked Questions ==

= Does this plugin open my site to the public internet? =

WordPress contacts NexoFlow over HTTPS to pull jobs. NexoFlow may also POST to /wp-json/nexoflow/v1/sync with the site connect key so a publish can land immediately. That route does not accept article content.

= When do posts appear after I publish in NexoFlow? =

As soon as NexoFlow notifies this site, or within about a minute on the next WordPress cron run. Use Sync now if you want to pull by hand.

= What happens if NexoFlow cannot be reached? =

The last error is stored and shown on the settings page. Jobs are not retried forever. After five failed attempts a job is acknowledged as failed.

= Are posts deleted when I uninstall the plugin? =

No. Uninstall removes NexoFlow settings only. Posts stay on the site.

== Changelog ==

= 1.0.2 =
* Use https://nexoflow.net as the only API host.
* Set the plugin author to fl0rentg.
* Write SEO title, description, and focus keyword for every article.

= 1.0.1 =
* Pull jobs every minute even after a zip update.
* Allow NexoFlow to trigger an immediate pull on publish.

= 1.0.0 =
* Initial release.
