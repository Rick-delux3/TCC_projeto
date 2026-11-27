/**
 * Usuários criados por database/seeders/E2EPermissionsSeeder.php.
 * Mantenha os dados em sincronia com o seeder.
 */
export const PASSWORD = 'E2e@Teste123';

/** Lead que já tem análises no banco de dev. Troque com E2E_LEAD_ID=... */
export const LEAD_ID = Number(process.env.E2E_LEAD_ID ?? 5);

export type Perfil = 'ceo' | 'visualizar' | 'solicitar' | 'semVisualizar';

type Usuario = {
    /** 'ceo' entra por CPF em /ceo/admin/login/form; 'integrante' por e-mail em /admin/login/form */
    tipo: 'ceo' | 'integrante';
    login: string;
    descricao: string;
};

export const USUARIOS: Record<Perfil, Usuario> = {
    ceo: { tipo: 'ceo', login: '11144477735', descricao: 'CEO (acesso total)' },
    visualizar: { tipo: 'integrante', login: 'e2e.visualizar@teste.local', descricao: 'Integrante só com "Visualizar análises"' },
    solicitar: { tipo: 'integrante', login: 'e2e.solicitar@teste.local', descricao: 'Integrante com "Visualizar" + "Solicitar"' },
    semVisualizar: { tipo: 'integrante', login: 'e2e.sem-visualizar@teste.local', descricao: 'Integrante com "Solicitar" mas SEM "Visualizar"' },
};

export const USUARIO_INATIVO = 'e2e.inativo@teste.local';

/** Arquivo onde a sessão logada de cada perfil fica salva. */
export const authFile = (perfil: Perfil) => `tests/e2e/.auth/${perfil}.json`;

export const rotas = {
    dashboardAdmin: '/Dashboard/Admin',
    analisesDoLead: (leadId = LEAD_ID) => `/Dashboard/Admin/leads/${leadId}/analises`,
    dadosDoLead: (leadId = LEAD_ID) => `/Dashboard/Admin/leads/${leadId}/analises/dados`,
};
