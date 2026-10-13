<div class="grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
    @include('simulation.partials.business-activity')

    <div class="simulation-field min-w-0" data-activity-picker data-search-url="{{ route('simulation.activities') }}">
        <label for="descrever_atividade" class="form-label">Atividade exercida <span class="text-danger">*</span></label>
        <input type="hidden" name="atividade_economica_id" value="{{ $selectedActivity?->id }}" data-activity-id>
        <div class="relative">
            <input id="descrever_atividade" type="text" name="descrever_atividade" class="form-control"
                value="{{ $selectedActivity?->descricao }}" placeholder="Busque por atividade ou código"
                maxlength="255" required autocomplete="off" role="combobox" aria-autocomplete="list"
                aria-expanded="false" aria-controls="activity-options" aria-describedby="activity-help activity-status{{ $errors->has('atividade_economica_id') ? ' activity-error' : '' }}"
                @if($errors->has('atividade_economica_id')) aria-invalid="true" @endif
                data-activity-search>
            <ul id="activity-options" role="listbox" aria-label="Atividades disponíveis" hidden
                class="absolute left-0 right-0 top-full z-30 max-h-60 list-none overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg" data-activity-options></ul>
        </div>
        <span id="activity-help" class="text-xs text-slate-500">Digite pelo menos 2 caracteres e selecione a atividade da lista.</span>
        <span id="activity-status" class="text-xs text-slate-600" role="status" aria-live="polite" data-activity-status></span>
        @error('atividade_economica_id')
            <span id="activity-error" class="text-xs text-red-600">{{ $message }}</span>
        @enderror
    </div>
</div>
