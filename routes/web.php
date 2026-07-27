<?php

use App\Http\Controllers\Auth\CompanyNewPasswordController;
use App\Http\Controllers\Auth\CompanyPasswordResetLinkController;
use App\Http\Controllers\CepController;
use App\Http\Controllers\CorretorAuthController;
use App\Http\Controllers\CorretorDashboardController;
use App\Http\Controllers\CorretorEquipeController;
use App\Http\Controllers\CorretorRegistrationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardLeadController;
use App\Http\Controllers\ImobiliariaAuthController;
use App\Http\Controllers\ImobiliariaRegistrationController;
use App\Http\Controllers\InsuranceAnalysisController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\TwoFactorController;
use App\Services\PottencialService;
use App\Services\TooService;
use Illuminate\Support\Facades\Route;

Route::get('/debug/too/auth', function (TooService $tooService) {
    return response()->json($tooService->testAuthentication());
})->middleware('analysis.enabled');

Route::get('/teste/token_acesso', [PottencialService::class, 'testAuthentication'])
    ->middleware('analysis.enabled');

Route::view('/', 'index')->name('index');

Route::get('/dashboard', fn () => redirect()->route('company.dashboard'))
    ->middleware(['auth', '2fa'])
    ->name('dashboard');

Route::get('/analise', fn () => redirect()->route('company.dashboard'))
    ->middleware(['auth', '2fa', 'analysis.enabled'])
    ->name('analise');

