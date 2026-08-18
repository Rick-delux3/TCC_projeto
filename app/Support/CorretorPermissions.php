<?php

namespace App\Support;

class CorretorPermissions
{
    public const VIEW_REAL_ESTATE_COMPANIES = 'imobiliarias.visualizar';

    public const CREATE_REAL_ESTATE_COMPANIES = 'imobiliarias.cadastrar';

    public const UPDATE_REAL_ESTATE_COMPANIES = 'imobiliarias.editar';

    public const DELETE_REAL_ESTATE_COMPANIES = 'imobiliarias.remover';

    /**
     * Relação única entre as abilities usadas pelos Gates e as permissões
     * persistidas no cadastro do integrante. Dependências entre permissões
     * são declaradas separadamente em dependencies().
     */
    public static function abilities(): array
    {
        return [
            'view-leads' => ['leads.visualizar', 'leads.editar'],
            'edit-leads' => ['leads.editar'],
            'view-analyses' => ['analises.visualizar', 'analises.criar'],
            'create-analysis' => ['analises.criar'],
            'view-real-estate-companies' => [self::VIEW_REAL_ESTATE_COMPANIES],
            'create-real-estate-company' => [self::CREATE_REAL_ESTATE_COMPANIES],
            'update-real-estate-company' => [self::UPDATE_REAL_ESTATE_COMPANIES],
            'delete-real-estate-company' => [self::DELETE_REAL_ESTATE_COMPANIES],
            'view-tags' => [
                'tags.visualizar',
                'tags.gerenciar',
            ],
            'manage-lead-tags' => [
                'tags.gerenciar',
            ],
        ];
    }

    public static function all(): array
    {
        return [
            'leads.visualizar' => 'Visualizar leads/clientes',
            'leads.editar' => 'Editar dados de leads/clientes',
            'analises.visualizar' => 'Visualizar análises',
            'analises.criar' => 'Solicitar análises',
            self::VIEW_REAL_ESTATE_COMPANIES => 'Visualizar imobiliárias',
            self::CREATE_REAL_ESTATE_COMPANIES => 'Cadastrar imobiliárias',
            self::UPDATE_REAL_ESTATE_COMPANIES => 'Editar dados de imobiliárias',
            self::DELETE_REAL_ESTATE_COMPANIES => 'Remover imobiliárias',
            'tags.visualizar' => 'Visualizar tags',
            'tags.gerenciar' => 'Gerenciar tags dos leads',
        ];
    }

    /**
     * Permissões que só podem ser concedidas quando suas dependências
     * também estiverem selecionadas.
     */
    public static function dependencies(): array
    {
        return [
            self::CREATE_REAL_ESTATE_COMPANIES => [self::VIEW_REAL_ESTATE_COMPANIES],
            self::UPDATE_REAL_ESTATE_COMPANIES => [self::VIEW_REAL_ESTATE_COMPANIES],
            self::DELETE_REAL_ESTATE_COMPANIES => [self::VIEW_REAL_ESTATE_COMPANIES],
        ];
    }

    public static function dependenciesFor(string $permission): array
    {
        return self::dependencies()[$permission] ?? [];
    }

    public static function dependentsFor(string $permission): array
    {
        return array_keys(array_filter(
            self::dependencies(),
            static fn (array $dependencies): bool => in_array($permission, $dependencies, true),
        ));
    }

    public static function selectionSatisfiesDependencies(array $permissions): bool
    {
        $selectedPermissions = array_fill_keys(
            array_filter(
                $permissions,
                static fn (mixed $permission): bool => is_string($permission),
            ),
            true,
        );

        foreach (self::dependencies() as $permission => $dependencies) {
            if (! isset($selectedPermissions[$permission])) {
                continue;
            }

            foreach ($dependencies as $dependency) {
                if (! isset($selectedPermissions[$dependency])) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function groups(): array
    {
        $labels = self::all();
        $groups = [
            'leads' => [
                'label' => 'Leads e clientes',
                'description' => 'Consulta e manutenção dos dados comerciais.',
                'permissions' => ['leads.visualizar', 'leads.editar'],
            ],
            'analyses' => [
                'label' => 'Análises',
                'description' => 'Acesso às consultas e solicitações de análise.',
                'permissions' => ['analises.visualizar', 'analises.criar'],
            ],
            'real-estate-companies' => [
                'label' => 'Imobiliárias',
                'description' => 'Visualização e operações do cadastro de imobiliárias.',
                'permissions' => [
                    self::VIEW_REAL_ESTATE_COMPANIES,
                    self::CREATE_REAL_ESTATE_COMPANIES,
                    self::UPDATE_REAL_ESTATE_COMPANIES,
                    self::DELETE_REAL_ESTATE_COMPANIES,
                ],
            ],
            'tags' => [
                'label' => 'Tags dos leads',
                'description' => 'Consulta e gerenciamento dos resultados comerciais.',
                'permissions' => ['tags.visualizar', 'tags.gerenciar'],
            ],
        ];

        foreach ($groups as &$group) {
            $group['permissions'] = array_intersect_key(
                $labels,
                array_flip($group['permissions']),
            );
        }

        unset($group);

        return $groups;
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
