# ADR-008: HTTP Client

## Status
Accepted

## Date
2026-01-03

## Context
Applications need to make authenticated HTTP requests using vault secrets. The typical pattern exposes secrets to application code, risking logging and memory leaks.

## Decision
PSR-18 wrapper with fluent authentication API:

- Immutable design (each `with*` returns new instance)
- Late binding (secrets retrieved at request time)
- Memory safety (`sodium_memzero` after injection)
- Audit integration (logs calls without exposing secrets)

## SecretPlacement Options

```php
enum SecretPlacement {
    case Bearer;      // Authorization: Bearer {secret}
    case BasicAuth;   // Authorization: Basic {base64}
    case Header;      // Custom header
    case QueryParam;  // URL query parameter
    case BodyField;   // Request body field
    case OAuth2;      // OAuth 2.0 with token refresh
    case ApiKey;      // X-API-Key header
}
```

## Usage

```php
// Bearer authentication
$response = $httpClient
    ->withAuthentication('stripe_api_key', SecretPlacement::Bearer)
    ->sendRequest(new Request('POST', 'https://api.stripe.com/v1/charges'));

// Basic auth with two secrets
$response = $httpClient
    ->withAuthentication('password', SecretPlacement::BasicAuth, [
        'usernameSecret' => 'username',
    ])
    ->sendRequest($request);

// OAuth 2.0
$response = $httpClient
    ->withOAuth(OAuthConfig::clientCredentials($tokenUrl, $clientId, $clientSecret))
    ->sendRequest($request);
```

## Memory Safety

```php
private function injectBearer(RequestInterface $request): RequestInterface
{
    $secret = $this->vault->retrieve($this->secretIdentifier);
    try {
        return $request->withHeader('Authorization', 'Bearer ' . $secret);
    } finally {
        sodium_memzero($secret);  // Immediate cleanup
    }
}
```

## Consequences

**Positive:**
- Application code never sees raw secrets
- Memory cleared immediately after use
- PSR-18 compatible
- Audit trail for API calls

**Negative:**
- Wrapper overhead per request
- Brief memory exposure during injection

## References
- `Classes/Http/VaultHttpClient.php`
- `Classes/Http/SecretPlacement.php`
- `Classes/Http/SecureHttpClientFactory.php`
