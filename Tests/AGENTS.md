# AGENTS.md - Tests

> Testing guidelines for nr-vault.

**See also**: `Tests/E2E/AGENTS.md` for E2E-specific patterns.

## Test Structure

```
Tests/
├── Unit/           # Fast, isolated tests (mocked dependencies)
├── Functional/     # TYPO3 integration tests (real database)
├── E2E/            # Playwright browser tests (full stack)
├── Architecture/   # PHPat dependency rules
├── Fuzz/           # Input fuzzing tests
└── Build/          # PHPUnit configuration
```

## Running Tests

```bash
# All tests
make test

# Specific suites
make unit                    # Unit only
make functional              # Functional only

# In container with options
ddev exec .Build/bin/phpunit -c Build/phpunit.xml --testsuite Unit --filter "OrphanCleanup"

# E2E tests (requires running TYPO3)
cd Tests/E2E && npx playwright test
```

## Unit Test Conventions

```php
#[CoversClass(MyService::class)]
final class MyServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MyService $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new MyService(/* mocked dependencies */);
    }

    #[Test]
    public function methodNameDescribesExpectedBehavior(): void
    {
        // Arrange
        $input = 'test';

        // Act
        $result = $this->subject->process($input);

        // Assert
        self::assertSame('expected', $result);
    }
}
```

Key rules:
- One test class per production class
- Use `#[Test]` attribute (not `@test` annotation)
- Use `#[CoversClass]` for coverage tracking
- Method names describe behavior, not implementation
- Mock all external dependencies
- Use `self::assert*` (not `$this->assert*`)

## Functional Test Conventions

```php
#[CoversClass(VaultService::class)]
final class VaultServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    protected array $coreExtensionsToLoad = [
        'scheduler',
    ];

    #[Test]
    public function storesAndRetrievesSecret(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        // ... test with real database
    }
}
```

Key rules:
- Use CSV fixtures for test data
- Clean database state between tests
- Test real TYPO3 integration points

## E2E Test Conventions

```typescript
test.describe('Secret Management', () => {
  test('can create and delete secret', async ({ authenticatedPage: page }) => {
    await page.goto('/typo3/module/admin/vault/secrets/create');
    await waitForModuleContent(page);

    const frame = getModuleFrame(page);
    // ... interact with TYPO3 backend
  });
});
```

Key rules:
- Use `authenticatedPage` fixture for admin access
- Use `getModuleFrame()` for TYPO3 v14 iframe structure
- Use `waitForModuleContent()` before assertions
- Generate unique test identifiers for isolation

## Mocking Patterns

### Mock VaultService
```php
$vaultService = $this->createMock(VaultServiceInterface::class);
$vaultService->method('retrieve')->willReturn('secret-value');
$vaultService->expects($this->once())->method('store');
```

### Mock Backend User
```php
$GLOBALS['BE_USER'] = $this->createMock(BackendUserAuthentication::class);
$GLOBALS['BE_USER']->method('isAdmin')->willReturn(true);
$GLOBALS['BE_USER']->user = ['uid' => 1, 'username' => 'admin'];
```

### Mock ConnectionPool
```php
$connectionPool = $this->createMock(ConnectionPool::class);
$queryBuilder = $this->createMock(QueryBuilder::class);
$connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
```

## Security Testing

1. **Never use real secrets** - Use generated test values
2. **Test access control** - Verify permissions are enforced
3. **Test audit logging** - Verify operations are logged
4. **Test edge cases** - Empty strings, null, special chars

## Coverage Requirements

- New code: Minimum 80% line coverage
- Security-critical code: 100% coverage required
- Run with coverage: `ddev exec .Build/bin/phpunit -c Build/phpunit.xml --testsuite Unit --coverage-text`

## Common Issues

| Issue | Solution |
|-------|----------|
| `GeneralUtility::makeInstance` fails | Use `GeneralUtility::addInstance()` in setUp |
| Singleton not reset | Set `$resetSingletonInstances = true` |
| E2E iframe not found | Use `getModuleFrame(page)` helper |
| Functional test DB error | Check `$testExtensionsToLoad` |

---

*[n] Netresearch DTT GmbH*
