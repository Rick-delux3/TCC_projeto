@php
    $selectedPermissions = array_values(array_filter(
        is_array($selectedPermissions ?? null) ? $selectedPermissions : [],
        static fn ($permission) => is_string($permission),
    ));

    $permissionDependencies = \App\Support\CorretorPermissions::dependencies();

    $groupIcons = [
        'leads' => 'bi-people',
        'analyses' => 'bi-clipboard2-data',
        'real-estate-companies' => 'bi-buildings',
        'tags' => 'bi-tags',
    ];

    $permissionIcons = [
        'leads.visualizar' => 'bi-eye',
        'leads.editar' => 'bi-pencil-square',
        'analises.visualizar' => 'bi-eye',
        'analises.criar' => 'bi-shield-check',
        'imobiliarias.visualizar' => 'bi-eye',
        'imobiliarias.cadastrar' => 'bi-plus-circle',
        'imobiliarias.editar' => 'bi-pencil-square',
        'imobiliarias.remover' => 'bi-trash3',
        'tags.visualizar' => 'bi-eye',
        'tags.gerenciar' => 'bi-tags-fill',
    ];
@endphp

<input type="hidden" name="permissions_submitted" value="1">

<div class="row g-3 team-permission-groups" data-team-permission-groups>
    @forelse ($permissionGroups ?? [] as $groupKey => $group)
        @php
            $groupPermissions = $group['permissions'] ?? [];
            $groupSlug = \Illuminate\Support\Str::slug((string) $groupKey);
            $groupHintId = "permission-group-{$groupSlug}-hint";
            $groupIcon = $groupIcons[$groupKey] ?? 'bi-shield-check';
            $isWideGroup = in_array($groupKey, ['real-estate-companies', 'tags'], true);
        @endphp

        <div class="col-12 {{ $isWideGroup ? '' : 'col-lg-6' }}">
            <fieldset
                class="team-permission-group h-100"
                data-team-permission-group="{{ $groupKey }}"
            >
                <legend class="team-permission-group-heading">
                    <span class="team-permission-group-icon" aria-hidden="true">
                        <i class="bi {{ $groupIcon }}"></i>
                    </span>

                    <span class="team-permission-group-copy">
                        <span class="team-permission-group-title">
                            {{ $group['label'] ?? $groupKey }}
                        </span>
                        <span class="team-permission-group-description">
                            {{ $group['description'] ?? '' }}
                        </span>
                    </span>
                </legend>

                <div class="team-permission-group-options">
                    @foreach ($groupPermissions as $permissionKey => $permissionLabel)
                        @php
                            $permissionId = 'permission-' . \Illuminate\Support\Str::slug((string) $permissionKey);
                            $permissionIcon = $permissionIcons[$permissionKey] ?? 'bi-check2-circle';
                            $dependencies = $permissionDependencies[$permissionKey] ?? [];
                            $dependenciesSatisfied = collect($dependencies)->every(
                                fn (string $dependency): bool => in_array($dependency, $selectedPermissions, true),
                            );
                            $dependents = \App\Support\CorretorPermissions::dependentsFor((string) $permissionKey);
                            $dependentIds = array_map(
                                static fn (string $dependent): string => 'permission-' . \Illuminate\Support\Str::slug($dependent),
                                $dependents,
                            );
                            $isChecked = $dependenciesSatisfied
                                && in_array((string) $permissionKey, $selectedPermissions, true);
                        @endphp

                        <label
                            class="team-permission-option {{ $dependenciesSatisfied ? '' : 'is-disabled' }}"
                            for="{{ $permissionId }}"
                            data-team-permission-option
                        >
                            <input
                                class="form-check-input @error('permissions') is-invalid @enderror @error('permissions.*') is-invalid @enderror"
                                type="checkbox"
                                name="permissions[]"
                                id="{{ $permissionId }}"
                                value="{{ $permissionKey }}"
                                data-team-permission-key="{{ $permissionKey }}"
                                @if ($dependencies !== [])
                                    data-team-permission-requires="{{ implode(' ', $dependencies) }}"
                                    aria-describedby="{{ $groupHintId }}"
                                @endif
                                @if ($dependentIds !== [])
                                    data-team-permission-controller
                                    aria-controls="{{ implode(' ', $dependentIds) }}"
                                    aria-describedby="{{ $groupHintId }}"
                                @endif
                                @checked($isChecked)
                                @disabled(! $dependenciesSatisfied)
                            >

                            <span class="team-permission-icon" aria-hidden="true">
                                <i class="bi {{ $permissionIcon }}"></i>
                            </span>

                            <span class="team-permission-label fw-semibold">
                                {{ $permissionLabel }}
                            </span>
                        </label>
                    @endforeach
                </div>

                @if ($groupKey === 'real-estate-companies')
                    <p class="team-permission-dependency-hint" id="{{ $groupHintId }}">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        Cadastro, edição e remoção exigem a permissão “Visualizar imobiliárias”.
                    </p>
                @endif
            </fieldset>
        </div>
    @empty
        <div class="col-12">
            <div class="alert alert-warning rounded-4 border-0 mb-0">
                Nenhuma permissão operacional foi disponibilizada para seleção.
            </div>
        </div>
    @endforelse
</div>