Route::prefix('/Dashboard')->group(function () {

    Route::middleware(['auth', '2fa'])->group(function () {

        Route::get('/User', [DashboardController::class, 'index'])
            ->name('company.dashboard');

        Route::put('/leads/{lead}', [DashboardLeadController::class, 'update'])
            ->name('dashboard.leads.update');

        Route::post('/leads/{lead}/reanalisar', [DashboardLeadController::class, 'reanalyze'])
            ->middleware('analysis.enabled')
            ->name('dashboard.leads.reanalyze');

        Route::get('/analises', [InsuranceAnalysisController::class, 'index'])
            ->name('insurance-analyses.index');

        Route::get('/analises/{batch}', [InsuranceAnalysisController::class, 'show'])
            ->name('insurance-analyses.show');

        Route::post('/analises/provider/{analysis}/retry', [InsuranceAnalysisController::class, 'retry'])
            ->middleware('analysis.enabled')
            ->name('insurance-analyses.retry');

        Route::post('/analises/provider/{analysis}/reanalisar', [InsuranceAnalysisController::class, 'providerReanalysis'])
            ->middleware('analysis.enabled')
            ->name('insurance-analyses.provider-reanalysis');

        Route::post('/analises/provider/{analysis}/sync-status', [InsuranceAnalysisController::class, 'syncStatus'])
            ->middleware('analysis.enabled')
            ->name('insurance-analyses.sync-status');
    });

    Route::prefix('/Admin')->middleware(['auth:admin', 'corretor.active', 'admin.2fa'])->group(function () {

        Route::get('/', [CorretorDashboardController::class, 'index'])
            ->middleware('can:access-dashboard')
            ->name('Dashboard-Admin');

        Route::prefix('simulacoes')->name('admin.simulations.')->middleware(['can:access-simulation-forms'])
            ->group(function () {
                Route::get('/abrir', [SimulationController::class, 'adminResolveForm'])->name('open');

                Route::get('/imobiliaria/{company}', [SimulationController::class, 'adminRegisteredCompanyForm'])
                    ->name('registered-company.form');

                Route::post('/imobiliaria/{company}', [SimulationController::class, 'adminStoreRegisteredCompanyLead'])
                    ->middleware('throttle:simulation-submit')
                    ->name('registered-company.store');

                Route::get('/sem-vinculo/{tipo}', [SimulationController::class, 'adminUnlinkedForm'])
                    ->whereIn('tipo', [
                        'imobiliaria_nao_cadastrada',
                        'locatario',
                        'locador',
                    ])
                    ->name('unlinked.form');

                Route::post('/sem-vinculo/{tipo}', [SimulationController::class, 'adminStoreUnlinkedLead'])
                    ->whereIn('tipo', [
                        'imobiliaria_nao_cadastrada',
                        'locatario',
                        'locador',
                    ])
                    ->middleware('throttle:simulation-submit')
                    ->name('unlinked.store');

            });

        Route::get('/leads', function () {
            return redirect()->to(route('Dashboard-Admin').'#leads-section');
        })
            ->middleware('can:view-leads')
            ->name('admin.leads.index');

        Route::post('/leads/{lead}', [DashboardLeadController::class, 'adminUpdate'])
            ->middleware('can:edit-leads')
            ->name('admin.leads.update');

        Route::post('/leads/{lead}/reanalisar', [DashboardLeadController::class, 'adminReanalyze'])
            ->middleware(['can:create-analysis', 'analysis.enabled'])
            ->name('admin.leads.reanalyze');

        Route::get('/analises', [InsuranceAnalysisController::class, 'adminIndex'])
            ->middleware(['can:view-analyses', 'analysis.enabled'])
            ->name('admin.insurance-analyses.index');

        Route::get('/analises/{batch}', [InsuranceAnalysisController::class, 'adminShow'])
            ->middleware(['can:view-analyses', 'analysis.enabled'])
            ->name('admin.insurance-analyses.show');

        Route::post('/analises/provider/{analysis}/retry', [InsuranceAnalysisController::class, 'adminRetry'])
            ->middleware(['can:create-analysis', 'analysis.enabled'])
            ->name('admin.insurance-analyses.retry');

        Route::post('/analises/provider/{analysis}/reanalisar', [InsuranceAnalysisController::class, 'adminProviderReanalysis'])
            ->middleware(['can:create-analysis', 'analysis.enabled'])
            ->name('admin.insurance-analyses.provider-reanalysis');

        Route::post('/analises/provider/{analysis}/sync-status', [InsuranceAnalysisController::class, 'adminSyncStatus'])
            ->middleware(['can:view-analyses', 'analysis.enabled'])
            ->name('admin.insurance-analyses.sync-status');

        Route::prefix('/equipe')
            ->name('admin.config-equipe.')
            ->middleware('can:manage-organization')
            ->group(function () {
                Route::get('/', [CorretorEquipeController::class, 'index'])->name('index');

                Route::get('/criar', [CorretorEquipeController::class, 'create'])->name('create');

                Route::post('/', [CorretorEquipeController::class, 'store'])->name('store');

                Route::get('/{corretor}/editar', [CorretorEquipeController::class, 'edit'])->name('edit');

                Route::put('/{corretor}', [CorretorEquipeController::class, 'update'])->name('update');

                Route::post('/{corretor}/reenviar-convite', [CorretorEquipeController::class, 'resendInvitation'])
                    ->middleware('throttle:3,10')
                    ->name('resend-invitation');

            });
    });

    Route::middleware('throttle:10,1')
        ->get(
            '/admin/integrante/convite/{corretor}',
            [CorretorAuthController::class, 'acceptMemberInvitation']
        )->name('admin.member.invite.accept');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['ceo.registration.open', 'throttle:5,1'])->group(function () {
    Route::get(config('admin.ceo_registration_path'), [CorretorRegistrationController::class, 'showCeoRegistrationForm'])
        ->name('admin.ceo.register.form');

    Route::post(config('admin.ceo_registration_path'), [CorretorRegistrationController::class, 'storeCeo'])
        ->name('admin.ceo.register.post');
});

Route::middleware(['guest:admin', 'throttle:5,1', 'auth.unframed'])->group(function () {
    Route::get('/ceo/admin/login/form', [CorretorAuthController::class, 'ceoShowLoginForm'])
        ->name('admin.ceo.login');

    Route::post('/ceo/admin/login', [CorretorAuthController::class, 'ceoLogin'])
        ->name('admin.ceo.login.post');

    Route::get('/admin/login/form', [CorretorAuthController::class, 'memberShowLoginForm'])
        ->name('admin.login');

    Route::post('/member/admin/login', [CorretorAuthController::class, 'memberLogin'])
        ->name('admin.member.login.post');

});

Route::middleware(['auth:admin', 'corretor.active'])->group(function () {
    Route::get('/admins/2fa', [CorretorAuthController::class, 'showTwoFactorForm'])->name('admin.2fa.form');
    Route::post('/admins/2fa', [CorretorAuthController::class, 'verifyTwoFactor'])->name('admin.2fa.verify');
    Route::post('/admins/2fa/resend', [CorretorAuthController::class, 'resendTwoFactor'])->name('admin.2fa.resend');

    Route::post('/admins/logout', [CorretorAuthController::class, 'logout'])->name('admin.logout');
});

Route::prefix('simulacao')
    ->name('simulation.')
    ->group(function () {
        // Página inicial do questionário.
        Route::get('/', [SimulationController::class, 'start'])
            ->middleware('throttle:simulation-page')
            ->name('start');

        // Recebe o perfil escolhido e redireciona.
        Route::post('/perfil', [SimulationController::class, 'chooseProfile'])
            ->middleware('throttle:simulation-submit')
            ->name('profile');

        Route::get('/sucesso', [SimulationController::class, 'success'])
            ->name('success');

        // Imobiliária cadastrada: tela para digitar chave.
        Route::get('/imobiliaria-cadastrada', [SimulationController::class, 'registeredCompanyAccess'])
            ->middleware('throttle:simulation-page')
            ->name('registered-company.access');

        Route::post('/imobiliaria-cadastrada/verificar', [SimulationController::class, 'verifyCompanyCode'])
            ->middleware('throttle:simulation-submit')
            ->name('registered-company.verify');

        // Formulário da imobiliária cadastrada após chave validada.
        Route::get('/imobiliaria-cadastrada/{code}', [SimulationController::class, 'registeredCompanyForm'])
            ->middleware('throttle:simulation-page')
            ->name('registered-company.form');

        Route::post('/imobiliaria-cadastrada/{code}', [SimulationController::class, 'storeRegisteredCompanyLead'])
            ->middleware('throttle:simulation-submit')
            ->name('registered-company.store');

        // Outros perfis.
        Route::get('/imobiliaria-nao-cadastrada/proprietario', [SimulationController::class, 'unregisteredCompanyForm'])
            ->middleware('throttle:simulation-page')
            ->name('unregistered-company.form');

        Route::post('/imobiliaria-nao-cadastrada/proprietario', [SimulationController::class, 'storeUnregisteredCompanyLead'])
            ->middleware('throttle:simulation-submit')
            ->name('unregistered-company.store');

        Route::get('/locatario', [SimulationController::class, 'tenantForm'])
            ->middleware('throttle:simulation-page')
            ->name('tenant.form');

        Route::post('/locatario', [SimulationController::class, 'storeTenantLead'])
            ->middleware('throttle:simulation-submit')
            ->name('tenant.store');
    });

Route::get('/cep/{cep}', [CepController::class, 'show'])
    ->where('cep', '[0-9\.\-]+')
    ->middleware('throttle:30,1')
    ->name('cep.show');

Route::prefix('/empresa')->middleware(['guest', 'auth.unframed'])->group(function () {
    Route::get('/form', [ImobiliariaRegistrationController::class, 'showRegistrationForm'])->name('empresa.register.form');
    Route::post('/register', [ImobiliariaRegistrationController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('empresa.register.post');
    Route::get('/login', [ImobiliariaAuthController::class, 'showLoginForm'])->name('empresa.login');
    Route::post('/login/post', [ImobiliariaAuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('empresa.login.post');
});

Route::post('/empresa/logout', [ImobiliariaAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('empresa.logout');

Route::middleware(['guest', 'auth.unframed'])->group(function () {
    Route::get('/empresa/forgot-password', [CompanyPasswordResetLinkController::class, 'create'])
        ->name('company.password.request');

    Route::post('/empresa/forgot-password', [CompanyPasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('company.password.email');

    Route::get('/empresa/reset-password/{token}', [CompanyNewPasswordController::class, 'create'])
        ->name('company.password.reset');

    Route::post('/empresa/reset-password', [CompanyNewPasswordController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('company.password.store');
});

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::get('/2fa', [TwoFactorController::class, 'index'])->name('2fa');
    Route::post('/2fa', [TwoFactorController::class, 'verify'])->name('2fa.verify.post');
    Route::post('/2fa/resend', [TwoFactorController::class, 'resend'])->name('2fa.resend');
});
