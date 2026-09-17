=== NexoFlow ===
Contributors: fl0rentg
Tags: nexoflow, publishing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish NexoFlow articles to this WordPress site.

== Description ==

NexoFlow pulls publish jobs from your NexoFlow project and writes them as WordPress posts on this site. The plugin connects outbound over HTTPS. NexoFlow does not need inbound access to your REST API for this path to work.

After you activate the plugin, open NexoFlow in the admin sidebar and paste the site connect key from your NexoFlow project. Save tests the connection. Use Test connection or Sync now later as needed.

The plugin creates or updates posts, categories, tags, featured images, and common SEO title and description fields.

This plugin connects to the NexoFlow service at https://nexoflow.net after an administrator pastes a site connect key. WordPress then sends that key and job acknowledgements over HTTPS. It does not track visitors or register public write routes.

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

No. WordPress contacts NexoFlow over HTTPS. The plugin does not register public write routes.

= What happens if NexoFlow cannot be reached? =

The last error is stored and shown on the settings page. Jobs are not retried forever. After five failed attempts a job is acknowledged as failed.

= Are posts deleted when I uninstall the plugin? =

No. Uninstall removes NexoFlow settings only. Posts stay on the site.

== Changelog ==

= 1.0.0 =
* Initial release.
