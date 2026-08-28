import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test, TestInfo } from '@playwright/test';

const PASSWORD = 'ClubOS-E2E-2026!';

const CORE_NAVIGATION = [
    { label: 'Membros', path: '/membros' },
    { label: 'Desportivo', path: '/desportivo' },
    { label: 'Eventos', path: '/eventos' },
    { label: 'Financeiro', path: '/financeiro' },
    { label: 'Configurações', path: '/configuracoes' },
] as const;

const emailForProject = (testInfo: TestInfo): string =>
    `e2e.${testInfo.project.name}@clubos.test`;

const pathPattern = (path: string): RegExp =>
    new RegExp(`${path.replaceAll('/', '\\/')}$`);

const login = async (page: Page, testInfo: TestInfo, intendedPath = '/dashboard') => {
    await page.goto(intendedPath);
    await expect(page).toHaveURL(/\/login$/);

    await page.getByLabel('Email').fill(emailForProject(testInfo));
    await page.getByLabel('Palavra-passe').fill(PASSWORD);
    await page.getByRole('button', { name: 'Entrar' }).click();

    await expect(page).toHaveURL(pathPattern(intendedPath));
};

const openSidebarIfNeeded = async (page: Page, testInfo: TestInfo) => {
    if (!testInfo.project.name.includes('mobile')) {
        return;
    }

    const openButton = page.getByRole('button', { name: 'Abrir menu lateral' });
    await expect(openButton).toBeVisible();
    await openButton.click();
};

const expectNoHorizontalOverflow = async (page: Page) => {
    const hasHorizontalOverflow = await page.evaluate(() => {
        const root = document.documentElement;
        return root.scrollWidth > root.clientWidth + 1;
    });

    expect(hasHorizontalOverflow).toBe(false);
};

test.describe('authenticated access', () => {
    test('protects the dashboard and returns to the intended route after a valid login', async ({ page }, testInfo) => {
        await login(page, testInfo);

        await expect(page.locator('body')).toContainText('Browser QA');
        await expectNoHorizontalOverflow(page);
    });

    test('rejects invalid credentials without creating an authenticated session', async ({ page }, testInfo) => {
        await page.goto('/login');
        await page.getByLabel('Email').fill(emailForProject(testInfo));
        await page.getByLabel('Palavra-passe').fill('invalid-password');
        await page.getByRole('button', { name: 'Entrar' }).click();

        await expect(page).toHaveURL(/\/login$/);

        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login$/);
    });

    test('logs out through the authenticated navigation and protects the session afterwards', async ({ page }, testInfo) => {
        await login(page, testInfo);
        await openSidebarIfNeeded(page, testInfo);

        const logoutButton = page.getByRole('button', { name: 'Sair' });
        await expect(logoutButton).toBeVisible();
        await logoutButton.click();

        await expect(page).toHaveURL(/\/$/);

        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login$/);
    });

    test('requests a password reset for the browser-specific fixture', async ({ page }, testInfo) => {
        await page.goto('/login');
        await page.getByRole('link', { name: 'Esqueceu a palavra-passe?' }).click();

        await expect(page).toHaveURL(/\/forgot-password$/);
        await page.locator('#email').fill(emailForProject(testInfo));
        await page.getByRole('button', { name: 'Email Password Reset Link' }).click();

        await expect(page.getByText('We have emailed your password reset link.', { exact: true })).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });

    for (const navigation of CORE_NAVIGATION) {
        test(`navigates to ${navigation.label} from the authenticated menu`, async ({ page }, testInfo) => {
            await login(page, testInfo);
            await openSidebarIfNeeded(page, testInfo);

            const navigationButton = page.getByRole('button', { name: navigation.label, exact: true });
            await expect(navigationButton).toBeVisible();
            await navigationButton.click();

            await expect(page).toHaveURL(pathPattern(navigation.path));
            await expect(page.locator('main')).toBeVisible();
            await expect(page.locator('body')).not.toContainText('Server Error');
            await expectNoHorizontalOverflow(page);
        });
    }

    test('has no serious or critical WCAG A/AA violations on the authenticated dashboard', async ({ page }, testInfo) => {
        await login(page, testInfo);

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();

        const blockingViolations = results.violations.filter(
            (violation) => violation.impact === 'serious' || violation.impact === 'critical',
        );

        expect(blockingViolations).toEqual([]);
    });
});
