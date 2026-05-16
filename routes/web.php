<?php


use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Auth\CompanyNewPasswordController;
use App\Http\Controllers\Auth\CompanyPasswordResetLinkController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CompanyRegistrationController;
use App\Http\Controllers\CompanyAuthController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\AdminsRegistrationController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PublicLeadController;
use App\Http\Controllers\SimulationController;
use App\Services\PottencialService;
use App\Http\Controllers\InsuranceAnalysisController;
use App\Http\Controllers\CepController;





//Route::get('/teste/token_acesso', [PottencialService::class, 'testAuthentication']);

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

    Route::get('/analises', [InsuranceAnalysisController::class, 'index'])
    ->name('insurance-analyses.index');

    Route::get('/analises/{batch}', [InsuranceAnalysisController::class, 'show'])
    ->name('insurance-analyses.show');

    Route::post('/analises/provider/{analysis}/retry', [InsuranceAnalysisController::class, 'retry'])
    ->name('insurance-analyses.retry');

    Route::post('/analises/provider/{analysis}/sync-status', [InsuranceAnalysisController::class, 'syncStatus'])
    ->name('insurance-analyses.sync-status');
    
    Route::get('/Admin', function (){
        return view('dashboard-admin');
    })
    ->middleware(['auth:admin', 'admin.2fa'])
    ->name('Dashboard-Admin');

    Route::get('/sync-status', [DashboardController::class, 'syncStatus'])
    ->middleware(['auth', '2fa'])->name('Dashboard.syncStatus');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});


Route::middleware('guest:admin')->group(function () {
    Route::get('/admin/login/form', [AdminAuthController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.post');

    Route::get('/admins/cadastro', [AdminsRegistrationController::class, 'showRegistrationForm'])->name('admin.register.form');
    Route::post('/admins/cadastro', [AdminsRegistrationController::class, 'store'])->name('admin.register.post');
});

Route::middleware('auth:admin')->group(function () {
    Route::get('/admin/2fa', [AdminAuthController::class, 'showTwoFactorForm'])->name('admin.2fa.form');
    Route::post('/admin/2fa', [AdminAuthController::class, 'verifyTwoFactor'])->name('admin.2fa.verify');
    Route::post('/admin/2fa/resend', [AdminAuthController::class, 'resendTwoFactor'])->name('admin.2fa.resend');

    Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');
});



Route::prefix('simulacao')
    ->name('simulation.')
    ->middleware('throttle:20,1')
    ->group(function () {
        // Página inicial do questionário.
        Route::get('/', [SimulationController::class, 'start'])
            ->name('start');

        // Recebe o perfil escolhido e redireciona.
        Route::post('/perfil', [SimulationController::class, 'chooseProfile'])
            ->name('profile');

        Route::get('/sucesso', [SimulationController::class, 'success'])
            ->name('success');

        // Imobiliária cadastrada: tela para digitar chave.
        Route::get('/imobiliaria-cadastrada', [SimulationController::class, 'registeredCompanyAccess'])
            ->name('registered-company.access');

        Route::post('/imobiliaria-cadastrada/verificar', [SimulationController::class, 'verifyCompanyCode'])
            ->middleware('throttle:5,1')
            ->name('registered-company.verify');

        // Formulário da imobiliária cadastrada após chave validada.
        Route::get('/imobiliaria-cadastrada/{code}', [SimulationController::class, 'registeredCompanyForm'])
            ->name('registered-company.form');

        Route::post('/imobiliaria-cadastrada/{code}', [SimulationController::class, 'storeRegisteredCompanyLead'])
            ->name('registered-company.store');

        // Outros perfis.
        Route::get('/imobiliaria-nao-cadastrada', [SimulationController::class, 'unregisteredCompanyForm'])
            ->name('unregistered-company.form');

        Route::post('/imobiliaria-nao-cadastrada', [SimulationController::class, 'storeUnregisteredCompanyLead'])
            ->name('unregistered-company.store');

        Route::get('/locatario', [SimulationController::class, 'tenantForm'])
            ->name('tenant.form');

        Route::post('/locatario', [SimulationController::class, 'storeTenantLead'])
            ->name('tenant.store');

        Route::get('/locador', [SimulationController::class, 'landlordForm'])
            ->name('landlord.form');

        Route::post('/locador', [SimulationController::class, 'storeLandlordLead'])
            ->name('landlord.store');
    });

Route::get('/cep/{cep}', [CepController::class, 'show'])
    ->where('cep', '[0-9\.\-]+')
    ->middleware('throttle:30,1')
    ->name('cep.show');
    
Route::prefix('/empresa')->group( function () {
    Route::get('/form', [CompanyRegistrationController::class, 'showRegistrationForm'])->name('empresa.register.form');
    Route::post('/register', [CompanyRegistrationController::class, 'store'])->name('empresa.register.post');
    Route::get('/login', [CompanyAuthController::class, 'showLoginForm'])->name('empresa.login');
    Route::post('/login/post', [CompanyAuthController::class, 'login'])->name('empresa.login.post');
    Route::get('/logout', [CompanyAuthController::class, 'logout'])->name('empresa.logout');
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
