=== NexoFlow ===
Contributors: fl0rentg
Tags: nexoflow, publishing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish NexoFlow articles to this WordPress site.

== Description ==

NexoFlow pulls publish jobs from your NexoFlow project and writes them as WordPress posts on this site. After you connect, WordPress checks for jobs every minute. NexoFlow can also call a signed sync URL so Publish now, autopilot, and schedules do not wait for the next site visit.

After you activate the plugin, open NexoFlow in the admin sidebar and paste the site connect key from your NexoFlow project. Save tests the connection. Use Test connection or Sync now later as needed.

The plugin creates or updates posts, categories, tags, featured images, and common SEO title and description fields.

This plugin connects to the NexoFlow service at https://nexoflow.net after an administrator pastes a site connect key. WordPress then sends that key and job acknowledgements over HTTPS. It does not track visitors. The optional sync URL only starts a pull. It does not accept post content.

NexoFlow terms: https://nexoflow.net/terms
NexoFlow privacy policy: https://nexoflow.net/privacy

== Installation ==

1. Upload the plugin ZIP through Plugins → Add New → Upload Plugin.
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

= 1.0.1 =
* Pull jobs every minute even after a zip update.
* Allow NexoFlow to trigger an immediate pull on publish.

= 1.0.0 =
* Initial release.
