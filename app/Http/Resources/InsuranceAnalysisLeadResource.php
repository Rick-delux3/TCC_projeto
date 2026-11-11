<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceAnalysisLeadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource['pageData'];
        if ($request->header('X-Analysis-Components') === '1') {
            $data['components'] = view('insurance-analyses.partials.live-content', ['pageData' => $data])->render();
        }

        return $data;
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store');
    }
}
