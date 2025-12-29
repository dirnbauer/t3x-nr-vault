import { test as base, expect, Page } from '@playwright/test';

/**
 * Extended test fixture with TYPO3 backend authentication.
 */
export const test = base.extend<{ authenticatedPage: Page }>({
  authenticatedPage: async ({ page }, use) => {
    // Login to TYPO3 backend
    await page.goto('/typo3/login');

    // Fill login form
    await page.fill('input[name="username"]', 'admin');
    // TYPO3 uses a visible password field with type="password"
    await page.fill('input[type="password"]', 'Joh316!!');

    // Submit login
    await page.click('button[type="submit"]');

    // Wait for redirect to backend
    await page.waitForURL(/\/typo3\/(main|module)/);

    // Verify we're logged in
    await expect(page.locator('.scaffold')).toBeVisible();

    await use(page);
  },
});

export { expect };
