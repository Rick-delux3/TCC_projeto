<?php

namespace App\Http\Controllers;

use App\Models\Corretor;
use App\Services\CorretorInvitationService;
use App\Support\CorretorPermissions;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CorretorEquipeController extends Controller
{
    public function __construct(
        private CorretorInvitationService $invitationService
    ) {}

    public function index()
    {
        $search = request('search');

        $corretores = Corretor::query()
            ->when($search, function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('role', 'like', "%{$search}%");
                });
            })
            ->orderByRaw("CASE WHEN role = 'CEO' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return view('corretor.config_equipe.index', compact('corretores', 'search'));
    }

    public function create()
    {
        $permissionGroups = CorretorPermissions::groups();

        return view('corretor.config_equipe.create', [
            'permissionGroups' => $permissionGroups,
        ]);
    }

    public function store(Request $request)
    {
        $ceo = Auth::guard('admin')->user();

        abort_if(
            ! $ceo || ! $ceo->isCeo(),
            403,
            'Apenas o CEO pode cadastrar e convidar integrantes.'
        );

        $routeIndex = route('admin.config-equipe.index');

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:corretores,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(CorretorPermissions::keys())],
            'active' => ['nullable', 'boolean'],

        ],
            [
                'nome.required' => 'Informe o nome!',
                'email.required' => 'Informe o email!',
                'email.email' => 'Informe um email válido',
                'email.unique' => 'Já existe um cadastro com esse email!',
                'password.required' => 'Informe uma senha!',
                'password.min' => 'A senha deve ter pelo menos 8 caracteres!',
                'password.confirmed' => 'As senhas não conferem!',
                'permissions.*.in' => 'Uma das permissões selecionadas é inválida!',
                'permissions.*.distinct' => 'Uma permissão não pode ser selecionada mais de uma vez!',
            ]

        );

        $validated['permissions'] = $this->normalizeAndValidatePermissions(
            $validated['permissions'] ?? [],
        );

        $integrante = Corretor::create([
            'name' => $validated['nome'],
            'email' => mb_strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'password_set_at' => now(),

            'role' => Corretor::ROLE_INTEGRANTE,

            'permissions' => array_values($validated['permissions'] ?? []),

            'active' => $request->boolean('active', true),

            'invited_by_corretor_id' => Auth::guard('admin')->id(),
            'invited_at' => now(),
        ]);

        try {
            $this->invitationService->sendOrResend(
                integrante: $integrante,
                sentBy: $ceo,
                request: $request
            );

            return redirect($routeIndex)->with(
                'success',
                'Integrante cadastrado e convite adicionado à fila de envio.'
            );

        } catch (DomainException $exception) {
            return redirect($routeIndex)
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('Falha no envio do convite do integrante', [
                'corretor_id' => $integrante->id,
                'exception' => $exception::class,
                'mailer' => config('mail.default'),
            ]);

            return redirect($routeIndex)
                ->with(
                    'error',
                    'O integrante foi cadastrado, mas o convite não pôde ser enviado. Utilize o botão de reenvio.'
                );
        }

    }

    public function edit(Corretor $corretor)
    {
        abort_if($corretor->role === Corretor::ROLE_CEO, 403);

        $permissionGroups = CorretorPermissions::groups();

        return view('corretor.config_equipe.edit', [
            'integrante' => $corretor,
            'permissionGroups' => $permissionGroups,
        ]);
    }

    public function update(Request $request, Corretor $corretor)
    {
        abort_if($corretor->role === Corretor::ROLE_CEO, 403);

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('corretores', 'email')->ignore($corretor->id),
            ],

            'password' => ['nullable', 'min:8', 'string', 'confirmed'],

            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'string',
                'distinct',
                Rule::in(CorretorPermissions::keys()),
            ],

            'active' => ['nullable', 'boolean'],

        ],
            [
                'nome.required' => 'Informe o nome.',
                'email.required' => 'Informe o e-mail.',
                'email.email' => 'Informe um e-mail válido.',
                'email.unique' => 'Já existe outro corretor cadastrado com este e-mail.',
                'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
                'password.confirmed' => 'A confirmação da senha não confere.',
                'permissions.*.in' => 'Uma das permissões selecionadas é inválida.',
                'permissions.*.distinct' => 'Uma permissão não pode ser selecionada mais de uma vez.',
            ]

        );

        $validated['permissions'] = $this->normalizeAndValidatePermissions(
            $validated['permissions'] ?? [],
        );

        $data = [
            'name' => $validated['nome'],

            'email' => mb_strtolower($validated['email']),

            'permissions' => array_values($validated['permissions'] ?? []),

            'active' => $request->boolean('active'),
        ];

        if (filled($validated['password'] ?? null)) {
            $data['password'] = Hash::make($validated['password']);
        }

        $corretor->update($data);

        return redirect()->route('admin.config-equipe.index')
            ->with('success', 'Dados do integrante atualizados com sucesso!');

    }

    public function resendInvitation(
        Request $request,
        Corretor $corretor
    ) {
        $ceo = Auth::guard('admin')->user();

        abort_if(
            ! $ceo || ! $ceo->isCeo(),
            403,
            'Apenas o CEO pode reenviar convites.'
        );

        abort_if(
            $corretor->isCeo(),
            403,
            'O CEO não utiliza convite de integrante.'
        );

        try {
            $this->invitationService->sendOrResend(
                integrante: $corretor,
                sentBy: $ceo,
                request: $request
            );

            return back()->with(
                'success',
                "Novo convite adicionado à fila de envio para {$corretor->email}."
            );
        } catch (DomainException $exception) {
            return back()->with(
                'error',
                $exception->getMessage()
            );
        } catch (\Throwable $exception) {
            Log::error('Falha ao reenviar convite.', [
                'corretor_id' => $corretor->id,
                'exception' => $exception::class,
                'mailer' => config('mail.default'),
            ]);

            return back()->with(
                'error',
                'Não foi possível reenviar o convite. Verifique a configuração de e-mail e a fila.'
            );
        }
    }

    private function normalizeAndValidatePermissions(array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));

        if (! CorretorPermissions::selectionSatisfiesDependencies($permissions)) {
            throw ValidationException::withMessages([
                'permissions' => 'Para cadastrar, editar ou remover imobiliárias, selecione também “Visualizar imobiliárias”.',
            ]);
        }

        return $permissions;
    }
}
