<template data-simulation-template="{{ $condition }}">
    @include($fields)
</template>
<div id="{{ $condition }}-fields" class="contents" data-simulation-fields="{{ $condition }}">
    @if ($visible)
        @include($fields)
    @endif
</div>
