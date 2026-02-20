<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CompanyRegistrationController;
use App\Http\Controllers\CompanyAuthController;

Route::get('/analise', function () {
    return view('escolha-analise');
})->middleware(['auth', 'verified'])->name('analise');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/', [CompanyRegistrationController::class, 'showRegistrationForm']);
Route::post('/empresa/register', [CompanyRegistrationController::class, 'store'])->name('empresa.register.post');

Route::get('/empresa/login', [CompanyAuthController::class, 'showLoginForm'])->name('empresa.login');
Route::post('/empresa/login', [CompanyAuthController::class, 'login'])->name('empresa.login.post');

Route::get('/empresa/logout', [CompanyAuthController::class, 'logout'])->name('empresa.logout');

require __DIR__.'/auth.php';
