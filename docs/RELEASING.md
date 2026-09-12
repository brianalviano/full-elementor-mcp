# Safe Elementor MCP — Release Engineering & Distribution Guide

This document outlines the standard release process for packaging, verifying, and publishing production distributions of **Safe Elementor MCP**.

---

## 1. Release Invariants & Checklist

Before building a release, verify the following:

- [ ] **Version Consistency**:
  - `full-elementor-mcp.php`: Plugin Header `Version: X.Y.Z`
  - `full-elementor-mcp.php`: `FULL_ELEMENTOR_MCP_VERSION` constant
  - `readme.txt`: `Stable tag: X.Y.Z`
  - `CHANGELOG.md`: Entry for `[X.Y.Z]`
- [ ] **Database Schema Invariant**:
  - `Full_Elementor_MCP_Database_Installer::DB_VERSION` corresponds to the current schema (1.4.0).
  - Exactly 4 safety tables maintained (`journal`, `checkpoints`, `audit_log`, `tokens`).
- [ ] **Test Suite Green**:
  - Run all 7 test suites:
    ```bash
    php tests/test-phase1-foundation.php
    php tests/test-phase2-journal.php
    php tests/test-phase3-validation.php
    php tests/test-phase4-middleware.php
    php tests/test-phase5-checkpoints.php
    php tests/test-phase6-audit-tools.php
    php tests/test-phase7-release-concurrency.php
    ```
- [ ] **Syntax Lint**:
  - `find . -name "*.php" -exec php -l {} +` passes with zero errors on PHP 8.0 through 8.4.

---

## 2. Release Artifact Generation

Release packaging is fully automated and deterministic via `scripts/build-release.php`:

```bash
php scripts/build-release.php
```

### Packaging Actions:
1. **Pre-Flight Scans**:
   - Scans all files for accidental credentials, private keys, and tokens.
   - Verifies version alignment across headers and constants.
   - Runs `php -l` on all PHP files included in the build.
2. **Deterministic Single-Root Assembly**:
   - Compiles distribution archive with single root directory: `safe-elementor-mcp/`.
   - Excludes VCS files (`.git/`), CI workflows (`.github/`), test suites (`tests/`), build scripts (`scripts/`), and scratch files.
3. **Artifacts Output to `dist/`**:
   - `dist/safe-elementor-mcp-X.Y.Z.zip`
   - `dist/safe-elementor-mcp.zip` (latest alias)
   - `dist/safe-elementor-mcp-X.Y.Z.zip.sha256`
   - `dist/manifest.json` containing build timestamp, file list, and per-file SHA-256 hashes.
4. **Post-Build Validation**:
   - Inspects the produced ZIP to verify single-root compliance and absence of excluded directories.

---

## 3. Safe Release Tagging & Publication

> [!IMPORTANT]
> Releases and Git tags are **NEVER** created or pushed automatically by AI assistants. The human maintainer retains sole authority over release publication.

Once all tests pass and `scripts/build-release.php` succeeds:

1. Review git diff:
   ```bash
   git status
   git diff
   ```
2. Commit release changes:
   ```bash
   git commit -m "Release vX.Y.Z"
   ```
3. Tag the release:
   ```bash
   git tag -a vX.Y.Z -m "Release vX.Y.Z"
   git push origin main --tags
   ```
4. GitHub Actions will trigger `.github/workflows/release.yml` upon tag push, running the automated test suite, packaging the release, and attaching the artifacts (`.zip`, `.sha256`, `manifest.json`) to the GitHub Release draft.
