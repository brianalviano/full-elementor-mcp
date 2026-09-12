=== Safe Elementor MCP ===
Contributors: brianalviano
Tags: elementor, mcp, ai, model-context-protocol, page-builder, claude, ai-agent, openai, gemini
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.8.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A production-safe MCP server for AI-powered Elementor development. Deep page-building capabilities with snapshots, undo, scoped access, and safety guardrails.

== Description ==

**Safe Elementor MCP** turns any WordPress + Elementor site into a production-safe Model Context Protocol (MCP) server that AI agents (Claude Code, Claude Desktop, Cursor, Antigravity, custom MCP clients) can drive end-to-end.

It builds on top of the WordPress Abilities API and the WordPress MCP Adapter and adds **131+ Elementor-specific MCP tools** covering the legacy Elementor 3.x model (sections / columns / widgets) **and** the new Elementor 4.0 atomic model (`e-flexbox`, `e-div-block`, `e-heading`, `e-button`, …).

= Why use this =

Generic content tools talk to WordPress core. They cannot:

* Build pages out of Elementor containers, sections and columns.
* Read or write the `_elementor_data` element tree without corrupting it.
* Generate atomic Elementor 4.0 elements with the required `$$type`-wrapped props, `styles` map and local class IDs.
* Maintain unique 7-char element IDs across edits without collisions.
* Apply page-level / element-level custom CSS, popup triggers, theme-builder conditions, dynamic tags or a Pro template-library import.

Full Elementor MCP does all of that and exposes it as MCP tools any agent can call.

= Highlights =

* **131+ MCP tools** across Query, Page, Layout, Widget, Template, Global, Composite, Stock Image, SVG, Custom Code, Atomic Layout and Atomic Widget groups.
* **Elementor 4.0 atomic support** out of the box — typed prop wrapping, `styles` map generation, class-ID issuance.
* **Elementor 3.x legacy support** — sections, columns, containers, full-fat widgets with auto-generated JSON schemas from the live control registry.
* **Two transports** — HTTP (REST) for any client, plus an optional **stdio proxy** (`bin/full-elementor-mcp-proxy.mjs`) for desktop clients that only speak stdio (Claude Desktop, VS Code MCP, etc.).
* **VS Code-ready** — bundled `.vscode/mcp.json` registers the stdio proxy as a workspace MCP server with masked credential prompts.
* **Schema sanitiser** for Gemini / Antigravity compatibility (strips empty enum strings, normalises empty `properties` objects).
* **Permission-aware** — every write tool defers to `current_user_can( 'edit_post', $post_id )`. Application Passwords and capability rules are respected.
* **Per-tool admin toggle** — disable any of the 131 tools individually from the WP Admin UI.
* **Hardened** — `</script>` escaping in injected JS, multi-line SVG event-handler stripping, ID collision reservation across duplicates, and a defensive bool-handling pattern for every tree mutation.

= Tool categories =

* **Query (9)** — list widgets, get schemas, walk page structures, read element settings, list pages/templates, get global kit settings.
* **Page (10)** — create, update, delete, duplicate, set featured image, set slug, set page meta, import/export, set page settings.
* **Layout (15)** — add/move/remove/duplicate/wrap/unwrap/replace containers and elements, reorder, find, manage `_css_classes`.
* **Widget (50+)** — universal `add-widget` / `update-widget` plus convenience wrappers for heading, button, image, video, icon, divider, spacer, icon-box, image-box, testimonial, counter, progress, alert, social-icons, tabs, accordion, toggle, gallery, etc.
* **Template (10)** — apply, list, delete library templates; import; set theme-builder conditions; popup settings; dynamic tags.
* **Global (2)** — list kits, set active kit.
* **Composite (1)** — `build-page` declarative one-shot page generator.
* **Stock images (3)** — search Openverse, sideload, add as image widget with attribution.
* **SVG icons (2)** — upload from URL or string, with full SVG sanitisation.
* **Custom code (6)** — page/element CSS, JS HTML-widget injection, Pro code-snippet CRUD.
* **Atomic widgets / E4.0 (10)** — `e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-svg`, `e-youtube`, `e-video`, `e-divider` plus universal add/update.
* **Atomic layout / E4.0 (3)** — `e-flexbox`, `e-div-block`, `detect-elementor-version`.

The full list is browseable from **WP Admin → Settings → Full Elementor MCP → Tools** with descriptions and per-tool on/off toggles.

= Sample agent prompts =

The plugin ships ready-to-paste agent briefs for common verticals:

* Car wash
* Dental clinic
* Hair salon
* Local business landing
* Web developer portfolio

Drop one into your client of choice and let the agent build the page through the MCP tools.

== Installation ==

1. Upload the `full-elementor-mcp/` folder to `/wp-content/plugins/`, or install via the WP Admin Plugin uploader.
2. Activate **Full Elementor MCP** through the **Plugins** menu.
3. Confirm **Elementor**, the **WordPress MCP Adapter** and (optionally) **Elementor Pro** are active.
4. Visit **Settings → Full Elementor MCP → Connection** for the MCP endpoint URL and a copy-paste config for your MCP client.

== MCP endpoint ==

Once active, the MCP server is exposed at:

`https://your-site.example/wp-json/mcp/full-elementor-mcp-server`

If pretty permalinks are off, use:

`https://your-site.example/?rest_route=/mcp/full-elementor-mcp-server`

Authentication uses standard WordPress Application Passwords. The user must have `edit_posts` for read tools and `edit_post` on the target post for write tools; `unfiltered_html` is required for custom-CSS / custom-JS injection.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open protocol for connecting AI assistants to external tools and data. https://modelcontextprotocol.io

