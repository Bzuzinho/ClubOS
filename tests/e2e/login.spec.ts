import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test.describe('login shell', () => {
    test('is usable without horizontal overflow across the viewport matrix', async ({ page }) => {
        await page.goto('/login');

        await expect(page).toHaveTitle(/Login/i);
        await expect(page.getByLabel('Email')).toBeVisible();
        await expect(page.getByLabel('Palavra-passe')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible();

        const hasHorizontalOverflow = await page.evaluate(() => {
            const root = document.documentElement;
            return root.scrollWidth > root.clientWidth + 1;
        });

        expect(hasHorizontalOverflow).toBe(false);
    });

    test('has no serious or critical WCAG A/AA violations', async ({ page }) => {
        await page.goto('/login');

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();

        const blockingViolations = results.violations.filter(
            (violation) => violation.impact === 'serious' || violation.impact === 'critical',
        );

        expect(blockingViolations).toEqual([]);
    });
});
