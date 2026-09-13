# Safe Elementor MCP

> A production-safe MCP server for AI-powered Elementor development. Deep page-building capabilities with snapshots, undo, scoped access, and safety guardrails.

[![Latest Release](https://img.shields.io/github/v/release/brianalviano/safe-elementor-mcp?label=release&color=blue)](https://github.com/brianalviano/safe-elementor-mcp/releases)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.0-777BB4.svg)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/wordpress-%3E%3D6.9-21759B.svg)](https://wordpress.org/)
[![Elementor](https://img.shields.io/badge/elementor-3.20%2B%20%7C%204.0%20atomic-D8358F.svg)](https://elementor.com/)
[![CI Status](https://img.shields.io/badge/tests-100%25%20passing-brightgreen.svg)](#testing)
[![License](https://img.shields.io/badge/license-GPL--3.0%2B-green.svg)](LICENSE)

**Safe Elementor MCP** is a WordPress plugin that exposes Elementor and Elementor Pro to AI assistants, coding agents, and development tools over the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/). It builds on the WordPress Abilities API and WordPress MCP Adapter, adding safety controls for automated changes: write-ahead logging (WAL), distributed lease locking, encrypted recovery checkpoints, and append-only audit logging.

---

## 1. Why Safe Elementor MCP?

Generic WordPress content tools cannot construct modern Elementor pages. They cannot:
- Assemble pages out of nested Elementor flexbox containers, sections, and columns.
- Safely manipulate the `_elementor_data` element tree without risking structure corruption.
- Synthesize Elementor 4.0 atomic elements with the required `$$type`-wrapped properties, class IDs, and style maps.
- Guarantee unique 7-character element IDs across complex duplicate and import operations.
- Ensure automated changes can be rolled back safely if an agent diverges from requirements.

Safe Elementor MCP solves these challenges with **131+ specialized tools** and a dedicated safety layer.

---

## 2. Key Features

- **131+ MCP Tools**: Covers Query, Page, Layout, Widget, Template, Global, Composite, Stock Image, SVG, Custom Code, Atomic Layout, and Atomic Widget groups.
- **Elementor 4.0 Atomic Support**: Generates `e-flexbox`, `e-div-block`, and atomic widgets with style maps and class issuance.
- **Elementor 3.x Support**: Manages containers, sections, columns, and classic Elementor widgets with auto-generated JSON schemas.
- **Dual Transport**: HTTP (REST API / SSE) for direct network connections, plus a bundled **stdio proxy** (`bin/full-elementor-mcp-proxy.mjs`) for desktop MCP clients.
- **Scoped Credentials**: Fine-grained Application Password scoping (`read_only` or custom allowlists) and WordPress capability checks (`edit_post`, `unfiltered_html`, `manage_options`).
- **Anti-SSRF Protection**: External asset requests block loopback, RFC 1918 private subnets, and cloud metadata endpoints (`169.254.169.254`).
- **Per-Tool Admin Toggle**: Enable or disable any of the 131 tools from the WordPress Admin UI.

---

## 3. Safety Features

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                                   SAFETY LAYER                                   │
├────────────────────────────────┬─────────────────────────────────────────────────┤
│ Write-Ahead Logging (WAL)      │ Records state transitions and pre/post hashes   │
│ Distributed Lease Fencing      │ Monotonic fencing tokens prevent write conflicts│
│ Encrypted Checkpoints          │ AEAD-encrypted snapshots (Sodium / AES-256-GCM) │
│ Audit Logging                  │ Append-only log with automatic secret redaction │
│ Safe Undo & Rollback           │ Two-step conflict-checked mutation rollback     │
│ Admin Recovery Console         │ Web control plane under Settings → EMCP Safety  │
└────────────────────────────────┴─────────────────────────────────────────────────┘
```

1. **Write-Ahead Logging (WAL)**: All element mutations record state transitions (`pending` → `committed`) in `wp_elementor_mcp_journal`, logging SHA-256 hashes before and after execution.
2. **Distributed Fencing & Mutual Exclusion**: Leases stored in `wp_elementor_mcp_tokens` ensure only one client modifies a target resource at any moment. Monotonic fencing tokens reject stale writers.
3. **Encrypted Checkpoints**: Checkpoint records (`wp_elementor_mcp_checkpoints`) encrypt post trees and global site kits with authenticated encryption (AEAD).
4. **Append-Only Audit Log**: Mutations and recovery actions are recorded in `wp_elementor_mcp_audit_log` with automatic redaction of passwords, tokens, and keys.
5. **Admin Recovery Console**: Built-in visual interface (**Settings → EMCP Safety**) allowing administrators to review changes, inspect audit events, and perform rollbacks.
6. **Safe Uninstall Policy**: Audit logs, journals, and checkpoints are preserved by default if the plugin is uninstalled. Complete data removal requires explicit administrator opt-in.

---

## 4. Requirements

- **PHP**: 8.0 or higher (compatible with PHP 8.0, 8.1, 8.2, 8.3, 8.4).
- **WordPress**: 6.9 to 7.1 (with Abilities API).
- **Elementor**: Minimum 3.20.0 (or 4.0+ for atomic components). Elementor Pro is optional; Pro abilities degrade cleanly when absent.
- **WordPress MCP Adapter**: Version 0.1.0+ (tools remain registered in WordPress Abilities API even if adapter is inactive).
- **Database**: MySQL 8.0+ or MariaDB 10.5+ with InnoDB and utf8mb4.
- **Crypto Backend**: PHP `sodium` extension (recommended) or `openssl` with AES-256-GCM support.

---

## 5. Installation

1. Download `safe-elementor-mcp.zip` from the latest [GitHub Release](https://github.com/brianalviano/safe-elementor-mcp/releases).
2. Open **WordPress Admin → Plugins → Add New → Upload Plugin**.
3. Upload the ZIP archive and activate **Safe Elementor MCP**.
4. Configure WordPress Application Password credentials under **Users → Profile**.
5. Connect your MCP client using HTTP or stdio.

---

## 6. Connecting an MCP Client

Safe Elementor MCP can be used with MCP-compatible AI assistants and coding agents such as Codex, OpenCode, Claude Code/Desktop, Cursor, Antigravity, VS Code, and other compatible clients.

### Transport Options

#### HTTP / REST
For clients that support the WordPress MCP Adapter HTTP transport, use the Safe Elementor MCP server endpoint:
- With pretty permalinks: `https://example.com/wp-json/mcp/full-elementor-mcp-server`
- Without pretty permalinks: `https://example.com/?rest_route=/mcp/full-elementor-mcp-server`

#### stdio
For MCP clients that require a local stdio process, use the bundled proxy:
```
bin/full-elementor-mcp-proxy.mjs
```

Compatibility depends on the MCP transport supported by your client.

### Configuration Examples

#### Generic stdio client
```json
{
  "mcpServers": {
    "safe-elementor-mcp": {
      "command": "node",
      "args": ["/path/to/full-elementor-mcp/bin/full-elementor-mcp-proxy.mjs"],
      "env": {
        "WP_URL": "https://example.com",
        "WP_USERNAME": "your_wp_username",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

#### Claude Desktop example
Add to `claude_desktop_config.json`:
```json
{
  "mcpServers": {
    "safe-elementor-mcp": {
      "command": "node",
      "args": ["/path/to/full-elementor-mcp/bin/full-elementor-mcp-proxy.mjs"],
      "env": {
        "WP_URL": "https://example.com",
        "WP_USERNAME": "your_wp_username",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

#### VS Code example
A pre-configured `.vscode/mcp.json` is included in the source repository for workspace setup convenience (excluded from release archives). It registers the stdio proxy with masked credential prompts.

---

## 7. Tool Categories

| Category | Count | Purpose & Key Abilities |
| -------- | ----- | ----------------------- |
| **Query** | 9 | Schema discovery, element tree walking, page listing, global kit inspection. |
| **Page** | 10 | Create, duplicate, trash, update meta, set featured image, set page settings. |
| **Layout** | 15 | Add, move, duplicate, wrap, unwrap, replace containers, reorder elements, find elements. |
| **Widget** | 50+ | Universal `add-widget` / `update-widget` plus wrappers for heading, button, image, video, icon-box, testimonial, etc. |
| **Template** | 10 | Apply templates, import/export, popup display conditions, theme builder parts. |
| **Global** | 2 | Inspect design kits, activate global style kits. |
| **Composite** | 1 | `build-page` — declarative full-page construction from a single nested JSON brief. |
| **Stock Images** | 3 | Search Openverse, sideload images to media library, insert image widgets with attribution. |
| **SVG Icons** | 2 | Sanitized SVG upload from URL or raw string with handler stripping. |
| **Custom Code** | 6 | Page/element custom CSS, HTML widget JS injection, Elementor Pro code snippet management. |
| **Atomic Layout (E4.0)** | 3 | `e-flexbox`, `e-div-block`, `detect-elementor-version`. |
| **Atomic Widgets (E4.0)** | 10 | `e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-svg`, `e-video`, `e-divider`, and more. |

---

## 8. Compatibility

- **Elementor 3.x & 4.x**: Automatically detects whether the active Elementor install is running 3.x (containers, sections, columns) or 4.0+ (atomic elements with style maps and class IDs).
- **Elementor Pro**: Pro features (popups, theme builder templates, custom code snippets) activate when Pro is present and degrade cleanly when absent.
- **Upgrades**: Upgrades from earlier Full Elementor MCP installations preserve all existing post data, journal rows, and options without manual migration steps.

---

## 9. Security & Recovery

- **Authentication**: All requests require valid WordPress credentials with appropriate capabilities (`edit_post`, `unfiltered_html`, `manage_options`).
- **Input Sanitization**: Tree structures and inputs are validated before applying writes. Script tags and malicious event handlers in SVGs and HTML widgets are sanitized.
- **Rollback Capabilities**: Every mutation is logged in the Write-Ahead Journal. Rollbacks verify before-and-after hashes to prevent overwriting intermediate changes.
- **Encrypted Snapshots**: Checkpoints protect complete page states and site kits using authenticated encryption (`sodium` or `openssl` AES-256-GCM).

---

## 10. Testing

Safe Elementor MCP includes automated test coverage for:

- Mutation safety and write-ahead logging
- Element-tree validation and security boundaries
- Encrypted checkpoints and restore verification
- Safe undo and conflict-checked rollback
- Locking and lease fencing for concurrent writes
- Multi-process concurrency and crash recovery
- Real MySQL behavior, transactions, and schema constraints
- WordPress and Elementor runtime compatibility
- Clean installation, deactivation, and in-place upgrade paths
- Release package structure and manifest integrity

For test suite implementation and continuous integration details, see the [`tests/`](tests/) directory and [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

---

## 11. Origins & Credits

Safe Elementor MCP is independently maintained by Brian Alviano.

Safe Elementor MCP was originally based on [Full Elementor MCP by Zainulabidin90](https://github.com/Zainulabidin90/full-elementor-mcp) and has since diverged into an independently maintained project with a dedicated safety layer (write-ahead logging, encrypted checkpoints, lease fencing, idempotency, scoped credentials, audit logging, undo, and recovery tooling).

### Foundations & Dependencies

- Built on top of the [WordPress Abilities API](https://github.com/WordPress/abilities-api) and the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter).
- Integration with [Elementor](https://elementor.com/) and Elementor Pro.
- Stock image search powered by the [Openverse API](https://api.openverse.org).

*Safe Elementor MCP is an independent open-source project and is not affiliated with, endorsed by, or sponsored by Elementor, WordPress, or upstream project authors.*

---

## 12. License

Safe Elementor MCP is licensed under the **GNU General Public License v3.0 or later** ([GPL-3.0-or-later](LICENSE)).
