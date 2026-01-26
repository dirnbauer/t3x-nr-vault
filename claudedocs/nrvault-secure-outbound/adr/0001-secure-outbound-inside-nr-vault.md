# ADR 0001: Implement “Secure Outbound” as an nr-vault enhancement (not a separate extension)

- **Status:** Accepted
- **Date:** 2026-01-12

## Context

We need a TYPO3-wide solution for outbound service calls that centralizes:
- credential handling,
- policy enforcement,
- audit logging,
- and (optionally) a Rust transport backend.

There are two organizational packaging options:
1) build a new TYPO3 extension (e.g. `t3x-nr-secure-http`) and let nr-vault consume it, or
2) enhance `t3x-nr-vault` directly with Service Registry + SecureHttpClient.

## Decision

We will implement Secure Outbound **inside `t3x-nr-vault`** as a first-class feature:
- `ServiceRegistryService`
- `CredentialSetService`
- `SecureHttpClientInterface` + default transport
- Backend module extensions
- Audit logging for outbound calls

Other extensions (nr-llm, shipping integrations, custom extensions) will depend on nr-vault for outbound calls.

## Consequences

### Positive
- Single source of truth for secrets, ACL, and audit.
- Fewer dependency/coupling issues (no cyclic dependencies).
- Unified backend UI for secrets + services + credential sets.
- Clear product story: nr-vault becomes the governance platform for outbound calls.

### Negative
- nr-vault grows in scope and responsibility.
- Teams that want “HTTP only” will still pull nr-vault (acceptable given the primary value is governance + secrets).

## Alternatives considered

- Separate extension `t3x-nr-secure-http` with nr-vault as a dependency.
  - Rejected because it complicates adoption, creates unclear ownership boundaries, and risks cycles later.
- Separate extension with no dependency on nr-vault.
  - Rejected because it duplicates encryption/audit/ACL capabilities and becomes “yet another secret store”.

## Notes

If a future scenario demands it, we can still split packages later, but the MVP should optimize for clarity and adoption.

