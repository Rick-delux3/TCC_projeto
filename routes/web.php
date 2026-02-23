<?php


use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Auth\CompanyNewPasswordController;
use App\Http\Controllers\Auth\CompanyPasswordResetLinkController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CompanyRegistrationController;
use App\Http\Controllers\CompanyAuthController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\TwoFactorLoginController;
use App\Http\Controllers\AdminsRegistrationController;

Route::get('/analise', function () {
    return view('escolha-analise');
})->middleware(['auth', 'verified', '2fa'])->name('analise');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});




Route::get('/admin/form', [AdminsRegistrationController::class, 'showRegistrationForm'])->name('admin.register.form');
Route::post('/admin/register', [AdminsRegistrationController::class, 'store'])->name('admin.register.post');

Route::get('/', [CompanyRegistrationController::class, 'showRegistrationForm'])->name('empresa.register.form');
Route::post('/empresa/register', [CompanyRegistrationController::class, 'store'])->name('empresa.register.post');

Route::get('/empresa/login', [CompanyAuthController::class, 'showLoginForm'])->name('empresa.login');
Route::post('/empresa/login', [CompanyAuthController::class, 'login'])->name('empresa.login.post');

Route::get('/empresa/logout', [CompanyAuthController::class, 'logout'])->name('empresa.logout');

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
