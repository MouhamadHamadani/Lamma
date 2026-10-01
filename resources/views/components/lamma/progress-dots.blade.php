{{-- Question progress: done = navy dot, current = wide coral pill, upcoming = line (HANDOFF). Decorative: the text chip beside it says "Question 3 of 10". --}}
@props(['total', 'current'])
<ol aria-hidden="true" {{ $attributes->class('flex items-center gap-1.5') }}>
    @foreach (range(1, $total) as $n)
        <li @class([
            'h-2.5 rounded-full',
            'w-2.5 bg-navy' => $n < $current,
            'w-6 bg-coral' => $n === $current,
            'w-2.5 bg-line' => $n > $current,
        ])></li>
    @endforeach
</ol>
