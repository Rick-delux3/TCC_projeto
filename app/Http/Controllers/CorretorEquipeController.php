<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Notifications\CorretorIntegranteLoginNotification;
use App\Support\CorretorPermissions;

use App\Models\Corretor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;



class CorretorEquipeController extends Controller
{
    public function index()
    {
        $search = request('search');

        $corretores = Corretor::query()
            ->when($search, function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('nome', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('role', 'like', "%{$search}%");
                });
            })
            ->orderByRaw("CASE WHEN role = 'CEO' THEN 0 ELSE 1 END")
            ->orderBy('nome')
            ->get();

        return view('corretor.config_equipe.index', compact('corretores', 'search'));
    }

    public function create()
    {
        $permissions = CorretorPermissions::all();

        return view('corretor.config_equipe.create', ['permissions' => $permissions]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:corretores,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(CorretorPermissions::keys())],
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
        ]
        
        
        );

        $integrante = Corretor::create([
            'nome' => $validated['nome'],
            'email' => mb_strtolower($validated['email']),
            'password' => Hash::make($validated['password']),

            'role' => Corretor::ROLE_INTEGRANTE,

            'permissions' => array_values($validated['permissions'] ?? []),

            'active' => $request->boolean('active', true),

            'invited_by_corretor_id' => Auth::guard('admin')->id(),
            'invited_at' => now(),
        ]);

        $integrante->notify(new CorretorIntegranteLoginNotification());

        return redirect()->route('admin.config-equipe.index')
        ->with('success', 'Corretor Integrante cadastrado com sucesso! O link para login foi enviado por email.');

    }


    public function edit(Corretor $corretor)
    {
        abort_if($corretor->role === Corretor::ROLE_CEO, 403);

        $permissions = CorretorPermissions::all();

        return view('corretor.config_equipe.edit', [
            'integrante' => $corretor,
            'permissions' => $permissions,
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
            ]
        
        );

        $data = [
            'nome' => $validated['nome'],

            'email' => mb_strtolower($validated['email']),

            'permissions' => array_values($validated['permissions'] ?? []),

            'active' => $request->boolean('active'),
        ];

        if(filled($validated['password'] ?? null))
        {
            $data['password'] = Hash::make($validated['password']);
        }

        $corretor->update($data);

        return redirect()->route('admin.config-equipe.index')
        ->with('success', 'Dados do integrante atualizados com sucesso!');
        
    }
}
 