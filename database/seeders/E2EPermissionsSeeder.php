<?php

namespace Database\Seeders;

use App\Models\Corretor;
use App\Support\CorretorPermissions;
use Illuminate\Database\Seeder;

/**
 * Usuários fixos para os testes E2E (Playwright) de permissões do multicálculo.
 *
 * Rodar: php artisan db:seed --class=E2EPermissionsSeeder
 *
 * Todos já têm o convite aceito e o 2FA de primeiro acesso verificado,
 * para que o Playwright consiga logar sem código por e-mail.
 * Idempotente: pode rodar quantas vezes quiser (updateOrCreate por e-mail).
 */
class E2EPermissionsSeeder extends Seeder
{
    public const PASSWORD = 'E2e@Teste123';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('E2EPermissionsSeeder não roda em produção.');

            return;
        }

        $users = [
            [
                'email' => 'e2e.ceo@teste.local',
                'name' => 'E2E CEO',
                'cpf' => '11144477735',
                'role' => Corretor::ROLE_CEO,
                'permissions' => [],
                'active' => true,
            ],
            [
                'email' => 'e2e.visualizar@teste.local',
                'name' => 'E2E Só Visualizar',
                'cpf' => null,
                'role' => Corretor::ROLE_INTEGRANTE,
                'permissions' => [CorretorPermissions::VIEW_LEADS, CorretorPermissions::VIEW_ANALYSIS],
                'active' => true,
            ],
            [
                'email' => 'e2e.solicitar@teste.local',
                'name' => 'E2E Visualizar e Solicitar',
                'cpf' => null,
                'role' => Corretor::ROLE_INTEGRANTE,
                'permissions' => [
                    CorretorPermissions::VIEW_LEADS,
                    CorretorPermissions::VIEW_ANALYSIS,
                    CorretorPermissions::CREATE_ANALYSIS,
                ],
                'active' => true,
            ],
            [
                // "Solicitar" sem "Visualizar": a dependência não é satisfeita,
                // então o integrante não deve ter acesso nenhum às análises.
                'email' => 'e2e.sem-visualizar@teste.local',
                'name' => 'E2E Sem Visualizar',
                'cpf' => null,
                'role' => Corretor::ROLE_INTEGRANTE,
                'permissions' => [CorretorPermissions::VIEW_LEADS, CorretorPermissions::CREATE_ANALYSIS],
                'active' => true,
            ],
            [
                'email' => 'e2e.inativo@teste.local',
                'name' => 'E2E Inativo',
                'cpf' => null,
                'role' => Corretor::ROLE_INTEGRANTE,
                'permissions' => [CorretorPermissions::VIEW_ANALYSIS],
                'active' => false,
            ],
        ];

        foreach ($users as $user) {
            Corretor::query()->updateOrCreate(
                ['email' => $user['email']],
                $user + [
                    'password' => self::PASSWORD,
                    'first_login_verified_at' => now(),
                    'invite_accepted_at' => now(),
                    'password_set_at' => now(),
                ],
            );
        }

        $this->command?->info('Usuários E2E prontos (senha: '.self::PASSWORD.').');
    }
}
