<?php


use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Auth\CompanyNewPasswordController;
use App\Http\Controllers\Auth\CompanyPasswordResetLinkController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImobiliariaRegistrationController;
use App\Http\Controllers\ImobiliariaAuthController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\CorretorRegistrationController;
use App\Http\Controllers\CorretorAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SimulationController;
use App\Services\PottencialService;
use App\Http\Controllers\InsuranceAnalysisController;
use App\Http\Controllers\CepController;
use App\Models\Imobiliaria;
use App\Http\Controllers\DashboardLeadController;

Route::get('/teste/token_acesso', [PottencialService::class, 'testAuthentication']);

Route::view('/', 'index')->name('index');

Route::get('/dashboard', fn () => redirect()->route('Dashboard'))
    ->middleware(['auth', '2fa'])
    ->name('dashboard');

Route::get('/analise', fn () => redirect()->route('Dashboard'))
    ->middleware(['auth', '2fa'])
    ->name('analise');


Route::prefix('/Dashboard')->group(function () {

    Route::get('/User',[DashboardController::class, 'index'])
    ->middleware(['auth', '2fa'])
    ->name('Dashboard');

    Route::post('/sync-again', [DashboardController::class, 'syncAgain'])
    ->middleware(['auth', '2fa'])
    ->name('Dashboard.syncAgain');
    
    Route::put('/leads/{lead}', [DashboardLeadController::class, 'update'])
    ->name('dashboard.leads.update');

    Route::post('/leads/{lead}/reanalisar', [DashboardLeadController::class, 'reanalyze'])
    ->name('dashboard.leads.reanalyze');
    
    Route::get('/analises', [InsuranceAnalysisController::class, 'index'])
    ->middleware(['auth', '2fa'])
    ->name('insurance-analyses.index');

    Route::get('/analises/{batch}', [InsuranceAnalysisController::class, 'show'])
    ->middleware(['auth', '2fa'])
    ->name('insurance-analyses.show');

    Route::post('/analises/provider/{analysis}/retry', [InsuranceAnalysisController::class, 'retry'])
    ->middleware(['auth', '2fa'])
    ->name('insurance-analyses.retry');

    Route::post('/analises/provider/{analysis}/sync-status', [InsuranceAnalysisController::class, 'syncStatus'])
    ->middleware(['auth', '2fa'])
    ->name('insurance-analyses.sync-status');

    
    Route::get('/Admin', function (){
        return view('dashboard-admin');
    })
    ->middleware(['auth:admin', 'admin.2fa'])
    ->name('Dashboard-Admin');

    Route::get('/sync-status', [DashboardController::class, 'syncStatus'])
    ->middleware(['auth', '2fa', 'throttle:sync-status'])->name('Dashboard.syncStatus');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});


Route::middleware('guest:admin')->group(function () {
    Route::get('/admin/login/form', [CorretorAuthController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/admin/login', [CorretorAuthController::class, 'login'])->name('admin.login.post');

    Route::get('/admins/cadastro', [CorretorRegistrationController::class, 'showRegistrationForm'])->name('admin.register.form');
    Route::post('/admins/cadastro', [CorretorRegistrationController::class, 'store'])->name('admin.register.post');
});

Route::middleware('auth:admin')->group(function () {
    Route::get('/admin/2fa', [CorretorAuthController::class, 'showTwoFactorForm'])->name('admin.2fa.form');
    Route::post('/admin/2fa', [CorretorAuthController::class, 'verifyTwoFactor'])->name('admin.2fa.verify');
    Route::post('/admin/2fa/resend', [CorretorAuthController::class, 'resendTwoFactor'])->name('admin.2fa.resend');

    Route::post('/admin/logout', [CorretorAuthController::class, 'logout'])->name('admin.logout');
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
        Route::get('/imobiliaria-nao-cadastrada', [SimulationController::class, 'unregisteredCompanyForm'])
            ->middleware('throttle:simulation-page')
            ->name('unregistered-company.form');

        Route::post('/imobiliaria-nao-cadastrada', [SimulationController::class, 'storeUnregisteredCompanyLead'])
            ->middleware('throttle:simulation-submit')
            ->name('unregistered-company.store');

        Route::get('/locatario', [SimulationController::class, 'tenantForm'])
            ->middleware('throttle:simulation-page')
            ->name('tenant.form');

        Route::post('/locatario', [SimulationController::class, 'storeTenantLead'])
            ->middleware('throttle:simulation-submit')
            ->name('tenant.store');

        Route::get('/locador', [SimulationController::class, 'landlordForm'])
            ->middleware('throttle:simulation-page')
            ->name('landlord.form');

        Route::post('/locador', [SimulationController::class, 'storeLandlordLead'])
            ->middleware('throttle:simulation-submit')
            ->name('landlord.store');
    });

Route::get('/cep/{cep}', [CepController::class, 'show'])
    ->where('cep', '[0-9\.\-]+')
    ->middleware('throttle:30,1')
    ->name('cep.show');
    
Route::prefix('/empresa')->group( function () {
    Route::get('/form', [ImobiliariaRegistrationController::class, 'showRegistrationForm'])->name('empresa.register.form');
    Route::post('/register', [ImobiliariaRegistrationController::class, 'store'])->name('empresa.register.post');
    Route::get('/login', [ImobiliariaAuthController::class, 'showLoginForm'])->name('empresa.login');
    Route::post('/login/post', [ImobiliariaAuthController::class, 'login'])->name('empresa.login.post');
    Route::get('/logout', [ImobiliariaAuthController::class, 'logout'])->name('empresa.logout');
});
    

Route::middleware('guest')->group(function () {
    Route::get('/empresa/forgot-password', [CompanyPasswordResetLinkController::class, 'create'])
        ->name('company.password.request');

    Route::post('/empresa/forgot-password', [CompanyPasswordResetLinkController::class, 'store'])
        ->name('company.password.email');

    Route::get('/empresa/reset-password/{token}', [CompanyNewPasswordController::class, 'create'])
        ->name('company.password.reset');

    Route::post('/empresa/reset-password', [CompanyNewPasswordController::class, 'store'])
        ->name('company.password.store');
});

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::get('/2fa', [TwoFactorController::class, 'index'])->name('2fa');
    Route::post('/2fa', [TwoFactorController::class, 'verify'])->name('2fa.verify.post');
    Route::post('/2fa/resend', [TwoFactorController::class, 'resend'])->name('2fa.resend');
});
