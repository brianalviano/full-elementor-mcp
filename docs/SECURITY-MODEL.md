# Safe Elementor MCP — Security Model & Threat Architecture

## 1. Executive Summary

Safe Elementor MCP bridges autonomous AI agents and WordPress/Elementor production sites. Because AI agents execute mutations over programmatic transports (HTTP REST and stdio), the security model assumes:
- Untrusted or hallucinated input may be submitted at high velocity.
- Multiple agents or human editors may operate simultaneously on overlapping resources.
- Network access from agents could attempt Server-Side Request Forgery (SSRF) against internal services.
- Accidental or malicious mass mutations must have deterministic, atomic, and cryptographically verifiable rollback capabilities.

To address these threats, Safe Elementor MCP implements a 6-layer defense model.

---

## 2. Security Defense Layers

```
                                 [ AI Agent / MCP Client ]
                                             │
                                             ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ Layer 1: Authentication & Credential Scopes (Application Password UUID, RBAC)           │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ Layer 2: Input Sanitization & Anti-SSRF (Private IP filters, script tag escape)         │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ Layer 3: Critical Asset Protection (Active Kit & Front Page break-glass override)      │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ Layer 4: Distributed Lease Locks & Monotonic Fencing Tokens (CAS, stale writer abort)   │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ Layer 5: Write-Ahead Logging (WAL) & Cryptographic Checkpoints (AEAD, SHA-256 state)   │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ Layer 6: Append-Only Forensic Audit Trail & Admin Recovery Console (Zero Plaintext)    │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

### Layer 1: Authentication & Credential Scoping
- **WordPress Capabilities**: Read tools require standard reading capabilities; mutation tools require `edit_post` on the target post ID; template tools require `edit_posts` / `publish_posts`; code snippet and global kit modification requires `manage_options` and `unfiltered_html`.
- **Credential Scopes**: Application Passwords can be bound to scoped policies in `wp_options`:
  - `read_only`: Only allows reading abilities; mutations fail closed with `credential_scope_insufficient`.
  - `custom`: Explicit allowlist of allowed ability IDs and explicit denylist.
  - Fail-closed: An unknown mode or empty allowlist in custom mode denies all ability execution.

### Layer 2: Input Sanitization & Anti-SSRF Protection
- **SSRF Resolution**: Before fetching external assets (stock images via Openverse, SVGs from URL), the hostname is resolved via DNS and verified against prohibited subnets:
  - Loopback (`127.0.0.0/8`, `::1`)
  - Private networks (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`)
  - Cloud Metadata Services (`169.254.169.254`)
  - Link-local and multicast addresses.
- **Script Escaping**: Raw JavaScript strings inserted via HTML widgets are escaped to prevent closing `</script>` tag injection breakouts.
- **SVG Sanitization**: Strips `<?php` tags, `<script>` tags, inline `on*=` event handlers across multiple lines, and `javascript:` URIs before deferring to Elementor's native SVG sanitizer.

### Layer 3: Critical Asset Protection
- Certain assets dictate the layout and branding of the entire website:
  - The Active Site Kit (`elementor_active_kit`)
  - The Homepage (`page_on_front`)
  - The Blog Index (`page_for_posts`)
- Modifications to these resources require the explicit parameter `allow_critical_override: true`. If absent, mutation is aborted before acquiring a lease or modifying data.

### Layer 4: Distributed Lease Locking & Fencing Tokens
- Multi-process and multi-agent coordination requires strict mutual exclusion.
- The `elementor_mcp_tokens` table maintains leases for resource keys (e.g. `post:42`).
- Each lease acquisition increments a **monotonic fencing token**.
- If an agent process stalls or crashes, its lease expires automatically. When a new agent claims the lease, the fencing token increments. Any stale write from the delayed agent is rejected via `stale_writer_conflict`.

### Layer 5: Write-Ahead Logging (WAL) & Cryptographic Checkpoints
- Prior to applying changes to the Elementor element tree or post meta:
  1. The complete current state is hashed (`before_hash`).
  2. A durable journal entry (`elementor_mcp_journal`) is written with status `pending`.
  3. On high-impact mutations or explicit triggers, an encrypted checkpoint (`elementor_mcp_checkpoints`) is created.
- Checkpoints are encrypted using Authenticated Encryption with Associated Data (AEAD):
  - Sodium `crypto_aead_xchacha20poly1305_ietf` (primary)
  - OpenSSL `aes-256-gcm` (fallback)
- State integrity is verified with HMAC-SHA256 authenticated tags.

### Layer 6: Append-Only Forensic Audit Logging & Admin Recovery Console
- Every mutation, recovery operation, and critical error is appended to `elementor_mcp_audit_log`.
- **Zero Plaintext Secrets**: The audit logger inspects all argument and metadata payloads, automatically redacting:
  - Passwords and secret keys
  - Authorization headers and bearer tokens
  - API keys and tokens
  - Salting and cryptographic seeds
- Payload sizes are strictly bounded (< 32 KiB) to prevent denial-of-service via database bloating.
- Forensic logs are preserved by default on plugin uninstallation to ensure forensic auditability after security incidents.
