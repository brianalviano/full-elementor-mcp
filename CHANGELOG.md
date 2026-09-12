# Changelog

All notable changes to **Safe Elementor MCP** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.8.0] - 2026-09-12

### Added
- **Enterprise Safety Engine**:
  - **Write-Ahead Logging (WAL)**: Every mutating ability creates a durable, sequenced journal record before write execution.
  - **Cryptographic Recovery Checkpoints**: Tamper-evident, AEAD-encrypted (Sodium `XChaCha20-Poly1305` with `OpenSSL AES-256-GCM` fallback) full-page and active-kit snapshots with authenticated HMAC state validation.
  - **Distributed Lease Locking & Fencing Tokens**: Mutual exclusion with monotonic fencing tokens prevents write overlap and stale worker race conditions across concurrent processes.
  - **Forensic Audit Logger**: Append-only audit trail recording user IDs, client UUIDs, target resources, and sanitised arguments with zero-plaintext credential guarantee.
  - **Safe Undo & Recovery Manager**: Two-step undo for individual changes and checkpoints, with strict live-state conflict detection against `after_hash`.
  - **Admin Safety Console**: Classic WordPress Admin dashboard under **Settings → EMCP Safety** for inspecting changes, checkpoints, forensic audit logs, and initiating operator recovery.
- **Compatibility Engine**:
  - `Full_Elementor_MCP_Compatibility_Checker` verifying runtime requirements (PHP >= 8.0, WordPress >= 6.9, Elementor / Pro, MCP Adapter, Abilities API, Sodium/OpenSSL crypto).
  - Hard activation gate preventing activation on incompatible systems without altering database state.
- **Deterministic Release Packaging**:
  - `scripts/build-release.php` CLI tool generating single-root `safe-elementor-mcp/` zip distribution, pre-build secret scanning, syntax validation, SHA-256 manifest, and archive integrity testing.
- **Automated CI/CD**:
  - GitHub Actions matrix testing on PHP 8.0, 8.1, 8.2, 8.3, and 8.4 (`.github/workflows/ci.yml`).
  - Automated release pipeline (`.github/workflows/release.yml`).
- **Comprehensive Test Suite**:
  - Multi-process concurrency and crash recovery suite in `tests/test-phase7-release-concurrency.php`.
  - Complete 7-phase standalone test suite covering all safety and ability mechanics.

### Changed
- **Rebrand**: Product identity renamed from "Full Elementor MCP" to **Safe Elementor MCP** with production safety positioning.
- **Internal Identifiers Preserved**: Machine-facing identifiers (`full_elementor_mcp_*`, ability slugs `full-elementor-mcp/*`, DB tables, option keys) preserved for 100% backward compatibility.
- **Safe Retention Policy**: `uninstall.php` preserves all forensic audit logs, WAL journal entries, and checkpoints by default; complete table and option purge requires explicit administrative opt-in via `full_elementor_mcp_delete_data_on_uninstall`.
- **Database Schema**: Version bumped to `1.4.0` with exactly 4 safety tables (`wp_elementor_mcp_journal`, `wp_elementor_mcp_checkpoints`, `wp_elementor_mcp_audit_log`, `wp_elementor_mcp_tokens`).

---

## [1.7.1] - 2026-09-01

### Added
- Bundled `.vscode/mcp.json` registering stdio proxy as workspace MCP server with masked credential prompts.

---

## [1.7.0] - 2026-08-15

### Changed
- Maintainer updates and metadata refinement.

---

## [1.6.1] - 2026-07-20

### Fixed
- Stripped accidental double class prefix.
- Corrected stdio proxy relative path generation in Admin connection view.

---

## [1.6.0] - 2026-06-10

### Added
- 21 new MCP tools (find-element, batch-update, reorder-elements, wrap/unwrap/replace, dynamic tags, popup settings, theme builder conditions).
- Elementor 4.0 atomic component group support (`e-flexbox`, `e-div-block`, atomic widgets) with `$$type` wrapping and style map synthesis.
- Centralised ID generation with per-request reservation.

---

## [1.0.0] - 2026-01-15

### Added
- Initial release with complete legacy Elementor 3.x tool set.
