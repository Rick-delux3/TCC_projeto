@php
    $passwordFields = [
        'current_password' => ['label' => 'Senha atual', 'autocomplete' => 'current-password'],
        'password' => ['label' => 'Nova senha', 'autocomplete' => 'new-password'],
        'password_confirmation' => ['label' => 'Confirmar nova senha', 'autocomplete' => 'new-password'],
    ];
    $firstPasswordError = collect(array_keys($passwordFields))
        ->first(fn ($field) => $errors->updatePassword->has($field));
@endphp

<section class="profile-section" aria-labelledby="profile-password-title">
    <header class="profile-section__heading">
        <span class="profile-section__icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
        <div>
            <h2 id="profile-password-title">Alterar senha</h2>
            <p>Escolha uma senha longa e exclusiva para esta conta.</p>
        </div>
    </header>

    <form method="POST" action="{{ route('password.update') }}" class="profile-form profile-form--password">
        @csrf
        @method('PUT')

        @foreach ($passwordFields as $field => $settings)
            <div class="profile-field">
                <label for="{{ $field }}">{{ $settings['label'] }}</label>
                <input
                    type="password"
                    id="{{ $field }}"
                    name="{{ $field }}"
                    class="profile-input"
                    autocomplete="{{ $settings['autocomplete'] }}"
                    required
                    @error($field, 'updatePassword')
                        aria-invalid="true" aria-describedby="{{ $field }}-error"
                        @if ($field === $firstPasswordError) autofocus @endif
                    @enderror
                >
                @error($field, 'updatePassword')
                    <p id="{{ $field }}-error" class="profile-error">{{ $message }}</p>
                @enderror
            </div>
        @endforeach

        <div class="profile-actions">
            <button type="submit" class="profile-button profile-button--secondary">Atualizar senha</button>
        </div>
    </form>
</section>
