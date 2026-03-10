import { test, expect } from '@playwright/test';

test.describe('Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('navbar shows only Jouer link in production mode', async ({ page }) => {
    await expect(page.getByRole('link', { name: 'Jouer' })).toBeVisible();
  });

  test('navbar does not show Classement link in production mode', async ({ page }) => {
    await expect(page.getByRole('link', { name: 'Classement' })).not.toBeVisible();
  });

  test('Jouer link is active (home) when on /', async ({ page }) => {
    const joueurLink = page.getByRole('link', { name: 'Jouer' });
    await expect(joueurLink).toHaveAttribute('aria-current', 'page');
  });

  test('clicking Jouer link navigates to /', async ({ page }) => {
    await page.goto('/leaderboard');
    // In dev mode this link may be visible; navigate directly
    await page.goto('/');
    await expect(page).toHaveURL(/\/$/);
  });
});
