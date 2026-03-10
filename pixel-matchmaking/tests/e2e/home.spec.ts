import { test, expect } from '@playwright/test';

test.describe('Home page (matchmaking hub)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('shows the main heading', async ({ page }) => {
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  });

  test('shows the search button', async ({ page }) => {
    await expect(page.getByRole('button', { name: /rechercher un match/i })).toBeVisible();
  });

  test('shows the ongoing matches section', async ({ page }) => {
    await expect(page.getByText(/matchs en cours/i)).toBeVisible();
  });

  test('shows mock match cards', async ({ page }) => {
    await expect(page.getByText('Stadium A1')).toBeVisible();
    await expect(page.getByText('Canyon Rush')).toBeVisible();
    await expect(page.getByText('Valley Core')).toBeVisible();
  });

  test('unauthenticated: search button redirects to signin', async ({ page }) => {
    await page.getByRole('button', { name: /rechercher un match/i }).click();
    await expect(page).toHaveURL(/\/auth\/signin/);
  });
});
