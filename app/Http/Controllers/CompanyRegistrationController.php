<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\LeadLoversService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class CompanyRegistrationController extends Controller
{
    private $token;
    private $baseURL;

    function __construct()
    {
        $this->token = config('services.leadlovers.token');
        $this->baseURL = 'https://llapi.leadlovers.com/webapi/';

    }

    public function showRegistrationForm()
    {
        $response = Http::get($this->baseURL . 'Tags?token=' . $this->token)->json();

        //dd('TAGS DO LEADLOVERS:', $response);

        $tagsOficiais = [];

        $listatags = $response['Tags'] ?? [];
        
        // (Assumindo que a API devolve as tags direto num array e o nome fica na chave 'Name')
        // Nós vamos ajustar essa parte exata assim que você me mandar a foto do dd()!
        if (is_array($listatags)) {
            foreach ($listatags as $tag) {
                if (isset($tag['Title'])) {
                    $NomedaTag = $tag['Title'];

                    if (str_starts_with($NomedaTag, 'Imobiliária')) {
                        $tagsOficiais[] = $NomedaTag;
                    }
                }

            }
        }

        sort($tagsOficiais);

        // 4. Manda a lista dinâmica para a View
        
        return view('register-company', compact('tagsOficiais'));
        // Ajuste o nome da view acima para o nome real do seu arquivo blade
    }


    

    public function store(Request $request, LeadLoversService $leadLovers)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:companies,name',
            'email' => 'required|string|lowercase|email:rfc,dns|max:255|unique:companies,email|unique:users,email',
            'phone' => ['required', 'string', 'max:15', 'regex:/^\(\d{2}\)\s\d{4,5}-\d{4}$/', 'unique:companies,phone'],
            'city' => 'required|string|max:55',
            'state' => 'required|string|max:2',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $company = Company::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'city' => $data['city'],
            'state' => $data['state'],
            'password' => Hash::make($data['password']),
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'company_id' => $company->id,
        ]);

        // Sends the standard Laravel email verification link.
        event(new Registered($user));

        $res = $leadLovers->createLead([
            'Name' => $company->name,
            'Email' => $company->email,
            'Phone' => $company->phone ?? '',
            'City' => $company->city,
            'State' => $company->state,
        ]);

        if (!is_array($res) || !isset($res['StatusCode']) || $res['StatusCode'] !== 200) {
            return back()->withErrors([
                'leadlovers' => 'Erro ao criar o lead no LeadLovers. Detalhes: ' . json_encode($res),
            ]);
        }

        return redirect()->route('empresa.login')->with(
            'success',
            'Cadastro realizado com sucesso. Verifique seu e-mail antes de concluir o acesso.'
        );
    }
}
