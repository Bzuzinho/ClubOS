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
    await page.getByLabel('Palavra-passe', { exact: true }).fill(PASSWORD);
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
        await page.getByLabel('Palavra-passe', { exact: true }).fill('invalid-password');
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
        await page.getByRole('button', { name: 'Enviar link de recuperação' }).click();

        await expect(page.getByText(/Enviámos um link para recuperar a palavra-passe/)).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });

    for (const navigation of CORE_NAVIGATION) {
        test(`navigates to ${navigation.label} from the authenticated menu`, async ({ page }, testInfo) => {
            await login(page, testInfo);
            await openSidebarIfNeeded(page, testInfo);

            const navigationButton = page
                .getByRole('navigation')
                .getByRole('button', { name: navigation.label, exact: true });
            await expect(navigationButton).toBeVisible();
            await navigationButton.click();

            await expect(page).toHaveURL(pathPattern(navigation.path));
            await expect(page.locator('main')).toBeVisible();
            await expect(page.locator('body')).not.toContainText('Server Error');
            await expectNoHorizontalOverflow(page);
        });
    }

    test('keeps the logistics article form usable inside every viewport', async ({ page }, testInfo) => {
        await login(page, testInfo, '/configuracoes');

        await page.getByRole('tab', { name: 'Logistica', exact: true }).click();
        const addArticleButton = page.getByRole('button', { name: 'Adicionar Artigo' });
        await expect(addArticleButton).toBeVisible();
        await addArticleButton.click();

        const dialog = page.getByTestId('product-dialog');
        const scrollArea = page.getByTestId('product-dialog-scroll-area');
        await expect(dialog).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Identificação' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Guardar', exact: true })).toBeVisible();

        const viewport = page.viewportSize();
        const dialogBox = await dialog.boundingBox();
        expect(viewport).not.toBeNull();
        expect(dialogBox).not.toBeNull();
        expect(dialogBox!.y).toBeGreaterThanOrEqual(0);
        expect(dialogBox!.y + dialogBox!.height).toBeLessThanOrEqual(viewport!.height + 1);

        const overflowY = await scrollArea.evaluate((element) => getComputedStyle(element).overflowY);
        expect(overflowY).toBe('auto');

        const codeBox = await page.getByLabel('Código *').boundingBox();
        const nameBox = await page.getByLabel('Nome *').boundingBox();
        expect(codeBox).not.toBeNull();
        expect(nameBox).not.toBeNull();

        if (viewport!.width < 640) {
            expect(nameBox!.y).toBeGreaterThan(codeBox!.y);
        } else {
            expect(Math.abs(nameBox!.y - codeBox!.y)).toBeLessThanOrEqual(1);
        }

        await scrollArea.evaluate((element) => {
            element.scrollTop = element.scrollHeight;
        });
        await expect(page.getByRole('heading', { name: 'Detalhes' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Cancelar' })).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });

    test('keeps stock actions visible and long article selections inside the viewport', async ({ page }, testInfo) => {
        await login(page, testInfo, '/logistica');

        await page.getByRole('tab', { name: 'Stock', exact: true }).click();

        const scrollArea = page.getByTestId('stock-tab-scroll-area');
        const actionsHeader = page.getByTestId('stock-actions-header');
        const registerButton = page.getByTestId('register-stock-movement-button');
        await expect(registerButton).toBeVisible();

        const canScroll = await scrollArea.evaluate((element) => element.scrollHeight > element.clientHeight);
        expect(canScroll).toBe(true);
        await scrollArea.evaluate((element) => {
            element.scrollTop = Math.min(500, element.scrollHeight - element.clientHeight);
        });

        await expect(registerButton).toBeInViewport();
        const scrollAreaBox = await scrollArea.boundingBox();
        const actionsHeaderBox = await actionsHeader.boundingBox();
        expect(scrollAreaBox).not.toBeNull();
        expect(actionsHeaderBox).not.toBeNull();
        expect(Math.abs(actionsHeaderBox!.y - scrollAreaBox!.y)).toBeLessThanOrEqual(2);

        await registerButton.click();
        const dialog = page.getByTestId('stock-movement-dialog');
        await expect(dialog).toBeVisible();
        await page.getByRole('combobox', { name: 'Artigo' }).click();

        const options = page.getByTestId('stock-article-options');
        await expect(options).toBeVisible();
        await expect(page.getByRole('option', { name: 'Artigo E2E 32' })).toHaveCount(1);

        const viewport = page.viewportSize();
        const optionsBox = await options.boundingBox();
        expect(viewport).not.toBeNull();
        expect(optionsBox).not.toBeNull();
        expect(optionsBox!.y).toBeGreaterThanOrEqual(0);
        expect(optionsBox!.y + optionsBox!.height).toBeLessThanOrEqual(viewport!.height + 1);
        expect(optionsBox!.height).toBeLessThanOrEqual(289);
        expect(await options.evaluate((element) => getComputedStyle(element).overflowY)).toBe('auto');
        await expectNoHorizontalOverflow(page);
    });

    test('has no serious or critical WCAG A/AA violations on the authenticated dashboard', async ({ page }, testInfo) => {
        await login(page, testInfo);

        // Inertia/NProgress is transient navigation chrome. Wait until it is removed
        // so axe audits the stable dashboard rather than an in-flight transition.
        await expect(page.locator('#nprogress')).toHaveCount(0);

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();

        const blockingViolations = results.violations.filter(
            (violation) => violation.impact === 'serious' || violation.impact === 'critical',
        );

        expect(blockingViolations).toEqual([]);
    });

    const P1_WORKSPACES = [
        '/membros', '/financeiro', '/eventos', '/comunicacao', '/logistica',
        '/patrocinios', '/configuracoes', '/admin/loja', '/admin/loja/produtos',
        '/admin/loja/encomendas', '/desportivo', '/desportivo/estrutura',
        '/desportivo/planeamento', '/desportivo/treinos',
    ];

    for (const path of P1_WORKSPACES) {
        test(`P1: navigation and active content fit ${path}`, async ({ page }, testInfo) => {
            await login(page, testInfo, path);
            await expect(page.locator('main')).toBeVisible();
            await expect(page.locator('#nprogress')).toHaveCount(0);
            await expectNoHorizontalOverflow(page);

            const firstTabList = page.locator('main').getByRole('tablist').first();
            if (await firstTabList.count()) {
                const values = await firstTabList.getByRole('tab').evaluateAll((tabs) =>
                    tabs.map((tab) => tab.textContent?.trim() ?? ''),
                );
                for (const name of values) {
                    const tab = firstTabList.getByRole('tab', { name, exact: true });
                    if (await tab.isDisabled()) continue;
                    await tab.click();
                    await expect(tab).toHaveAttribute('aria-selected', 'true');
                    await expect(page.locator('#nprogress')).toHaveCount(0);
                    await expectNoHorizontalOverflow(page);
                    const panelId = await tab.getAttribute('aria-controls');
                    if (panelId) {
                        const panel = page.locator(`[id="${panelId}"]`);
                        if (await panel.count()) await expect(panel).toBeVisible();
                    }
                }
            }
            const overflowing = await page.locator('main [data-slot="table-container"], main [role="tablist"]').evaluateAll((elements) =>
                elements.filter((element) => element.getClientRects().length > 0 && element.scrollWidth > element.clientWidth + 1)
                    .map((element) => ({ slot: element.getAttribute('data-slot'), width: element.clientWidth, scroll: element.scrollWidth })),
            );
            expect(overflowing).toEqual([]);
            await expect(page.locator('main')).not.toContainText('Server Error');
        });
    }

});
