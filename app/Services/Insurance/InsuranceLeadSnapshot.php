<?php

namespace App\Services\Insurance;

use App\Models\InsuranceQuote;
use App\Models\Lead;
use Illuminate\Support\Arr;

final class InsuranceLeadSnapshot
{
    private const RELATIONS = ['lead_empresa', 'company', 'imobiliariaVinculada', 'endereco', 'despesas', 'conjuge', 'locador', 'imobiliariaInformada'];

    public function capture(Lead $lead): array
    {
        $lead->load(self::RELATIONS);
        $snapshot = ['attributes' => Arr::only($lead->getAttributes(), [
            'id', 'company_id', 'tipo_solicitante', 'cpf', 'data_nascimento', 'tipo_locacao',
            'tipo_pessoa', 'empresa_constituida', 'data_fundacao_calculada', 'ramo_atividade',
            'descrever_atividade', 'atividade_economica_id', 'estado_civil', 'nome', 'email', 'tel', 'origem',
        ]), 'relations' => []];
        foreach (self::RELATIONS as $name) {
            $model = $lead->getRelation($name);
            $snapshot['relations'][$name] = $model ? (in_array($name, ['company', 'imobiliariaVinculada'], true)
                ? Arr::only($model->getAttributes(), ['id', 'name', 'cnpj', 'email', 'phone', 'cep', 'city', 'state', 'tokio_partner_code'])
                : $model->getAttributes()) : null;
        }

        $document = $lead->rentalApplicantDocument();
        $isCompany = strlen($document) === 14 || $lead->tipo_pessoa === 'J';
        $snapshot['is_company'] = $isCompany;
        if ($isCompany) {
            $snapshot['relations']['conjuge'] = null;
            $snapshot['attributes']['estado_civil'] = null;
        }
        $snapshot['tokio'] = [
            'has_spouse' => ! $isCompany && filled(data_get($snapshot, 'relations.conjuge.cpf')) && filled(data_get($snapshot, 'relations.conjuge.nome')),
            'partner_code' => $lead->tipo_solicitante === 'imobiliaria_cadastrada' ? $lead->imobiliariaVinculada?->getAttribute('tokio_partner_code') : null,
            'cnae' => null,
            'parameters' => Arr::only(config('services.tokio', []), ['commission', 'remuneration', 'discount', 'surcharge', 'assistance', 'tipo_imovel']),
        ];
        if (config('services.tokio.enabled', false) && ! $isCompany && $lead->atividade_economica_id) {
            $snapshot['tokio']['cnae'] = \App\Models\AtividadeEconomicaCodigo::query()
                ->where('atividade_economica_id', $lead->atividade_economica_id)->where('seguradora', 'tokio')->where('ativo', true)
                ->whereHas('atividadeEconomica', fn ($query) => $query->where('ativo', true))
                ->pluck('codigo')->filter(fn ($code) => ctype_digit((string) $code))->sortBy(fn ($code) => (int) $code)->first();
        }

        return $snapshot;
    }

    public function restore(array $snapshot, string $kind = 'primary'): Lead
    {
        $lead = (new Lead)->newFromBuilder($snapshot['attributes']);
        foreach (self::RELATIONS as $name) {
            $attributes = $snapshot['relations'][$name] ?? null;
            $lead->setRelation($name, $attributes ? $lead->{$name}()->getRelated()->newFromBuilder($attributes) : null);
        }
        if ($kind === 'spouse') {
            $spouse = $lead->conjuge;
            $lead->forceFill([
                'nome' => $spouse?->nome, 'cpf' => $spouse?->cpf,
                'data_nascimento' => $spouse?->getAttribute('data_nascimento'),
                'tipo_pessoa' => 'F',
            ]);
            $lead->setRelation('lead_empresa', null);
        }
        if ($kind !== 'joint') {
            $lead->setRelation('conjuge', null);
        }

        return $lead;
    }

    public function attach(InsuranceQuote $quote): void
    {
        $quote->setRelation('lead', $this->restore(
            $quote->scenario->providerRun->round->lead_snapshot,
            $quote->scenario->kind,
        ));
    }
}
