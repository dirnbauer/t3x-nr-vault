# PRD: nr-vault Secure Outbound (Service Registry + Credential Sets + SecureHttpClient)

- **Product:** `t3x-nr-vault` (TYPO3 extension)
- **Document type:** Product Requirements Document (PRD)
- **Version:** 0.1 (Draft)
- **Date:** 2026-01-12
- **Status:** Proposed
- **Owner:** TBD (Engineering / Platform)
- **Stakeholders:** Extension owners (nr-llm, DHL integrations, UM, custom projects), Security/Compliance, Ops/Hosting, TYPO3 integrators

---

## 1) Executive summary

TYPO3 projects increasingly depend on external APIs (LLMs, shipping, payments, CRM, marketing, internal platforms). Today, most integrations are implemented “locally” per extension and per project:

- endpoints are configured in multiple places,
- credentials get passed around in PHP memory,
- every integration re-implements auth/retry/timeouts/logging,
- and there is no centralized policy enforcement (SSRF hardening, allowed hosts/paths, rate limits),
- plus no consistent audit trail per outbound API call.

This PRD proposes an **nr-vault enhancement** that turns nr-vault into a **governed outbound integration platform**:

1. **Service Registry**: a central registry of service endpoints + security policies.
2. **Credential Sets**: typed bundles of secrets (OAuth2, API key, Basic, etc.) managed as one logical credential.
3. **SecureHttpClient**: a stable PHP API for all extensions to call external services by `serviceId`.
4. **Transport backends**: default PHP transport everywhere; optional Rust engine (via FFI) for reduced plaintext secret exposure in PHP flows and modern transport options (HTTP/2, optionally HTTP/3).

This is a product feature first (governance + security + operability). Performance and HTTP/3 are optional upsides, not the core justification.

---

## 2) Problem statement

### 2.1 Current pain
- **Secrets sprawl:** keys and tokens live in TypoScript, extension settings, environment variables, DB tables, and sometimes plain config files.
- **Accidental exposure risk:** secrets appear in PHP variables, debug output, exception contexts, dumps, log contexts, traces.
- **Inconsistent resiliency:** timeouts, retries, backoff, circuit-breaking, rate limiting differ per integration (or are missing).
- **No central audit:** it’s hard to answer “which extension called which external service using which credential and when?”.
- **Hard rotations:** key rotation requires hunting through projects/extensions and coordinating releases.
- **SSRF & misuse risk:** internal endpoints or cloud metadata endpoints can be reached if an integration is sloppy.

### 2.2 Desired outcome
- **One place** to configure external services and their auth.
- **One client API** to call those services.
- **Policies and safety rails** are enforced centrally and consistently.
- **Rotation** happens centrally (no code changes).
- **Auditability** is built in.
- Optional: **reduce plaintext secret surface in PHP application flows** using a Rust backend.

---

## 3) Goals and non-goals

### 3.1 Goals (MVP)
**G1 — Service Registry**
- Central definition of services: `serviceId`, base URL, default headers, timeouts, retry policy, and security policy.

**G2 — Credential Sets**
- Typed credentials, stored encrypted, validated by type, managed as one unit.
- Simple rotation: update credential set once, all consumers use it.

**G3 — SecureHttpClient API**
- PHP API for other extensions: call by `serviceId`.
- Response: raw bytes + convenience JSON decoding (array). (No “typed DTO generation” in MVP.)

**G4 — Audit trail**
- Record each outbound call (metadata only) in an auditable log store.
- Provide filters by service, caller, status, timeframe.

**G5 — Security hardening**
- Deny-by-default policy enforcement around:
  - allowed hosts/base URLs,
  - allowed methods,
  - allowed path patterns,
  - block access to private IP ranges / link-local / metadata IPs,
  - strict timeout caps, max body sizes.

### 3.2 Goals (Phase 2+)
**G6 — Optional Rust transport backend**
- Rust engine that performs: decrypt → HTTP → return response.
- Rust backend is optional, behind a transport interface, with safe fallback to PHP transport.

**G7 — HTTP/3 (optional)**
- HTTP/3 is a feature flag, not a requirement. Must fall back to HTTP/2/1.1.

### 3.3 Non-goals
- Not an inbound API gateway.
- Not a generic OpenAPI schema registry + codegen system (too heavy for the baseline).
- Not a security sandbox. FFI does **not** make the system RCE-proof.
- Not a replacement for enterprise secret managers (HashiCorp Vault / cloud secret managers). The goal is TYPO3-native governance and practical safety.

---

## 4) Users & personas

1. **Extension developer (internal/partner)**
   - Wants a stable API for outbound requests and auth handling.
   - Does not want to handle secret storage or rotation logic.

2. **Integrator / Ops**
   - Wants centralized config, easy rotations, controlled rollouts.
   - Needs clear runtime requirements (FFI optional).

3. **Security / Compliance**
   - Wants audit trails, policy enforcement, and reduced secret exposure in application logic.

4. **Project owner**
   - Wants faster integration delivery and lower operational risk.

---

## 5) Core use cases

