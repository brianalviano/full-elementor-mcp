# Safe Elementor MCP

> A production-safe MCP server for AI-powered Elementor development. Deep page-building capabilities with snapshots, undo, scoped access, and safety guardrails.

[![Plugin Version](https://img.shields.io/badge/version-1.8.0-blue.svg)](full-elementor-mcp.php)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.0-777BB4.svg)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/wordpress-%3E%3D6.9-21759B.svg)](https://wordpress.org/)
[![Elementor](https://img.shields.io/badge/elementor-3.20%2B%20%7C%204.0%20atomic-D8358F.svg)](https://elementor.com/)
[![CI Status](https://img.shields.io/badge/tests-100%25%20passing-brightgreen.svg)](#comprehensive-test-suite)
[![License](https://img.shields.io/badge/license-GPL--3.0%2B-green.svg)](LICENSE)

**Safe Elementor MCP** is a self-contained WordPress plugin that exposes the complete surface of Elementor and Elementor Pro to autonomous AI agents over the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/). It builds on top of the WordPress Abilities API and the WordPress MCP Adapter, wrapping every design operation with enterprise safety guardrails: write-ahead logging (WAL), distributed lease locking, cryptographic recovery checkpoints, and append-only forensic audit logging.

---

## Why Safe Elementor MCP?

Generic WordPress content tools cannot construct modern Elementor pages. They cannot:
- Assemble pages out of nested Elementor flexbox containers, sections, and columns.
- Safely manipulate the `_elementor_data` element tree without risking structure corruption.
- Synthesize Elementor 4.0 atomic elements with the required `$$type`-wrapped properties, class IDs, and style maps.
- Guarantee unique 7-character element IDs across complex duplicate and import operations.
- Ensure automated changes can be rolled back safely if an agent diverges from requirements.

Safe Elementor MCP solves all of these challenges with **131+ specialized tools** and a battle-tested safety engine.

---

## Enterprise Safety Engine

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                             SAFETY & RECOVERY ENGINE                             │
├────────────────────────────────┬─────────────────────────────────────────────────┤
│ Write-Ahead Logging (WAL)      │ Every mutation records durable pre/post states  │
│ Distributed Lease Fencing      │ Monotonic fencing tokens prevent write conflicts│
│ Cryptographic Checkpoints      │ AEAD-encrypted snapshots (Sodium / AES-256-GCM) │
│ Forensic Audit Logging         │ Append-only log with zero-plaintext credentials │
│ Safe Undo & Rollback           │ Two-step conflict-checked mutation rollback     │
│ WordPress Admin Safety Console │ Control plane under Settings → EMCP Safety      │
└────────────────────────────────┴─────────────────────────────────────────────────┘
```

1. **Write-Ahead Logging (WAL)**: All tree mutations log state transitions (`pending` → `committed`) in `wp_elementor_mcp_journal`, recording SHA-256 hashes before and after execution.
2. **Distributed Fencing & Mutual Exclusion**: Leases stored in `wp_elementor_mcp_tokens` ensure only one client modifies a target resource at any moment. Monotonic fencing tokens detect and reject stale writers.
3. **Cryptographic Snapshots**: Checkpoints (`wp_elementor_mcp_checkpoints`) encrypt full post trees and global site kits with tamper-evident HMAC validation.
4. **Append-Only Forensic Audit Trail**: Every mutation and recovery action is recorded in `wp_elementor_mcp_audit_log` with automatic secret redaction (passwords, tokens, API keys).
5. **Admin Recovery Console**: Built-in visual interface (**Settings → EMCP Safety**) allowing administrators to inspect changes, review audit events, and initiate safe rollbacks.
6. **Safe Uninstall Policy**: Forensic logs, journals, and checkpoints are preserved by default upon plugin removal. Complete data deletion requires explicit administrator opt-in.

---

## Highlights

- **131+ MCP Tools**: Across Query, Page, Layout, Widget, Template, Global, Composite, Stock Image, SVG, Custom Code, Atomic Layout, and Atomic Widget groups.
- **Elementor 4.0 Atomic Support**: Native generation of `e-flexbox`, `e-div-block`, atomic widgets with style maps and class issuance.
- **Elementor 3.x Legacy Support**: Containers, sections, columns, and classic widgets with auto-generated JSON schemas.
- **Dual Transport**: HTTP (REST API / SSE) for direct connections, plus bundled **stdio proxy** (`bin/full-elementor-mcp-proxy.mjs`) for desktop MCP clients.
- **Strict Capabilities & Scopes**: Fine-grained Application Password scoping (`read_only` or custom allowlists) and capability enforcement (`edit_post`, `unfiltered_html`, `manage_options`).
- **Anti-SSRF Protection**: External requests block loopback, RFC 1918 private subnets, and cloud metadata endpoints (`169.254.169.254`).
- **Per-Tool Admin Toggle**: Enable or disable any of the 131 tools from the WordPress Admin UI.

---

## Tool Categories

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
| **SVG Icons** | 2 | Sanitized SVG upload from URL or raw string with multi-line handler stripping. |
| **Custom Code** | 6 | Page/element custom CSS, HTML widget JS injection, Elementor Pro code snippet management. |
| **Atomic Layout (E4.0)** | 3 | `e-flexbox`, `e-div-block`, `detect-elementor-version`. |
| **Atomic Widgets (E4.0)** | 10 | `e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-svg`, `e-video`, `e-divider`, and more. |

---

## Requirements

- **PHP**: 8.0 or higher (fully tested on 8.0, 8.1, 8.2, 8.3, 8.4).
- **WordPress**: 6.9 to 7.1 (with Abilities API).
- **Elementor**: Minimum 3.20.0 required (or 4.0+ for atomic components). Elementor Pro is optional; Pro abilities degrade cleanly when absent.
- **WordPress MCP Adapter**: Minimum tested version 0.1.0+ (gracefully degrades if absent; tools remain registered in WordPress Abilities API).
- **Database**: MySQL 8.0+ or MariaDB 10.5+ with InnoDB and utf8mb4.
- **Crypto Backend**: PHP `sodium` extension (preferred) or `openssl` with AES-256-GCM support.

---

## Installation & Setup

1. **Install Plugin**:
   Download `safe-elementor-mcp-1.8.0.zip` from [Releases](https://github.com/brianalviano/full-elementor-mcp/releases) and install via **Plugins → Add New → Upload Plugin**, or extract into `wp-content/plugins/full-elementor-mcp/`.
2. **Activate Plugin**:
   The plugin verifies system prerequisites automatically during activation. All safety database tables are provisioned via `dbDelta()`.
3. **Configure MCP Credentials**:
   Generate an **Application Password** in WordPress under **Users → Profile**.
4. **Connect Your MCP Client**:

### Claude Desktop Configuration
Add to `claude_desktop_config.json`:
```json
{
  "mcpServers": {
    "safe-elementor-mcp": {
      "command": "node",
      "args": ["/path/to/safe-elementor-mcp/bin/full-elementor-mcp-proxy.mjs"],
      "env": {
        "WP_URL": "https://example.com",
        "WP_USERNAME": "your_wp_username",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

### VS Code Configuration
The plugin includes a pre-configured `.vscode/mcp.json` that registers the stdio proxy as a workspace server with secure input prompts for credentials.

---

## Quick Example: Atomic Elementor Page

```jsonc
// 1. Detect active Elementor engine version
{ "tool": "full-elementor-mcp/detect-elementor-version" }

// 2. Add an atomic flexbox section to post 100
{
  "tool": "full-elementor-mcp/add-flexbox",
  "input": {
    "post_id": 100,
    "parent_id": "",
    "tag": "section",
    "padding": 60,
    "background_color": "#121212"
  }
}

// 3. Insert an atomic heading inside the section
{
  "tool": "full-elementor-mcp/add-atomic-heading",
  "input": {
    "post_id": 100,
    "parent_id": "<section-id>",
    "title": "Production-Safe Elementor with AI",
    "tag": "h1",
    "color": "#ffffff"
  }
}
```

---

## Comprehensive Test Suite

Safe Elementor MCP includes 7 comprehensive test suites executable directly via the command line:

```bash
php tests/test-phase1-foundation.php         # Safety foundation, locks, SSRF, scopes
php tests/test-phase2-journal.php            # Write-Ahead Logging & state tracking
php tests/test-phase3-validation.php         # Tree validation & schema enforcement
php tests/test-phase4-middleware.php         # Mutation middleware & lock integration
php tests/test-phase5-checkpoints.php        # Cryptographic checkpoints & recovery
php tests/test-phase6-audit-tools.php        # Audit logging, undo tools & Admin safety
php tests/test-phase7-release-concurrency.php # Multi-process concurrency & crash recovery
```

---

## Documentation Links

- [Architecture Overview](docs/ARCHITECTURE.md)
- [Security Model & Threat Architecture](docs/SECURITY-MODEL.md)
- [Security Policy & Reporting](SECURITY.md)
- [Changelog](CHANGELOG.md)
- [Release Engineering & Distribution](docs/RELEASING.md)

---

## License

Safe Elementor MCP is licensed under the **GNU General Public License v3.0 or later** ([GPL-3.0-or-later](LICENSE)).

---

## Credits

- Built on top of the [WordPress Abilities API](https://github.com/WordPress/abilities-api) and the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter).
- Stock image search powered by the [Openverse API](https://api.openverse.org).
