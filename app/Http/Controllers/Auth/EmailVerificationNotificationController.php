<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            Log::error('Falha ao reenviar o link de verificação de e-mail.', [
                'user_id' => $request->user()->id,
                'exception' => $exception::class,
                'mailer' => config('mail.default'),
            ]);

            return back()->withErrors([
                'email' => 'Não foi possível enviar o link de verificação agora. Tente novamente em alguns instantes.',
            ]);
        }

        return back()->with('status', 'verification-link-sent');
    }
}