- **UC1 — LLM provider integration (nr-llm):** expensive keys and high-frequency requests. Centralized rotation + auditing.
- **UC2 — Shipping / logistics:** multiple API integrations, consistent retry/timeouts.
- **UC3 — Payments:** stricter auditability and policy enforcement.
- **UC4 — Multi-tenant TYPO3:** service and credential selection based on site/context; avoid cross-tenant abuse.
- **UC5 — Incident response:** disable a service centrally; rotate a credential set; review outbound activity.

---

## 6) Functional requirements

### 6.1 Service Registry (Admin UI + API)
**Must**
- CRUD services: `serviceId` (stable identifier), name, base URL(s), active flag.
- Configure:
  - default headers (non-secret),
  - timeouts (connect/request),
  - retry policy (retries, backoff),
  - security policy (allowed hosts, allowed paths, allowed methods, max sizes, private-range block, etc.).
- Bind a service to exactly one credential set (MVP).
- “Test connection” action (safe, configurable endpoint/method).

**Should**
- Allow per-service rate limit and circuit breaker (Phase 2).

### 6.2 Credential Sets (Admin UI + API)
**Must**
- CRUD credential sets with:
  - `type` (Bearer Token, API Key Header, Basic, OAuth2 Client Credentials — MVP set),
  - typed form fields and validation,
  - encrypted-at-rest storage,
  - rotation metadata (last rotated, version).
- Provide “reveal secret” permission gating (preferably never reveal by default).

**Should**
- Provide migration path from existing single secrets (import/attach).
- Support additional types: mTLS, signed requests, custom headers, etc. (Phase 2).

### 6.3 SecureHttpClient (PHP API)
**Must**
- Allow extensions to call external services by `serviceId`, without ever passing credentials.
- Enforce service policy for every call.
- Produce an audit record for every call (success or failure).
- Provide safe logging and redaction rules.

**API sketch (MVP)**
```php
interface SecureHttpClientInterface
{
    public function request(
        string $serviceId,
        string $method,
        string $path,
        array $options = []
    ): SecureHttpResponse;
}
```

**Options (MVP)**
- `query`: array
- `headers`: array (non-secret)
- `json`: array|object
- `body`: string
- `timeout`: float override (clamped by policy)
- `return`: `'raw'|'json'`
- `idempotencyKey`: string (optional)

**SecureHttpResponse**
- `statusCode(): int`
- `headers(): array`
- `body(): string`
- `json(): array` (throws on invalid JSON)
- `timing(): array` (optional)

### 6.4 Audit & reporting
**Must**
- Log metadata only:
  - timestamp, serviceId, caller identity (extension + code location if available),
  - method, path template, status, duration, bytes in/out,
  - error classification (timeout, dns, tls, policy_denied, http_error, parse_error),
  - correlation id.
- Provide retention controls and safe defaults.

**Must not**
- Store request/response bodies in audit by default.
- Store Authorization headers or secrets anywhere in audit.

---

## 7) Non-functional requirements

### 7.1 Security requirements (non-negotiable)
- **No secrets in logs** (redaction and defensive coding).
- **Policy enforcement** is done server-side and cannot be bypassed by consumers.
- **Deny-by-default**: services must be explicitly configured; no “raw URL requests” in secure mode.
- **SSRF protections** (minimum):
  - restrict to configured base URLs/hosts,
  - block private and link-local IP ranges and metadata endpoints,
  - enforce allowed methods and path patterns.

### 7.2 Reliability requirements
- Deterministic timeouts.
- Retries only for idempotent requests (or explicit opt-in).
- Clear error taxonomy.

### 7.3 Performance requirements
- Baseline overhead should be low enough for frequent calls.
- Rust transport is optional; should not be required for correctness.

### 7.4 Compatibility
- Works without FFI (pure PHP transport).
- Rust transport must not break environments that can’t or won’t enable FFI.

---

## 8) Data model (proposed)

> Naming is illustrative. Exact table names should follow existing nr-vault conventions.

### 8.1 `tx_nrvault_secret` (existing primitive)
- Stores **one encrypted secret value** (blob/string) with ACL/audit semantics.
- Type-agnostic primitive.

### 8.2 `tx_nrvault_credential_set` (new semantic layer)
**Key point:** Credential sets do **not** replace secrets. They *use secrets*.

**Proposed relation (MVP decision):**
- A credential set references exactly **one** secret row containing an **encrypted JSON payload** of the credential set fields.
  - Example decrypted payload:
    - Bearer: `{"token":"..."}`
    - OAuth2 CC: `{"client_id":"...","client_secret":"...","token_url":"...","scopes":["..."]}`

**Columns (proposed)**
- `uid`
- `name`
- `type` (enum)
- `secret_uid` (FK → `tx_nrvault_secret.uid`)  ← encrypted payload lives there
- `meta_json` (non-sensitive metadata; can include token_url, header_name, etc.)
- `rotation_version`, `last_rotated_at`, `created_at`, `updated_at`
- `active`

This keeps encryption/audit in one place and gives credential sets a clean typed UX and reference target.

