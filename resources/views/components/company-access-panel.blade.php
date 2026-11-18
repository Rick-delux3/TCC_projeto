@props(['illustration', 'titleId'])

<section {{ $attributes->class('registered-access') }} aria-labelledby="{{ $titleId }}">
    <div class="registered-access__visual" aria-hidden="true">
        <img src="{{ asset($illustration) }}" alt="" width="864" height="1080" decoding="async">
    </div>

    <div class="registered-access__content">
        <x-brand-logo variant="logo_header" class="registered-access__logo" />

        {{ $slot }}
    </div>
</section>
