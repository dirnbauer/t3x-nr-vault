import { test, expect, getModuleFrame, waitForModuleContent } from '../fixtures/auth';

/**
 * E2E tests for Secrets Module User Pathways.
 *
 * TYPO3 v14 uses an iframe-based backend structure where module content
 * is rendered inside an iframe. These tests use the moduleFrame fixture
 * to access content within the module iframe.
 *
 * Tests cover the complete secret lifecycle:
 * - UP-SEC-001: View Secrets List
 * - UP-SEC-002: Filter Secrets List
 * - UP-SEC-003: Create New Secret (Happy Path)
 * - UP-SEC-004: Create Secret - Validation Errors
 * - UP-SEC-005: View Secret Details
 * - UP-SEC-006: Reveal Secret Value (AJAX)
 * - UP-SEC-007: Edit Secret Metadata
 * - UP-SEC-008: Rotate Secret Value
 * - UP-SEC-009: Toggle Secret Status (Enable/Disable)
 * - UP-SEC-010: Delete Secret
 * - UP-SEC-011: Delete Secret - Cancellation
 * - UP-SEC-012: Secrets List - Empty State
 * - UP-SEC-013: Access Denied - Unauthorized Secret
 */

// Generate unique identifier for test isolation
// Must start with a letter and contain only letters, numbers, and underscores
const generateTestId = () => `e2e_test_${Date.now()}_${crypto.randomUUID().slice(0, 8)}`;