### 8.3 `tx_nrvault_service`
**Columns (proposed)**
- `uid`
- `service_id` (unique string key; stable ID)
- `name`
- `active`
- `base_urls_json` (list of allowed base URLs)
- `default_headers_json` (non-secret)
- `timeouts_json` (connect/request caps)
- `retry_json`
- `policy_json` (allowed methods, allowed path patterns, max sizes, deny private ranges, etc.)
- `credential_set_uid` (FK)
- `created_at`, `updated_at`

### 8.4 `tx_nrvault_outbound_audit`
**Columns (proposed)**
- `uid`
- `timestamp`
- `service_id`
- `caller` (extension + class/method if available)
- `method`
- `path_template`
- `status`
- `duration_ms`
- `bytes_out`, `bytes_in`
- `error_class`
- `correlation_id`
- `extra_json` (safe meta)

---

## 9) Architecture & components

### 9.1 PHP layer (always present)
- `VaultService` (existing secrets API)
- `CredentialSetService` (new)
- `ServiceRegistryService` (new)
- `SecureHttpClient` (new)
- Backend Module (manage services and credential sets, view audit)

### 9.2 Transport backends
**Default:** `PhpTransport` (PSR-18 client or Symfony HttpClient integration)  
**Optional:** `RustFfiTransport` (decrypt + send in Rust)

All consumers talk only to `SecureHttpClientInterface`.

### 9.3 Rust engine (optional)
- Implements:
  - decrypt credential set payload using master key (deployment choice),
  - build authenticated request,
  - send HTTP request,
  - return response bytes + metadata.
- Must implement strict memory management across the FFI boundary.
- Must be integrated using a production-safe FFI configuration (preload-only).

### 9.4 Threat model (explicit)
**What we protect against (primary):**
- accidental secret exposure via logs/dumps/debug contexts,
- inconsistent policy enforcement across extensions,
- lack of auditability and rotation control.

**What we do NOT claim (primary):**
- protection against a fully compromised PHP runtime (RCE). A compromised runtime can still abuse the outbound client as a capability.

If stronger isolation is required, plan a sidecar/daemon backend (Phase 3+).

---

## 10) Deployment & packaging

### 10.1 Baseline: PHP-only
- Works everywhere; no special requirements.

### 10.2 Optional Rust transport
This is the controversial part and must be handled deliberately:

- In “managed” environments, you can ship/install a native library as an OS-level artifact.
- For broad TYPO3 distribution, shipping binaries inside an extension package is likely to hurt adoption.

**Requirement:** Rust transport must be optional and must not break installations.

---

## 11) Rollout plan

### Phase 1 — MVP (PHP-only)
- Service Registry + Credential Sets + SecureHttpClient
- Policy enforcement + audit
- Migrate 1 consumer extension to use `SecureHttpClient`

**Exit criteria**
- Configure and rotate 2 real services without code changes.
- Audit shows reliable metadata.
- No secrets appear in logs under normal operation.

### Phase 2 — Rust backend beta (optional)
- Implement `RustFfiTransport`
- Provide preload-based FFI integration guide
- Benchmark and stability tests

**Exit criteria**
- 100% functional parity with PHP transport.
- Fallback is robust.
- Memory-leak tests pass.
- Controlled rollout to one production service.

### Phase 3 — Hardening + packaging + (optional) sidecar
- Packaging pipeline (multi-arch builds, signing, update process)
- Sidecar prototype for stronger isolation (if required)

---

## 12) Success metrics (KPIs)

- Adoption:
  - # services configured per installation
  - # consuming extensions migrated
  - % outbound calls through SecureHttpClient
- Operations:
  - median time-to-rotate credential (goal: minutes, not days)
  - incident response speed (disable service, rotate, audit)
- Security:
  - “secrets in logs” regression tests: 0
- Reliability:
  - error rates by service
  - latency distribution

---

## 13) Risks & mitigations

1. **“FFI = sandbox” misconception**  
   - Mitigation: document threat model; consider sidecar for isolation.

2. **Binary packaging blocks adoption**  
   - Mitigation: keep Rust optional; external artifact distribution.

3. **Audit tables grow without bounds**  
   - Mitigation: retention policy, sampling, metadata-only design.

4. **SSRF / abuse via service registry**  
   - Mitigation: deny-by-default, strict policy enforcement, IP range blocking.

5. **Typed DTO expectations**  
   - Mitigation: MVP = raw + JSON array; typed DTOs only in curated connectors later.

---

## 14) Open questions (to be resolved via ADRs)

- Packaging strategy for Rust artifacts (OS package vs embedded vs sidecar).
- Sidecar vs FFI as the “security-grade” mode.
- Multi-tenant mapping model (global vs per site/root page).
- Rate limiting & circuit breaker defaults.
- Which credential types are MVP vs Phase 2.

---

## 15) Appendix: terminology

- **Secret**: one encrypted atomic value stored by nr-vault.
- **Credential set**: typed grouping of secret fields representing one usable credential.
- **Service**: endpoint configuration + policy + credential binding.
- **Secure Outbound**: the entire feature set described in this PRD.