= Do I need Elementor Pro? =

No. Pro-only tools (popups, theme builder, custom-code snippets, dynamic tags) self-skip when Pro is not active. The other 100+ tools work on free Elementor.

= Does this work with Elementor 4.0 atomic elements? =

Yes. The plugin auto-detects Elementor 4.0+ and registers an additional group of atomic-aware tools (`add-flexbox`, `add-div-block`, `add-atomic-heading`, etc.) that emit the required `$$type`-wrapped props and `styles` map. On Elementor 3.x those tools simply don't register; the legacy tools take over.

= Which AI clients are supported? =

Anything that speaks MCP — Claude Code, Claude Desktop, Cursor, Antigravity, custom clients. HTTP-only clients use the REST endpoint directly. Desktop stdio-only clients use the bundled `bin/full-elementor-mcp-proxy.mjs`.

= Is it safe? =

Every write tool checks WordPress capabilities. Custom-CSS / custom-JS / code-snippet tools additionally require `unfiltered_html`. SVG uploads are sanitised (PHP tags stripped, `<script>` rejected, `on*=` event handlers and `javascript:` URLs removed, then run through Elementor's own sanitiser when available). Custom JS injection escapes literal `</script>` sequences before wrapping. Element-tree mutations always re-read, mutate by reference, and check the boolean return before saving.

= Can I disable individual tools? =

Yes. **Settings → Full Elementor MCP → Tools** has a per-tool toggle. Disabled tools are filtered out before the MCP server registers them.

= Does it require a build step? =

No Composer dependencies, no build step. Drop the folder into `wp-content/plugins/` and activate.

== Screenshots ==

1. Tools tab — browse and toggle every MCP tool.
2. Connection tab — copy-paste config for HTTP and stdio clients.
3. Prompts tab — ready-to-use agent briefs.
4. Changelog tab.

== Changelog ==

= 1.8.0 =
* Major release: Enterprise-grade safety engine with WAL mutations, cryptographic checkpoints, lock fencing, audit logging, and Admin recovery console.
* Pre-flight compatibility diagnostics with strict activation/deactivation guards.
* Safe uninstall policy: audit and safety data preserved by default.
* Rebrand to Safe Elementor MCP with backward-compatible machine interfaces.

= 1.7.1 =
* Added bundled `.vscode/mcp.json` that registers the stdio proxy as a workspace MCP server for VS Code (Copilot Chat / MCP), with masked prompts for `WP_URL`, `WP_USERNAME` and `WP_APP_PASSWORD`.
* Updated README with a VS Code connection section.

= 1.7.0 =
* Repackaged and rebranded under new maintainer (Zain Ul Abidin / Progressive Robot).
* Updated plugin metadata: Plugin URI, Author, Author URI, Description, Contributors.
* Refreshed admin header links to point at the new maintainer.

= 1.6.1 =
* Bugfix: stripped accidental double `Full_` prefix introduced by the v1.6.0 rename across every PHP class (`Full_Full_Elementor_MCP_*` -> `Full_Elementor_MCP_*`).
* Bugfix: corrected admin Connection page proxyPath to `bin/full-elementor-mcp-proxy.mjs` (was generating broken MCP client config snippets).
* Updated Openverse HTTP User-Agent to `Full-Elementor-MCP/<version>`.
* Removed dead `delete_option` call in `uninstall.php` for an option that is never written.

= 1.6.0 =
* Added 21 new MCP tools (find-element, batch-update, reorder-elements, wrap/unwrap/replace, set/add/remove-element-class, dynamic tags, popup settings, theme-builder conditions, and more).
* Added Elementor 4.0 atomic group (flexbox, div-block, atomic widgets) with `$$type` wrapping and `styles` map generation.
* Centralised ID generation with per-request collision reservation.
* Hardened custom-JS injection (`</script>` escape pass) and SVG sanitisation (multi-line `on*=` regex).
* Fixed bool-as-array bug in atomic add/update tools, atomic layout add tools, and removed duplicate `list-templates` registration.
* Container shorthand keys (`justify_content`, `align_items`, `align_content`) are now normalised tree-wide on import/template/replace paths.

= 1.5.x =
* Initial Elementor 4.0 atomic-element scaffolding.
* Stock-image and SVG-icon abilities.

= 1.4.x =
* Schema sanitiser for Gemini / Antigravity client compatibility.

= 1.0.0 =
* Initial release with the legacy Elementor 3.x tool set.

== Upgrade Notice ==

= 1.8.0 =
Enterprise safety engine upgrade. Introduces cryptographic checkpoints, lock fencing, WAL mutation logging, and compatibility checks. Full backward compatibility with prior tool abilities.

= 1.7.1 =
Adds a bundled VS Code MCP workspace config (`.vscode/mcp.json`). No code or data changes; safe drop-in upgrade from 1.7.0.

= 1.7.0 =
Maintainer rebrand. No data migration required; safe drop-in upgrade from 1.6.x.

= 1.6.1 =
Fixes a critical naming bug from 1.6.0 (double `Full_` class prefix) and corrects the admin Connection page proxy path. Recommended upgrade.

= 1.6.0 =
Adds 21 new tools, full Elementor 4.0 atomic support, and several correctness/security fixes. No data migration required.

== License ==

GPL-3.0-or-later. https://www.gnu.org/licenses/gpl-3.0.html

Built on top of the WordPress Abilities API and the WordPress MCP Adapter. Stock-image search powered by the Openverse API.
