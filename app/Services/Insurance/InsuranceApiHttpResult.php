<?php

namespace App\Services\Insurance;

use Symfony\Component\HttpFoundation\Response;

class InsuranceApiHttpResult
{
    public function description(?int $status): string
    {
        return match (true) {
            $status === null => 'Sem resposta HTTP do endpoint de orçamento. Pode haver falha de autenticação, conexão, DNS, TLS ou timeout; o resultado remoto não está confirmado.',
            $status === 200 => 'Solicitação processada. Confira o resultado de negócio no corpo da resposta.',
            $status === 201 => 'Recurso criado pela companhia.',
            $status === 202 => 'Solicitação aceita para processamento; o orçamento pode ainda não estar concluído.',
            $status === 204 => 'Solicitação processada sem corpo de resposta.',
            $status === 400 => 'Requisição recusada: confira o formato e os dados enviados.',
            $status === 401 => 'Autenticação ausente, inválida ou expirada.',
            $status === 403 => 'Acesso negado pela companhia.',
            $status === 404 => 'Endpoint ou recurso não encontrado.',
            $status === 405 => 'Método HTTP não permitido nesse endpoint.',
            $status === 408 => 'O servidor informou tempo limite da requisição.',
            $status === 409 => 'Conflito com o estado atual do recurso.',
            $status === 422 => 'Dados ou regras de negócio rejeitados; confira os detalhes retornados.',
            $status === 429 => 'Limite de requisições atingido; respeite o Retry-After, quando informado.',
            $status >= 100 && $status < 200 => 'Resposta informativa; não confirma criação do orçamento.',
            $status >= 200 && $status < 300 => 'Sucesso HTTP; confira o resultado de negócio no corpo da resposta.',
            $status >= 300 && $status < 400 => 'Redirecionamento recebido e não seguido. Confira o endpoint configurado.',
            $status >= 400 && $status < 500 => 'Requisição recusada pela companhia; confira o corpo da resposta.',
            $status >= 500 && $status < 600 => 'Falha no servidor ou gateway da companhia. A criação pode ter ocorrido; consulte antes de repetir.',
            default => 'Código HTTP não padronizado retornado pela companhia.',
        };
    }

    public function label(?int $status): string
    {
        return $status === null ? 'sem resposta' : $status.' '.(Response::$statusTexts[$status] ?? 'Código não padronizado');
    }
}
