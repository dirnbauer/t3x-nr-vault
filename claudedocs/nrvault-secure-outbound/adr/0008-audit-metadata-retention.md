# ADR 0008: Outbound audit logs store metadata only, with retention controls (no bodies, no secrets)

- **Status:** Accepted
- **Date:** 2026-01-12

## Context

We want auditability (“who called what, when, and with what outcome”) without:
- leaking secrets into logs,
- storing sensitive request/response bodies,
- or exploding database size and causing operational pain.

Audit is necessary, but must be safe and bounded.

## Decision

- The outbound audit log stores **metadata only**:
  - serviceId, caller identity, method, path template, status code, duration, bytes in/out, error classification, correlation id.
- It does **not** store:
  - request/response bodies,
  - Authorization headers,
  - any secret material.
- Retention and/or sampling is supported and should have safe defaults.

## Consequences

### Positive
- Useful for compliance, debugging, and incident response.
- Low risk of secret leakage via audit.
- Bounded storage growth.

### Negative
- Deep forensic analysis may still require separate application-level tracing in exceptional cases.

## Alternatives considered

- Store request/response bodies by default.  
  - Rejected: high leakage risk and storage blow-up.
- No audit logs.  
  - Rejected: undermines the core governance value proposition.

