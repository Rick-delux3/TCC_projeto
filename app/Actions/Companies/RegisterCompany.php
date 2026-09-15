<?php

namespace App\Actions\Companies;

use App\Exceptions\LeadLoversApiException;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\LeadLoversTag;
use App\Models\User;
use App\Services\CompanyTagService;
use App\Services\LeadLoversApiClient;
use App\Support\ManualLeadResultTags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RegisterCompany
{
    public function __construct(
        private readonly CompanyTagService $companyTags,
        private readonly LeadLoversApiClient $leadLovers,
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
        if (! empty($data['leadlovers_tag_id'])) {
            $tagId = (int) $data['leadlovers_tag_id'];

            if (! $this->companyTags->isAvailable($tagId)) {
                throw ValidationException::withMessages([
                    'leadlovers_tag_id' => 'A imobiliária selecionada não está mais disponível.',
                ]);
            }

            return LeadLoversTag::query()
                ->where('leadlovers_tag_id', $tagId)
                ->where('active', true)
                ->firstOrFail();
        }

        if ($this->companyTags->hasAvailableTags()) {
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

        if ($localTagExist || $companyExist) {
            throw ValidationException::withMessages([
                'company_name' => 'Já existe uma imobiliária ou tag cadastrada com esse nome.',
            ]);
        }

        $remoteTag = $this->createOrReconcileRemoteTag($title);
        $localTag = LeadLoversTag::query()->firstOrNew([
            'leadlovers_tag_id' => $remoteTag['id'],
        ]);
        $remoteTagAlreadyAssigned = Imobiliaria::query()
            ->where('leadlovers_tag_id', $remoteTag['id'])
            ->exists();
        $protectedCommercialIdentity = $localTag->exists
            && in_array(
                $localTag->key,
                ManualLeadResultTags::leadloversKeys(),
                true
            );

        if (
            ($localTag->exists && ! $localTag->active)
            || $protectedCommercialIdentity
            || $remoteTagAlreadyAssigned
        ) {
            throw ValidationException::withMessages([
                'company_name' => 'A tag correspondente não está disponível no catálogo local.',
            ]);
        }

        if (! $localTag->exists) {
            $localTag->key = $this->companyTags->keyForRemoteTag(
                $remoteTag['name'],
                $remoteTag['id']
            );
            $localTag->active = true;
        }

        $localTag->title = $remoteTag['name'];
        $localTag->raw_payload = $remoteTag;
        $localTag->save();

        return $localTag;
    }

    private function createOrReconcileRemoteTag(string $title): array
    {
        try {
            $remoteTag = $this->leadLovers->createTag($title);
        } catch (LeadLoversApiException $exception) {
            if (
                $exception->statusCode === 400
                && $exception->errorCode === 'NAME_EXISTS'
            ) {
                return $this->reconcileExistingRemoteTag($title);
            }

            $this->throwRemoteTagFailure($exception, 'create');
        }

        if (! $this->remoteTagMatches($remoteTag, $title)) {
            Log::warning(
                'Cadastro interrompido: resposta de criação de tag incompatível.',
                ['remote_tag_id' => $remoteTag['id'] ?? null]
            );

            throw ValidationException::withMessages([
                'company_name' => 'A LeadLovers não confirmou o nome da tag criada.',
            ]);
        }

        return $remoteTag;
    }

    private function reconcileExistingRemoteTag(string $title): array
    {
        try {
            $remoteTags = $this->leadLovers->listTags();
        } catch (LeadLoversApiException $exception) {
            $this->throwRemoteTagFailure(
                $exception,
                'list_after_name_exists'
            );
        }

        $expectedName = $this->companyTags
            ->normalizeTagNameForComparison($title);
        $matchesById = [];

        foreach ($remoteTags as $remoteTag) {
            if (
                $this->companyTags->normalizeTagNameForComparison(
                    $remoteTag['name']
                ) !== $expectedName
            ) {
                continue;
            }

            $matchesById[$remoteTag['id']] = $remoteTag;
        }

        if (count($matchesById) !== 1) {
            Log::warning(
                'Cadastro interrompido: NAME_EXISTS sem correspondência remota inequívoca.',
                [
                    'requested_name_ref' => hash('sha256', $expectedName),
                    'matching_remote_ids' => count($matchesById),
                ]
            );

            throw ValidationException::withMessages([
                'company_name' => 'Já existe uma tag remota com esse nome, mas ela não pôde ser identificada com segurança.',
            ]);
        }

        return array_values($matchesById)[0];
    }

    private function remoteTagMatches(array $remoteTag, string $title): bool
    {
        return isset($remoteTag['id'], $remoteTag['name'])
            && is_int($remoteTag['id'])
            && $remoteTag['id'] > 0
            && is_string($remoteTag['name'])
            && $this->companyTags->normalizeTagNameForComparison(
                $remoteTag['name']
            ) === $this->companyTags->normalizeTagNameForComparison($title);
    }

    private function throwRemoteTagFailure(
        LeadLoversApiException $exception,
        string $operation,
    ): never {
        Log::warning('Cadastro interrompido: operação de tag recusada.', [
            'operation' => $operation,
            'http_status' => $exception->statusCode,
            'error_code' => $exception->errorCode,
            'transient' => $exception->isTransient,
            'configuration_error' => $exception->isConfigurationError,
        ]);

        $message = match (true) {
            $exception->isConfigurationError => 'A integração com a LeadLovers está indisponível. Entre em contato com o suporte.',
            $exception->isTransient => 'Não foi possível criar a tag na LeadLovers. Tente novamente em alguns minutos.',
            default => 'A LeadLovers recusou a criação da tag. Verifique o nome informado.',
        };

        throw ValidationException::withMessages([
            'company_name' => $message,
        ]);
    }
}
