@props([
    'status',
])

@if ($status)
    <div role="status" {{ $attributes->class('flex items-center gap-2.5 rounded-input border-2 border-teal bg-tint-teal px-4 py-3 text-[15px] font-semibold') }}>
        <x-lamma.icon name="check" :size="20" :stroke="2.6" />
        {{ $status }}
    </div>
@endif
