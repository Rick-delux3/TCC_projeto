<?php

namespace App\Http\Controllers;

use App\Models\Imobiliaria;
use App\Models\LeadLoversTag;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Http\Requests\StoreCompanyRequest;
use App\Services\CompanyTagService;
use App\Services\LeadLoversService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImobiliariaRegistrationController extends Controller
{
    public function showRegistrationForm(CompanyTagService $companyTags)
    {
       $tagsOficiais = $companyTags->availableTags();

        return view('imobiliaria.register-company', compact('tagsOficiais'));
    }

    public function store(StoreCompanyRequest $request, CompanyTagService $companyTags, LeadLoversService $leadlovers)
    {
        $data = $request->validated();

        if(! empty($data['leadlovers_tag_id'])){
            $companyTag = LeadLoversTag::query()
            ->where(
                'leadlovers_tag_id',
                $data['leadlovers_tag_id']
            )->where('active', true)
            ->firstOrFail();
        } else {

            if($companyTags->hasAvailableTags()) {
                throw ValidationException::withMessages([
                    'company_name' => 'Uma imobiliária ficou disponível. Atualize a página e selecione-a.'
                ]);
            }

            $title = $data['company_name'];

            $localTagExist = LeadLoversTag::query()->where('title', $title)
            ->exists();

            $companyExist = Imobiliaria::query()->where('name', $title)
            ->exists();

            if($localTagExist || $companyExist) {
                throw ValidationException::withMessages([
                    'company_name' => 'Já existe uma imobiliária ou tag cadastrada com esse nome'
                ]);
            }

            $createTag = $leadlovers->createTag($title);

            if(! $createTag['success'] || ! $createTag['tag_id']){
                Log::warning('Cadastro interrompido: tag não criada', [
                    'title' => $title,
                    'status' => $createTag['status'],
                    'error' => $createTag['error'],
                ]);


                throw ValidationException::withMessages([
                    'company_name' => 'Não foi possível criar a tag na LeadLovers. Tente novamente em alguns minutos.',
                ]);

            }

            $companyTag = LeadLoversTag::updateOrCreate(
                ['leadlovers_tag_id' => $createTag['tag_id']],

                [
                    'title' => $title,
                    'active' => true,
                    'raw_payload' => $createTag['response'],
                ]
            );
        }

        [$company, $user] = DB::transaction(function () use ($companyTag, $data) {
            $password = Hash::make($data['password']);

            $company = Imobiliaria::create([
                'name' => $companyTag->title,
                'email' => $data['email'],
                'phone' => $data['phone'],
                'cnpj' => $data['cnpj'],
                'cep' => $data['cep'],
                'city' => $data['city'],
                'state' => $data['state'],
                'password' => $password,
                'lead_form_token' => Str::random(64),
                'lead_form_active' => true,
                'leadlovers_tag_id' => $companyTag->leadlovers_tag_id,
                'leadlovers_tag_name' => $companyTag->title,
            ]);

            $user = User::create([
                'name' => $companyTag->title,
                'email' => $data['email'],
                'password' => $password,
                'company_id' => $company->id,
            ]);

            return [$company, $user];
        });

        // Sends the standard Laravel email verification link.
        try {
            event(new Registered($user));
        } catch (Throwable $exception) {
            Log::error('Cadastro concluído, mas a verificação de e-mail não foi enviada.', [
                'company_id' => $company->id,
                'exception' => $exception::class,
                'mailer' => config('mail.default'),
            ]);
        }


        return redirect()->route('empresa.login')->with(
            'success',
            'Cadastro realizado com sucesso. Faça login para continuar.'
        );
    }
}
