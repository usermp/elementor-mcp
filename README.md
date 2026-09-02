# Elementor MCP

> **Machine Content Producer** — bridge between AI assistants and WordPress/Elementor for automated page creation.

[![Version](https://img.shields.io/badge/version-1.5.0-blue.svg)](https://github.com/usermp/elementor-mcp/releases)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![Elementor](https://img.shields.io/badge/Elementor-3.x-pink.svg)](https://elementor.com)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A REST API + webhook plugin that lets external AI services (OpenCode, custom LLM clients, automation scripts) **create, read, update, and delete Elementor pages and templates programmatically**.

## ✨ Features

- 📄 **Pages CRUD** — full lifecycle management of WordPress pages with Elementor data
- 🎨 **Templates CRUD + Kit management** — work with `elementor_library` posts and the active site Kit
- 🚀 **Bulk creation** — batch endpoint (up to 50 pages per request) with per-item error reporting
- 🔗 **Webhook receiver** — HMAC-SHA256 signed payloads, dispatches `page.create` / `page.update` / `page.delete` / `template.create` / `ping`
- 🤖 **AI chat panel** — in-admin conversation UI that talks to OpenRouter (or any OpenAI-compatible endpoint) and generates Elementor JSON on demand
- 🧠 **AI client** — `MCP_OpenCode_Client` with a hardened system prompt, fenced-JSON extraction, fallback parsing, and temperature/token clamping
- 📥 **JSON importer** — accepts bare sections arrays, Elementor export envelopes, or single-element objects; drops blocked types, regenerates IDs, sanitizes settings, enforces depth/element caps
- 🔍 **Diff engine** — preview added/removed/modified/moved operations before applying AI output to an existing page
- 🔐 **WordPress Application Password auth** — standard WP capability checks (`edit_pages`, `manage_options`)
- ⚡ **Action Scheduler integration** — background jobs with automatic WP-Cron fallback
- 🛡️ **Rate limiting** — per-user and per-IP buckets via transients
- 🧩 **Custom Elementor widget** — `MCP Info Card` with editable controls
- 🪝 **Editor hooks** — listen to `elementor/save_post`, `elementor/editor/after_save`
- 🔁 **Idempotency** — `X-Idempotency-Key` header or `idempotency_key` body field
- 📊 **In-option logger** — last 200 events, viewable in admin
- 🧪 **Self-contained test runner** — 99 tests, no PHPUnit required
- 🗑️ **Clean uninstall** — strips every `mcp_*` option and meta on plugin deletion

## 📦 Requirements

- WordPress **6.5** or higher
- PHP **7.4** or higher
- Elementor **3.x** (Pro recommended for dynamic tags; the plugin itself works with Free)

## 🚀 Installation

```bash
# 1. Clone into your plugins directory
cd wp-content/plugins/
git clone https://github.com/usermp/elementor-mcp.git

# 2. Activate from WP Admin → Plugins
# 3. Open MCP → Settings to copy your webhook secret
# 4. Users → Profile → Application Passwords → create one for API auth
```

## 🔌 REST API

All endpoints live under the `/wp-json/mcp/v1/` namespace. Authentication is **HTTP Basic** with your WordPress username + an Application Password.

| Method | Endpoint                     | Description                          |
| ------ | ---------------------------- | ------------------------------------ |
| `POST`   | `/pages`                     | Create a page (title + Elementor data) |
| `GET`    | `/pages`                     | List pages (paginated) |
| `GET`    | `/pages/{id}`                | Get a single page with `_elementor_data` |
| `PUT`    | `/pages/{id}`                | Update title/content/Elementor JSON |
| `DELETE` | `/pages/{id}`                | Delete a page |
| `POST`   | `/pages/batch`               | Create up to 50 pages in one request |
| `POST`   | `/templates`                 | Create an Elementor template |
| `GET`    | `/templates`                 | List templates |
| `GET`    | `/templates/{id}`            | Get a template |
| `PUT`    | `/templates/{id}`            | Update a template |
| `DELETE` | `/templates/{id}`            | Delete a template |
| `GET`    | `/kit`                       | Get the active Elementor Kit |
| `PUT`    | `/kit`                       | Set the active Kit by post ID |
| `POST`   | `/webhook`                   | Receive HMAC-signed events |
| `POST`   | `/chat`                      | Generate Elementor JSON from a prompt (used by the in-admin chat panel) |
| `POST`   | `/chat/apply`                | Apply an AI-generated (or imported) Elementor payload to a page |

### Example: create a page with Elementor data

```bash
curl -u "admin:xxxx xxxx xxxx xxxx" \
     -H "Content-Type: application/json" \
     -d '{
           "title": "Landing Page",
           "status": "draft",
           "elementor": {
             "data": [
               {"id":"a1","elType":"section","settings":{},"elements":[
                 {"id":"b1","elType":"column","settings":{},"elements":[
                   {"id":"c1","elType":"widget","widgetType":"heading","settings":{"title":"Hello!"}}
                 ]}
               ]}
             ]
           }
         }' \
     https://example.com/wp-json/mcp/v1/pages
```

## 🪝 Webhook Authentication

Compute `HMAC-SHA256(raw_body, webhook_secret)` and send it in the `X-MCP-Signature` header. The raw body must be JSON with at least an `event` field.

```bash
BODY='{"event":"page.create","title":"Hi"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -X POST https://example.com/wp-json/mcp/v1/webhook \
     -H "Content-Type: application/json" \
     -H "X-MCP-Signature: $SIG" \
     -d "$BODY"
```

## 🧰 Architecture

```
elementor-mcp.php              ← Plugin bootstrap, autoloader, lifecycle hooks
includes/
├── class-plugin.php           ← Singleton orchestrator
├── class-autoloader.php       ← PSR-4 style autoloader with fail-soft pending classes
├── class-activator.php        ← Default options on activation
├── class-deactivator.php
├── admin/
│   ├── class-settings.php     ← MCP → Settings admin page
│   └── class-chat-page.php    ← MCP → Chat admin page (AI chat UI)
├── api/
│   ├── class-rest-controller.php  ← /wp-json/mcp/v1/* routes
│   ├── class-webhook-handler.php  ← HMAC + dispatch
│   ├── class-auth.php             ← Signature & nonce helpers, idempotency
│   ├── class-rate-limiter.php     ← Per-user / per-IP buckets
│   └── class-chat-rest.php        ← /chat and /chat/apply handlers
├── services/
│   ├── class-page-builder.php     ← wp_insert_post + Elementor meta writer
│   ├── class-template-manager.php ← Templates + Kit
│   ├── class-importer.php         ← Elementor JSON importer (envelope/bare/single)
│   ├── class-diff-engine.php     ← Elementor tree diff (add/remove/modify/move)
│   └── class-opencode-client.php  ← AI client (OpenAI-compatible chat completions)
├── elementor/
│   ├── class-widget-base.php      ← Abstract widget base
│   ├── class-control-register.php ← Widget & control registration
│   ├── class-editor-hooks.php     ← elementor/save_post etc.
│   └── widgets/class-widget-info-card.php
├── jobs/class-queue.php           ← Action Scheduler + WP-Cron fallback
└── utils/
    ├── class-validator.php
    └── class-logger.php           ← In-option rolling log (200 entries)
```

## 🛣️ Roadmap

- [x] REST API for pages/templates/Kit
- [x] Webhook with HMAC verification
- [x] Action Scheduler + WP-Cron fallback
- [x] Rate limiting
- [x] Custom Elementor widget
- [x] AI client (`MCP_OpenCode_Client`) with system prompt + clamps
- [x] Elementor JSON importer with sanitization + blocked-widget list
- [x] Diff engine for previewing AI changes
- [x] In-admin chat panel + REST endpoints for AI generation and apply
- [x] Test runner with 99 tests across OpenCode/Importer/Diff/Chat flow
- [ ] `MCP_History_Page` — chat session history viewer
- [ ] `MCP_GDPR` — personal data exporter/eraser hooks
- [ ] PHPUnit test coverage that runs inside a real WP install

## 📝 Changelog

See [`readme.txt`](./readme.txt) for the WordPress.org changelog.

## 📄 License

GPL-2.0-or-later © Mohammad Yeganeh