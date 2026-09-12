# Security Policy

Safe Elementor MCP is built from the ground up for mission-critical, production WordPress environments driven by AI agents. We take the security of your websites and agent workflows with the utmost seriousness.

---

## Supported Versions

Only the latest major release receives active security patches.

| Version | Supported          |
| ------- | ------------------ |
| 1.8.x   | :white_check_mark: |
| < 1.8.0 | :x:                |

---

## Reporting a Vulnerability

If you discover a potential security vulnerability in Safe Elementor MCP, **please do not disclose it publicly or open a public GitHub issue.**

Please report security issues directly to the security maintainers:

- **Email**: `security@progressive-robot.com` (or create a private security advisory on GitHub)
- **Subject**: `[SECURITY] Safe Elementor MCP Vulnerability Report`
- **Details to Include**:
  - Plugin version and environment details (PHP, WordPress, Elementor versions).
  - Detailed description of the vulnerability and attack vector.
  - Step-by-step reproduction instructions or a minimal Proof of Concept (PoC).
  - Impact assessment (confidentiality, integrity, availability).

### Response Timeline
- **Acknowledgement**: Within 48 hours of initial report.
- **Triage & Assessment**: Within 5 business days.
- **Patch & Advisory Release**: Coordinated with the reporter before public disclosure.

---

## Core Security Safeguards

Safe Elementor MCP enforces multi-layered defense-in-depth protections:

1. **Strict Capability Checks**: Every mutating tool verifies `current_user_can( 'edit_post', $post_id )` or `manage_options`. Code snippet and custom CSS abilities strictly require `unfiltered_html`.
2. **Credential Scopes**: Application Passwords can be constrained to `read_only` or scoped allowlists/denylists, preventing unauthorized write operations.
3. **Distributed Fencing & Mutual Exclusion**: Leases and fencing tokens prevent concurrent race conditions, write skew, and stale worker corruption.
4. **Anti-SSRF Protections**: URL sideloading and image fetch tools strictly validate IP resolution, blocking loopback (`127.0.0.0/8`, `::1`), private networks (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), and cloud metadata services (`169.254.169.254`).
5. **Zero-Plaintext Credential Sanitization**: The append-only audit logger automatically redacts passwords, bearer tokens, salts, API keys, and authorization headers before persistence.
6. **Encrypted Checkpoints**: Snapshots are encrypted at rest using AEAD (`XChaCha20-Poly1305` or `AES-256-GCM`) with authenticated HMAC integrity tags.
7. **Input Hardening**: Injected JavaScript defensively escapes closing `</script>` sequences. SVG imports strip scripts and event handlers before deferring to Elementor's native sanitizer.
