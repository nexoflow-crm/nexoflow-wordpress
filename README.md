# NexoFlow for WordPress

Connect your WordPress site to [NexoFlow](https://nexoflow.net) and automatically publish content generated and scheduled by your NexoFlow project.

NexoFlow for WordPress acts as the bridge between your WordPress site and NexoFlow. Once connected, NexoFlow can create and update posts, assign categories and tags, publish featured images, and apply SEO metadata to your articles.

The plugin is also the execution layer for the **NexoFlow Website Agent**: NexoFlow can ask the site what it is able to do and carry out a small, fixed set of page operations on it. New pages are drafts. A published page, the homepage, the header or the footer is changed only when the request says so and its previous version can be kept. See [Website Agent](#website-agent).

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
* Website Agent: capability discovery, draft pages, and reversible changes to published pages (see below)
* Search listings, link previews, structured data, a clean sitemap and redirects, with no SEO plugin needed
* Move pages to the Trash and bring them back, write image descriptions, and a page cache on request

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

Clone the repository and copy its `nexoflow` folder into your WordPress plugins directory:

```bash
git clone https://github.com/nexoflow-crm/nexoflow-wordpress.git
cp -R nexoflow-wordpress/nexoflow /path/to/wordpress/wp-content/plugins/nexoflow
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

## Website Agent

The Website Agent lets a NexoFlow user say what they want done to their website and have NexoFlow do it on the connected WordPress site.

NexoFlow is the intelligence layer. This plugin is the secure execution layer. The plugin never reasons, never calls an AI provider and never handles billing. Its whole job is:

**authenticate, discover capabilities, authorize, validate, execute, return a result.**

```text
                 NEXOFLOW
                     │
              AI Website Agent
        (reasoning and planning)    
                     │
               Action request
                     ▼
          NEXOFLOW WORDPRESS PLUGIN
                     │
   Authentication ─ Validator ─ Dispatcher ─ Request record
                     │
      ┌──────────────┼──────────────┐
      │              │              │
  WordPress       Gutenberg      Elementor
   provider        provider       provider
      │              │              │
  WordPress       Block APIs    Elementor abilities
    APIs                        (WordPress Abilities API)
      └──────────────┼──────────────┘
                     │
                  WEBSITE
```

### What the Website Agent can do

| Area | Status |
| --- | --- |
| Authentication with the site connect key | **Implemented** |
| Site identity (`site.id`) and wrong-site refusal | **Implemented** |
| Capability discovery: `GET /site/capabilities` | **Implemented** |
| Builder detection: block editor, Elementor, Elementor Pro, Elementor MCP abilities, Atomic Editor | **Implemented** |
| Action allowlist, envelope and parameter validation, structured errors | **Implemented** |
| Acting WordPress account and permission checks against the actual page | **Implemented** |
| Request record: idempotent replay and audit trail | **Implemented** |
| Revision check and editor-lock check on changes | **Implemented** |
| `site.read`, `pages.list`, `pages.get`, `pages.create`, `pages.update` | **Implemented** |
| Changing published pages in `live` mode, with a restore point, and `pages.restore` to undo | **Implemented** |
| `blocks.read` (block tree of a page) | **Implemented** |
| `blocks.insert` (add sections to a page without touching the others) | **Implemented** for top-level blocks |
| `blocks.replace` (change sections of a page in place) | **Implemented** for top-level blocks |
| Homepages drawn from a block theme template: `templates.read`, `templates.insert`, `templates.replace`, `templates.restore` | **Implemented** for top-level blocks |
| Header, footer and other template parts of a block theme, through the same `templates.*` actions with `kind: part` | **Implemented** for top-level blocks |
| `media.upload` (add an image to the media library) | **Implemented** for JPEG, PNG, WebP and GIF |
| `elementor.inspect` (element tree through Elementor's own ability) | **Implemented**, run against Elementor 4.3.4 and 4.4.2 |
| `elementor.guide`, `elementor.create_page`, `elementor.build`, `elementor.publish` (build pages through Elementor's own abilities) | **Implemented**, tested with Elementor 4.3.4 on a classic and a block theme, and with 4.4.2 on WordPress 7.1.5 and 7.2. Elementor 4.4 renamed `get-widget-schema` to `get-widget-schemas`; the plugin uses whichever the site has |
| `site.set_front_page` (choose the homepage, and put the earlier one back) | **Implemented** |
| Diagnostics card in the WordPress admin | **Implemented** |
| Activity log with plain-word reasons and a report to copy for support (never the site connect key) | **Implemented** |
| Elementor setup step with a button to Elementor's own MCP page, found in the admin menu | **Implemented** |

### Authentication

Website Agent requests use the same site connect key as publishing, sent as `Authorization: Bearer nfwp_…`. There is no second credential and no WordPress password involved.

A request is refused when the site has no connect key, the header is missing, the key is not this site's key, or the Website Agent is switched off on this site.

The plugin gives each site an identifier, `site.id`, returned by capability discovery. A request may carry it as `site_id`; if it does not match, the request is refused with `SITE_MISMATCH`. The identifier is tied to the install and its address, so a copy of the site on a staging domain has a different one and will not accept requests meant for the original.

### Acting account

WordPress permissions belong to WordPress users, so the agent works as one. The administrator who saves the site connect key becomes the acting account. Sites with no recorded account use their first administrator. An administrator can change this on the NexoFlow settings page with **Work as my account**.

Every action runs as that account and is checked with `current_user_can()` against the actual page. If the account is deleted or loses a permission, the matching capabilities stop being offered.

### Capability discovery

```
GET /wp-json/nexoflow/v1/site/capabilities
```

Returns what this specific site can do right now:

```json
{
  "success": true,
  "api_version": "1",
  "plugin": { "version": "2.0.1" },
  "site": { "id": "nfsite_3f9c…", "connected": true, "url": "https://example.com/", "language": "en-US", "front_page": { "show": "page", "page_id": 2, "template": null } },
  "wordpress": { "version": "6.9", "php_version": "8.3.6" },
  "theme": { "name": "Hello Elementor", "block_theme": false, "template_parts": [] },
  "post_types": [ { "name": "page", "label": "Pages", "hierarchical": true } ],
  "integrations": { "seo": ["yoast"], "woocommerce": { "active": false, "version": null } },
  "agent": { "enabled": true, "default_mode": "draft", "modes": ["draft", "live"], "live_edits": { "available": true, "reason": "", "message": "" }, "request_tracking": true, "replay_days": 30 },
  "actor": { "id": 1, "roles": ["administrator"], "permissions": { "edit_pages": true, "publish_pages": true } },
  "builders": {
    "gutenberg": { "installed": true, "active": true, "supported": true, "available": true },
    "elementor": {
      "installed": true, "active": true, "version": "4.4.0", "minimum_version": "4.3.0",
      "supported": true, "available": true,
      "pro": { "installed": true, "active": true, "version": "4.4.0" },
      "atomic_editor": true,
      "abilities_api": true,
      "mcp": { "available": true, "adapter": true, "abilities": ["elementor/get-page-structure", "…"] }
    }
  },
  "capabilities": ["blocks.insert", "blocks.read", "blocks.update", "elementor.inspect", "media.upload", "pages.create", "pages.read", "pages.restore", "pages.update", "site.read"],
  "capability_details": {
    "pages.create": { "provider": "wordpress", "supported": true, "available": true, "permissioned": true, "usable": true, "requires": "edit_pages", "reason": "", "message": "" }
  },
  "actions": [
    { "name": "pages.create", "provider": "wordpress", "capability": "pages.create", "mutates": true, "modes": ["draft"], "available": true, "parameters": { "title": { "type": "string", "required": true, "max_length": 200 } } }
  ]
}
```

A capability is only listed in `capabilities` when it is usable. `capability_details` separates the reasons:

| Field | Meaning |
| --- | --- |
| `supported` | This site is able to do it at all |
| `available` | It can be used right now |
| `permissioned` | The acting account is allowed to |
| `usable` | All of the above |
| `reason`, `message` | Why not, as a stable code and a sentence |

The document contains no keys, salts, credentials, file paths, environment values or list of installed plugins. Only the builders, SEO plugins and WooCommerce are detected.

### Builder detection

A builder is reported with `installed`, `active`, `supported` and `available` as separate facts. Installed never implies available.

**Block editor (Gutenberg).** Supported when the WordPress block APIs exist. Active when pages are edited with the block editor, so a site on the Classic Editor plugin reports `BLOCK_EDITOR_DISABLED`.

**Elementor.** The plugin detects whether Elementor and Elementor Pro are installed, whether they are active, and their versions.

**Elementor MCP.** Elementor's MCP server is built on the WordPress Abilities API: from Elementor 4.3.0, on WordPress 6.9 or newer, Elementor registers `elementor/…` abilities, and only while Elementor MCP is switched on for the site (**Elementor > Elementor MCP**). The plugin reports `mcp.available` when those abilities are actually registered, and lists them. It never assumes them from the Elementor version alone. Reasons reported when they are missing:

| Reason | Meaning |
| --- | --- |
| `ELEMENTOR_NOT_INSTALLED` | Elementor is not on this site |
| `ELEMENTOR_INACTIVE` | Installed, not active |
| `ELEMENTOR_VERSION_UNSUPPORTED` | Older than 4.3.0 |
| `ABILITIES_API_UNAVAILABLE` | WordPress older than 6.9 |
| `ELEMENTOR_MCP_UNAVAILABLE` | Elementor's MCP feature is not loaded |
| `ELEMENTOR_MCP_DISABLED` | Elementor MCP is switched off for this site |
| `ELEMENTOR_ABILITY_MISSING` | This Elementor version does not offer the specific ability |

The plugin calls Elementor's abilities in process, as the acting account. It does not read or write Elementor's stored page data, and it does not need an application password or an MCP connection of its own.

Elementor itself currently limits its write abilities to pages built with the Atomic Editor (`atomic_editor` in the document).

### Action execution

```
POST /wp-json/nexoflow/v1/actions/execute
```

```json
{
  "request_id": "nf_req_8f2c1e7a",
  "authorization_id": "nf_auth_456",
  "site_id": "nfsite_3f9c…",
  "action": "pages.create",
  "parameters": { "title": "Garage Door Installation" },
  "mode": "draft"
}
```

| Field | Required | Notes |
| --- | --- | --- |
| `request_id` | Yes | 8 to 128 characters. Identifies the request for replay. |
| `action` | Yes | One of the actions in the allowlist |
| `parameters` | No | A JSON object. Only the parameters the action defines are accepted. |
| `mode` | No | `draft` (default) or `live`. See [Modes](#modes). `publish` is refused in this version. |
| `authorization_id` | No | NexoFlow's reference for this request. Stored in the record and returned. Never interpreted. |
| `site_id` | No | When sent, must match this site |

Any other field is refused with `INVALID_REQUEST`. A field the plugin does not understand is never silently ignored.

The dispatcher then, in order: answers a repeated `request_id` from the record, looks the action up in the allowlist, checks the mode, checks the capability is available, checks there is an acting account with the needed WordPress capability, validates the parameters, switches to the acting account, checks permission on the actual page, records the request, executes, and stores the result.

Success:

```json
{
  "success": true,
  "request_id": "nf_req_8f2c1e7a",
  "authorization_id": "nf_auth_456",
  "action": "pages.create",
  "mode": "draft",
  "replayed": false,
  "result": {
    "page_id": 123,
    "title": "Garage Door Installation",
    "status": "draft",
    "builder": "gutenberg",
    "revision": "9c1f0a7d2b44e810",
    "edit_url": "https://example.com/wp-admin/post.php?post=123&action=edit"
  }
}
```

Error, with the matching HTTP status:

```json
{
  "success": false,
  "request_id": "nf_req_8f2c1e7a",
  "action": "pages.update",
  "error": {
    "code": "CONTENT_CONFLICT",
    "message": "This page changed after it was read. Read it again before changing it.",
    "details": { "page_id": 123, "current_revision": "41be07c3a9d2f615" }
  }
}
```

#### Actions

| Action | Capability | Provider | Changes the site | Parameters |
| --- | --- | --- | --- | --- |
| `site.read` | `site.read` | WordPress | No | None |
| `pages.list` | `pages.read` | WordPress | No | `status`, `search`, `page`, `per_page` |
| `pages.get` | `pages.read` | WordPress | No | `page_id`, `include_content` |
| `pages.create` | `pages.create` | WordPress | Yes, as a draft | `title`, `content`, `excerpt`, `slug`, `parent_id` |
| `pages.update` | `pages.update` | WordPress | Yes. Drafts in `draft` mode, published pages in `live` mode. | `page_id`, `expected_revision`, and at least one of `title`, `content`, `excerpt`, `slug` |
| `pages.restore` | `pages.restore` | WordPress | Yes. Published pages need `live` mode. | `page_id`, `expected_revision`, `revision_id` |
| `blocks.read` | `blocks.read` | Gutenberg | No | `page_id`, `include_markup` |
| `blocks.insert` | `blocks.insert` | Gutenberg | Yes. Published pages need `live` mode. | `page_id`, `expected_revision`, `content`, `position`, `path` |
| `blocks.replace` | `blocks.update` | Gutenberg | Yes. Published pages need `live` mode. | `page_id`, `expected_revision`, `replacements` |
| `templates.read` | `templates.read` | Gutenberg | No | `template`, `kind`, `include_markup` |
| `templates.insert` | `templates.update` | Gutenberg | Yes, always `live` mode | `template`, `kind`, `expected_revision`, `content`, `position`, `path` |
| `templates.replace` | `templates.update` | Gutenberg | Yes, always `live` mode | `template`, `kind`, `expected_revision`, `replacements` |
| `templates.restore` | `templates.update` | Gutenberg | Yes, always `live` mode | `template`, `kind`, `expected_revision`, `revision_id` |
| `media.upload` | `media.upload` | Media | Yes. Adds a file; no page shows it until one uses it. | `filename`, `data`, `alt` |
| `elementor.inspect` | `elementor.inspect` | Elementor | No | `page_id`, `element_id`, `include_content` |
| `elementor.guide` | `elementor.build` | Elementor | No | `types` |
| `elementor.create_page` | `elementor.build` | Elementor | Yes, `draft` only: the page is always a draft | `title`, `layout` |
| `elementor.build` | `elementor.build` | Elementor | Yes, `draft` only: Elementor holds a change to a published page back | `page_id`, `expected_revision`, `structure`, `settings`, `styles`, `replace`, `parent_id` |
| `elementor.publish` | `elementor.publish` | Elementor | Yes, `live` only | `page_id`, `expected_revision` |
| `site.set_front_page` | `site.front_page` | WordPress | Yes, `live` only | `page_id` |

The full parameter rules are in the `actions` list of the capability document.

#### Error codes

| Code | HTTP | Meaning |
| --- | --- | --- |
| `NOT_CONNECTED` | 401 | The site has no connect key |
| `MISSING_CREDENTIALS` | 401 | No Bearer key was sent |
| `INVALID_CREDENTIALS` | 401 | The key is not this site's key |
| `AGENT_DISABLED` | 403 | The Website Agent is switched off on this site |
| `SITE_MISMATCH` | 409 | `site_id` is for a different site |
| `INVALID_REQUEST` | 400 | The request is malformed or has unknown fields |
| `UNKNOWN_ACTION` | 404 | The action is not in the allowlist |
| `MODE_NOT_SUPPORTED` | 422 | The action cannot run in this mode |
| `INVALID_PARAMETERS` | 422 | A parameter is missing, unknown or not valid |
| `CAPABILITY_UNAVAILABLE` | 409 | The site cannot do this right now. `details.reason` says why. |
| `NO_ACTING_USER` | 409 | There is no WordPress account to act as |
| `INSUFFICIENT_PERMISSION` | 403 | The acting account is not allowed to |
| `NOT_FOUND` | 404 | The page does not exist |
| `DRAFT_REQUIRED` | 409 | The page is published and the request did not say `live` |
| `RESTORE_POINT_UNAVAILABLE` | 409 | The site cannot keep the previous version, so the published page was not changed |
| `CONTENT_CONFLICT` | 409 | The page changed since it was read |
| `CONTENT_LOCKED` | 409 | Someone has the page open in the editor |
| `UNSUPPORTED_BUILDER_OPERATION` | 409 | The page belongs to a different builder |
| `REQUEST_ID_REUSED` | 409 | The `request_id` was used for a different request |
| `REQUEST_IN_PROGRESS` | 409 | The same request is still running |
| `REQUEST_INCOMPLETE` | 409 | An earlier attempt did not finish. Check the site, then send a new request. |
| `STORAGE_UNAVAILABLE` | 503 | The request could not be recorded, so nothing was changed |
| `PROVIDER_ERROR` | 502 | The page builder reported an error |
| `EXECUTION_FAILED` | 500 | WordPress could not complete the action |

Error responses never contain stack traces, file paths, queries or credentials.

### Modes

Every request runs in a mode. The rule the whole plugin keeps is: **a page visitors can see is only changed when the request says `live`.**

| Mode | What it may change |
| --- | --- |
| `draft` (default) | Drafts and pending pages only. A published, scheduled or private page is refused with `DRAFT_REQUIRED`. |
| `live` | Any page the acting account may edit, including published ones. Visitors see the change at once. |
| `publish` | Refused. No action in this version can publish a draft. |

`pages.create` only accepts `draft`: a new page always starts as a draft.

#### Changing a published page

In `live` mode, before anything is written, the plugin stores the page as it is now as a WordPress revision and returns its ID as `restore_revision_id`:

```json
{
  "success": true,
  "action": "blocks.insert",
  "mode": "live",
  "result": {
    "page_id": 2,
    "status": "publish",
    "revision": "41be07c3a9d2f615",
    "previous_revision": "9c1f0a7d2b44e810",
    "restore_revision_id": 318,
    "inserted": { "path": "1", "count": 1 }
  }
}
```

`pages.restore` with that `revision_id` puts the page back. If the site cannot keep that version (page revisions are switched off, or too few are kept to hold it next to the page's autosaves), the page is not changed and the answer is `RESTORE_POINT_UNAVAILABLE`. `agent.live_edits` in the capability document says in advance whether live changes are possible.

Also in `live` mode:

* The status never changes. A live edit cannot publish, unpublish or schedule.
* The slug of a published page cannot be changed. WordPress does not redirect the old address of a page, so its links would break.
* The revision check and the editor-lock check apply exactly as for drafts.
* A site can switch live changes off with the `nexoflow_agent_allow_live_edits` filter. Drafts keep working. The switch covers every change visitors would see: published pages and posts (content, titles, search fields, Trash and restore), the homepage, redirects, the site's search settings and the page cache.

#### Adding a section

`blocks.insert` adds one or more blocks at the `start` or `end` of a page, or `before` or `after` a top-level block identified by its `path` from `blocks.read`. The page is parsed and written back with the WordPress block functions, and the blocks already on it come through byte for byte.

* `content` must be block markup. HTML outside a block is refused, so what lands on the page is always a set of editable blocks.
* Only the new blocks are sanitized. Existing blocks, including custom HTML an administrator added, are not rewritten.
* A core block name that does not exist on the site is refused. Blocks from other plugins are accepted, because many only exist in the editor.
* It is offered only on sites where pages are edited with the block editor.

#### Changing a section

`blocks.replace` swaps top-level blocks of a page for new blocks. `replacements` is a list of up to 20 changes, each with the `path` of a section from `blocks.read` and the `content` that takes its place:

```json
{
  "request_id": "nf_01JEXAMPLE0004",
  "action": "blocks.replace",
  "mode": "live",
  "parameters": {
    "page_id": 42,
    "expected_revision": "9f2c41d7a0b3e685",
    "replacements": [
      { "path": "3", "content": "<!-- wp:group -->...<!-- /wp:group -->" }
    ]
  }
}
```

* All changes of one request are saved together, with one restore point. If one of them is refused, none is made.
* The same rules as for a new section apply to `content`: block markup only, sanitized, no core block that does not exist.
* Every block that is not replaced, and the whitespace between blocks, comes through byte for byte.
* One section can be replaced by several blocks. A section cannot be named twice in one request.
* To change a section, read it with `include_markup`, change that markup, and send it back.
* A replacement can take a section away instead: `{ "path": "5", "remove": true }`, with `content` left out. This is how a page is rebuilt with fewer sections than it had: the first sections are swapped for the new ones and the rest are taken away, in one request with one restore point. A request that would leave nothing on the page is refused (`replacements: removes_everything`). The result reports `replaced: { sections, blocks, removed }`.

`templates.replace` does the same for a template or template part.

#### Building with Elementor

Elementor is only ever driven through the abilities it registers with the WordPress Abilities API, the same ones its MCP server offers: Elementor 4.3 or newer, WordPress 6.9 or newer, with Elementor MCP switched on (the settings page has a button that asks Elementor to switch it on, as the signed-in administrator). The plugin never reads or writes Elementor's stored page data.

* `elementor.guide` lists the element types Elementor can build with on the site, the settings of the types asked for, and the images in the media library.
* `elementor.create_page` makes a new page built with Elementor. It is always a draft. `layout` is `theme`, `full_width` or `canvas`; on a block theme only `canvas` and `full_width` show the page instead of the theme's own template, and Elementor then draws the whole page.
* `elementor.build` hands `structure` (Elementor's XML, one tag per element with a `configuration-id`), `settings` and `styles` to Elementor's `build-composition`. On a draft the page is saved as built. On a published page Elementor holds the change back in an autosave: visitors keep seeing the page as it was, so no `live` mode is asked for. `replace` swaps what is there; `parent_id` builds inside one element. What Elementor refuses comes back as `INVALID_PARAMETERS` with what it said.
* `elementor.publish` (`live` only) publishes a draft, or puts the held-back change in front of visitors. For a published page a restore point is always taken first (`restore_revision_id`), and `pages.restore` puts it back.
* `site.set_front_page` (`live` only, needs `manage_options`) makes a published page the homepage and returns what it was before; `page_id: 0` goes back to the site's own homepage.

Three things Elementor 4.3 does that the plugin makes up for, each found on a real site:

* After a held-back change is published, or a page is restored, Elementor keeps serving the rendered copy it made before. The plugin asks Elementor to regenerate (`files_manager->clear_cache()`).
* While a change to a published page is held back, Elementor puts that page's layout back on the theme's default. On a block theme with its own homepage template visitors would then see the theme's page. The plugin puts the layout the page had back at once (WordPress's page template field).
* WordPress may see nothing new to keep when only Elementor's data changed. For pages a builder keeps its own data for, a restore point is always taken.

#### The homepage

`site.read` and the capability document report `site.front_page`:

| Field | Meaning |
| --- | --- |
| `show` | `page` when a page is set as the homepage, otherwise `posts` |
| `page_id` | That page, when there is one |
| `template` | The block theme template that draws the homepage, for example `mytheme//front-page`, or `null` |

When `template` is set, the homepage sections live in that template rather than in a page. This is how sites made with many AI site builders work. Use the `templates.*` actions on it:

* `templates.read` returns its blocks with paths, like `blocks.read` does for a page.
* With `include_markup`, both read actions also return the exact markup of each top-level section, and for a section that is a pattern, the markup of that pattern. NexoFlow uses this to build new sections the way the site's own sections are built.
* `templates.insert` adds a section before or after one of them. `templates.restore` undoes it.
* A template is shown to every visitor, so both only run in `live` mode and always keep a restore point.
* They need the acting account to have `edit_theme_options`.
* A template that was never customized only exists as a file in the theme. The first change stores the theme's own content as a template post and changes that, which is what the WordPress Site Editor does. The theme files are never written.
* Sections that come from a pattern or a template part show up as one block. A new section can go before or after them, but not inside them yet. A template part is changed as its own document, see below.

#### Header and footer

On a block theme the header and footer are template parts that every page shares. The capability document lists them under `theme.template_parts`, each with an `id`, `slug`, `area` (`header`, `footer` or `uncategorized`) and `title`. In `templates.read`, a section that is a template part also carries `template_part`, the ID of the part it shows.

The `templates.*` actions work on a part when `kind` is `part`:

```json
{
  "request_id": "nf_01JEXAMPLE0005",
  "action": "templates.read",
  "parameters": { "template": "mytheme//header", "kind": "part", "include_markup": true }
}
```

* A part follows the same rules as a template: `live` mode only, a restore point every time, `edit_theme_options`, and the theme files are never written.
* The first change stores the part the way the Site Editor does, for the active theme and with its area, so WordPress keeps drawing it as the header or footer.
* Links are added by writing them into the part, as `core/navigation-link` blocks inside its `core/navigation` block. Menus that are stored separately from the part are not changed.
* A template ID is not accepted as a part, or the other way round.

#### Images

`media.upload` adds one image to the media library. NexoFlow sends the image itself, base64 encoded in `data`. The site is never given an address to fetch.

* JPEG, PNG, WebP and GIF, up to 8 MB. What the file is, is read from the file itself. A file that is not one of these is refused whatever it is named, and SVG is not accepted.
* The file is stored under the name sent in `filename`, with the extension of the image it really is.
* `alt` is stored as the image's description.
* The answer has `attachment_id`, `url`, `mime_type`, `width`, `height`, `filesize` and `alt`. Use the `url` in an image or cover block.
* It needs the acting account to have `upload_files`.
* The image is not kept in the request record.

#### Always

* `pages.create` always creates a draft.
* The status, post type, author, dates and meta of a page are never taken from the request.
* New content is always passed through the WordPress post sanitizer, whatever the acting account is allowed to write.

### Idempotency

Every request that changes the site is recorded under its `request_id` before it runs.

* The same `request_id` sent again returns the stored result with `"replayed": true`. The action does not run a second time, even if the site has changed since.
* The same `request_id` with a different action or parameters is refused with `REQUEST_ID_REUSED`.
* A request that was refused, or failed without changing anything, frees its `request_id`.
* A request that broke off part-way keeps its `request_id` claimed and answers `REQUEST_INCOMPLETE` from then on, because the plugin cannot know whether the site changed.

Results can be replayed for 30 days. The record is stored in the plugin's own table, `{prefix}nexoflow_agent_requests`, which is also the audit trail shown in the **Activity** column on the settings page. If the table cannot be created, change actions are not offered at all.

### Concurrency handling

Reading a page returns a `revision`. It changes whenever the page's title, slug, content, excerpt, status, parent or modified time changes, and it works on sites with WordPress revisions switched off.

Every action that changes a page (`pages.update`, `pages.restore`, `blocks.insert`) must send it back as `expected_revision`. If the page has changed in the meantime the answer is `CONTENT_CONFLICT` with the current revision, and nothing is written. NexoFlow can then read the page again and regenerate the change.

A page that is open in the WordPress editor is refused with `CONTENT_LOCKED`.


### Security model

* **Allowlist.** Only the actions above exist. There is no action for PHP, SQL, shell commands, file access, options, plugin or theme installation, user accounts or outgoing HTTP, and no generic passthrough to WordPress or to another plugin.
* **Strict input.** Unknown request fields and unknown parameters are refused. Each parameter has a type, a length or range, and where it applies a fixed list of values.
* **WordPress permissions.** Every action runs as the acting account and is checked against the actual page.
* **Live content.** Nothing visitors can see changes unless the request says `live`, and never without a restore point.
* **Kill switch.** An administrator can turn the Website Agent off on the settings page. Publishing keeps working. The `nexoflow_agent_enabled` filter can force it off.
* **Record.** Every change request, and every well-formed request that was refused, is recorded with its action, outcome and `authorization_id`. Successful reads are not recorded.
* **No caching.** Website Agent answers are sent with `Cache-Control: no-store`.

### AI and billing

NexoFlow does the AI work. The plugin has no AI provider setting, stores no AI provider key and never contacts an AI provider.

The plugin knows nothing about billing. A `credits` field in a request is refused like any other unknown field, so it can never be treated as authorization. NexoFlow may send an `authorization_id`, which the plugin only stores and returns as a reference.

### Adding a builder

A builder is a class that implements `NexoFlow_Agent_Builder` and is added with the `nexoflow_agent_providers` filter. It declares its own capabilities and actions, says whether it owns a given page, and executes its own actions. The dispatcher, validation, permissions, idempotency and the record apply to it unchanged. An added provider cannot replace a built-in action.

## Structured Data and llms.txt

A connected site carries two things NexoFlow keeps current for it. Both come from the business's own records in NexoFlow; the plugin never writes them itself.

| | What the site does | Where it comes from |
|---|---|---|
| Structured data | Prints one `<script type="application/ld+json" class="nexoflow-structured-data">` in the head of each page NexoFlow has markup for | `GET /api/v1/wordpress-plugin/structured-data` |
| llms.txt | Answers `/llms.txt` as `text/plain` | `GET /api/v1/wordpress-plugin/llms-txt` |

How it works (`includes/class-site-data.php`):

- **Fetched about once an hour**, on the plugin's existing one-minute schedule (no schedule of its own), and when **Sync now** is pressed. Each request sends the version the site already holds, and NexoFlow answers `unchanged` without sending the data again.
- **Stored in the site's options** (`nexoflow_structured_data`, `nexoflow_llms_txt`, neither autoloaded). A page view reads the option and never calls NexoFlow.
- **A failed fetch changes nothing.** The site keeps what it has.
- **A page is matched by its address**: the site's address as NexoFlow has it, plus the request path without a trailing slash and without the query. Archive pages, search results and missing pages get no markup.
- **Only this site's pages are kept**, at most 200, and only values that read as schema.org JSON-LD. The markup is JSON-encoded with angle brackets and ampersands written as escapes, so nothing in it can close the script tag.
- **llms.txt is plain text**: control characters and opening angle brackets are removed when it is stored, and it is sent with `X-Content-Type-Options: nosniff`.
- **Your own file wins.** A file named `llms.txt` in the site's root folder is served by the web server before WordPress runs.
- **Disconnecting removes both**, and so does uninstalling.

Filters: `nexoflow_print_structured_data` and `nexoflow_serve_llms_txt` (return `false` to switch one off).

The settings page shows both under **Status**: how many pages carry structured data, and a link to the site's `/llms.txt`.

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

### Website Agent routes

The two Website Agent routes do accept requests that change the site. They require the site connect key and only run the actions in the allowlist. A published page is only changed when the request says so, and its previous version is always kept. See [Security model](#security-model).

### Visitor Privacy

The plugin does not track website visitors.

It does not add analytics scripts, advertising scripts, or visitor tracking functionality to the WordPress frontend.

## Data Flow

The plugin contacts the NexoFlow service only for publishing-related operations. Website Agent requests travel the other way: NexoFlow calls the site.

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
* For Elementor: Elementor 4.3.0 or newer, WordPress 6.9 or newer, and Elementor MCP switched on

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
git clone https://github.com/nexoflow-crm/nexoflow-wordpress.git
cd nexoflow-wordpress
```

Install the plugin in a local WordPress installation:

```bash
cp -R nexoflow /path/to/wordpress/wp-content/plugins/nexoflow
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

```text
nexoflow/
├── includes/
│   ├── class-plugin.php        Bootstrap
│   ├── class-client.php        Site connect key and calls to NexoFlow
│   ├── class-auth.php          Checks the key on incoming requests
│   ├── class-cron.php          Pulls publish jobs
│   ├── class-site-data.php     Prints structured data and serves llms.txt
│   ├── class-seo.php           Search titles, descriptions, canonicals, archives, redirects, Trash
│   ├── class-seo-markup.php    Search and link-preview tags in the page head
│   ├── class-page-cache.php    Page cache, only when asked for
│   ├── class-indexnow.php      Change notices to search engines
│   ├── class-bing.php          Bing's verification tag
│   ├── class-publisher.php     Creates and updates posts
│   ├── class-rest.php          Sync route
│   ├── class-settings.php      Settings page
│   └── agent/
│       ├── class-agent.php                 Wires the Website Agent together
│       ├── class-agent-rest.php            Capability and action routes
│       ├── class-agent-dispatcher.php      The pipeline every action goes through
│       ├── class-agent-validator.php       Request and parameter validation
│       ├── class-agent-capabilities.php    Capability registry
│       ├── class-agent-actions.php         Action allowlist
│       ├── class-agent-request-store.php   Idempotency and audit record
│       ├── class-agent-actor.php           Acting WordPress account
│       ├── class-agent-site.php            Site identifier
│       ├── class-agent-documents.php       Pages: lookup, revision, builder, lock, restore points
│       ├── class-agent-templates.php       Block theme templates and template parts
│       ├── class-agent-abilities.php       WordPress Abilities API
│       ├── class-agent-admin.php           Diagnostics card, Elementor step, Activity log
│       ├── class-agent-error.php           Error codes
│       ├── interface-agent-provider.php    What a provider implements
│       ├── interface-agent-builder.php     What a page builder adds
│       └── providers/
│           ├── class-provider-wordpress.php
│           ├── class-provider-gutenberg.php
│           ├── class-provider-media.php
│           ├── class-provider-seo.php
│           └── class-provider-elementor.php
├── assets/
├── nexoflow.php
├── uninstall.php
└── readme.txt
tests/
├── run.php            Runs everything: php tests/run.php
├── agent.php          Website Agent tests
├── live.php           Website Agent check inside a real WordPress
├── agent-world.php    A connected test site
├── stubs.php          WordPress stand-ins
└── stubs-agent.php    WordPress stand-ins for the Website Agent
```

### Tests

```bash
make test
```

The tests run without WordPress. They use stand-ins for the WordPress functions the plugin calls, and an in-memory SQLite database for the request record, so PHP needs the `sqlite3` extension.

`tests/live.php` runs the Website Agent inside a real WordPress, through the real REST server and database. Use it on a disposable site with the plugin active and no connect key. It creates pages, an image, two users and a role, and removes them again:

```bash
NEXOFLOW_LIVE_TEST=1 wp eval-file tests/live.php
```

It also prints what was detected for the block editor and Elementor, which is the quickest way to see how a given Elementor setup is reported.

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
