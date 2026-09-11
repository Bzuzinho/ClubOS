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
    test('keeps member sports tabs and populated training fields readable', async ({ page }, testInfo) => {
        await login(page, testInfo, '/membros');
        await page.getByRole('tab', { name: 'Membros', exact: true }).click();
        await expect(page).toHaveURL(/\/membros\?tab=list$/);
        await expect(page.locator('#nprogress')).toHaveCount(0);
        await Promise.all([
            page.waitForResponse(response => {
                const url = new URL(response.url());
                return url.pathname === '/membros' && url.searchParams.get('search') === 'e2e.sports@clubos.test' && response.ok();
            }),
            page.getByPlaceholder('Pesquisar por nome, NIF, nº sócio ou email...').fill('e2e.sports@clubos.test'),
        ]);
        await expect(page.locator('#nprogress')).toHaveCount(0);
        await page.getByRole('link', { name: /Atleta E2E Desportivo/ }).filter({ visible: true }).first().click();
        await expect(page).toHaveURL(/\/membros\/[^/?]+$/);
        await page.getByRole('tab', { name: 'Desportivo', exact: true }).click();
        const navigation = page.getByRole('navigation', { name: 'Áreas desportivas do membro' });
        const tabs = navigation.getByRole('tab');
        await expect(tabs).toHaveCount(7);
        for (let i = 0; i < 7; i += 1) {
            const tab = tabs.nth(i);
            await tab.click();
            await expect(tab).toHaveAttribute('aria-selected', 'true');
            await expectNoHorizontalOverflow(page);
        }
        await navigation.getByRole('tab', { name: 'Treinos', exact: true }).click();
        const training = page.getByRole('row').filter({ hasText: '#E2E-DESPORTIVO' });
        await expect(training).toBeVisible();
        await expect(training.getByRole('cell')).toHaveCount(8);
        await expect(training.getByRole('cell').filter({ hasText: 'E2E Época' })).toBeVisible();
        await expect(training.getByRole('cell').filter({ hasText: 'Presente' })).toBeVisible();
        await expect(training.getByRole('cell').filter({ hasText: 'E2E Masters' })).toBeVisible();
        await expect(training.getByRole('cell').filter({ hasText: 'E2E Descrição completa' })).toBeVisible();
        await training.getByRole('button', { name: 'Ver registos', exact: true }).click();
        const records = page.getByRole('region', { name: 'Registos de #E2E-DESPORTIVO' });
        await expect(records.getByRole('cell', { name: '32.540 s', exact: true })).toBeVisible();
        await expect(records.getByRole('cell', { name: '50 m · Livre', exact: true })).toBeVisible();
        await expect(records.getByText('Nota técnica', { exact: true })).toBeVisible();
        await expect(records).toContainText('E2E Melhorar a viragem');

        await expectNoHorizontalOverflow(page);
        expect(await page.locator('main [data-slot="table-container"], main [role="tablist"], main [class*="overflow"]').evaluateAll(
            elements => elements.filter(el => el.getClientRects().length > 0 && el.scrollWidth > el.clientWidth + 1).length,
        )).toBe(0);
    });

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
        '/desportivo/planeamento', '/desportivo/treinos', '/website', '/website/paginas',
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
                    const overflowing = await page.locator('main [data-slot="table-container"], main [role="tablist"], main table').evaluateAll((elements) =>
                        elements.map((element) => element.tagName === 'TABLE' ? element.parentElement ?? element : element)
                            .filter((element) => element.getClientRects().length > 0 && element.scrollWidth > element.clientWidth + 1)
                            .map((element) => ({ slot: element.getAttribute('data-slot'), width: element.clientWidth, scroll: element.scrollWidth })),
                    );
                    expect(overflowing).toEqual([]);
                    const panelId = await tab.getAttribute('aria-controls');
                    if (panelId) {
                        const panel = page.locator(`[id="${panelId}"]`);
                        if (await panel.count()) await expect(panel).toBeVisible();
                    }
                }
            }
            await expect(page.locator('main')).not.toContainText('Server Error');
        });
    }

    test('P1: populated reconciliation keeps invoice fields and allocation usable', async ({ page }, testInfo) => {
        await login(page, testInfo, '/financeiro');
        await page.getByRole('tab', { name: 'Banco', exact: true }).click();
        await page.getByPlaceholder('Pesquisar descricao, referencia, conta ou centro de custo')
            .fill(`E2E-BANK-${testInfo.project.name}`);
        await page.getByRole('button', { name: /Consultar sugestao|Consultar sugestoes de conciliacao/ }).click();
        await page.getByRole('button', { name: 'Abrir conciliacao manual', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Conciliacao Manual', exact: true });
        await expect(dialog).toBeVisible();
        const invoice = dialog.getByRole('row').filter({ hasText: `Browser QA ${testInfo.project.name}` });
        await expect(invoice).toHaveCount(1);
        await expect(invoice.getByRole('cell')).toHaveCount(9);
        const amount = invoice.getByRole('spinbutton');
        await amount.fill('10');
        await expect(amount).toHaveValue('10');
        await expect(dialog.getByText('Total alocado', { exact: true }).locator('..')).toContainText('10');
        const overflowing = await dialog.locator('[data-slot="table-container"]').evaluateAll((elements) =>
            elements.filter((element) => element.scrollWidth > element.clientWidth + 1).length,
        );
        expect(overflowing).toBe(0);
        await expectNoHorizontalOverflow(page);
        await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click();
        await expect(dialog).not.toBeVisible();
    });

    test('P1: populated periodisation exposes cycles and the linked session', async ({ page }, testInfo) => {
        await login(page, testInfo, '/desportivo/planeamento');
        await Promise.all([
            page.waitForResponse((response) => response.url().includes('/desportivo/planeamento?season_id=') && response.request().method() === 'GET' && response.ok()),
            page.getByRole('combobox', { name: 'Época de planeamento' }).selectOption({ label: 'E2E Época · E2E' }),
        ]);
        await expect(page.locator('#nprogress')).toHaveCount(0);
        await expect(page.getByText('E2E Preparação', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('E2E Base', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('E2E Semana', { exact: true }).first()).toBeVisible();
        await expectNoHorizontalOverflow(page);
        await page.getByRole('tab', { name: 'Sessões', exact: true }).click();
        await expect(page.getByRole('tab', { name: 'Sessões', exact: true })).toHaveAttribute('aria-selected', 'true');
        await expect(page.getByText('#E2E-DESPORTIVO', { exact: false }).first()).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });

    test('P1: populated Cais and Live retain athlete and series controls', async ({ page }, testInfo) => {
        await login(page, testInfo, '/desportivo/cais');
        await expect(page.getByText('Atleta E2E Desportivo', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Comportamento', exact: true })).toBeVisible();
        await expectNoHorizontalOverflow(page);
        await page.getByRole('button', { name: 'Cards', exact: true }).click();
        await expect(page.getByText('Atleta E2E Desportivo', { exact: true })).toBeVisible();
        await expectNoHorizontalOverflow(page);
        await page.getByRole('button', { name: 'Lista', exact: true }).click();
        const overflowing = await page.locator('main [class*="overflow"]').evaluateAll((elements) =>
            elements.filter((element) => element.getClientRects().length > 0 && element.scrollWidth > element.clientWidth + 1)
                .map((element) => ({ width: element.clientWidth, scroll: element.scrollWidth })),
        );
        expect(overflowing).toEqual([]);
        await page.getByRole('button', { name: 'Live', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Live · Monitorização fina' })).toBeVisible();
        await page.getByRole('checkbox', { name: 'Selecionar Atleta E2E Desportivo' }).check();
        await page.getByRole('button', { name: /8×50.*E2E Livre técnico/ }).click();
        await expect(page.getByRole('button', { name: 'START', exact: true })).toBeEnabled();
        await expectNoHorizontalOverflow(page);
    });

    test('P1: website editor keeps tools and device previews within the available width', async ({ page }, testInfo) => {
        await login(page, testInfo, '/website/paginas');
        await page.locator('[data-website-page]').filter({ hasText: 'E2E Editor' }).getByRole('link', { name: 'Editar', exact: true }).click();
        const editor = page.getByTestId('website-editor');
        await expect(editor).toBeVisible();
        const properties = page.getByRole('complementary', { name: 'Propriedades da página' });
        for (const tab of ['Conteúdo', 'Estilo', 'Comportamento', 'Página', 'Imagens', 'Histórico']) {
            await properties.getByRole('tab', { name: tab, exact: true }).click();
            await expect(properties.getByRole('tab', { name: tab, exact: true })).toHaveAttribute('aria-selected', 'true');
            expect(await editor.evaluate((element) => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
            const overflowing = await properties.locator('[data-radix-scroll-area-viewport]').evaluateAll((elements) =>
                elements.filter((element) => element.scrollWidth > element.clientWidth + 1).length,
            );
            expect(overflowing).toBe(0);
        }
        const frame = page.frameLocator('iframe[title="Pré-visualização em tempo real"]');
        await expect(frame.getByText('E2E Pré-visualização', { exact: true })).toBeVisible();
        for (const device of ['desktop', 'tablet', 'mobile']) {
            await page.getByRole('button', { name: device, exact: true }).click();
            const preview = page.getByRole('region', { name: 'Pré-visualização da página' });
            const iframe = page.locator('iframe[title="Pré-visualização em tempo real"]');
            await expect.poll(async () => {
                const outer = await preview.boundingBox();
                const inner = await iframe.boundingBox();
                return !!outer && !!inner && inner.x >= outer.x - 1 && inner.x + inner.width <= outer.x + outer.width + 1;
            }).toBe(true);
            await expect(frame.getByText('E2E Pré-visualização', { exact: true })).toBeVisible();
            expect(await editor.evaluate((element) => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
        }
        await expect(page.getByRole('button', { name: 'Guardar', exact: true })).toBeVisible();
    });

});
