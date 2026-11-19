<div class="container simulation-page py-4">
    <section class="simulation-panel simulation-panel--compact simulation-success mx-auto" aria-labelledby="completion-title">
        <div class="simulation-panel__body p-4 p-md-5">
            <x-brand-logo variant="logo_header" class="simulation-start__brand" />
            <span class="simulation-success__mark" aria-hidden="true">@include('simulation.partials.form-icon', ['icon' => 'check'])</span>
            {{ $slot }}
        </div>
    </section>
</div>
