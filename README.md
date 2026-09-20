# NexoFlow for WordPress

Connect your WordPress site to [NexoFlow](https://nexoflow.net) and automatically publish content generated and scheduled by your NexoFlow project.

NexoFlow for WordPress acts as the bridge between your WordPress site and NexoFlow. Once connected, NexoFlow can create and update posts, assign categories and tags, publish featured images, and apply SEO metadata to your articles.

## Features

* Automatically publish NexoFlow content to WordPress
* Create and update WordPress posts
* Assign categories and tags
* Upload and set featured images
* Set SEO titles and meta descriptions
* Set focus keywords
* Support scheduled publishing
* Support NexoFlow Autopilot publishing
* Manually trigger a sync from WordPress
* Securely acknowledge completed publish jobs
* HTTPS communication with the NexoFlow service
* No visitor tracking or analytics
* No post content is sent through the sync URL

## How It Works

The plugin uses a simple pull-based publishing model.

```text
NexoFlow
    │
    │ Publish Job
    ▼
WordPress Plugin
    │
    ├── Create / Update Post
    ├── Set Categories & Tags
    ├── Upload Featured Image
    └── Apply SEO Metadata
    │
    ▼
WordPress
```

After the plugin is connected, WordPress checks NexoFlow for pending publish jobs approximately once per minute.

For time-sensitive publishing, NexoFlow can also securely trigger a sync request. This allows actions such as **Publish now**, scheduled publishing, and Autopilot jobs to start without waiting for the next regular sync.

The sync endpoint only triggers a job check. It does not receive article content.

## Installation

### From WordPress

1. Download the latest plugin release.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload the NexoFlow plugin ZIP.
4. Install and activate the plugin.
5. Open **NexoFlow** in the WordPress admin sidebar.
6. Enter the **Site Connect Key** from your NexoFlow project.
7. Click **Save**.

The plugin will test the connection when the key is saved.

### From Source

Clone the repository into your WordPress plugins directory:

```bash
cd wp-content/plugins
git clone https://github.com/YOUR-ORG/YOUR-REPO.git nexoflow
```

Activate the plugin from **WordPress → Plugins**.

## Connecting a Site

A site must be connected to a NexoFlow project before it can receive publish jobs.

In NexoFlow:

1. Open the project you want to connect.
2. Add or configure the WordPress site.
3. Generate or copy the site's connect key.

In WordPress:

1. Open **NexoFlow** from the admin sidebar.
2. Paste the site connect key.
3. Click **Save**.
4. Confirm that the connection test succeeds.

Once connected, the site can receive publish jobs from the associated NexoFlow project.

## Publishing

NexoFlow publish jobs can contain the information required to create or update an article, including:

* Title
* Content
* Categories
* Tags
* Featured image
* SEO title
* Meta description
* Focus keyword
* Publishing status
* Scheduled publishing information

The plugin processes the job and applies the requested changes using the standard WordPress APIs.

After a job has been successfully processed, the plugin sends an acknowledgement to NexoFlow so the job can be marked accordingly.

## Syncing

There are two ways the plugin can check for new jobs.

### Automatic Sync

WordPress periodically checks NexoFlow for pending jobs.

This means a connected site does not require a visitor to manually open the website before content can be published.

### Triggered Sync

NexoFlow can securely request a sync when an action needs to happen immediately.

This is used for workflows such as:

* Publish now
* Autopilot publishing
* Scheduled publishing
* Manual NexoFlow publishing

The triggered endpoint does not contain article content. It only tells the WordPress plugin to check NexoFlow for available jobs.

## Manual Sync

Administrators can manually trigger synchronization from the NexoFlow settings page in WordPress.

This is useful when:

* Testing a new connection
* Troubleshooting a publishing issue
* Checking for pending jobs
* Confirming that the site can communicate with NexoFlow

Use **Test connection** to verify the connection or **Sync now** to immediately check for pending publish jobs.

## Security

The plugin is designed to keep the WordPress site and NexoFlow communication separated from public website traffic.

### Site Connect Key

The site connect key is required before the plugin can communicate with the associated NexoFlow project.

It should be treated as a credential and should not be committed to source control or shared publicly.

### HTTPS

Communication between the WordPress plugin and NexoFlow takes place over HTTPS.

### Sync URL

The optional sync URL is only a trigger.

It does not:

* Receive article content
* Accept arbitrary WordPress post data
* Expose WordPress publishing functionality publicly

When triggered, the plugin performs its normal authenticated pull from NexoFlow.

### Visitor Privacy

The plugin does not track website visitors.

It does not add analytics scripts, advertising scripts, or visitor tracking functionality to the WordPress frontend.

## Data Flow

The plugin communicates with the NexoFlow service only for publishing-related operations.

```text
WordPress Admin
      │
      │ Site Connect Key
      ▼
   NexoFlow
      │
      │ Pending Publish Jobs
      ▼
WordPress Plugin
      │
      ├── Posts
      ├── Categories
      ├── Tags
      ├── Media
      └── SEO Metadata
      │
      ▼
WordPress Database
```

The plugin does not send normal website visitor activity to NexoFlow.

## Requirements

* WordPress 6.x or newer
* PHP 7.4 or newer
* A NexoFlow project
* A valid NexoFlow site connect key
* HTTPS-enabled WordPress site recommended

## Troubleshooting

### Connection test fails

Check that:

* The site connect key is correct.
* The WordPress server can make outbound HTTPS requests.
* The site has access to `https://nexoflow.net`.
* The NexoFlow project still has the WordPress site connected.

### Content is not publishing

First try **NexoFlow → Sync now**.

Then check:

* Whether the NexoFlow project has a pending publish job.
* Whether the site connect key is still valid.
* Whether WordPress can communicate with NexoFlow.
* Whether the WordPress user/server has permission to create posts and upload media.
* Whether the scheduled publishing time has been reached.

### Scheduled content is delayed

WordPress scheduled tasks can depend on the site's cron configuration and traffic.

NexoFlow can also trigger a sync directly for scheduled publishing, reducing the dependency on normal website visits.

## Development

Clone the repository:

```bash
git clone https://github.com/YOUR-ORG/YOUR-REPO.git
cd YOUR-REPO
```

Install the plugin in a local WordPress installation:

```bash
cp -R . /path/to/wordpress/wp-content/plugins/nexoflow
```

Activate it from the WordPress admin dashboard.

### Recommended Development Environment

A local WordPress environment such as:

* Local
* Docker
* wp-env
* DevKinsta
* XAMPP

can be used for development and testing.

## Project Structure

The exact structure may change as the plugin evolves, but the main responsibilities are separated around:

```text
nexoflow/
├── admin/
│   └── WordPress admin settings and UI
├── includes/
│   └── Plugin functionality and NexoFlow integration
├── assets/
│   └── Admin assets
├── nexoflow.php
└── readme.txt
```

## Contributing

Contributions, bug reports, and improvements are welcome.

Before submitting a pull request:

1. Test the plugin on a clean WordPress installation.
2. Verify that connecting and disconnecting a site works correctly.
3. Test publishing and updating posts.
4. Test media and taxonomy handling.
5. Test failed and retryable requests.
6. Make sure credentials and site connect keys are never logged or committed.

## Reporting Issues

If you find a bug or security issue, please provide enough information to reproduce the problem without exposing:

* Site connect keys
* API credentials
* Passwords
* Private site information
* User data

For security-sensitive issues, contact the NexoFlow team privately rather than publishing credentials or exploitable details in a public issue.

## Links

* [NexoFlow](https://nexoflow.net)
* [NexoFlow Terms](https://nexoflow.net/terms)
* [NexoFlow Privacy Policy](https://nexoflow.net/privacy)

## License

See the `LICENSE` file in this repository for licensing information.
