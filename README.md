# Full Elementor MCP

> A WordPress plugin that exposes the full surface of Elementor and Elementor Pro to AI agents over the **Model Context Protocol (MCP)** — 131+ tools across pages, containers, widgets, templates, popups, theme parts, custom code, stock images and SVG icons.

[![Plugin Version](https://img.shields.io/badge/version-1.7.1-blue.svg)](full-elementor-mcp.php)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.0-777BB4.svg)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/wordpress-%3E%3D6.9-21759B.svg)](https://wordpress.org/)
[![Elementor](https://img.shields.io/badge/elementor-3.20%2B%20%7C%204.0%20atomic-D8358F.svg)](https://elementor.com/)
[![License](https://img.shields.io/badge/license-GPL--3.0%2B-green.svg)](LICENSE)

`Full Elementor MCP` is a self-contained MCP server that ships as a normal WordPress plugin. It builds on top of the WordPress Abilities API and the WordPress MCP Adapter to give an AI agent precise, schema-validated control over a real Elementor site — both the legacy Elementor 3.x model (sections / columns / widgets) and the new Elementor 4.0 atomic model (`e-flexbox`, `e-div-block`, `e-heading`, `e-button`, …).

## Why use this

Generic content tools talk to WordPress core. They can't:

- Build pages out of Elementor containers, sections and columns.
- Read or write the `_elementor_data` element tree without corrupting it.
- Generate atomic Elementor 4.0 elements with the required `$$type`-wrapped props, `styles` map and local class IDs.
- Maintain unique 7-char element IDs across edits without collisions.
- Apply page-level / element-level custom CSS, popup triggers, theme-builder conditions, dynamic tags or a Pro template-library import.

`Full Elementor MCP` does all of that and exposes it as MCP tools any agent can call (Claude Code, Claude Desktop, Cursor, Antigravity, custom MCP clients, etc.).

## Highlights

- **131+ MCP tools** organised into Query, Page, Layout, Widget, Template, Global, Composite, Stock Image, SVG, Custom Code, Atomic Layout and Atomic Widget groups.
- **Elementor 4.0 atomic support** out of the box — typed prop wrapping, `styles` map generation, class-ID issuance.
- **Elementor 3.x legacy support** — sections, columns, containers, full-fat widgets with auto-generated JSON schemas from the live control registry.
- **Two transports** — HTTP (REST) for any client, plus an optional **stdio proxy** (`bin/full-elementor-mcp-proxy.mjs`) for desktop clients that only speak stdio (Claude Desktop, etc.).
- **Schema sanitiser** for Gemini / Antigravity compatibility (strips empty enum strings, normalises empty `properties` objects).
- **Permission-aware** — every write tool defers to `current_user_can( 'edit_post', $post_id )`. Application Passwords and capability rules are respected.
- **Per-tool admin toggle** — disable any of the 131 tools individually from the WP Admin UI.
- **Hardened** — `</script>` escaping in injected JS, multi-line SVG event-handler stripping, ID collision reservation across duplicates, and a defensive bool-handling pattern for every tree mutation.

## Tool categories

| Group | Tools | Purpose |
|---|---|---|
| Query | 9 | List widgets, get schemas, walk page structures, read element settings, list pages/templates, get global kit settings |
| Page | 10 | Create, update, delete, duplicate, set featured image, set slug, set page meta, import/export, set page settings |
| Layout | 15 | Add/move/remove/duplicate/wrap/unwrap/replace containers and elements, reorder, find, manage `_css_classes` |
| Widget | 50+ | Universal `add-widget` / `update-widget` plus convenience wrappers for heading, button, image, video, icon, divider, spacer, icon-box, image-box, testimonial, counter, progress, alert, social-icons, tabs, accordion, toggle, gallery, etc. |
| Template | 10 | Apply, list, delete library templates; import; set theme-builder conditions; popup settings; dynamic tags |
| Global | 2 | List kits, set active kit |
| Composite | 1 | `build-page` — declarative full-page generator from a single nested structure |
| Stock images | 3 | Search Openverse, sideload, add as image widget with attribution |
| SVG icons | 2 | Upload from URL or string, with full SVG sanitisation |
| Custom code | 6 | Page/element CSS, JS HTML-widget injection, Pro code-snippet CRUD |
| Atomic widgets (E4.0) | 10 | `e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-svg`, `e-youtube`, `e-video`, `e-divider` + universal add/update |
| Atomic layout (E4.0) | 3 | `e-flexbox`, `e-div-block`, `detect-elementor-version` |

The full list is browseable from **WP Admin → Settings → Full Elementor MCP → Tools** with descriptions and per-tool on/off toggles.

## Requirements

- WordPress **6.9+** (Abilities API)
- PHP **8.0+**
- Elementor **3.20+** (or **4.0+** for atomic tools — the plugin auto-detects and registers the atomic group only when supported)
- The **WordPress MCP Adapter** plugin (declared as a hard dependency; an admin notice flags any missing dependency)
- Elementor Pro is optional. Pro-only tools (popups, theme builder, custom-code snippets, dynamic tags) self-skip when Pro isn't active.

## Installation

### Manual

1. Download or clone this repo.
2. Place the `full-elementor-mcp/` folder into `wp-content/plugins/`.
3. Activate **Full Elementor MCP** in WP Admin → Plugins.
4. Confirm **Elementor**, the **WordPress MCP Adapter** and (optionally) **Elementor Pro** are active.

### Build / dependencies

The plugin has no Composer dependencies and no build step. The repo is the deliverable.

## MCP endpoint

Once active, the MCP server is exposed at:

```
https://your-site.example/wp-json/mcp/full-elementor-mcp-server
```

If pretty permalinks are off, use:

```
https://your-site.example/?rest_route=/mcp/full-elementor-mcp-server
```

Authentication uses standard WordPress **Application Passwords** (WP Admin → Users → Profile → Application Passwords). The user must have `edit_posts` for read tools and `edit_post` on the target post for write tools; `unfiltered_html` is required for custom-CSS / custom-JS injection.

## Connecting an MCP client

### Claude Code / Cursor / generic HTTP MCP clients

Add an HTTP server entry pointing at the endpoint above with Basic auth (`username:application-password`).

### Claude Desktop, Antigravity, or any stdio-only client

Use the bundled stdio→HTTP proxy:

```jsonc
{
  "mcpServers": {
    "full-elementor-mcp": {
      "command": "node",
      "args": [
        "C:\\path\\to\\full-elementor-mcp\\bin\\full-elementor-mcp-proxy.mjs"
      ],
      "env": {
        "WP_URL": "https://your-site.example",
        "WP_USERNAME": "your-wp-username",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "MCP_LOG_FILE": "C:\\path\\to\\full-elementor-mcp.log"
      }
    }
  }
}
```

The exact paths, the endpoint URL, and a copy-paste config are also auto-generated under **WP Admin → Settings → Full Elementor MCP → Connection**.

### VS Code (GitHub Copilot Chat / MCP)

The repo ships a ready-to-use [.vscode/mcp.json](.vscode/mcp.json) that registers the bundled stdio proxy as an MCP server scoped to this workspace. Open the `full-elementor-mcp` folder in VS Code, then run **MCP: List Servers → full-elementor-mcp → Start**. VS Code will prompt once for your `WP_URL`, `WP_USERNAME` and `WP_APP_PASSWORD` (the password input is masked) and reuse them for the session.

To use the same proxy from another VS Code workspace, drop this into that workspace's own `.vscode/mcp.json`:

```jsonc
{
  "inputs": [
    { "id": "wp_url",          "type": "promptString", "description": "WordPress site URL" },
    { "id": "wp_username",     "type": "promptString", "description": "WordPress username" },
    { "id": "wp_app_password", "type": "promptString", "description": "Application Password", "password": true }
  ],
  "servers": {
    "full-elementor-mcp": {
      "type": "stdio",
      "command": "node",
      "args": ["C:\\path\\to\\full-elementor-mcp\\bin\\full-elementor-mcp-proxy.mjs"],
      "env": {
        "WP_URL": "${input:wp_url}",
        "WP_USERNAME": "${input:wp_username}",
        "WP_APP_PASSWORD": "${input:wp_app_password}"
      }
    }
  }
}
```

## Quick example

Once the MCP client is connected, an agent can drive Elementor like this:

```jsonc
// 1. Decide between legacy and atomic mode.
{ "tool": "full-elementor-mcp/detect-elementor-version" }

// 2. Build a flexbox container at the top of post 42.
{
  "tool": "full-elementor-mcp/add-flexbox",
  "input": {
    "post_id": 42,
    "parent_id": "",
    "tag": "section",
    "padding": 80,
    "background_color": "#0a0a0a"
  }
}

// 3. Drop an atomic heading inside it.
{
  "tool": "full-elementor-mcp/add-atomic-heading",
  "input": {
    "post_id": 42,
    "parent_id": "<id from step 2>",
    "title": "Hello, atomic Elementor",
    "tag": "h1",
    "color": "#ffffff"
  }
}
```

For a full declarative one-shot page generator, see `full-elementor-mcp/build-page`.

## Sample prompts

The `prompts/` folder ships ready-to-paste agent briefs for common verticals:

- `CAR_WASH.md`
- `DENTAL_CLINIC.md`
- `HAIR_SALON.md`
- `LOCAL_BUSINESS.md`
- `WEB_DEVELOPER_PORTFOLIO.md`

Drop one into your client of choice and let the agent build the page through the MCP tools.

## Security

- Every write tool checks `current_user_can` and `edit_post` against the target.
- Custom JS injection strips `<script>` tags from input and defensively escapes any literal `</script>` sequence in the body before wrapping.
- SVG sanitisation strips PHP tags, refuses any `<script>` element, removes `on*=` event handlers (multi-line aware) and `javascript:` URLs, then defers to Elementor's own SVG sanitiser when available.
- Custom CSS / JS / code-snippet tools additionally require the `unfiltered_html` capability.
- Element-tree mutations always re-read the page, mutate by reference, and check the boolean return of `insert_element` / `update_element_settings` / `remove_element` / `replace_element` before saving — no stale-write or bool-as-array failures.
- ID collisions are prevented per-request via a static issued-set in `Full_Elementor_MCP_Id_Generator` plus an explicit `reserve()` step before any tree-wide ID reassignment (`apply-template`, `import-template`, `replace-element`, `duplicate-element`, `duplicate-page`).

## Architecture

```
full-elementor-mcp.php             Bootstrap, dependency check, MCP-Adapter wiring
includes/
  class-plugin.php                 Singleton orchestrator
  class-elementor-data.php         Tree read/write, save with CSS-cache busting
  class-element-factory.php        Builds valid Elementor element JSON
  class-id-generator.php           7-hex IDs with collision reservation
  class-atomic-props.php           $$type wrap / unwrap helpers
  class-atomic-styles.php          Local class + style-map generation
  class-openverse-client.php       Stock-image source
  abilities/
    class-ability-registrar.php    Boots all ability groups
    class-query-abilities.php
    class-page-abilities.php
    class-layout-abilities.php
    class-widget-abilities.php
    class-template-abilities.php
    class-global-abilities.php
    class-composite-abilities.php
    class-stock-image-abilities.php
    class-svg-icon-abilities.php
    class-custom-code-abilities.php
    class-atomic-layout-abilities.php
    class-atomic-widget-abilities.php
  schemas/
    class-control-mapper.php       Elementor control → JSON Schema
    class-schema-generator.php     Per-widget schema synth
  validators/
    class-element-validator.php
    class-settings-validator.php   Runtime common-keys lookup with cache
  admin/                           Settings UI (Tools / Connection / Prompts / Changelog)
bin/
  full-elementor-mcp-proxy.mjs     Stdio → HTTP MCP proxy
prompts/                           Ready-to-use agent briefs
```

## Compatibility

- Verified against PHP 8.0–8.4 (full repo passes `php -l` on 8.4).
- Atomic-tool group only registers when `ELEMENTOR_VERSION >= 4.0.0`.
- Pro-only tools self-skip when Elementor Pro isn't loaded.
- Schemas are sanitised for cross-client compatibility (Gemini/Antigravity reject empty enum strings).

## Development

This is a plain WordPress plugin — no build step. To work on it:

1. Symlink or copy `full-elementor-mcp/` into a local WordPress dev install's `wp-content/plugins/`.
2. Activate, then call any tool over the REST endpoint or via the stdio proxy.
3. Lint: `php -l <file>` (PHP 8.0+ required).

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).

## Credits

- Built on top of the [WordPress Abilities API](https://github.com/WordPress/abilities-api) and the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter).
- Stock-image search powered by the [Openverse API](https://api.openverse.org).
- SVG sanitisation defers to Elementor's own `\Elementor\Utils::get_svg_sanitizer()` when available.
