<?php

namespace App\Http\Controllers;


use App\Actions\Companies\RegisterCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Services\CompanyTagService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;


class ImobiliariaController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim($filter['search'] ?? '');
        $status = $filter['status'] ?? null;
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
                'creayted_at',
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

            return view('corretor.imobiliarias-aba.index', [
                'companies' => $companies,
                'filter' => $filter,
                'summary' => [
                    'total' => Imobiliaria::query()->count(),
                    'active' => Imobiliaria::query()->where('lead_form_active', true)->count(),
                    'inactive' => Imobiliaria::query()->where('lead_form_active', false)->count(),
                ],
            ]);
    }

    public function create(CompanyTagService $companyTags): View
    {
        return view('corretor.imobiliarias-aba.create', [
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
}

