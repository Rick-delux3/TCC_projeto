@props(['title', 'titleId', 'descriptionId', 'eyebrow', 'icon' => 'bi-person-vcard'])

<div class="modal-header dashboard-modal-heading">
    <div class="dashboard-modal-heading__identity">
        <span class="dashboard-modal-heading__icon" aria-hidden="true"><i class="bi {{ $icon }}"></i></span>
        <div class="dashboard-modal-heading__copy">
            <span class="dashboard-modal-heading__eyebrow">{{ $eyebrow }}</span>
            <h2 class="modal-title" id="{{ $titleId }}">{{ $title }}</h2>
            <p id="{{ $descriptionId }}" class="dashboard-modal-heading__description">{{ $description }}</p>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
</div>
@if ($slot->isNotEmpty())
    <div class="dashboard-modal-status">{{ $slot }}</div>
@endif
