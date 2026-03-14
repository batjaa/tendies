import { test, expect } from '@playwright/test';

test.describe('Waitlist signup', () => {
  test('shows waitlist section when waitlist mode is enabled', async ({
    page,
  }) => {
    await page.goto('/');
    const section = page.locator('#waitlist');
    await expect(section).toBeVisible();
    await expect(
      section.getByText('Get early access to Tendies Pro.'),
    ).toBeVisible();
    await expect(section.locator('#waitlist-form')).toBeVisible();
  });

  test('submits email and shows success state', async ({ page }) => {
    await page.goto('/');
    const section = page.locator('#waitlist');

    await section
      .locator('input[name="email"]')
      .fill('e2e-test@example.com');
    await section.locator('#waitlist-btn').click();

    // Form should disappear, success message should show
    await expect(section.locator('#waitlist-form-wrapper')).toBeHidden();
    await expect(section.locator('#waitlist-success')).toBeVisible();
    await expect(section.getByText("You're on the list!")).toBeVisible();
  });

  test('shows error for duplicate email', async ({ page }) => {
    await page.goto('/');
    const section = page.locator('#waitlist');

    // Submit same email again (already signed up in previous test)
    await section
      .locator('input[name="email"]')
      .fill('e2e-test@example.com');
    await section.locator('#waitlist-btn').click();

    // Error should appear, form should remain visible
    await expect(section.locator('#waitlist-error')).toBeVisible();
    await expect(section.locator('#waitlist-form')).toBeVisible();
  });

  test('shows validation error for invalid email', async ({ page }) => {
    await page.goto('/');
    const section = page.locator('#waitlist');

    await section.locator('input[name="email"]').fill('not-an-email');
    await section.locator('#waitlist-btn').click();

    // Browser validation should prevent submission (required + type=email)
    // The form should still be visible
    await expect(section.locator('#waitlist-form')).toBeVisible();
    await expect(section.locator('#waitlist-success')).toBeHidden();
  });

  test('hides pricing section when waitlist mode is on', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#pricing')).toHaveCount(0);
  });

  test('waitlist counter is visible', async ({ page }) => {
    await page.goto('/');
    const section = page.locator('#waitlist');
    await expect(section.getByText('on the waitlist')).toBeVisible();
  });
});
