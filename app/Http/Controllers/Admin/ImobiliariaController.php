<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Companies\RegisterCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Services\CompanyTagService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Throwable;

class ImobiliariaController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim($filters['search'] ?? '');
        $status = $filters['status'] ?? null;
        $cnpjSearch = preg_replace('/\D+/', '', $search) ?? '';

        $companies = Imobiliaria::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'cnpj',
                'cep',
                'city',
                'state',
                'lead_access_code',
                'lead_form_active',
                'created_at',
            ])
            ->when($search !== '', function (Builder $query) use ($search, $cnpjSearch): void {
                $query->where(function (Builder $query) use ($search, $cnpjSearch): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");

                    if ($cnpjSearch !== '') {
                        $query->orWhere('cnpj', 'like', "%{$cnpjSearch}%");
                    }
                });
            })
            ->when(
                $status === 'active',
                fn (Builder $query): Builder => $query->where('lead_form_active', true),
            )
            ->when(
                $status === 'inactive',
                fn (Builder $query): Builder => $query->where('lead_form_active', false),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('corretor.imobiliarias.index', [
            'companies' => $companies,
            'filters' => $filters,
            'summary' => [
                'total' => Imobiliaria::query()->count(),
                'active' => Imobiliaria::query()->where('lead_form_active', true)->count(),
                'inactive' => Imobiliaria::query()->where('lead_form_active', false)->count(),
            ],
        ]);
    }

    public function create(CompanyTagService $companyTags): View
    {
        return view('corretor.imobiliarias.create', [
            'tagsOficiais' => $companyTags->availableTags(),
        ]);
    }

    public function store(
        StoreCompanyRequest $request,
        RegisterCompany $registerCompany,
    ): RedirectResponse {
        $corretor = $request->user('admin');

        abort_unless($corretor instanceof Corretor, 403);

        $registration = $registerCompany->execute(
            data: $request->validated(),
            registeredBy: $corretor,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect()
            ->route('admin.imobiliarias.index')
            ->with(
                'success',
                "A imobiliária {$registration['company']->name} foi cadastrada com sucesso.",
            );
    }

    public function destroy(
        Request $request,
        Imobiliaria $company,
    ): RedirectResponse {
        $corretor = $request->user('admin');

        abort_unless($corretor instanceof Corretor, 403);

        Gate::forUser($corretor)
            ->authorize('delete-real-estate-company');

        $ip = $request->ip();
        $userAgent = $request->userAgent();

        try {
            $deletedCompanyName = DB::transaction(function () use (
                $company,
                $corretor,
                $ip,
                $userAgent,
            ): string {
                $companyToDelete = Imobiliaria::query()->lockForUpdate()
                    ->findOrFail($company->getKey());

                $companyId = $companyToDelete->getKey();
                $companyName = $companyToDelete->name;

                $usersCount = $companyToDelete->usuarios()->count();
                $leadsCount = $companyToDelete->leads()->count();
                $batchesCount = $companyToDelete->lotesAnalisesSeguro()->count();
                $analysesCount = $companyToDelete->analisesSeguro()->count();

                $companyToDelete->delete();

                CorretorActivityLog::query()->create([
                    'corretor_id' => $corretor->id,
                    'action' => 'imobiliaria_deleted',
                    'model_type' => Imobiliaria::class,
                    'model_id' => $companyId,
                    'old_values' => [
                        'name' => $companyName,
                        'users_deleted' => $usersCount,
                        'leads_unlinked' => $leadsCount,
                        'analysis_batches_unlinked' => $batchesCount,
                        'analyses_unlinked' => $analysesCount,
                    ],
                    'new_values' => null,
                    'description' => "Imobiliária {$companyName} removida.",
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                ]);

                return $companyName;
            });
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.imobiliarias.index')
                ->with(
                    'error',
                    'Não foi possível remover a imobiliária. Tente novamente.',
                );
        }

        return redirect()
            ->route('admin.imobiliarias.index')
            ->with(
                'success',
                "A imobiliária {$deletedCompanyName} foi removida com sucesso.",
            );
    }

    public function update(
        UpdateCompanyRequest $request,
        Imobiliaria $company,
    ): RedirectResponse {
        $corretor = $request->user('admin');

        abort_unless($corretor instanceof Corretor, 403);

        Gate::forUser($corretor)
            ->authorize('update-real-estate-company');

        $data = $request->validated();
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        try {
            $result = DB::transaction(function () use (
                $company,
                $data,
                $corretor,
                $ip,
                $userAgent,
            ): array {
                $companyToUpdate = Imobiliaria::query()->lockForUpdate()
                    ->findOrFail($company->getKey());

                $primaryUser = $companyToUpdate->usuarios()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $oldName = $companyToUpdate->name;
                $oldStatus = (bool) $companyToUpdate->lead_form_active;

                $companyToUpdate->fill($data);

                $changedFields = array_keys(
                    $companyToUpdate->getDirty()
                );

                if ($primaryUser !== null) {
                    $primaryUser->fill([
                        'name' => $companyToUpdate->name,
                        'email' => $companyToUpdate->email,
                    ]);
                }

                $userNeedsUpdate = $primaryUser?->isDirty() ?? false;

                if ($changedFields === [] && ! $userNeedsUpdate) {
                    return [
                        'changed' => false,
                        'name' => $companyToUpdate->name,
                    ];
                }

                $companyToUpdate->save();
                $primaryUser?->save();

                CorretorActivityLog::query()->create([
                    'corretor_id' => $corretor->id,
                    'action' => 'imobiliaria_updated',
                    'model_type' => Imobiliaria::class,
                    'model_id' => $companyToUpdate->getKey(),
                    'old_values' => [
                        'name' => $oldName,
                        'lead_form_active' => $oldStatus,
                    ],
                    'new_values' => [
                        'name' => $companyToUpdate->name,
                        'lead_form_active' => (bool) $companyToUpdate
                            ->lead_form_active,
                        'changed_fields' => $changedFields,
                        'user_synchronized' => $userNeedsUpdate,
                    ],
                    'description' => sprintf(
                        'Atualizou a imobiliária "%s".',
                        $companyToUpdate->name,
                    ),
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                ]);

                return [
                    'changed' => true,
                    'name' => $companyToUpdate->name,
                ];
            });
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.imobiliarias.index')
                ->with(
                    'error',
                    'Não foi possível atualizar a imobiliária. Tente novamente.',
                );
        }

        if (! $result['changed']) {
            return redirect()
                ->route('admin.imobiliarias.index')
                ->with(
                    'info',
                    "Nenhuma alteração foi realizada na imobiliária {$result['name']}.",
                );
        }

        return redirect()
            ->route('admin.imobiliarias.index')
            ->with(
                'success',
                "A imobiliária {$result['name']} foi atualizada com sucesso.",
            );
    }
}
