<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        $broker = Password::broker('users');

        try {
            $status = $broker->sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            $this->removeUndeliveredToken($broker, $request->string('email')->toString());

            Log::error('Falha ao enviar o link de recuperação de senha do usuário.', [
                'exception' => $exception::class,
                'mailer' => config('mail.default'),
            ]);

            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'Não foi possível enviar o link agora. Tente novamente em alguns instantes.',
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
            $user = User::query()
                ->where('email', $email)
                ->first();

            if ($user) {
                $broker->getRepository()->delete($user);
            }
        } catch (Throwable $cleanupException) {
            Log::warning('Não foi possível remover um token de senha de usuário não entregue.', [
                'exception' => $cleanupException::class,
            ]);
        }
    }
}
