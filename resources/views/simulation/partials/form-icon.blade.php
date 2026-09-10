<svg class="simulation-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($icon)
        @case('arrow')<path d="M4 12h15m-6-6 6 6-6 6"/>@break
        @case('check')<path d="m5 12 4 4L19 6"/>@break
        @case('building')<path d="M3 21h18M5 21V7h6m0 14V3h8v18M8 10v1m0 3v1m6-9v1m2-1v1m-2 3v1m2-1v1m-2 3v1m2-1v1m-3 5v-3h3v3"/>@break
        @case('house')<path d="m3 10 9-7 9 7M5 9v12h5v-7h4v7h5V9"/>@break
        @case('trash')<path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7m4-7v7"/>@break
        @case('info')<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v.1"/>@break
        @default <circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 .5c0 1.7-2.5 1.7-2.5 3.5m0 3v.1"/>
    @endswitch
</svg>
