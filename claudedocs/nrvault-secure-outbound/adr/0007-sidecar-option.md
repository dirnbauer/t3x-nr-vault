# ADR 0007: Keep the design compatible with a future sidecar/daemon backend for stronger isolation

- **Status:** Accepted
- **Date:** 2026-01-12

## Context

FFI does not provide true isolation. If the threat model includes “PHP process compromise”, a separate process (sidecar/daemon) running under different OS permissions can provide stronger separation:
- master key not readable by PHP process user,
- narrower filesystem and network capabilities,
- independent hardening and observability.

We don’t want to block MVP with sidecar complexity, but we must not paint ourselves into a corner.

## Decision

- The transport abstraction (ADR 0003) remains compatible with a future `SidecarTransport`.
- Request/response specs are designed to be serializable (e.g., JSON or binary framing) so that FFI and sidecar can share the same protocol shape.
- Sidecar mode is explicitly a Phase 3 candidate, not MVP scope.

## Consequences

### Positive
- Preserves an upgrade path to stronger isolation without breaking consumers.
- Allows security-conscious customers to adopt a more robust deployment model later.

### Negative
- Some design choices (spec framing, error taxonomy) must be slightly more disciplined early on.

## Alternatives considered

- Commit only to FFI and ignore sidecar.  
  - Rejected: too limiting for serious security requirements.
- Start with sidecar immediately.  
  - Rejected: slows down MVP and increases operational burden prematurely.

