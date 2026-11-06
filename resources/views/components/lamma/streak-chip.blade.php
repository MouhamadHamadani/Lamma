{{-- The phone's "3 in a row" chip, next to the +N: a sun sticker with a bolt and the count. Shown from 3 right answers in a row. Display only: a streak scores nothing. --}}
@use('App\Support\Isolate')
@props(['count'])
<span {{ $attributes->class('inline-flex h-10 items-center gap-1.5 whitespace-nowrap rounded-chip border-3 border-navy bg-sun px-3.5 font-display text-lg font-extrabold text-navy shadow-sticker-sm') }} data-test="streak" data-streak="{{ $count }}">
    <x-lamma.icon name="bolt" :size="20" :stroke="2.4" />
    {!! __(':count in a row', ['count' => Isolate::ltr($count)]) !!}
</span>
