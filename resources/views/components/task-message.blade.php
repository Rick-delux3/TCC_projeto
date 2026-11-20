@props(['id', 'title', 'tone' => 'info', 'action' => 'Entendi', 'headerClass' => '', 'buttonClass' => 'btn-secondary', 'showClose' => true, 'autoShow' => true])
@php($branded = config('branding.active', 'tcc') === 'tcc')
<div id="{{ $id }}" class="modal fade" tabindex="-1" aria-hidden="true"
     aria-labelledby="{{ $id }}-title" aria-describedby="{{ $id }}-message" @if ($autoShow) data-task-message-auto @endif>
    <div class="modal-dialog modal-dialog-centered">
        <div @class(['task-dialog' => $branded, 'modal-content' => ! $branded]) @if ($branded) data-tone="{{ $tone }}" @endif>
            <div class="{{ $branded ? 'task-dialog__heading' : 'modal-header '.$headerClass }}">
                @if ($branded)
                    <span class="task-dialog__symbol" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            @if ($tone === 'success')
                                <path d="m5 12 4.5 4.5L19 7"/>
                            @elseif ($tone === 'error')
                                <path d="M12 6v7m0 4h.01"/>
                            @else
                                <path d="M12 11v6m0-10h.01"/>
                            @endif
                        </svg>
                    </span>
                @endif
                <h2 id="{{ $id }}-title" class="{{ $branded ? 'task-dialog__title' : 'modal-title fs-5' }}">{{ $title }}</h2>
                @if ($showClose)
                    <button type="button" @class(['task-dialog__close' => $branded, 'btn-close' => ! $branded, 'btn-close-white' => ! $branded && $headerClass !== '']) data-bs-dismiss="modal" aria-label="Fechar">@if ($branded)<span aria-hidden="true">×</span>@endif</button>
                @endif
            </div>
            <div id="{{ $id }}-message" class="{{ $branded ? 'task-dialog__message' : 'modal-body' }}">{{ $slot }}</div>
            <div class="{{ $branded ? 'task-dialog__actions' : 'modal-footer' }}">
                <button type="button" class="{{ $branded ? 'task-dialog__action' : 'btn '.$buttonClass }}" data-bs-dismiss="modal">{{ $action }}</button>
            </div>
        </div>
    </div>
</div>
@if ($autoShow)
    @once
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const messages = Array.from(document.querySelectorAll('[data-task-message-auto]'));
                function showNext() {
                    const message = messages.shift();
                    if (!message) return;
                    message.addEventListener('hidden.bs.modal', showNext, { once: true });
                    bootstrap.Modal.getOrCreateInstance(message).show();
                }
                showNext();
            }, { once: true });
        </script>
    @endonce
@endif
