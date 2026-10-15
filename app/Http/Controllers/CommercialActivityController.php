<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchCommercialActivityRequest;
use App\Models\AtividadeEconomica;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CommercialActivityController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(SearchCommercialActivityRequest $request): JsonResponse
    {
        $term = AtividadeEconomica::normalizarBusca($request->validated('q'));

        if (strlen($term) < 2) {
            return response()->json(['data' => [], 'has_more' => false]);
        }

        $activities = AtividadeEconomica::query()->disponiveis()
            ->select(['id', 'descricao'])
            ->where(function (Builder $query) use ($term): void {
                foreach (array_unique(explode(' ', $term)) as $word) {
                    $query->where('descricao_busca', 'like', '%'.$word.'%');
                }
            })
            ->orderBy('descricao')->orderBy('id')->limit(11)->get();

        return response()->json([
            'data' => $activities->take(10)->map(fn (AtividadeEconomica $activity): array => [
                'id' => $activity->id,
                'descricao' => $activity->descricao,
            ])->values(),
            'has_more' => $activities->count() > 10,
        ]);
    }
}
