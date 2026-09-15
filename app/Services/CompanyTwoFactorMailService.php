<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class CompanyTwoFactorMailService
{
    public function sendCode(string $recipient, string $code, CarbonInterface $expiresAt): void
    {
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('The company 2FA recipient must be a valid email address.');
        }

        if (! preg_match('/^\d{6}$/', $code)) {
            throw new InvalidArgumentException('The company 2FA code must contain exactly six digits.');
        }

        Mail::send('emails.2fa-code', [
            'code' => $code,
            'expiresAt' => $expiresAt->format('H:i T'),
        ], function (Message $message) use ($recipient): void {
            $message
                ->to($recipient)
                ->subject('Seu código de verificação');
        });
    }
}
