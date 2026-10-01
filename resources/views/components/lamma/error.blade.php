{{-- Inline form error: coral-700 text with an icon, so it never relies on colour alone. Announced when it appears. --}}
<p role="alert" {{ $attributes->class('flex items-center gap-1.5 text-sm font-semibold text-coral-700') }}>
    <x-lamma.icon name="alert" :size="18" />
    {{ $slot }}
</p>
