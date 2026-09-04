=== Elementor MCP ===
Contributors: usermp
Tags: elementor, rest-api, automation, opencode, page-builder, webhook, ai
Requires at least: 6.5
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Machine Content Producer - bridge between OpenCode and WordPress/Elementor for automated page creation.

== Description ==

Elementor MCP (Machine Content Producer) is a WordPress plugin that enables external services such as OpenCode to programmatically create, edit, and manage complete Elementor pages through a REST API and webhooks.

Features:

* REST API under `/wp-json/mcp/v1/` for pages CRUD
* REST API for templates CRUD and active Kit selection
* Batch endpoint for bulk page creation (up to 50 per request)
* Webhook endpoint with HMAC signature verification
* WordPress Application Password authentication
* Action Scheduler integration for background jobs (with WP-Cron fallback)
* Page settings (Elementor Kit) support
* Sample Elementor widget (MCP Info Card) with custom controls
* GDPR compliant (Personal Data Eraser/Exporter hooks)
* Rate limiting per user and per IP
* Extensible via actions and filters

== Installation ==

1. Upload the `elementor-mcp` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **MCP > Settings** to configure
4. Generate an Application Password under **Users > Profile** for the API user

== REST Endpoints ==

* `POST   /wp-json/mcp/v1/pages`        - Create page
* `GET    /wp-json/mcp/v1/pages`        - List pages
* `GET    /wp-json/mcp/v1/pages/{id}`   - Get page
* `PUT    /wp-json/mcp/v1/pages/{id}`   - Update page
* `DELETE /wp-json/mcp/v1/pages/{id}`   - Delete page
* `POST   /wp-json/mcp/v1/pages/batch`  - Batch create (max 50)
* `POST   /wp-json/mcp/v1/templates`    - Create template
* `GET    /wp-json/mcp/v1/templates`    - List templates
* `GET    /wp-json/mcp/v1/templates/{id}`  - Get template
* `PUT    /wp-json/mcp/v1/templates/{id}`  - Update template
* `DELETE /wp-json/mcp/v1/templates/{id}`  - Delete template
* `GET    /wp-json/mcp/v1/kit`          - Get active kit
* `PUT    /wp-json/mcp/v1/kit`          - Set active kit
* `POST   /wp-json/mcp/v1/webhook`      - Receive webhook from OpenCode

== Webhook Authentication ==

Webhook requests must include `X-MCP-Signature` header containing the HMAC-SHA256 of the raw request body using your configured webhook secret (see MCP > Settings).

== Frequently Asked Questions ==

= Does it require Elementor Pro? =

The basic REST API and template management work with Elementor Free. The custom MCP Info Card widget works in both Free and Pro.

= How do I authenticate API requests? =

Use WordPress Application Passwords. Send `Authorization: Basic base64(username:application_password)` header.

= How do I send webhooks? =

Compute `HMAC-SHA256(body, webhook_secret)` and send it in the `X-MCP-Signature` header. Body must be JSON with at least an `event` field.

== Changelog ==

= 1.8.0 =
* Add Theme Builder (MCP_Themer): custom post type for header / footer / single / archive / 404 with conditional rendering, priority-based fallback chain, and full Elementor widget support
* Add Themer blank-template.php: no-chrome fallback when no matching template part
* Add full Performance_Analyzer rewrite: 12 checks (cron overdue, object cache, autoload size, revisions, transients, plugins, themes, PHP version, WP version, memory_limit, uploads writable, cron overdue) with 12h transient cache, option persistence, A/B/C/D/F grading
* Add full Security_Scanner rewrite: 12 checks (WP_DEBUG, file_editor, admin user, DB prefix, WP version, PHP version, salts, readme.html, xmlrpc, wp-config.php perms, DB table prefix, login rate-limiting)
* Add MCP_Audit_Page: side-by-side perf + sec cards with severity badges, copy-able fix snippets, one-click Refresh with nonce verification
* Add WP-CLI commands: `wp mcp audit [--refresh]` and `wp mcp security [--refresh]`
* Add REST endpoints: GET /mcp/v1/audit/performance and GET /mcp/v1/audit/security (manage_options)
* Add Agent Registry tools: performance_audit, security_audit
* Bugfix: autoloader map 'Performance' → 'Performance_Analyzer'
* Bugfix: format_items fallback to line-by-line log on associative tables
* Bugfix: chat.js guard null response.error, use `path:` instead of `url:`
* Bugfix: settings.php bootstrap_submenu_pages() registers audit page
* Bugfix: plugin.php register Themer in admin bootstrap
* Template_Builder: more detailed style guidance in prompts

= 1.7.0 =
* Add Template_Builder, Site_Crawler, Agent_Registry, Api_Key, Idempotency, Media_Uploader, Block_Repair, Output_Repair, Error_Tracker, Audit_Page
* Snapshot + Rollback with elementor data integrity
* Performance + Security analyzers (initial)
* WP-CLI commands: status, build-template, clone, tools, errors, api-key
* 20 new features total in this release

= 1.6.0 =
* Add Site_Cloner: clone any public URL into an Elementor page via REST /wp-json/mcp/v1/clone
* Add Site_Crawler: multi-page discovery (nav + path probing for about/services/contact/blog)
* Add AI_Translator + DOM_Analyzer: per-page structure + palette + typography extraction, AI prompt
* Add Template_Builder: compose a complete Elementor site from a brand brief (no URL needed)
  - 7 design systems: Modern SaaS, Warm Editorial, Bold Studio, Calm Spa, Restaurant Warm, Tourism Vivid, Persian Traditional
  - 7 role-based section builders: header, hero, features, about, testimonials, cta, footer
* Add fenced-JSON fallback parser (bracket-aware, returns first valid array)
* Add AI temperature + max_tokens clamps on OpenCode_Client
* Switch default model to nvidia/nemotron-3-super-120b-a12b:free (Llama 3.3 70B no longer free on OpenRouter)
* Tests: 99 → 153, all passing; no PHPUnit dependency

= 1.5.0 =
* Template Manager with full CRUD and active Kit support
* Webhook handler with HMAC signature verification
* Action Scheduler queue with WP-Cron fallback
* Rate limiter (user and IP based)
* Custom Elementor widget (Info Card)
* Elementor editor hooks
* Batch page creation endpoint
* Idempotency helpers

= 1.0.0 =
* Initial MVP release
* REST API endpoints (POST/GET/PUT/DELETE /pages)
* Application Password authentication
* Settings page
* Validator and logger utilities

== Upgrade Notice ==

= 1.5.0 =
Adds templates, webhooks, queue, and Elementor widget.

