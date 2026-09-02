=== Elementor MCP ===
Contributors: usermp
Tags: elementor, rest-api, automation, opencode, page-builder, webhook, ai
Requires at least: 6.5
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.5.0
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

