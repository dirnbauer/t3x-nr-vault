<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-04-20 | Last verified: 2026-04-20 -->

# AGENTS.md — Tests/E2E

## Overview
Playwright browser E2E tests for the nr-vault TYPO3 backend module. Targets the running DDEV instance.

## Key Files
| File | Purpose |
|------|---------|
| `Tests/E2E/vault-module.spec.ts` | Basic module loading |
| `Tests/E2E/fixtures/auth.ts` | Auth fixture + `getModuleFrame`, `waitForModuleContent` helpers |
| `Tests/E2E/user-pathways/secrets.spec.ts` | Secret CRUD + reveal journey |
| `Tests/E2E/user-pathways/audit.spec.ts` | Audit log workflows |
| `Tests/E2E/user-pathways/migration.spec.ts` | Migration wizard |
| `Tests/E2E/user-pathways/overview.spec.ts` | Dashboard |
| `Tests/E2E/user-pathways/cross-module.spec.ts` | Multi-module interactions |
| `Tests/E2E/accessibility/` | axe-core accessibility assertions |
| `Tests/E2E/USER_PATHWAYS.md` | Journey catalogue |
| `playwright.config.ts` | Project + reporter config (repo root) |

## Golden Samples
| Pattern | Reference |
|---------|-----------|
| Iframe-aware test | `Tests/E2E/user-pathways/secrets.spec.ts` |
| Auth fixture usage | `Tests/E2E/fixtures/auth.ts` |
| Accessibility assertion | `Tests/E2E/accessibility/*.spec.ts` |

## Setup
```bash
# 1. Start the TYPO3 instance
make up

# 2. (First run) install Playwright browsers
npx playwright install --with-deps
```

## Build/Tests
| Task | Command |
|------|---------|
| All E2E | `npx playwright test` (from repo root) |
| Single file | `npx playwright test user-pathways/secrets.spec.ts` |
| Headed UI | `npx playwright test --ui` |
| Debug | `npx playwright test --debug` |
| Report | `npx playwright show-report` |

## Directory Structure
```
Tests/E2E/
├── fixtures/
│   └── auth.ts
├── user-pathways/
│   ├── audit.spec.ts
│   ├── cross-module.spec.ts
│   ├── migration.spec.ts
│   ├── overview.spec.ts
│   └── secrets.spec.ts
├── accessibility/
├── tca/
└── vault-module.spec.ts
```

## Code Style
- TypeScript, ES modules.
- Each test fully isolated — generate unique identifiers per run:
  `const uniqueId = \`test-${Date.now()}-${Math.random().toString(36).slice(2,8)}\``
- Always await inside tests; never mix fire-and-forget promises.
- Prefer role/label locators over CSS selectors.
- No `page.waitForTimeout(ms)` — use `waitForModuleContent` or explicit `waitFor`.
- Group pathways by domain (secrets, audit, migration) rather than by page.

## Security
- **Test credentials** (`admin` / DDEV default) are for local DDEV only — never commit production credentials.
- **Never** point E2E tests at a production instance.
- **Clipboard access** — tests that read clipboard require browser permissions in `playwright.config.ts`.
- **Fixtures** — no real secrets; use placeholders like `fixture-secret-<uniqueId>`.

## Checklist
- [ ] Test uses `getModuleFrame(page)` for any assertion inside the module iframe
- [ ] `waitForModuleContent(page)` called after navigation
- [ ] No TYPO3 error page shown: assert absence of "Oops, an error occurred" / "503"
- [ ] Unique identifiers for isolation (no shared state across tests)
- [ ] No `waitForTimeout` — use explicit `waitFor`
- [ ] Accessibility added when a new UI surface is introduced
- [ ] Cleanup: tests delete the records they create

## Examples
### TYPO3 v14 iframe-aware test
```typescript
import { test, expect, getModuleFrame, waitForModuleContent } from '../fixtures/auth';

test('Secrets list renders', async ({ authenticatedPage: page }) => {
  await page.goto('/typo3/module/admin/vault/secrets');
  await waitForModuleContent(page);

  const frame = getModuleFrame(page);
  await expect(frame.locator('h1')).toContainText('Secrets');
  await expect(page.locator('text=Oops, an error occurred')).not.toBeVisible();
});
```

### Module URLs
| Module | URL |
|--------|-----|
| Overview | `/typo3/module/admin/vault` |
| Secrets list | `/typo3/module/admin/vault/secrets` |
| Create secret | `/typo3/module/admin/vault/secrets/create` |
| Audit log | `/typo3/module/admin/vault/audit` |

## When Stuck
| Issue | Resolution |
|-------|------------|
| Element not found | Content lives in iframe — use `getModuleFrame(page)` |
| Timeout waiting | Call `waitForModuleContent(page)` after `goto` |
| Flaky tests | Generate unique identifiers; replace `waitForTimeout` with `waitFor` |
| Auth failures | `make up` to ensure DDEV is running |

- Playwright docs: <https://playwright.dev/docs/intro>
- Invoke skill: `typo3-testing` for PHP-side integration tips
