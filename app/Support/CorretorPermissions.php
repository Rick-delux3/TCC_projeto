<?php

namespace App\Support;

class CorretorPermissions
{
    public const VIEW_REAL_ESTATE_COMPANIES = 'imobiliarias.visualizar';

    public const CREATE_REAL_ESTATE_COMPANIES = 'imobiliarias.cadastrar';

    public const UPDATE_REAL_ESTATE_COMPANIES = 'imobiliarias.editar';

    public const DELETE_REAL_ESTATE_COMPANIES = 'imobiliarias.remover';

    public const VIEW_LEADS = 'leads.visualizar';

    public const EDIT_LEADS = 'lead.editar';

    public const VIEW_TAGS = 'tags.visualizar';

    public const MANAGE_LEAD_TAGS = 'tags.gerenciar';

    public const VIEW_ANALYSIS = 'analises.visualizar';

    public const CREATE_ANALYSIS = 'analises.criar';
   
    /**
     * Relação única entre as abilities usadas pelos Gates e as permissões
     * persistidas no cadastro do integrante. Dependências entre permissões
     * são declaradas separadamente em dependencies().
     */
    public static function abilities(): array
    {
        return [
            'view-leads' => [self::VIEW_LEADS],
            'edit-leads' => [self::EDIT_LEADS],
            'view-analyses' => [self::VIEW_ANALYSIS],
            'create-analysis' => [self::CREATE_ANALYSIS],
            'view-real-estate-companies' => [self::VIEW_REAL_ESTATE_COMPANIES],
            'create-real-estate-company' => [self::CREATE_REAL_ESTATE_COMPANIES],
            'update-real-estate-company' => [self::UPDATE_REAL_ESTATE_COMPANIES],
            'delete-real-estate-company' => [self::DELETE_REAL_ESTATE_COMPANIES],
            'view-tags' => [
                self::VIEW_TAGS
            ],
            'manage-lead-tags' => [
                self::MANAGE_LEAD_TAGS
            ],
        ];
    }

    public static function all(): array
    {
        return [
            self::VIEW_LEADS => 'Visualizar leads/clientes',
            self::EDIT_LEADS => 'Editar dados de leads/clientes',
            self::VIEW_ANALYSIS => 'Visualizar análises',
            self::CREATE_ANALYSIS => 'Solicitar análises',
            self::VIEW_REAL_ESTATE_COMPANIES => 'Visualizar imobiliárias',
            self::CREATE_REAL_ESTATE_COMPANIES => 'Cadastrar imobiliárias',
            self::UPDATE_REAL_ESTATE_COMPANIES => 'Editar dados de imobiliárias',
            self::DELETE_REAL_ESTATE_COMPANIES => 'Remover imobiliárias',
            self::VIEW_TAGS => 'Visualizar tags',
            self::MANAGE_LEAD_TAGS => 'Gerenciar tags dos leads',
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

            self::EDIT_LEADS => [self::VIEW_LEADS],

            self::CREATE_ANALYSIS => [self::VIEW_ANALYSIS],

            self::MANAGE_LEAD_TAGS => [self::VIEW_TAGS],
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
                'permissions' => [
                    self::VIEW_LEADS,
                    self::EDIT_LEADS,
                ],
            ],
            'analyses' => [
                'label' => 'Análises',
                'description' => 'Acesso às consultas e solicitações de análise.',
                'permissions' => [
                    self::VIEW_ANALYSIS,
                    self::CREATE_ANALYSIS,
                ],
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
                'permissions' => [
                    self::VIEW_TAGS,
                    self::MANAGE_LEAD_TAGS,
                ],
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
