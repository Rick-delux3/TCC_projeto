<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkLeadCompanyRequest;
use App\Models\Lead;
use App\Services\LeadCompanyLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class LeadCompanyController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(LinkLeadCompanyRequest $request, Lead $lead, LeadCompanyLinkService $links): JsonResponse|RedirectResponse
    {
        $requestLog = $links->request(
            $lead,
            (int) $request->validated('company_id'),
            $request->user('admin'),
            $request->ip(),
            $request->userAgent(),
        );

        $message = 'A solicitação de vínculo foi recebida e será processada em segundo plano.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'request_id' => $requestLog->id], 202);
        }

        return back()->with('success', $message);
    }
}
