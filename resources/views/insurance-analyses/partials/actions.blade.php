<footer class="border-top pt-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ url()->current() }}" class="btn btn-outline-primary">Atualizar visualização</a>
        @if ($permissions['edit_lead'])
            <button type="button" class="btn btn-outline-secondary" disabled>Editar dados — em breve</button>
        @endif
        @if ($permissions['create_analysis'])
            <button type="button" class="btn btn-outline-secondary" disabled>Reanalisar — em breve</button>
        @endif
    </div>
    <p class="small text-body-secondary mt-2 mb-0">Esta página exibe os dados salvos ao carregá-la. Use “Atualizar visualização” para consultar os novos resultados. Edição, reanálise e atualização visual automática serão disponibilizadas na próxima etapa.</p>
</footer>
