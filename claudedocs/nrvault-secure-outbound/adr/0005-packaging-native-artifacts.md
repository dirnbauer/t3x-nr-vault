# ADR 0005: Do not ship native binaries inside the default TYPO3 extension package; distribute Rust artifacts separately

- **Status:** Accepted
- **Date:** 2026-01-12

## Context

Shipping native binaries inside extension packages:
- complicates security review and supply-chain trust,
- complicates updates (CVE patching),
- complicates platform support (x86_64, aarch64, glibc vs musl),
- and often triggers “no executables in extensions” policies in security-conscious environments.

We still want Rust as an optional performance/security feature where it makes sense.

## Decision

- The default nr-vault distribution remains **PHP-only**.
- Rust transport artifacts are distributed as **separate platform-specific artifacts**, e.g.:
  - OS packages (deb/rpm),
  - container images / sidecar,
  - or a dedicated “engine package” download with checksums/signing.
- “Bundled binary inside extension” is allowed only for controlled managed environments and is not the default path.

## Consequences

### Positive
- Better adoption in security-conscious TYPO3 environments.
- Clear update and patching model for native components.
- Cleaner separation of responsibilities and reduced TER friction.

### Negative
- Additional installation steps for Rust mode.
- Requires CI/CD pipeline for multi-arch artifacts and release management.

## Alternatives considered

- Bundle `libvault.so` directly in the extension.  
  - Rejected as default; allowed only in managed/special cases.

