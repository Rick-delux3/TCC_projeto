@props(['title', 'eyebrow', 'description', 'backUrl' => null, 'backLabel' => 'Voltar para a listagem', 'headingId' => 'management-page-title'])

@once
    @push('styles')
        <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
    @endpush
@endonce

<header class="management-page-header" aria-labelledby="{{ $headingId }}">
    <div class="management-page-header__copy">
        @if ($backUrl)
            <a href="{{ $backUrl }}" class="management-back-link">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>{{ $backLabel }}
            </a>
        @endif
        <span class="management-eyebrow">{{ $eyebrow }}</span>
        <h1 id="{{ $headingId }}">{{ $title }}</h1>
        <p>{{ $description }}</p>
    </div>
    @if ($slot->isNotEmpty())
        <div class="management-page-header__actions">{{ $slot }}</div>
    @endif
</header>
