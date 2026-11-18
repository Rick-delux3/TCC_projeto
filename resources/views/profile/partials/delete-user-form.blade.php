<section
    class="profile-delete"
    aria-labelledby="profile-delete-title"
    x-data
    @if ($errors->userDeletion->any())
        x-init="$nextTick(() => $refs.dialog.showModal())"
    @endif
>
    <header class="profile-section__heading">
        <span class="profile-section__icon profile-section__icon--danger" aria-hidden="true">
            <i class="bi bi-trash3" aria-hidden="true"></i>
        </span>
        <div>
            <h2 id="profile-delete-title">Excluir conta</h2>
            <p>O acesso desta conta ao painel será removido permanentemente.</p>
        </div>
    </header>

    <button
        type="button"
        class="profile-button profile-button--danger-outline"
        x-ref="trigger"
        x-on:click="$refs.dialog.showModal()"
        aria-haspopup="dialog"
        aria-controls="deleteAccountModal"
    >Excluir minha conta</button>

    <dialog
        id="deleteAccountModal"
        class="profile-dialog"
        aria-labelledby="deleteAccountModalLabel"
        aria-describedby="deleteAccountModalDescription"
        x-ref="dialog"
        x-on:close="$refs.password.value = ''; $refs.trigger.focus()"
    >
        <div class="profile-dialog__inner">
            <header class="profile-section__heading">
                <span class="profile-section__icon profile-section__icon--danger" aria-hidden="true"><i class="bi bi-trash3"></i></span>
                <div>
                    <h2 id="deleteAccountModalLabel">Confirmar exclusão</h2>
                    <p id="deleteAccountModalDescription">Informe sua senha atual para excluir a conta. A exclusão é permanente.</p>
                </div>
            </header>

            <form method="POST" action="{{ route('profile.destroy') }}" class="profile-form">
                @csrf
                @method('DELETE')

                <div class="profile-field">
                    <label for="delete_password">Senha atual</label>
                    <input
                        type="password"
                        id="delete_password"
                        name="password"
                        class="profile-input"
                        autocomplete="current-password"
                        x-ref="password"
                        required
                        autofocus
                        @error('password', 'userDeletion') aria-invalid="true" aria-describedby="delete-password-error" @enderror
                    >
                    @error('password', 'userDeletion')
                        <p id="delete-password-error" class="profile-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="profile-actions profile-actions--dialog">
                    <button type="button" class="profile-button profile-button--secondary" x-on:click="$refs.dialog.close()">Cancelar</button>
                    <button type="submit" class="profile-button profile-button--danger">Excluir conta</button>
                </div>
            </form>
        </div>
    </dialog>
</section>
