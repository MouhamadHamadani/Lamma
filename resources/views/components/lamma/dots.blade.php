{{-- Three pulsing dots (1.2s loop, 150ms stagger). Still, at full strength, under reduced motion. Decorative: pair with text. --}}
<span aria-hidden="true" {{ $attributes->class('inline-flex items-center gap-2') }}>
    @foreach ([0, 1, 2] as $n)
        <span class="size-3 rounded-full bg-navy motion-safe:animate-dots" style="animation-delay: {{ $n * 150 }}ms"></span>
    @endforeach
</span>
