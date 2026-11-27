import { test, expect, type Page } from '@playwright/test';
import { preencherLogin } from './support/login';
import { USUARIO_INATIVO, authFile, rotas } from './support/users';

/**
 * Multicálculo (Análises de seguro fiança): controle de acesso por perfil.
 *
 * Regras testadas (app/Support/CorretorPermissions.php + AppServiceProvider):
 *  - CEO passa em todas as permissões.
 *  - "Visualizar análises" libera a tela; "Solicitar análises" libera as ações.
 *  - "Solicitar" depende de "Visualizar": sem a dependência, nada é liberado.
 *  - Integrante inativo não consegue entrar; visitante é mandado para o login.
 *
 * Nenhum teste dispara cotação: só abrimos telas e lemos permissões.
 */

const botaoVisualizarAnalises = (page: Page) =>
    page.locator('a.btn', { hasText: 'Visualizar análises' });

/** Lê o bloco `permissions` do JSON da tela de análises do lead (usa os cookies da sessão). */
async function permissoesDoLead(page: Page) {
    const resposta = await page.request.get(rotas.dadosDoLead(), { headers: { Accept: 'application/json' } });
    expect(resposta.status()).toBe(200);
    const json = await resposta.json();
    return (json.data ?? json).permissions as { view: boolean; create_analysis: boolean };
}

test.describe('Visitante sem login', () => {
    test('é redirecionado para o login ao abrir as análises de um lead', async ({ page }) => {
        await page.goto(rotas.analisesDoLead());

        await expect(page).toHaveURL(/login/);
        await expect(page.locator('#password')).toBeVisible();
    });
});

test.describe('CEO', () => {
    test.use({ storageState: authFile('ceo') });

    test('vê o atalho, abre as análises e pode solicitar novas', async ({ page }) => {
        await page.goto(rotas.dashboardAdmin);
        await expect(botaoVisualizarAnalises(page)).toBeVisible();

        await page.goto(rotas.analisesDoLead());
        await expect(page).toHaveTitle(/^Análise de/);

        expect(await permissoesDoLead(page)).toMatchObject({ view: true, create_analysis: true });
    });
});

test.describe('Integrante só com "Visualizar análises"', () => {
    test.use({ storageState: authFile('visualizar') });

    test('vê as análises, mas NÃO pode solicitar', async ({ page }) => {
        await page.goto(rotas.dashboardAdmin);
        await expect(botaoVisualizarAnalises(page)).toBeVisible();

        await page.goto(rotas.analisesDoLead());
        await expect(page).toHaveTitle(/^Análise de/);

        expect(await permissoesDoLead(page)).toMatchObject({ view: true, create_analysis: false });
    });
});

test.describe('Integrante com "Visualizar" + "Solicitar"', () => {
    test.use({ storageState: authFile('solicitar') });

    test('vê as análises e pode solicitar', async ({ page }) => {
        await page.goto(rotas.analisesDoLead());
        await expect(page).toHaveTitle(/^Análise de/);

        expect(await permissoesDoLead(page)).toMatchObject({ view: true, create_analysis: true });
    });
});

test.describe('Integrante com "Solicitar" mas SEM "Visualizar"', () => {
    test.use({ storageState: authFile('semVisualizar') });

    test('não vê o atalho de análises no painel', async ({ page }) => {
        await page.goto(rotas.dashboardAdmin);

        await expect(page).toHaveURL(new RegExp(`${rotas.dashboardAdmin}/?`));
        await expect(botaoVisualizarAnalises(page)).toHaveCount(0);
    });

    test('recebe 403 ao digitar a URL das análises direto', async ({ page }) => {
        const resposta = await page.goto(rotas.analisesDoLead());

        expect(resposta?.status()).toBe(403);
    });

    test('recebe 403 também na API de dados', async ({ page }) => {
        const resposta = await page.request.get(rotas.dadosDoLead(), { headers: { Accept: 'application/json' } });

        expect(resposta.status()).toBe(403);
    });
});

test.describe('Integrante inativo', () => {
    test('não consegue entrar no painel', async ({ page }) => {
        await preencherLogin(page, 'integrante', USUARIO_INATIVO);

        await expect(page).toHaveURL(/\/admin\/login\/form/);
        await expect(page.locator('#member-email-error')).toContainText(/está inativo/i);

        // Mesmo tentando a URL direta, continua sem acesso.
        await page.goto(rotas.analisesDoLead());
        await expect(page).toHaveURL(/login/);
    });
});
