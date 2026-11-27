import fs from 'node:fs';
import { test as setup, expect } from '@playwright/test';
import { preencherLogin } from './support/login';
import { USUARIOS, authFile, rotas, type Perfil } from './support/users';

/**
 * Etapa de preparação: faz login uma vez com cada perfil e salva a sessão
 * (cookies) em tests/e2e/.auth/<perfil>.json. Os testes reutilizam essas
 * sessões, então não precisam logar de novo a cada teste.
 *
 * Se a sessão salva ainda for válida, o login é pulado. Isso evita o
 * rate limit de login do sistema (5 tentativas por minuto por IP).
 */
for (const [perfil, usuario] of Object.entries(USUARIOS) as [Perfil, (typeof USUARIOS)[Perfil]][]) {
    setup(`login: ${usuario.descricao}`, async ({ browser }) => {
        const arquivo = authFile(perfil);

        if (fs.existsSync(arquivo)) {
            const contexto = await browser.newContext({ storageState: arquivo });
            const pagina = await contexto.newPage();
            await pagina.goto(rotas.dashboardAdmin);
            const aindaLogado = new URL(pagina.url()).pathname.startsWith(rotas.dashboardAdmin);
            await contexto.close();

            if (aindaLogado) {
                setup.info().annotations.push({ type: 'sessão', description: 'reaproveitada' });
                return;
            }
        }

        const contexto = await browser.newContext();
        const pagina = await contexto.newPage();
        await preencherLogin(pagina, usuario.tipo, usuario.login);
        await expect(pagina).toHaveURL(new RegExp(`${rotas.dashboardAdmin}/?$`));
        await contexto.storageState({ path: arquivo });
        await contexto.close();
    });
}
