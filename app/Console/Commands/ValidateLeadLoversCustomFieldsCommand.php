<?php

namespace App\Console\Commands;

use App\Exceptions\LeadLoversApiException;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversLeadResolver;
use Illuminate\Console\Command;

class ValidateLeadLoversCustomFieldsCommand extends Command
{
    protected $signature = 'leadlovers:validate-custom-fields';

    protected $description = 'Valida os IDs configurados de campos personalizados da LeadLovers';

    public function handle(
        LeadLoversApiClient $leadLovers,
        LeadLoversLeadResolver $resolver,
    ): int {
        if (! config('services.leadlovers.enabled', false)) {
            $this->error('Integracao com a LeadLovers desativada. Nenhuma chamada foi realizada.');

            return self::FAILURE;
        }

        try {
            $remoteFields = $leadLovers->listCustomFields();
        } catch (LeadLoversApiException $exception) {
            $this->error(
                'Nao foi possivel consultar os campos personalizados.'
                .($exception->statusCode !== null
                    ? " HTTP {$exception->statusCode}."
                    : '')
            );

            return self::FAILURE;
        }

        $remoteIds = [];

        foreach ($remoteFields as $field) {
            $id = $resolver->positiveInteger($field['id'] ?? null);

            if ($id !== null) {
                $remoteIds[$id] = true;
            }
        }

        $configured = config('services.leadlovers.dynamic_fields', []);
        $configured = is_array($configured) ? $configured : [];
        $seen = [];
        $errors = 0;

        foreach ($configured as $name => $candidate) {
            $id = $resolver->positiveInteger($candidate);

            if ($id === null) {
                $errors++;
                $this->error("{$name}: ID ausente ou invalido.");

                continue;
            }

            if (isset($seen[$id])) {
                $errors++;
                $this->error("{$name}: ID {$id} tambem esta configurado em {$seen[$id]}.");

                continue;
            }

            $seen[$id] = $name;

            if (! isset($remoteIds[$id])) {
                $errors++;
                $this->error("{$name}: ID {$id} nao existe no catalogo remoto.");

                continue;
            }

            $this->line("{$name}: ID {$id} confirmado.");
        }

        if ($configured === []) {
            $this->warn('Nenhum campo personalizado esta configurado.');

            return self::FAILURE;
        }

        if ($errors > 0) {
            $this->error("Validacao concluida com {$errors} problema(s).");

            return self::FAILURE;
        }

        $this->info('Todos os campos personalizados configurados foram confirmados.');

        return self::SUCCESS;
    }
}
