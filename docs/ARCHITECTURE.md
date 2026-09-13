# Safe Elementor MCP — System Architecture

## 1. Architectural Overview

Safe Elementor MCP is a WordPress plugin providing a Model Context Protocol (MCP) server for Elementor and Elementor Pro. It enables autonomous AI agents to query, design, construct, style, and manage pages safely.

```
┌────────────────────────────────────────────────────────────────────────┐
│                        MCP Clients (AI Agents)                         │
│           Codex / OpenCode / Claude / Cursor / Antigravity / Custom    │
└───────────────────┬────────────────────────────────┬───────────────────┘
                    │ (HTTP / SSE Transport)         │ (Stdio Transport)
                    ▼                                ▼
┌───────────────────────────────────────┐ ┌──────────────────────────────┐
│       WordPress REST API Endpoint     │ │    Node.js Stdio Proxy       │
│      (/wp-json/mcp/v1/abilities)      │ │(bin/full-elementor-mcp-proxy)│
└───────────────────┬───────────────────┘ └──────────────┬───────────────┘
                    │                                    │
                    └─────────────────┬──────────────────┘
                                      ▼
                    ┌────────────────────────────────────┐
                    │      WordPress MCP Adapter         │
                    └─────────────────┬──────────────────┘
                                      ▼
                    ┌────────────────────────────────────┐
                    │       WordPress Abilities API      │
                    └─────────────────┬──────────────────┘
                                      ▼
                    ┌────────────────────────────────────┐
                    │    Full_Elementor_MCP_Plugin       │
                    │        (Core Orchestrator)         │
                    └─────────────────┬──────────────────┘
                                      │
              ┌───────────────────────┴───────────────────────┐
              ▼                                               ▼
┌───────────────────────────┐                   ┌───────────────────────────┐
│     Query Abilities       │                   │    Mutation Middleware    │
│  (Read-only, no locks)    │                   │   (WAL, Locking, Safety)  │
└───────────────────────────┘                   └─────────────┬─────────────┘
                                                              │
              ┌───────────────────────────────────────────────┴──────────┐
              ▼                                                          ▼
┌───────────────────────────┐                              ┌───────────────────────────┐
│  Atomic & Legacy Builders │                              │     Recovery Engine       │
│  (Element tree synthesis, │                              │  (Undo Manager, Rollback, │
│   class issuing, CSS)     │                              │   Checkpoint Restorer)    │
└─────────────┬─────────────┘                              └─────────────┬─────────────┘
              │                                                          │
              └───────────────────────────────┬──────────────────────────┘
                                              ▼
                             ┌───────────────────────────────────┐
                             │       WordPress Database          │
                             │  - wp_elementor_mcp_journal       │
                             │  - wp_elementor_mcp_checkpoints   │
                             │  - wp_elementor_mcp_audit_log     │
                             │  - wp_elementor_mcp_tokens        │
                             │  - wp_postmeta (_elementor_data)  │
                             └───────────────────────────────────┘
```

---

## 2. Core Subsystems

### A. Ability Registration Layer
- Registers 131+ structured tools with the WordPress Abilities API.
- **Dynamic Grouping**: Automatically determines whether Elementor 3.x legacy container tools or Elementor 4.0 atomic component tools should be registered based on the active Elementor environment.
- **Admin Filtering**: Tools disabled via **Settings → Safe Elementor MCP → Tools** are filtered prior to registration.

### B. Safety & Mutation Middleware
Every mutation ability (`add-widget`, `update-element`, `apply-template`, etc.) passes through `Full_Elementor_MCP_Mutation_Middleware`:
1. **SSRF & Parameter Validation**: Sanitizes inputs and inspects URLs.
2. **Lock Acquisition**: Acquires a distributed lease lock on the target resource (e.g. `post:123`) using Compare-And-Swap (CAS) in `wp_elementor_mcp_tokens`.
3. **Fencing Verification**: Validates caller possession of the active fencing token.
4. **Pre-State Capture**: Reads current `_elementor_data` and computes SHA-256 hash.
5. **WAL Logging**: Inserts a durable record into `wp_elementor_mcp_journal` with status `pending`.
6. **Delegate Execution**: Invokes the underlying Elementor builder logic.
7. **Post-State Verification & Completion**: Re-hashes post state, verifies change application, and marks journal status `committed`.
8. **Audit Logging**: Appends a redacted event to `wp_elementor_mcp_audit_log`.
9. **Lease Release**: Releases the lock lease while monotonically advancing the fencing token.

### C. Database Architecture (Exactly 4 Safety Tables)
The safety engine operates exclusively on 4 dedicated tables:

| Table Name | Purpose | Primary Key |
| ---------- | ------- | ----------- |
| `wp_elementor_mcp_journal` | Write-Ahead Log (WAL) tracking every mutation attempt, before/after states, hashes, and execution status | `id` (bigint auto-increment) |
| `wp_elementor_mcp_checkpoints` | Cryptographically encrypted, tamper-evident historical snapshots of pages and global kits | `id` (bigint auto-increment) |
| `wp_elementor_mcp_audit_log` | Append-only immutable forensic event log with redacted arguments and user correlation | `id` (bigint auto-increment) |
| `wp_elementor_mcp_tokens` | Distributed leases, fencing tokens, and idempotency cache results | `token_key` (varchar-128) |

---

## 3. Backward Compatibility & System Requirements

To ensure existing client configurations, automation scripts, and database integrations continue operating without disruption:
- **Plugin Directory & Packaging**: The distribution ZIP is named `safe-elementor-mcp-X.Y.Z.zip`, while the internal root directory is strictly `full-elementor-mcp/` so in-place upgrades never break WordPress `active_plugins` options or require re-activation.
- **Machine Identifiers**: Prefix `full_elementor_mcp_*`, constants (`FULL_ELEMENTOR_MCP_VERSION`, etc.), option names, and database table names remain unchanged.
- **Ability Names**: Ability names remain prefixed with `full-elementor-mcp/` (e.g. `full-elementor-mcp/add-heading`).
- **Public Product Identity**: The public branding, documentation, and user-facing admin consoles use **Safe Elementor MCP**.

### Compatibility Requirements
- **WordPress**: 6.9 to 7.1 (fully tested on both 6.9 and 7.1 core with real MySQL).
- **Elementor**: Minimum 3.20.0 required. Elementor 4.0+ activates the Atomic architecture (`e-flexbox`, `e-div-block`, etc.). Elementor Pro is optional; Pro abilities degrade cleanly when absent.
- **WordPress MCP Adapter**: Minimum tested version 0.1.0+. If the MCP Adapter is absent, the plugin operates in standalone mode, emitting an admin notice and registering tools via the WordPress Abilities API.
- **PHP**: 8.0, 8.1, 8.2, 8.3, 8.4.
- **Database**: MySQL 8.0+ / MariaDB 10.5+ with InnoDB and utf8mb4.
