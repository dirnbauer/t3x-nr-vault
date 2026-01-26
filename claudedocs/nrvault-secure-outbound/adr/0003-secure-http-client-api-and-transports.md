# ADR 0003: Provide a stable `SecureHttpClientInterface` with pluggable transports (PHP default, Rust optional)

- **Status:** Accepted
- **Date:** 2026-01-12

## Context

Multiple extensions need to call external HTTP services with centralized credentials, policies, and audit. We need:
- a stable, minimal public PHP API,
- a clean boundary between “product logic” (registry/policy/audit) and “transport engine”.

We also want optional Rust (FFI or later sidecar) without forcing it on everyone.

## Decision

We define a public `SecureHttpClientInterface` and a transport abstraction:

- **SecureHttpClient** (product logic):
  - resolves service by `serviceId`,
  - loads credential set,
  - enforces policy (deny-by-default),
  - executes request via a configured transport backend,
  - records audit metadata,
  - returns a response wrapper.

- **TransportInterface** (engine):
  - `send(RequestSpec): ResponseSpec`
  - Backends:
    - `PhpTransport` (default; PSR-18 or Symfony HttpClient)
    - `RustFfiTransport` (optional)
    - (future) `SidecarTransport`

Consumers never talk to transports directly; they only use `SecureHttpClientInterface`.

## Consequences

### Positive
- Decouples governance logic from transport implementation.
- Allows fallback and progressive rollout of Rust.
- Keeps API surface small and stable for consumers.
- Avoids “typed DTO in Rust” trap: response is raw/json in PHP.

### Negative
- Slight abstraction overhead.
- Requires careful policy enforcement placement (must not be bypassable).

## Alternatives considered

- Let consumers pick HTTP client (PSR-18) directly.  
  - Rejected: loses central policy enforcement and audit guarantees.
- Make Rust mandatory.  
  - Rejected: adoption killer; too many environments can’t/won’t run native code.

