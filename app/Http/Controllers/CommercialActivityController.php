<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchCommercialActivityRequest;
use App\Models\AtividadeEconomica;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            ->with(['codigos' => fn (HasMany $query): HasMany => $query
                ->select(['id', 'atividade_economica_id', 'codigo'])
                ->where('seguradora', 'tokio')->where('ativo', true)->orderBy('id')])
            ->where(function (Builder $query) use ($term): void {
                $query->where(function (Builder $descriptions) use ($term): void {
                    foreach (array_unique(explode(' ', $term)) as $word) {
                        $descriptions->where('descricao_busca', 'like', '%'.$word.'%');
                    }
                })->orWhereHas('codigos', function (Builder $codes) use ($term): void {
                    $codes->where('seguradora', 'tokio')->where('ativo', true)
                        ->where('codigo', 'like', str_replace(' ', '', $term).'%');
                });
            })
            ->orderBy('descricao')->orderBy('id')->limit(11)->get();

        return response()->json([
            'data' => $activities->take(10)->map(fn (AtividadeEconomica $activity): array => [
                'id' => $activity->id,
                'descricao' => $activity->descricao,
                'codigos' => $activity->codigos->pluck('codigo')->all(),
            ])->values(),
            'has_more' => $activities->count() > 10,
        ]);
    }
}
