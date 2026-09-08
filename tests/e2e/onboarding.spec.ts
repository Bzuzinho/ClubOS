import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test.describe('onboarding público', () => {
    test('explica instalação opcional e acesso permanente pelo browser', async ({ page }) => {
        await page.goto('/instalar');

        await expect(page).toHaveTitle(/Instalar BSCN/i);
        await expect(page.getByRole('heading', { name: /Aceder ao/i })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Continuar para a minha área' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Também funciona sempre no browser' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'bscn.pt/login' })).toBeVisible();

        const hasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        expect(hasHorizontalOverflow).toBe(false);
    });

    test('não tem violações graves de acessibilidade', async ({ page }) => {
        await page.goto('/instalar');

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();

        expect(results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact ?? ''))).toEqual([]);
    });
});
