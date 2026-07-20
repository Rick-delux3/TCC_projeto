<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Imobiliaria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

class CompanyPasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.company-forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $broker = Password::broker('companies');

        try {
            $status = $broker->sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            $this->removeUndeliveredToken($broker, $request->string('email')->toString());

            Log::error('Falha ao enviar o link de recuperação de senha da imobiliária.', [
                'exception' => $exception::class,
                'mailer' => config('mail.default'),
            ]);

            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'Não foi possível enviar o link agora. Verifique sua conexão e tente novamente em alguns instantes.',
                ]);
        }

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : back()->withInput($request->only('email'))
                ->withErrors(['email' => __($status)]);
    }

    private function removeUndeliveredToken(object $broker, string $email): void
    {
        try {
            $company = Imobiliaria::query()
                ->where('email', $email)
                ->first();

            if ($company) {
                $broker->getRepository()->delete($company);
            }
        } catch (Throwable $cleanupException) {
            Log::warning('Não foi possível remover um token de senha não entregue.', [
                'exception' => $cleanupException::class,
            ]);
        }
    }
}
