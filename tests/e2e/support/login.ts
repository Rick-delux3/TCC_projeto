import { expect, type Page } from '@playwright/test';
import { PASSWORD } from './users';

/** Preenche e envia o formulário de login de CEO (CPF) ou integrante (e-mail). */
export async function preencherLogin(page: Page, tipo: 'ceo' | 'integrante', login: string, senha = PASSWORD) {
    if (tipo === 'ceo') {
        await page.goto('/ceo/admin/login/form');
        await page.locator('#cpf').fill(login);
        await page.locator('#password').fill(senha);
        await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    } else {
        await page.goto('/admin/login/form');
        await page.locator('#email').fill(login);
        await page.locator('#password').fill(senha);
        await page.getByRole('button', { name: 'Entrar no painel' }).click();
    }

    // Proteção contra o rate limit de login (5 POSTs/min por IP).
    await expect(page.getByText(/Too Many (Requests|Attempts)/i), 'Rate limit de login atingido: aguarde 1 minuto.').toHaveCount(0);
}
