<section class="profile-section" aria-labelledby="profile-details-title">
    <header class="profile-section__heading">
        <span class="profile-section__icon" aria-hidden="true"><i class="bi bi-person-vcard"></i></span>
        <div>
            <h2 id="profile-details-title">Dados da conta</h2>
            <p>Edite o nome e o e-mail do usuário vinculado ao painel.</p>
        </div>
    </header>

    <form method="POST" action="{{ route('profile.update') }}" class="profile-form">
        @csrf
        @method('PATCH')

        <div class="profile-field">
            <label for="name">Nome</label>
            <input
                type="text"
                id="name"
                name="name"
                class="profile-input"
                value="{{ old('name', $user->name) }}"
                autocomplete="name"
                required
                @error('name') aria-invalid="true" aria-describedby="profile-name-error" autofocus @enderror
            >
            @error('name')
                <p id="profile-name-error" class="profile-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="profile-field">
            <label for="email">E-mail</label>
            <input
                type="email"
                id="email"
                name="email"
                class="profile-input"
                value="{{ old('email', $user->email) }}"
                autocomplete="username"
                required
                @error('email')
                    aria-invalid="true" aria-describedby="profile-email-error"
                    @if (! $errors->has('name')) autofocus @endif
                @enderror
            >
            @error('email')
                <p id="profile-email-error" class="profile-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="profile-actions">
            <button type="submit" class="profile-button profile-button--primary">Salvar dados</button>
        </div>
    </form>
</section>