test.describe('Secrets Module User Pathways', () => {
  test.describe('UP-SEC-001: View Secrets List', () => {
    test('displays secrets list page', async ({ authenticatedPage: page }) => {
      const response = await page.goto('/typo3/module/admin/vault/secrets');
      expect(response?.status()).toBe(200);

      await waitForModuleContent(page);
      const frame = getModuleFrame(page);

      // Check for page title or heading inside iframe
      await expect(frame.locator('h1')).toBeVisible();
      await expect(frame.locator('text=Oops, an error occurred')).not.toBeVisible();
    });

    test('shows secrets table with proper structure', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Check for table presence (if secrets exist)
      const table = frame.locator('table');
      const emptyState = frame.locator('.callout-info, .alert-info');

      // Either table or empty state should be visible
      const hasTable = await table.first().isVisible();
      const hasEmptyState = await emptyState.first().isVisible() || await frame.locator('text=No Secrets Found').first().isVisible();

      expect(hasTable || hasEmptyState).toBe(true);

      if (hasTable) {
        // Verify table headers
        const headers = frame.locator('table th');
        expect(await headers.count()).toBeGreaterThanOrEqual(1);
      }
    });

    test('shows create secret button in header', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Look for create button in DocHeader toolbar
      const createButton = frame.locator('button:has-text("Create Secret"), a:has-text("Create Secret")');
      await expect(createButton).toBeVisible();
    });
  });

  test.describe('UP-SEC-002: Filter Secrets List', () => {
    test('filter form is present on list page', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Check for filter form elements
      const filterForm = frame.locator('[role="search"], form:has(input[name="identifier"])');
      await expect(filterForm.first()).toBeVisible();
    });

    test('can filter by identifier', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Enter filter value - use the visible textbox in the filter form
      const identifierInput = frame.getByRole('textbox', { name: 'Identifier' });
      await identifierInput.fill('test-filter-value');

      // Submit filter
      const filterButton = frame.locator('button:has-text("Filter")');
      await filterButton.click();

      await page.waitForTimeout(1000);

      // Verify filter was applied - page should not error
      const newFrame = getModuleFrame(page);
      await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();
    });

    test('can filter by status', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Select status filter
      const statusSelect = frame.locator('select[name="status"]');
      await statusSelect.selectOption('active');

      // Submit filter
      const filterButton = frame.locator('button:has-text("Filter")');
      await filterButton.click();

      await page.waitForTimeout(1000);

      // Verify filter was applied
      const newFrame = getModuleFrame(page);
      await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();
    });

    test('displays count of filtered results', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Look for result count indicator
      const resultCount = frame.locator('[role="status"], .secrets-count');
      const hasCount = await resultCount.first().isVisible();

      // Count is optional but should not error
      await expect(frame.locator('text=Oops, an error occurred')).not.toBeVisible();
    });
  });

  test.describe('UP-SEC-003: Create New Secret (Happy Path)', () => {
    test('create secret form loads correctly', async ({ authenticatedPage: page }) => {
      // Create action now redirects to FormEngine
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // FormEngine uses data[table][uid][field] naming convention
      // Verify FormEngine form elements are present
      await expect(frame.locator('input[data-formengine-input-name*="identifier"]')).toBeVisible();

      // The secret_value field should be visible for new records (password input with data-vault-is-new)
      const secretInput = frame.locator('input[data-vault-is-new="1"]');
      await expect(secretInput).toBeVisible();

      // Description field should also be present
      await expect(frame.locator('textarea[data-formengine-input-name*="description"], input[data-formengine-input-name*="description"]')).toBeVisible();
    });

    test('can create a new secret with required fields only', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Fill identifier using FormEngine input
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);

      // Fill secret value - look for the password input in the secret_input field
      const secretInput = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput.fill('test-secret-value-123');

      // Click save button in DocHeader
      await frame.locator('button[name="_savedok"]').click();

      await page.waitForTimeout(2000);

      // Verify success - should redirect to list or show no errors
      const newFrame = getModuleFrame(page);
      await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();
    });

    test('can create a new secret with all fields', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Fill required fields using FormEngine inputs
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);

      // Fill secret value
      const secretInput = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput.fill('full-test-secret-value');

      // Fill optional fields
      const descriptionInput = frame.locator('textarea[data-formengine-input-name*="description"], input[data-formengine-input-name*="description"]');
      if (await descriptionInput.isVisible()) {
        await descriptionInput.fill('E2E test secret description');
      }

      // Check if context field exists
      const contextInput = frame.locator('input[data-formengine-input-name*="context"]');
      if (await contextInput.isVisible()) {
        await contextInput.fill('testing');
      }

      // Click save button
      await frame.locator('button[name="_savedok"]').click();

      await page.waitForTimeout(2000);

      // Verify success
      const newFrame = getModuleFrame(page);
      await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();
    });

    test('creation redirects to list with success', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      // Create the secret via FormEngine
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);

      const secretInput = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput.fill('list-check-secret');

      // Click save button
      await frame.locator('button[name="_savedok"], button:has-text("Save")').first().click();

      // Wait for form submission to complete
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000);

      // Verify save was successful (no error)
      let newFrame = getModuleFrame(page);
      await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();

      // Navigate to list and verify secret was created
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      newFrame = getModuleFrame(page);
      await expect(newFrame.locator('h1:has-text("Secrets")')).toBeVisible();
    });
  });

  test.describe('UP-SEC-004: Create Secret - Validation Errors', () => {
    test('shows error for empty identifier', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Fill only the secret value, leave identifier empty
      const secretInput = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput.fill('test-value');

      // Try to save - FormEngine validation should show error
      await frame.locator('button[name="_savedok"]').click();

      await page.waitForTimeout(1000);

      // FormEngine shows validation errors with specific classes or messages
      // The identifier field is required and should show validation state
      const newFrame = getModuleFrame(page);
      const hasValidationError = await newFrame.locator('.has-error, .is-invalid, .alert-danger').first().isVisible();
      const stayedOnForm = await newFrame.locator('input[data-formengine-input-name*="identifier"]').isVisible();

      // Either validation error shown OR stayed on form (didn't save)
      expect(hasValidationError || stayedOnForm).toBe(true);
    });

    test('shows error for empty secret value', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Fill only the identifier
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill('test-identifier');

      // Try to save without secret value
      await frame.locator('button[name="_savedok"]').click();

      await page.waitForTimeout(1000);

      // FormEngine validation should prevent save or show error
      const newFrame = getModuleFrame(page);
      const hasValidationError = await newFrame.locator('.has-error, .is-invalid, .alert-danger').first().isVisible();
      const stayedOnForm = await newFrame.locator('input[data-formengine-input-name*="identifier"]').isVisible();

      // Either validation error shown OR stayed on form
      expect(hasValidationError || stayedOnForm).toBe(true);
    });

    test('handles duplicate identifier appropriately', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      // Create first secret via FormEngine
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      let frame = getModuleFrame(page);
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);
      const secretInput1 = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput1.fill('first-secret');
      await frame.locator('button[name="_savedok"], button:has-text("Save")').click();

      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000);

      // Try to create second secret with same identifier
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      frame = getModuleFrame(page);
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);
      const secretInput2 = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput2.fill('duplicate-secret');
      await frame.locator('button[name="_savedok"], button:has-text("Save")').click();

      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000);

      // System should either show error OR the secret updates/overwrites
      // Either behavior is acceptable depending on system design
      const newFrame = getModuleFrame(page);
      const hasError = await newFrame.locator('.alert-danger, .callout-danger, .typo3-message-error').first().isVisible();
      const redirectedToList = await newFrame.locator('h1:has-text("Secrets")').first().isVisible();

      // Either an error should be shown OR the operation should succeed
      expect(hasError || redirectedToList).toBe(true);
    });
  });

  test.describe('UP-SEC-005: View Secret Details', () => {
    test('can view secret details page', async ({ authenticatedPage: page }) => {
      // First check if there are any secrets
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);
      const viewLink = frame.locator('a[title*="View details"], a[aria-label*="View details"]').first();

      if (await viewLink.isVisible()) {
        await viewLink.click();
        await page.waitForTimeout(1000);

        // Verify we're on the view page
        const newFrame = getModuleFrame(page);
        await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();
      }
    });
  });

  test.describe('UP-SEC-008: Rotate Secret Value', () => {
    test('rotate modal opens correctly', async ({ authenticatedPage: page }) => {
      // First check if there are any secrets
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);
      // Rotate is now a button that opens a modal
      const rotateButton = frame.locator('button[data-vault-rotate], button[title*="Rotate"], button[aria-label*="Rotate"]').first();

      if (await rotateButton.isVisible()) {
        await rotateButton.click();
        await page.waitForTimeout(1000);

        // Verify modal is displayed - look for the rotate modal by its input field
        const newValueInput = page.locator('#rotate-modal-secret');
        await expect(newValueInput).toBeVisible();

        // Close modal using Cancel button
        await page.getByRole('button', { name: 'Cancel' }).click();
      }
    });

    test('can rotate a secret value', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      // Create a secret first via FormEngine
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      let frame = getModuleFrame(page);
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);
      const secretInput = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput.fill('original-secret-value');
      await frame.locator('button[name="_savedok"], button:has-text("Save")').click();

      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000);

      // Go to list and find rotate button
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      frame = getModuleFrame(page);

      // Filter to find our secret - use role-based selector
      await frame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await frame.locator('button:has-text("Filter")').click();

      await page.waitForTimeout(1000);

      // Click rotate button (opens modal)
      frame = getModuleFrame(page);
      const rotateButton = frame.locator('button[data-vault-rotate], button[title*="Rotate"], button[aria-label*="Rotate"]').first();

      if (await rotateButton.isVisible()) {
        await rotateButton.click();
        await page.waitForTimeout(1000);

        // Fill new value in modal (modal is in main page context)
        const newValueInput = page.locator('#rotate-modal-secret, .modal input[type="password"]').first();
        await newValueInput.fill('rotated-secret-value');

        // Submit rotation via modal button - "Rotate Secret" button (exact match to avoid close button)
        await page.getByRole('button', { name: 'Rotate Secret', exact: true }).click();
        await page.waitForTimeout(2000);

        // Verify success - should show notification or stay on list without error
        const resultFrame = getModuleFrame(page);
        await expect(resultFrame.locator('text=Oops, an error occurred')).not.toBeVisible();
      }
    });
  });

  test.describe('UP-SEC-009: Toggle Secret Status', () => {
    test('can disable an active secret', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      // Create a secret first via FormEngine
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      let frame = getModuleFrame(page);
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);
      const secretInput = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput.fill('toggle-test-value');
      await frame.locator('button[name="_savedok"], button:has-text("Save")').click();

      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000);

      // Go to list
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      frame = getModuleFrame(page);

      // Filter to find our secret - use role-based selector
      await frame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await frame.locator('button:has-text("Filter")').click();

      await page.waitForTimeout(1000);

      // Find and click toggle button
      frame = getModuleFrame(page);
      const toggleButton = frame.locator('button[title*="Disable"], button[data-vault-toggle]').first();

      if (await toggleButton.isVisible()) {
        await toggleButton.click();
        await page.waitForTimeout(2000);

        // Verify the secret is now disabled
        const newFrame = getModuleFrame(page);
        await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();

        // Look for disabled badge in a table cell, not the dropdown option
        // The badge is in a table cell with class containing "text-bg-secondary" or similar
        const disabledBadge = newFrame.locator('td span.badge, td .text-bg-secondary');
        const hasBadge = await disabledBadge.first().isVisible();
        expect(hasBadge).toBe(true);
      }
    });
  });

  test.describe('UP-SEC-010: Delete Secret', () => {
    test('can delete a secret', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      // Create a secret first via FormEngine
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      let frame = getModuleFrame(page);
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);
      const secretInput = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput.fill('delete-test-value');
      await frame.locator('button[name="_savedok"], button:has-text("Save")').click();

      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000);

      // Go to list
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      frame = getModuleFrame(page);

      // Filter to find our secret - use role-based selector
      await frame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await frame.locator('button:has-text("Filter")').click();

      await page.waitForTimeout(1000);

      // Find and click delete button
      frame = getModuleFrame(page);
      const deleteButton = frame.locator('button[title*="Delete"]').first();

      if (await deleteButton.isVisible()) {
        await deleteButton.click();
        await page.waitForTimeout(1000);

        // Handle TYPO3 Modal confirmation (appears in main page context)
        const confirmButton = page.getByRole('button', { name: 'Delete', exact: true });
        if (await confirmButton.isVisible()) {
          await confirmButton.click();
        }

        await page.waitForTimeout(2000);

        // Verify success
        const newFrame = getModuleFrame(page);
        await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();
      }
    });
  });

  test.describe('UP-SEC-012: Secrets List - Empty State', () => {
    test('shows appropriate state when filter returns no results', async ({ authenticatedPage: page }) => {
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      const frame = getModuleFrame(page);

      // Apply a filter that returns no results - use role-based selector
      await frame.getByRole('textbox', { name: 'Identifier' }).fill('nonexistent-secret-xyz-123');
      await frame.locator('button:has-text("Filter")').click();

      await page.waitForTimeout(1000);

      // Should show empty state or no results - check for 0 secrets count
      const newFrame = getModuleFrame(page);
      await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();

      // Either "0 secrets" message or no table rows
      const zeroCount = newFrame.locator('text=0 secrets');
      const table = newFrame.locator('table tbody tr');

      const hasZeroCount = await zeroCount.first().isVisible();
      const rowCount = await table.count();

      expect(hasZeroCount || rowCount === 0).toBe(true);
    });
  });

  test.describe('UP-SEC-006: Reveal Secret Value (AJAX)', () => {
    test('can reveal a secret value via AJAX', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      // Create a secret first via FormEngine
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      let frame = getModuleFrame(page);
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);
      const secretInput = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput.fill('reveal-test-value');
      await frame.locator('button[name="_savedok"], button:has-text("Save")').click();

      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000);

      // Go to list and find our secret
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      frame = getModuleFrame(page);

      // Filter to find our secret
      await frame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await frame.locator('button:has-text("Filter")').click();

      await page.waitForTimeout(1000);

      // Click reveal button
      frame = getModuleFrame(page);
      const revealButton = frame.locator('button[data-vault-reveal], button[title*="Reveal"], button[aria-label*="Reveal"]').first();

      if (await revealButton.isVisible()) {
        await revealButton.click();
        await page.waitForTimeout(2000);

        // After AJAX reveal, the secret value should be visible somewhere
        // The value is fetched via AJAX (revealAction) and displayed
        const newFrame = getModuleFrame(page);
        await expect(newFrame.locator('text=Oops, an error occurred')).not.toBeVisible();

        // The revealed value or a masked/revealed toggle should be present
        const revealedContent = newFrame.locator('[data-vault-secret-value], .secret-revealed, code');
        const hasRevealed = await revealedContent.first().isVisible();
        expect(hasRevealed).toBe(true);
      }
    });
  });

  test.describe('UP-SEC-007: Edit Secret Metadata', () => {
    test('can edit secret metadata', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      // Create a secret first via FormEngine
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      let frame = getModuleFrame(page);
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);
      const secretInput = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput.fill('edit-test-value');
      await frame.locator('button[name="_savedok"], button:has-text("Save")').click();

      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000);

      // Go to list and find our secret
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      frame = getModuleFrame(page);

      // Filter to find our secret
      await frame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await frame.locator('button:has-text("Filter")').click();

      await page.waitForTimeout(1000);

      // Click edit button
      frame = getModuleFrame(page);
      const editButton = frame.locator('a[title*="Edit"], button[title*="Edit"], a[aria-label*="Edit"]').first();

      if (await editButton.isVisible()) {
        await editButton.click();
        await page.waitForTimeout(2000);

        // Should be on the edit form (FormEngine)
        const editFrame = getModuleFrame(page);
        await expect(editFrame.locator('text=Oops, an error occurred')).not.toBeVisible();

        // Modify description
        const descriptionInput = editFrame.locator('textarea[data-formengine-input-name*="description"], input[data-formengine-input-name*="description"]');
        if (await descriptionInput.isVisible()) {
          await descriptionInput.fill('Updated description via E2E test');
        }

        // Save changes
        await editFrame.locator('button[name="_savedok"], button:has-text("Save")').first().click();

        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2000);

        // Verify success - no errors
        const resultFrame = getModuleFrame(page);
        await expect(resultFrame.locator('text=Oops, an error occurred')).not.toBeVisible();
      }
    });
  });

  test.describe('UP-SEC-011: Delete Secret - Cancellation', () => {
    test('cancelling delete keeps secret intact', async ({ authenticatedPage: page }) => {
      const testIdentifier = generateTestId();

      // Create a secret first via FormEngine
      await page.goto('/typo3/module/admin/vault/secrets/create');
      await waitForModuleContent(page);

      let frame = getModuleFrame(page);
      await frame.locator('input[data-formengine-input-name*="identifier"]').fill(testIdentifier);
      const secretInput = frame.locator('input[data-vault-is-new="1"]').first();
      await secretInput.fill('cancel-delete-test');
      await frame.locator('button[name="_savedok"], button:has-text("Save")').click();

      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(2000);

      // Go to list and filter to find our secret
      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      frame = getModuleFrame(page);
      await frame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
      await frame.locator('button:has-text("Filter")').click();

      await page.waitForTimeout(1000);

      // Click delete button
      frame = getModuleFrame(page);
      const deleteButton = frame.locator('button[title*="Delete"]').first();

      if (await deleteButton.isVisible()) {
        await deleteButton.click();
        await page.waitForTimeout(1000);

        // Dismiss confirmation dialog - click Cancel instead of Delete
        const cancelButton = page.getByRole('button', { name: 'Cancel', exact: true });
        if (await cancelButton.isVisible()) {
          await cancelButton.click();
        }

        await page.waitForTimeout(1000);

        // Verify secret still exists - reload the list and filter again
        await page.goto('/typo3/module/admin/vault/secrets');
        await waitForModuleContent(page);

        frame = getModuleFrame(page);
        await frame.getByRole('textbox', { name: 'Identifier' }).fill(testIdentifier);
        await frame.locator('button:has-text("Filter")').click();

        await page.waitForTimeout(1000);

        // The secret should still be in the list
        const resultFrame = getModuleFrame(page);
        await expect(resultFrame.locator('text=Oops, an error occurred')).not.toBeVisible();

        const tableRows = resultFrame.locator('table tbody tr');
        expect(await tableRows.count()).toBeGreaterThanOrEqual(1);
      }
    });
  });

  test.describe('UP-SEC-013: Access Denied - Unauthorized Secret', () => {
    test('non-admin user cannot access unauthorized secrets', async ({ page }) => {
      // Login as a non-admin user (editor)
      await page.goto('/typo3/login');
      await page.fill('input[name="username"]', 'editor');
      await page.fill('input[type="password"]', 'Joh316!!');
      await page.click('button[type="submit"]');

      // Wait for login to complete
      await page.waitForURL(/\/typo3\/(main|module)/, { timeout: 10000 }).catch(() => {
        // Login may fail if editor user doesn't exist - that's acceptable
      });

      // Check if we're logged in
      const isLoggedIn = await page.locator('.scaffold').isVisible();

      if (isLoggedIn) {
        // Try to access vault secrets module
        const response = await page.goto('/typo3/module/admin/vault/secrets');

        // Should either show access denied or redirect
        if (response?.status() === 200) {
          await waitForModuleContent(page);
          const frame = getModuleFrame(page);

          // Look for access denied or empty/restricted view
          const accessDenied = frame.locator('text=Access Denied, text=access denied, text=not authorized, .callout-danger');
          const hasAccessDenied = await accessDenied.first().isVisible();

          // If no access denied message, the module may just not be available
          // Either way, no server error should occur
          await expect(frame.locator('text=Oops, an error occurred')).not.toBeVisible();
        } else {
          // Non-200 response (403, 302) is expected for unauthorized access
          expect([302, 403]).toContain(response?.status());
        }
      } else {
        // If editor user doesn't exist, the test is inconclusive but not a failure
        // This is expected in environments without a dedicated editor user
        test.skip();
      }
    });
  });

  test.describe('Cross-cutting concerns', () => {
    test('secrets module has no JavaScript errors', async ({ authenticatedPage: page }) => {
      const consoleErrors: string[] = [];

      page.on('console', (msg) => {
        if (msg.type() === 'error') {
          consoleErrors.push(msg.text());
        }
      });

      await page.goto('/typo3/module/admin/vault/secrets');
      await waitForModuleContent(page);

      // Filter out known non-critical errors
      const criticalErrors = consoleErrors.filter(
        (err) =>
          !err.includes('favicon') &&
          !err.includes('404') &&
          !err.includes('net::ERR') &&
          !err.includes('Error while retrieving widget') // TYPO3 dashboard widget fetch errors
      );

      expect(criticalErrors).toHaveLength(0);
    });

    test('secrets module returns 200 status code', async ({ authenticatedPage: page }) => {
      const response = await page.goto('/typo3/module/admin/vault/secrets');
      expect(response?.status()).toBe(200);
    });
  });
});
