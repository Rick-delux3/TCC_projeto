<?php

namespace App\Actions\Companies;

use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\TwoFactorCode;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

class UpdateCompanyPassword
{
    public function execute(Imobiliaria $company, Corretor $changedBy, #[SensitiveParameter] string $password, ?string $ip = null): void
    {
        Gate::forUser($changedBy)->authorize('update-real-estate-company');

        $company->getConnection()->transaction(function () use ($company, $changedBy, $password, $ip): void {
            $lockedCompany = Imobiliaria::query()->lockForUpdate()->findOrFail($company->getKey());
            $users = $lockedCompany->usuarios()->orderBy('id')->lockForUpdate()->get();
            if ($users->isEmpty()) {
                if (blank($lockedCompany->name) || ! filter_var($lockedCompany->email, FILTER_VALIDATE_EMAIL)) {
                    throw new DomainException('Complete o nome e o e-mail da imobiliária antes de definir a senha de acesso.');
                }
                if (User::query()->where('email', $lockedCompany->email)->exists()) {
                    throw new DomainException('O e-mail desta imobiliária já pertence a outro usuário de acesso.');
                }
            }

            $hash = Hash::make($password);
            $lockedCompany->forceFill(['password' => $hash])->save();
            if ($users->isEmpty()) {
                $users->push($lockedCompany->usuarios()->create([
                    'name' => $lockedCompany->name,
                    'email' => $lockedCompany->email,
                    'password' => $hash,
                ]));
            }
            foreach ($users as $user) {
                $user->forceFill(['password' => $hash, 'remember_token' => Str::random(60)])->save();
                Password::broker('users')->deleteToken($user);
            }
            Password::broker('companies')->deleteToken($lockedCompany);
            TwoFactorCode::query()->whereIn('user_id', $users->modelKeys())->delete();

            CorretorActivityLog::query()->create([
                'corretor_id' => $changedBy->id,
                'action' => 'imobiliaria_password_updated',
                'model_type' => Imobiliaria::class,
                'model_id' => $lockedCompany->id,
                'new_values' => ['password_changed' => true],
                'description' => 'Alterou a senha de acesso da imobiliária.',
                'ip' => $ip,
            ]);
        });
    }
}
