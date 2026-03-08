import { test, expect } from '@playwright/test';

test.describe('Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('navbar shows Accueil link', async ({ page }) => {
    await expect(page.getByRole('link', { name: 'Accueil' })).toBeVisible();
  });

  test('navbar shows Jouer link', async ({ page }) => {
    await expect(page.getByRole('link', { name: 'Jouer' })).toBeVisible();
  });

  test('navbar shows Classement link', async ({ page }) => {
    await expect(page.getByRole('link', { name: 'Classement' })).toBeVisible();
  });

  test('clicking Classement link navigates to /leaderboard', async ({ page }) => {
    await page.getByRole('link', { name: 'Classement' }).click();
    await expect(page).toHaveURL(/\/leaderboard/);
  });

  test('clicking Accueil link navigates to /', async ({ page }) => {
    await page.goto('/leaderboard');
    await page.getByRole('link', { name: 'Accueil' }).click();
    await expect(page).toHaveURL(/\/$/);
  });
});
