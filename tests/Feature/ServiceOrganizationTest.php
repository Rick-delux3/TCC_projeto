<?php

use App\Services\Access\CompanyInvitationService;
use App\Services\Access\CompanyTwoFactorMailService;
use App\Services\Access\CorretorFirstLoginCodeService;
use App\Services\Access\CorretorInvitationService;
use App\Services\Dashboard\CorretorDashboardLeadQuery;
use App\Services\Insurance\LeadReanalysisService;
use App\Services\Insurance\Payloads\TokioCompanyApplicantsPayloadBuilder;
use App\Services\Insurance\PottencialService;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use App\Services\Insurance\TooService;
use App\Services\LeadLovers\CompanyTagService;
use App\Services\LeadLovers\LeadLoversApiClient;
use App\Services\LeadLovers\LeadLoversConfirmationRecoveryService;
use App\Services\LeadLovers\LeadLoversInitialFailureRecoveryService;
use App\Services\LeadLovers\LeadLoversLeadResolver;
use App\Services\LeadLovers\LeadLoversResultTagService;
use App\Services\LeadLovers\LeadLoversTagOperationCoordinator;
use App\Services\Leads\CompanyApplicantService;
use App\Services\Leads\LeadCompanyLinkService;
use App\Services\Leads\RejectedLeadRetentionService;
use App\Services\Lookup\CepService;
use App\Services\Lookup\CpfLookupService;
use Illuminate\Support\Facades\Http;

it('resolves domain services and their dependencies without external requests', function (string $service): void {
    Http::preventStrayRequests();
    Http::fake();
    config([
        'services.cpf_lookup.cpfhub.api_key' => 'test-key',
        'services.cpf_lookup.enabled' => false,
    ]);

    expect(app($service))->toBeInstanceOf($service);

    Http::assertNothingSent();
})->with([
    CompanyInvitationService::class,
    CompanyTwoFactorMailService::class,
    CorretorFirstLoginCodeService::class,
    CorretorInvitationService::class,
    CorretorDashboardLeadQuery::class,
    LeadReanalysisService::class,
    PottencialService::class,
    TooService::class,
    CompanyTagService::class,
    LeadLoversApiClient::class,
    LeadLoversConfirmationRecoveryService::class,
    LeadLoversInitialFailureRecoveryService::class,
    LeadLoversLeadResolver::class,
    LeadLoversResultTagService::class,
    LeadLoversTagOperationCoordinator::class,
    CompanyApplicantService::class,
    LeadCompanyLinkService::class,
    RejectedLeadRetentionService::class,
    CepService::class,
    CpfLookupService::class,
    InsuranceProviderResolver::class,
    TokioCompanyApplicantsPayloadBuilder::class,
]);
