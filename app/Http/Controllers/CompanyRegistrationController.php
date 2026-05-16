<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\LeadLoversTag;
use App\Models\User;
use App\Services\LeadLoversService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Http\Requests\StoreCompanyRequest;


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


    

    public function store(StoreCompanyRequest $request, LeadLoversService $leadLovers)
    {
        $data = $request->validated();

        $companyTag = LeadLoversTag::where('title', $data['name'])
            ->where('active', true)
            ->first();

        $company = Company::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'city' => $data['city'],
            'state' => $data['state'],
            'password' => Hash::make($data['password']),
            'lead_form_token' => Str::random(64),
            'lead_form_active' => true,
            'leadlovers_tag_id' => $companyTag?->leadlovers_tag_id,
            'leadlovers_tag_name' => $companyTag?->title,
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'company_id' => $company->id,
        ]);

        // Sends the standard Laravel email verification link.
        event(new Registered($user));

        if ($companyTag) {
            $res = $leadLovers->createLead([
                'Name' => $company->name,
                'Email' => $company->email,
                'Phone' => $company->phone ?? '',
                'City' => $company->city,
                'State' => $company->state,
                'Company' => $company->name,
                'Tag' => $companyTag->leadlovers_tag_id,
            ]);

            if (!is_array($res) || !$this->leadLoversResponseWasSuccessful($res)) {
                Log::warning('Empresa cadastrada, mas a LeadLovers retornou falha ao criar lead.', [
                    'company_id' => $company->id,
                    'status_code' => is_array($res) ? ($res['StatusCode'] ?? null) : null,
                    'message' => is_array($res) ? ($res['Message'] ?? $res['message'] ?? null) : null,
                ]);
            }
        } else {
            Log::warning('Empresa cadastrada sem tag local correspondente da LeadLovers.', [
                'company_id' => $company->id,
                'company_name' => $company->name,
            ]);
        }

        return redirect()->route('empresa.login')->with(
            'success',
            'Cadastro realizado com sucesso. Verifique seu e-mail antes de concluir o acesso.'
        );
    }

    private function leadLoversResponseWasSuccessful(array $response): bool
    {
        if (($response['StatusCode'] ?? null) === 200) {
            return true;
        }

        $message = (string) ($response['Message'] ?? $response['message'] ?? '');
        $exception = $response['Exception'] ?? $response['exception'] ?? null;

        return $exception === null
            && mb_stripos($message, 'Novo lead inserido na fila para processamento') !== false;
    }
}
