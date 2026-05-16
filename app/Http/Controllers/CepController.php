<?php

namespace App\Http\Controllers;

use App\Services\CepService;
use Illuminate\Http\JsonResponse;

class CepController extends Controller
{
    /**
     * Endpoint usado pelo JavaScript dos formulários.
     *
     * Exemplo:
     * GET /cep/18270000
     */
    public function show(string $cep, CepService $cepService): JsonResponse
    {
        $cep = preg_replace('/\D/', '', $cep ?? '');

        if (strlen($cep) !== 8) {
            return response()->json([
                'success' => false,
                'message' => 'CEP inválido. Informe 8 dígitos.',
            ], 422);
        }

        $address = $cepService->find($cep);

        if (!$address) {
            return response()->json([
                'success' => false,
                'message' => 'CEP não encontrado.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $address,
        ]);
    }
}