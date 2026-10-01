{{-- Ready (teal + check), Not ready (dashed) or Disconnected (dashed, muted). The state is always in the text, never colour alone.
     size: md 36 | sm 30 (phone). --}}
@props(['ready' => false, 'disconnected' => false, 'size' => 'md'])
@php $ready = $ready && ! $disconnected; @endphp
<span {{ $attributes->class([
    'inline-flex items-center gap-2 whitespace-nowrap rounded-chip border-2 px-3.5 font-bold',
    'h-9 text-sm' => $size === 'md',
    'h-[30px] text-xs' => $size === 'sm',
    'border-teal bg-tint-teal text-navy' => $ready,
    'border-dashed border-line-strong bg-white text-ink-subtle' => ! $ready,
]) }}>
    @if ($ready)
        <x-lamma.icon name="check" :size="16" :stroke="3" />
        {{ __('Ready') }}
    @elseif ($disconnected)
        {{ __('Disconnected') }}
    @else
        {{ __('Not ready') }}
    @endif
</span>
