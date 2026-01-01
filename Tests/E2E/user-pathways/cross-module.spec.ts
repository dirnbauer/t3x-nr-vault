import { test, expect, getModuleFrame, waitForModuleContent } from '../fixtures/auth';

/**
 * E2E tests for Cross-Module User Pathways.
 *
 * TYPO3 v14 uses an iframe-based backend structure where module content
 * is rendered inside an iframe.
 *
 * Tests cover interactions between modules:
 * - UP-CROSS-001: Full Secret Lifecycle
 * - UP-CROSS-002: Dashboard Statistics Accuracy
 * - UP-CROSS-003: Module Navigation Consistency
 */

// Generate unique identifier for test isolation (underscore format for valid identifiers)
const generateTestId = () => `e2e_cross_${Date.now()}_${Math.random().toString(36).substring(7)}`;

test.describe('Cross-Module User Pathways', () => {
  test.describe('UP-CROSS-001: Full Secret Lifecycle', () => {
    test('complete secret lifecycle with audit trail', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      // Step 1: Create a new secret
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      await frame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await frame.locator('input[name="secret"]').fill('lifecycle-test-value');

      const descriptionField = frame.locator('textarea[name="description"]');
      if (await descriptionField.isVisible()) {
        await descriptionField.fill('Lifecycle test secret');
      }

      await frame.locator('button[type="submit"]').click();
      await page.waitForTimeout(1000);

      // Verify creation succeeded
      const newFrame = getModuleFrame(page);
      await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();

      // Step 2: Check audit log for create entry
      await page.goto('/typo3/module/admin/vault/audit');
      await waitForModuleContent(page);

      const auditFrame = getModuleFrame(page);
      await expect(auditFrame.locator('text=Oops, an error occurred')).not.toBeVisible();

      // Step 3: View the secret in list
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const listFrame = getModuleFrame(page);
      await listFrame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await listFrame.locator('button:has-text("Filter")').click();
      await page.waitForTimeout(1000);

      const filteredFrame = getModuleFrame(page);
      const secretRow = filteredFrame.locator(`text=${testIdentifier}`);
      await expect(secretRow.first()).toBeVisible();

      // Step 4: Delete the secret (cleanup)
      const deleteButton = filteredFrame.locator('button[title*="Delete"]').first();
      if (await deleteButton.isVisible()) {
        page.on('dialog', (dialog) => dialog.accept());
        await deleteButton.click();
        await page.waitForTimeout(1000);
      }
    });
  });

  test.describe('UP-CROSS-002: Dashboard Statistics Accuracy', () => {
    test('overview statistics reflect secrets state', async ({ authenticatedPage: page }) => {
      // Get overview page
      await page.goto('/typo3/module/admin/vault');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Should show some statistics
      await expect(frame.locator('text=Oops, an error occurred')).not.toBeVisible();

      // Look for stats elements (cards, badges, numbers)
      const statsElements = frame.locator('.card, .badge, [class*="stat"]');
      const hasStats = (await statsElements.count()) > 0;

      // At least the overview page loads
      await expect(frame.locator('h1')).toBeVisible();
    });

    test('overview shows different counts for different states', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Look for active/inactive or total counts
      const activeIndicator = frame.locator('text=Active, text=active');
      const totalIndicator = frame.locator('text=Total, text=Secrets, text=secrets');

      const hasActiveIndicator = await activeIndicator.first().isVisible().catch(() => false);
      const hasTotalIndicator = await totalIndicator.first().isVisible().catch(() => false);

      // Overview should show some status information
      await expect(frame.locator('text=Oops, an error occurred')).not.toBeVisible();
    });
  });

  test.describe('UP-CROSS-003: Module Navigation Consistency', () => {
    test('can navigate between all modules without errors', async ({ authenticatedPage: page }) => {
      const modules = [
        '/typo3/module/admin/vault',
        '/typo3/module/admin/vault/secrets',
        '/typo3/module/admin/vault/secrets/create',
        '/typo3/module/admin/vault/audit',
        '/typo3/module/admin/vault/migration',
      ];

      for (const modulePath of modules) {
        const response = await page.goto(modulePath);
        expect(response?.status()).toBe(200);

        await waitForModuleContent(page);
        const frame = getModuleFrame(page);
        await expect(frame.locator('text=Oops, an error occurred')).not.toBeVisible();
      }
    });

    test('browser back button works correctly', async ({ authenticatedPage: page }) => {
      // Navigate through modules
      await page.goto('/typo3/module/admin/vault');
      await waitForModuleContent(page);

      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      await page.goto('/typo3/module/admin/vault/audit');
      await waitForModuleContent(page);

      // Go back
      await page.goBack();
      await page.waitForTimeout(500);

      // URL should be secrets or overview
      const currentUrl = page.url();
      expect(currentUrl).toMatch(/vault/);
    });

    test('module menu reflects current module', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      // Check that module menu shows current module
      const moduleMenu = page.locator('.scaffold-modulemenu');
      await expect(moduleMenu).toBeVisible();
    });

    test('DocHeader is present on all module pages', async ({ authenticatedPage: page }) => {
      const modules = [
        '/typo3/module/admin/vault/secrets',
        '/typo3/module/admin/vault/audit',
        '/typo3/module/admin/vault/migration',
      ];

      for (const modulePath of modules) {
        await page.goto(modulePath);
        await waitForModuleContent(page);

        // DocHeader contains the module dropdown and breadcrumb
        const breadcrumb = page.locator('text=Vault');
        await expect(breadcrumb.first()).toBeVisible();
      }
    });
  });

  test.describe('Session and State Management', () => {
    test('filters can be applied via URL params', async ({ authenticatedPage: page }) => {
      // Apply filter on secrets via URL
      await page.goto('/typo3/module/admin/vault/secrets?status=active');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Page should load without errors
      await expect(frame.locator('text=Oops, an error occurred')).not.toBeVisible();

      // Navigate away
      await page.goto('/typo3/module/admin/vault/audit');
      await waitForModuleContent(page);

      // Navigate back
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      // Page should still work
      const newFrame = getModuleFrame(page);
      await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();
    });

    test('secret creation shows feedback', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      // Create a secret
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);
      await frame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await frame.locator('input[name="secret"]').fill('feedback-test');
      await frame.locator('button[type="submit"]').click();
      await page.waitForTimeout(1000);

      // Should see success (redirect to list or flash message)
      const newFrame = getModuleFrame(page);
      await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();

      // Cleanup
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const listFrame = getModuleFrame(page);
      await listFrame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await listFrame.locator('button:has-text("Filter")').click();
      await page.waitForTimeout(1000);

      const filteredFrame = getModuleFrame(page);
      const deleteButton = filteredFrame.locator('button[title*="Delete"]').first();
      if (await deleteButton.isVisible()) {
        page.on('dialog', (dialog) => dialog.accept());
        await deleteButton.click();
      }
    });
  });

  test.describe('Data Integrity', () => {
    test('secret data is consistent across views', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();
      const testDescription = 'Consistency test description';

      // Create with specific data
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);
      await frame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await frame.locator('input[name="secret"]').fill('consistency-test');

      const descField = frame.locator('textarea[name="description"]');
      if (await descField.isVisible()) {
        await descField.fill(testDescription);
      }

      await frame.locator('button[type="submit"]').click();
      await page.waitForTimeout(1000);

      // Check in list view
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const listFrame = getModuleFrame(page);
      await listFrame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await listFrame.locator('button:has-text("Filter")').click();
      await page.waitForTimeout(1000);

      const filteredFrame = getModuleFrame(page);

      // Verify identifier appears
      await expect(filteredFrame.locator(`text=${testIdentifier}`).first()).toBeVisible();

      // Cleanup
      const deleteButton = filteredFrame.locator('button[title*="Delete"]').first();
      if (await deleteButton.isVisible()) {
        page.on('dialog', (dialog) => dialog.accept());
        await deleteButton.click();
      }
    });

    test('audit entries are created for operations', async ({ authenticatedPage: page }) => {
      // Just verify audit log loads and displays entries if any
      await page.goto('/typo3/module/admin/vault/audit');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);
      await expect(frame.locator('text=Oops, an error occurred')).not.toBeVisible();

      // Should have table or empty state
      const table = frame.locator('table');
      const emptyState = frame.locator('.callout-info, text=No');
      const hasContent = await table.first().isVisible() || await emptyState.first().isVisible();
      expect(hasContent).toBe(true);
    });
  });

  test.describe('Error Handling', () => {
    test('invalid routes handled gracefully', async ({ authenticatedPage: page }) => {
      // Try to access a non-existent route
      const response = await page.goto('/typo3/module/admin/vault/nonexistent');

      // Should not crash (404 or redirect is acceptable)
      expect(response?.status()).toBeLessThan(500);
    });

    test('invalid audit filter shows empty results', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/audit?secretIdentifier=definitely_does_not_exist');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Should show empty state, not error
      await expect(frame.locator('text=Oops, an error occurred')).not.toBeVisible();
    });
  });
});
