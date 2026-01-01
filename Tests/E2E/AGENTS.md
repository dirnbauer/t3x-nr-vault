# AGENTS.md - E2E Tests

> End-to-end testing guidelines for nr-vault using Playwright.

## Structure

```
Tests/E2E/
├── fixtures/
│   └── auth.ts           # Authentication fixture + TYPO3 helpers
├── user-pathways/        # User journey tests (87 tests)
│   ├── audit.spec.ts     # Audit log workflows
│   ├── cross-module.spec.ts  # Multi-module interactions
│   ├── migration.spec.ts     # Migration scenarios
│   ├── overview.spec.ts      # Dashboard workflows
│   └── secrets.spec.ts       # Secret management
├── accessibility/        # Accessibility tests
└── vault-module.spec.ts  # Basic module loading tests
```

## Running E2E Tests

```bash
# Requires running TYPO3 instance
make up

# Run all E2E tests
cd Tests/E2E && npx playwright test

# Run specific file
npx playwright test user-pathways/secrets.spec.ts

# Run with UI
npx playwright test --ui

# Debug mode
npx playwright test --debug
```

## TYPO3 v14 Iframe Architecture

TYPO3 v14 renders module content inside an iframe. **Always use the helpers**:

```typescript
import { test, expect, getModuleFrame, waitForModuleContent } from './fixtures/auth';

test('example', async ({ authenticatedPage: page }) => {
  await page.goto('/typo3/module/admin/vault/secrets');
  await waitForModuleContent(page);

  const frame = getModuleFrame(page);
  await expect(frame.locator('h1')).toContainText('Secrets');
});
```

## Key Patterns

### Test Isolation
```typescript
// Generate unique identifiers per test
const uniqueId = `test-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
```

### Error Detection
```typescript
// Always check for TYPO3 error pages
await expect(page.locator('text=Oops, an error occurred')).not.toBeVisible();
await expect(page.locator('text=503')).not.toBeVisible();
```

### Conditional Actions
```typescript
// Handle elements that may or may not exist
const button = page.locator('button[data-action="delete"]').first();
if (await button.isVisible()) {
  await button.click();
}
```

## Module URLs

| Module | URL |
|--------|-----|
| Overview | `/typo3/module/admin/vault` |
| Secrets List | `/typo3/module/admin/vault/secrets` |
| Create Secret | `/typo3/module/admin/vault/secrets/create` |
| Audit Log | `/typo3/module/admin/vault/audit` |

## Test Credentials

- **Username**: `admin`
- **Password**: `Joh316!!`

(DDEV defaults - never use in production)

## Common Issues

| Issue | Solution |
|-------|----------|
| Element not found | Use `getModuleFrame(page)` - content is in iframe |
| Timeout waiting | Use `waitForModuleContent(page)` before assertions |
| Flaky tests | Add unique identifiers, use `waitFor` explicitly |
| Auth failures | Check DDEV is running (`make up`) |

---

*[n] Netresearch DTT GmbH*
