<?php

namespace App\Http\Controllers;

use App\Actions\Companies\RegisterCompany;
use App\Http\Requests\StoreCompanyRequest;
use App\Services\CompanyTagService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImobiliariaRegistrationController extends Controller
{
    public function showRegistrationForm(CompanyTagService $companyTags)
    {
       $tagsOficiais = $companyTags->availableTags();

        return view('imobiliaria.register-company', compact('tagsOficiais'));
    }

    public function store(StoreCompanyRequest $request, RegisterCompany $registerCompany)
    {
        $registration = $registerCompany->execute($request->validated());
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


        return redirect()->route('empresa.login')->with(
            'success',
            'Cadastro realizado com sucesso. Faça login para continuar.'
        );
    }
}
