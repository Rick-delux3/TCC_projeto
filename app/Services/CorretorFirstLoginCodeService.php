<?php

namespace App\Services;

use App\Models\Corretor;
use App\Models\CorretorLoginVerificacaoCode;
use App\Notifications\CorretorFirstLoginCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CorretorFirstLoginCodeService
{
    public function sendCode(Corretor $corretor, Request $request): void
    {
        // Invalida códigos antigos ainda não usados
        CorretorLoginVerificacaoCode::where('corretor_id', $corretor->id)
            ->whereNull('used_at')
            ->update([
                'used_at' => now(),
            ]);

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);

        CorretorLoginVerificacaoCode::create([
            'corretor_id' => $corretor->id,
            'code_hash' => Hash::make($code),
            'expires_at' => $expiresAt,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        $corretor->forceFill([
            'first_login_code_sent_at' => now(),
        ])->save();

        $corretor->notify(
            new CorretorFirstLoginCodeNotification(
                code: $code,
                expiresAt: $expiresAt->format('H:i')
            )
        );
    }
}