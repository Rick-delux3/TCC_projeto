<?php

namespace App\Actions\Companies;

use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\LeadLoversTag;
use App\Models\User;
use App\Services\CompanyTagService;
use App\Services\LeadLoversService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RegisterCompany
{
    public function __construct(
        private readonly CompanyTagService $companyTags,
        private readonly LeadLoversService $leadlovers,
    ) {}

    public function execute(
        array $data,
        ?Corretor $registeredBy = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $companyTag = $this->resolveCompanyTag($data);

        return DB::transaction(function () use (
            $companyTag,
            $data,
            $registeredBy,
            $ip,
            $userAgent,
        ): array {
            $password = Hash::make($data['password']);

            $company = Imobiliaria::query()->create([
                'name' => $companyTag->title,
                'email' => $data['email'],
                'phone' => $data['phone'],
                'cnpj' => $data['cnpj'],
                'cep' => $data['cep'],
                'city' => $data['city'],
                'state' => $data['state'],
                'password' => $password,
                'lead_form_active' => $data['lead_form_active'] ?? true,
                'leadlovers_tag_id' => $companyTag->leadlovers_tag_id,
                'leadlovers_tag_name' => $companyTag->title,
            ]);

            $user = User::query()->create([
                'name' => $companyTag->title,
                'email' => $data['email'],
                'password' => $password,
                'company_id' => $company->id,
            ]);

            if ($registeredBy !== null) {
                CorretorActivityLog::query()->create([
                    'corretor_id' => $registeredBy->id,
                    'action' => 'imobiliaria_created',
                    'model_type' => Imobiliaria::class,
                    'model_id' => $company->id,
                    'new_values' => [
                        'name' => $company->name,
                        'user_id' => $user->id,
                        'lead_form_active' => $company->lead_form_active,
                    ],
                    'description' => sprintf(
                        'Cadastrou a imobiliária "%s".',
                        $company->name,
                    ),
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                ]);
            }

            return [
                'company' => $company,
                'user' => $user,
            ];
        });
    }

    private function resolveCompanyTag(array $data): LeadLoversTag
    {
        if(! empty($data['leadlovers_tag_id'])) {
            return LeadLoversTag::query()
                ->where('leadlovers_tag_id', $data['leadlovers_tag_id'])
                ->where('active', true)
                ->firstOrFail();
        }

        if($this->companyTags->hasAvailableTags()) {
            throw ValidationException::withMessages([
                'company_name' => 'Uma imobiliária ficou disponível. Atualize a página e selecione-a.',
            ]);
        }

        $title = $data['company_name'];

        $localTagExist = LeadLoversTag::query()
            ->where('title', $title)
            ->exists();

        $companyExist = Imobiliaria::query()
            ->where('name', $title)
            ->exists();
        
        if($localTagExist || $companyExist) {
            throw ValidationException::withMessages([
                'company_name' => 'Já existe uma imobiliária ou tag cadastrada com esse nome.',
            ]);
        }

        $createTag = $this->leadlovers->createTag($title);

        if(! $createTag['success'] || ! $createTag['tag_id']) {
            Log::warning('Cadastro interrompido: tag não criada', [
                'title' => $title,
                'status' => $createTag['status'],
                'error' => $createTag['error'],
            ]);

            throw ValidationException::withMessages([
                'company_name' => 'Não foi possível criar a tag na LeadLovers. Tente novamente em alguns minutos.',
            ]);
        }

        return LeadLoversTag::query()->updateOrCreate(
            ['leadlovers_tag_id' => $createTag['tag_id']],
            [
                'title' => $title,
                'active' => true,
                'raw_payload' => $createTag['response'],
            ],
        );
    }
}