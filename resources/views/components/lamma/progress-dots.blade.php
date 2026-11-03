{{-- Question progress: done = navy dot, current = wide coral pill, upcoming = line (HANDOFF). Decorative: the text chip beside it says "Question 3 of 10".
     every (Vote mode): a small upright marker after every `every` questions shows where the players vote on the next category; `voting` lights the one
     after the current question in coral while that vote is on. --}}
@props(['total', 'current', 'every' => null, 'voting' => false])
<ol aria-hidden="true" {{ $attributes->class('flex items-center gap-1.5') }}>
    @foreach (range(1, $total) as $n)
        <li @class([
            'h-2.5 rounded-full',
            'w-2.5 bg-navy' => $n < $current,
            'w-6 bg-coral' => $n === $current,
            'w-2.5 bg-line' => $n > $current,
        ])></li>
        @if ($every && $n % $every === 0 && $n < $total)
            <li @class([
                'mx-0.5 h-4 w-1 rounded-full',
                'bg-coral' => $voting && $n === $current,
                'bg-navy' => $n < $current,
                'bg-line-strong' => $n >= $current && ! ($voting && $n === $current),
            ]) data-vote-marker></li>
        @endif
    @endforeach
</ol>
