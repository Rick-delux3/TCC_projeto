<?php

namespace App\Http\Controllers;

use App\Actions\Companies\RegisterCompany;
use App\Actions\Companies\StartCompanyTwoFactorChallenge;
use App\Http\Requests\StoreCompanyRequest;
use App\Services\CompanyTagService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ImobiliariaRegistrationController extends Controller
{
    public function showRegistrationForm(CompanyTagService $companyTags): View
    {
        $tagsOficiais = $companyTags->availableTags();

        return view('imobiliaria.register-company', compact('tagsOficiais'));
    }

    public function store(
        StoreCompanyRequest $request,
        RegisterCompany $registerCompany,
        StartCompanyTwoFactorChallenge $startChallenge,
    ): RedirectResponse {
        try {
            $registration = $registerCompany->execute($request->validated());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('empresa.register.form'));
        }
        $company = $registration['company'];
        $user = $registration['user'];

        // Sends the standard Laravel email verification link.
        try {
            event(new Registered($user));
        } catch (Throwable $exception) {
            Log::error('Cadastro concluído, mas a verificação de e-mail não foi enviada.', [
                'company_id' => $company->id,
                'exception' => $exception::class,
                'mailer' => config('mail.default'),
            ]);
        }

        if (! $startChallenge->execute($user, $request)) {
            return redirect()->route('empresa.login')
                ->withErrors(['email' => 'Cadastro realizado, mas não foi possível enviar o código de verificação. Faça login para tentar novamente.'])
                ->onlyInput('email');
        }

        return redirect()->route('2fa')->with(
            'success',
            'Cadastro realizado com sucesso. Enviamos o código de verificação ao e-mail da imobiliária.'
        );
    }
}
